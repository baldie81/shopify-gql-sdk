<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\GraphQL;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Baldie81\ShopifyGraphQL\Exception\GraphQLException;
use Baldie81\ShopifyGraphQL\Exception\ShopifyException;
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
 * backs off and retries when a response is flagged `THROTTLED`.
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
        private readonly string $apiVersion = '2025-07',
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
     * @param array<string,mixed> $variables
     * @return array<string,mixed>
     * @throws GraphQLException|ThrottledException|TransportException
     */
    public function execute(string $query, array $variables = []): array
    {
        $attempt = 0;

        while (true) {
            $decoded = $this->send($query, $variables);
            $errors  = $decoded['errors'] ?? [];

            if (is_array($errors) && $errors !== []) {
                if ($this->isThrottled($errors)) {
                    if ($attempt < $this->maxRetries) {
                        ($this->sleeper)($this->backoffMicros($decoded));
                        $attempt++;
                        continue;
                    }

                    throw ThrottledException::fromResponse($errors, $decoded['extensions'] ?? []);
                }

                throw GraphQLException::fromErrors($errors);
            }

            return (array) ($decoded['data'] ?? []);
        }
    }

    /**
     * @param array<string,mixed> $variables
     * @return array<string,mixed>
     */
    private function send(string $query, array $variables): array
    {
        try {
            $response = $this->client->request('POST', $this->path, [
                // Cast to object so empty variables serialise as `{}`, not `[]`.
                'json' => ['query' => $query, 'variables' => (object) $variables],
            ]);
        } catch (ConnectException $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            throw $this->httpError($e);
        } catch (GuzzleException $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        return $this->decode($response);
    }

    private function httpError(RequestException $e): ShopifyException
    {
        $response = $e->getResponse();
        if ($response === null) {
            return new TransportException($e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();

        if ($status === 429) {
            return new ThrottledException('Shopify rate limited the request (HTTP 429).', $status, $e);
        }

        return new GraphQLException("Shopify returned HTTP {$status}.", $status, $e, rawBody: $body);
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $body    = (string) $response->getBody();
        $decoded = $body === '' ? [] : json_decode($body, true);

        if (!is_array($decoded)) {
            throw new GraphQLException(
                'Malformed JSON in Shopify response.',
                $response->getStatusCode(),
                rawBody: $body,
            );
        }

        return $decoded;
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
     * sane 0.2s–5s window.
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
        $seconds = max(0.2, min($seconds, 5.0));

        return (int) ceil($seconds * 1_000_000);
    }
}
