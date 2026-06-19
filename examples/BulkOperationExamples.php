<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;

/**
 * Bulk operations — asynchronous, JSONL-based exports and imports for large
 * datasets. Kick off, poll to a terminal status, then stream the result rows.
 */
final class BulkOperationExamples
{
    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * Export every product (id + title) and return the decoded rows.
     *
     * @return list<array<string,mixed>>
     */
    public function exportProducts(): array
    {
        $bulk = $this->shopify->bulkOperations();

        $op    = $bulk->runQuery('{ products { edges { node { id title } } } }')['bulkOperation'];
        $final = $bulk->poll($op['id']);   // waits until COMPLETED / FAILED / …

        $rows = [];
        if (($final['status'] ?? null) === 'COMPLETED') {
            foreach ($bulk->results($final) as $row) {  // streams the JSONL, decoded
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Bulk-update many products: one mutation invocation per row. `importMutation`
     * serialises the rows to JSONL, stages them, and starts the operation.
     * Select `id`/`userErrors` in the mutation to get them back in the result file.
     *
     * @param list<array<string,mixed>> $rows e.g. [['input' => ['id' => …, 'title' => …]], …]
     * @return list<array<string,mixed>>
     */
    public function bulkUpdateProducts(array $rows): array
    {
        $bulk = $this->shopify->bulkOperations();

        $started = $bulk->importMutation(
            'mutation call($input: ProductInput!) { productUpdate(input: $input) { product { id } userErrors { field message } } }',
            $rows,
        );

        $final   = $bulk->poll($started['bulkOperation']['id']);
        $results = [];
        if (($final['status'] ?? null) === 'COMPLETED') {
            foreach ($bulk->results($final) as $row) {
                $results[] = $row;
            }
        }

        return $results;
    }

    /**
     * Inspect or cancel the current operation of a type ("QUERY" or "MUTATION").
     */
    public function currentQuery(): ?array
    {
        return $this->shopify->bulkOperations()->current('QUERY');
    }
}
