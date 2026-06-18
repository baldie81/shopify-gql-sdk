<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use Baldie81\ShopifyGraphQL\Enum\BulkOperationStatus;
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

    public function __construct(Client $gql, ?callable $sleeper = null)
    {
        parent::__construct($gql);
        $this->sleeper = $sleeper ?? static fn (int $microseconds) => usleep($microseconds);
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
     * The most recent bulk operation of the given type (QUERY or MUTATION).
     *
     * @return array<string,mixed>|null
     */
    public function current(string $type = 'QUERY'): ?array
    {
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
