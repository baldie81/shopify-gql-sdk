<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\Enum\BulkOperationType;
use Baldie81\ShopifyGraphQL\ShopifyClient;

/**
 * BULK OPERATIONS — asynchronous, JSONL-based exports (queries) and imports
 * (mutations) for large datasets. Reached via `$shopify->bulkOperations()`.
 *
 *   $ex = new BulkOperationExamples(ExampleClient::create());
 *
 * Real-life usage: for an import you build **rows from your ERP feed** and pass
 * the whole batch as `$data` (defaulting to {@see SAMPLE_ROWS}) — bulk is the
 * mass-write path, so you hand it the entire set at once rather than looping:
 *
 *   $rows = array_map(fn ($p) => ['input' => ['id' => $p['gid'], 'title' => $p['title']]], $erp->products());
 *   $ex->bulkUpdateProducts($rows);
 *
 * The flow: kick off → poll to a terminal status → stream the result rows.
 * Shopify runs only ONE bulk operation of each type at a time per shop.
 *
 * Operations shown in this file:
 *   - exportProducts($query)   — runQuery + poll + results() (streamed JSONL)
 *   - bulkUpdateProducts($rows)— importMutation (rows → JSONL → staged upload) + poll
 *   - current($type)           — inspect the current operation (BulkOperationType)
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
    /** A batch of update rows, as built from an ERP feed. */
    private const SAMPLE_ROWS = [
        ['input' => ['id' => 'gid://shopify/Product/1', 'title' => 'Updated A']],
        ['input' => ['id' => 'gid://shopify/Product/2', 'title' => 'Updated B']],
    ];

    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * Export every product (id + title) and return the decoded rows.
     *
     * @return list<array<string,mixed>>
     */
    public function exportProducts(string $query = '{ products { edges { node { id title } } } }'): array
    {
        $bulk = $this->shopify->bulkOperations();

        $op    = $bulk->runQuery($query)['bulkOperation'];
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
     * serializes the rows to JSONL, stages them, and starts the operation. Select
     * `id`/`userErrors` in the mutation to get them back in the result file.
     *
     * @param list<array<string,mixed>> $rows e.g. [['input' => ['id' => …, 'title' => …]], …]
     * @return list<array<string,mixed>>
     */
    public function bulkUpdateProducts(array $rows = self::SAMPLE_ROWS): array
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
     * Inspect the current bulk operation of a type — e.g. to check progress, or
     * whether one is already running before you start another.
     */
    public function current(BulkOperationType $type = BulkOperationType::Query): ?array
    {
        return $this->shopify->bulkOperations()->current($type);
    }
}
