<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use Baldie81\ShopifyGraphQL\Enum\BulkOperationStatus;
use Baldie81\ShopifyGraphQL\Enum\BulkOperationType;
use Baldie81\ShopifyGraphQL\Exception\TransportException;
use Baldie81\ShopifyGraphQL\GraphQL\Client;

/**
 * Shopify Bulk Operations: run a query (export) or a mutation (import) that
 * Shopify processes asynchronously and returns as a JSONL file.
 *
 * The flow is: kick off with {@see runQuery()} / {@see runMutation()}, then
 * {@see poll()} the returned operation id until it reaches a terminal status,
 * and read its `url` to download the JSONL. A bulk *mutation* additionally needs
 * its variables uploaded as JSONL first (via staged uploads); pass the resulting
 * `stagedUploadPath` to {@see runMutation()}.
 */
final class BulkOperations extends AbstractResource
{
    private const string FIELDS = 'id status errorCode createdAt completedAt objectCount fileSize url partialDataUrl';

    /** @var callable(int):void */
    private $sleeper;

    private readonly Files $files;

    private readonly ClientInterface $downloader;

    public function __construct(Client $gql, ?callable $sleeper = null, ?Files $files = null, ?ClientInterface $downloader = null)
    {
        parent::__construct($gql);
        $this->sleeper    = $sleeper ?? static fn (int $microseconds) => usleep($microseconds);
        $this->files      = $files ?? new Files($gql);
        // Plain client for downloading the result file from external storage.
        $this->downloader = $downloader ?? new GuzzleClient(['timeout' => 0]);
    }

    /**
     * Stream the rows of a completed operation's result JSONL, each decoded to an
     * array. Reads `url` (or `partialDataUrl` when `$partial` is true); yields
     * nothing if that url isn't set yet (e.g. not COMPLETED). Streamed, so it
     * handles large result files without loading them all into memory.
     *
     * Each row is the decoded JSON object from one line — for a bulk *mutation*
     * that's your mutation's payload (so select `id`/`userErrors` to get them).
     *
     * @param array<string,mixed> $operation a bulk operation (from {@see get()}/{@see poll()})
     * @return iterable<array<string,mixed>>
     */
    public function results(array $operation, bool $partial = false): iterable
    {
        $url = (string) ($operation[$partial ? 'partialDataUrl' : 'url'] ?? '');
        if ($url === '') {
            return;
        }

        try {
            $body = $this->downloader->request('GET', $url, ['stream' => true])->getBody();
        } catch (\Throwable $e) {
            throw new TransportException('Failed to download bulk result: ' . $e->getMessage(), 0, $e);
        }

        $buffer = '';
        while (!$body->eof()) {
            $buffer .= $body->read(65536);
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line   = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);
                if ($line !== '') {
                    yield json_decode($line, true);
                }
            }
        }
        $buffer = trim($buffer);
        if ($buffer !== '') {
            yield json_decode($buffer, true);
        }
    }

    /**
     * Start a bulk query. Returns `{ bulkOperation, userErrors }`.
     *
     * @return array{bulkOperation:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function runQuery(string $query): array
    {
        $mutation = 'mutation ($query: String!) {'
            . ' bulkOperationRunQuery(query: $query) {'
            . ' bulkOperation { ' . self::FIELDS . ' } userErrors { field message } } }';

        $data = $this->gql->execute($mutation, ['query' => $query]);

        return $data['bulkOperationRunQuery'] ?? ['bulkOperation' => null, 'userErrors' => []];
    }

    /**
     * Start a bulk mutation, applying `$mutation` to each line of the JSONL at
     * `$stagedUploadPath`. Returns `{ bulkOperation, userErrors }`.
     *
     * @return array{bulkOperation:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function runMutation(string $mutation, string $stagedUploadPath): array
    {
        $gql = 'mutation ($mutation: String!, $path: String!) {'
            . ' bulkOperationRunMutation(mutation: $mutation, stagedUploadPath: $path) {'
            . ' bulkOperation { ' . self::FIELDS . ' } userErrors { field message } } }';

        $data = $this->gql->execute($gql, ['mutation' => $mutation, 'path' => $stagedUploadPath]);

        return $data['bulkOperationRunMutation'] ?? ['bulkOperation' => null, 'userErrors' => []];
    }

    /**
     * Run a bulk mutation over many inputs end-to-end: serialise `$rows` to JSONL,
     * upload it as a `BULK_MUTATION_VARIABLES` staged file, and start
     * `bulkOperationRunMutation`. Each row is the variables for one invocation of
     * `$mutation` (e.g. `['input' => [...]]`). Poll the returned operation with
     * {@see poll()}.
     *
     * Note: the rows must reference already-resolvable sources — a bulk mutation
     * can't perform the per-file byte uploads that {@see Files::upload()} does, so
     * media rows need hosted/pre-staged URLs.
     *
     * @param iterable<array<string,mixed>> $rows
     * @return array{bulkOperation:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function importMutation(string $mutation, iterable $rows): array
    {
        $jsonl = self::toJsonl($rows);
        if ($jsonl === '') {
            return ['bulkOperation' => null, 'userErrors' => [['message' => 'No rows to import.']]];
        }

        $stagedUploadPath = $this->files->stageJsonl($jsonl);

        return $this->runMutation($mutation, $stagedUploadPath);
    }

    /**
     * @param iterable<array<string,mixed>> $rows
     */
    private static function toJsonl(iterable $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = (string) json_encode($row);
        }

        return implode("\n", $lines);
    }

    /**
     * Fetch a bulk operation by id (version-agnostic, via the node interface).
     *
     * @return array<string,mixed>|null
     */
    public function get(string $id): ?array
    {
        $query = 'query ($id: ID!) { node(id: $id) { ... on BulkOperation { ' . self::FIELDS . ' } } }';
        $node  = $this->gql->execute($query, ['id' => $id])['node'] ?? null;

        return is_array($node) && $node !== [] ? $node : null;
    }

    /**
     * The most recent bulk operation of the given type. Accepts a
     * {@see BulkOperationType} (or the raw "QUERY"/"MUTATION" string).
     *
     * @return array<string,mixed>|null
     */
    public function current(BulkOperationType|string $type = BulkOperationType::Query): ?array
    {
        $type  = $type instanceof BulkOperationType ? $type->value : $type;
        $query = 'query ($type: BulkOperationType) { currentBulkOperation(type: $type) { ' . self::FIELDS . ' } }';

        return $this->gql->execute($query, ['type' => $type])['currentBulkOperation'] ?? null;
    }

    /**
     * Request cancellation of a running bulk operation.
     *
     * @return array{bulkOperation:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function cancel(string $id): array
    {
        $mutation = 'mutation ($id: ID!) {'
            . ' bulkOperationCancel(id: $id) {'
            . ' bulkOperation { ' . self::FIELDS . ' } userErrors { field message } } }';

        $data = $this->gql->execute($mutation, ['id' => $id]);

        return $data['bulkOperationCancel'] ?? ['bulkOperation' => null, 'userErrors' => []];
    }

    /**
     * Poll a bulk operation by id until it reaches a terminal status
     * (see {@see BulkOperationStatus::isTerminal()}) or the attempt budget runs
     * out. Returns the final operation (its `url` holds the JSONL when COMPLETED).
     *
     * @return array<string,mixed>
     */
    public function poll(string $id, int $maxAttempts = 60, float $intervalSeconds = 2.0): array
    {
        $op       = $this->get($id);
        $attempts = 0;

        while ($op !== null && !self::isTerminal($op) && $attempts < $maxAttempts) {
            ($this->sleeper)((int) ($intervalSeconds * 1_000_000));
            $op = $this->get($id);
            $attempts++;
        }

        return $op ?? [];
    }

    /**
     * @param array<string,mixed> $op
     */
    private static function isTerminal(array $op): bool
    {
        return BulkOperationStatus::tryFrom((string) ($op['status'] ?? ''))?->isTerminal() ?? false;
    }
}
