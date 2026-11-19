<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\GraphQL;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Baldie81\ShopifyGraphQL\Exception\GraphQLException;
use Baldie81\ShopifyGraphQL\Exception\ThrottledException;
use Baldie81\ShopifyGraphQL\Exception\TransportException;
use Psr\Http\Message\ResponseInterface;

/**
 * Thin GraphQL transport over a PSR-compatible Guzzle client.
 *
 * Everything is a single POST of `{query, variables}` to `graphql.json`. This
 * class JSON-encodes the operation, decodes `{data, errors, extensions}`,
 * normalizes Guzzle errors into the SDK hierarchy, and — because Shopify
 * throttles on calculated query cost rather than request count — transparently
 * backs off and retries when a response is flagged `THROTTLED` (or is an HTTP
 * 429). Any other non-2xx status, and any non-empty `errors` member, is thrown.
 */
final class Client
{
    /** @var callable(int):void */
    private $sleeper;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly string $path = 'graphql.json',
        private readonly int $maxRetries = 2,
        ?callable $sleeper = null,
        private readonly string $apiVersion = '2026-10',
    ) {
        $this->sleeper = $sleeper ?? static fn (int $microseconds) => usleep($microseconds);
    }

    public function apiVersion(): string
    {
        return $this->apiVersion;
    }

    /**
     * Whether the configured API version is at least `$version` (dated `YYYY-MM`).
     * `latest`/`unstable` count as newest. Used to gate version-specific behaviour.
     */
    public function apiVersionAtLeast(string $version): bool
    {
        if ($this->apiVersion === 'latest' || $this->apiVersion === 'unstable') {
            return true;
        }
        if (preg_match('/^\d{4}-\d{2}$/', $this->apiVersion) !== 1) {
            return false;
        }

        return $this->apiVersion >= $version; // YYYY-MM sorts lexically
    }

    /**
     * Run a query or mutation and return the GraphQL `data` block.
     *
     * Retries (up to `maxRetries`, with backoff) when Shopify throttles the
     * request — a `THROTTLED` error in the body or an HTTP 429 — and, for
     * *queries only*, on a transient 502/503/504. Mutations are never retried on
     * a gateway error, since the write may already have been applied — and
     * neither is a document that isn't plainly a query (see {@see isQuery()}).
     *
     * @param array<string,mixed> $variables
     * @return array<string,mixed>
     * @throws GraphQLException|ThrottledException|TransportException
     */
    public function execute(string $query, array $variables = []): array
    {
        $attempt = 0;

        while (true) {
            $response = $this->send($query, $variables);
            $status   = $response->getStatusCode();
            $body     = (string) $response->getBody();
            $canRetry = $attempt < $this->maxRetries;

            if ($status === 429) {
                if ($canRetry) {
                    ($this->sleeper)($this->retryAfterMicros($response, $attempt));
                    $attempt++;
                    continue;
                }

                throw new ThrottledException('Shopify rate limited the request (HTTP 429).', $status);
            }

            if ($status < 200 || $status >= 300) {
                if ($canRetry && in_array($status, [502, 503, 504], true) && self::isQuery($query)) {
                    ($this->sleeper)($this->retryAfterMicros($response, $attempt));
                    $attempt++;
                    continue;
                }

                throw $this->httpError($status, $body);
            }

            $decoded = $this->decode($status, $body);
            $errors  = self::normalizeErrors($decoded['errors'] ?? null);

            if ($errors !== []) {
                if ($this->isThrottled($errors)) {
                    if ($canRetry) {
                        ($this->sleeper)($this->backoffMicros($decoded));
                        $attempt++;
                        continue;
                    }

                    throw ThrottledException::fromResponse($errors, (array) ($decoded['extensions'] ?? []));
                }

                throw GraphQLException::fromErrors($errors, $status);
            }

            return (array) ($decoded['data'] ?? []);
        }
    }

    /**
     * @param array<string,mixed> $variables
     */
    private function send(string $query, array $variables): ResponseInterface
    {
        try {
            return $this->client->request('POST', $this->path, [
                // Cast to object so empty variables serialise as `{}`, not `[]`.
                'json'        => ['query' => $query, 'variables' => (object) $variables],
                // Statuses are handled here, uniformly, whatever the injected
                // client's own `http_errors` setting is.
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    /**
     * A non-2xx response. Shopify reports auth/routing failures as
     * `{"errors": "…"}` (a string, not a list), so surface that text.
     */
    private function httpError(int $status, string $body): GraphQLException
    {
        $decoded = json_decode($body, true);
        $errors  = self::normalizeErrors(is_array($decoded) ? ($decoded['errors'] ?? null) : null);
        $detail  = $errors === [] ? '' : ' ' . implode('; ', array_column($errors, 'message'));

        return new GraphQLException("Shopify returned HTTP {$status}.{$detail}", $status, errors: $errors, rawBody: $body);
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(int $status, string $body): array
    {
        $decoded = $body === '' ? [] : json_decode($body, true);

        if (!is_array($decoded)) {
            throw new GraphQLException('Malformed JSON in Shopify response.', $status, rawBody: $body);
        }

        return $decoded;
    }

    /**
     * Coerce the response's `errors` member — normally a list of error objects,
     * but a bare string (or a map) on some failures — to a list of
     * `['message' => …, …]` arrays. Empty when there is no error.
     *
     * @return list<array<string,mixed>>
     */
    private static function normalizeErrors(mixed $errors): array
    {
        if ($errors === null || $errors === '' || $errors === []) {
            return [];
        }
        if (!is_array($errors)) {
            return [['message' => is_scalar($errors) ? (string) $errors : 'Unknown GraphQL error']];
        }
        if (!array_is_list($errors)) {
            return [['message' => (string) json_encode($errors)]];
        }

        return array_map(
            static fn (mixed $error): array => is_array($error)
                ? $error
                : ['message' => is_scalar($error) ? (string) $error : 'Unknown GraphQL error'],
            $errors,
        );
    }

    /**
     * Whether the document is plainly a query, and so safe to send again: its
     * first token is `query` or the `{` of the shorthand form. Whatever GraphQL
     * ignores ahead of that token (whitespace, commas, `#` comments) is skipped,
     * so a mutation with a leading comment is not mistaken for a query. Anything
     * else — a mutation, or a document opening with a fragment — is not retried.
     */
    private static function isQuery(string $query): bool
    {
        $document = (string) preg_replace('/^(?:[\s,\x{FEFF}]+|#[^\r\n]*)+/u', '', $query);

        return preg_match('/^(?:query\b|\{)/', $document) === 1;
    }

    /**
     * How long to wait before retrying an HTTP-level 429/5xx: the `Retry-After`
     * header when present, otherwise 1s, 2s, 4s… — clamped to 0.2s–10s.
     */
    private function retryAfterMicros(ResponseInterface $response, int $attempt): int
    {
        $header  = $response->getHeaderLine('Retry-After');
        $seconds = is_numeric($header) ? (float) $header : (float) (2 ** $attempt);
        $seconds = max(0.2, min($seconds, 10.0));

        return (int) ceil($seconds * 1_000_000);
    }

    /**
     * @param list<array<string,mixed>> $errors
     */
    private function isThrottled(array $errors): bool
    {
        foreach ($errors as $error) {
            if (is_array($error) && ($error['extensions']['code'] ?? null) === 'THROTTLED') {
                return true;
            }
        }

        return false;
    }

    /**
     * Work out how long to wait before retrying a throttled request, from the
     * cost block: (requested - available) / restoreRate seconds, clamped to a
     * sane 0.2s–10s window.
     *
     * @param array<string,mixed> $decoded
     */
    private function backoffMicros(array $decoded): int
    {
        $cost      = $decoded['extensions']['cost'] ?? [];
        $requested = (float) ($cost['requestedQueryCost'] ?? 0);
        $status    = $cost['throttleStatus'] ?? [];
        $available = (float) ($status['currentlyAvailable'] ?? 0);
        $restore   = (float) ($status['restoreRate'] ?? 50);

        $deficit = max(0.0, $requested - $available);
        $seconds = $restore > 0 ? $deficit / $restore : 1.0;
        $seconds = max(0.2, min($seconds, 10.0));

        return (int) ceil($seconds * 1_000_000);
    }
}
