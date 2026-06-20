<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;

/**
 * BULK OPERATIONS — asynchronous, JSONL-based exports (queries) and imports
 * (mutations) for large datasets. Reached via `$shopify->bulkOperations()`.
 *
 *   $ex = new BulkOperationExamples(ExampleClient::create());
 *
 * The flow: kick off → poll to a terminal status → stream the result rows.
 * Shopify runs only ONE bulk operation of each type at a time per shop.
 *
 * Operations shown in this file:
 *   - exportProducts()     — runQuery + poll + results() (streamed, decoded JSONL)
 *   - bulkUpdateProducts() — importMutation (rows → JSONL → staged upload) + poll
 *   - currentQuery()       — inspect the current operation of a type
 *
 * Notes:
 *   - `importMutation` serializes your rows to JSONL and stages them for you; one
 *     mutation invocation runs per row.
 *   - Select `id` / `userErrors` in your mutation so they appear in the result file.
 *   - `results()` streams the (possibly huge) JSONL without loading it all into
 *     memory; for a FAILED op, check `status` + `errorCode`.
 *   - Also on the resource: runMutation() (pre-staged JSONL), get(), cancel().
 *
 * Related: every resource (bulk is the mass-write path), FileExamples (stageJsonl).
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
     * serializes the rows to JSONL, stages them, and starts the operation.
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
