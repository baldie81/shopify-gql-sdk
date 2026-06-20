<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;
use Baldie81\ShopifyGraphQL\Support\CollectionInput;

/**
 * COLLECTIONS — groupings of products, manual or smart (rule-based). Reached via
 * `$shopify->collections()`.
 *
 *   $ex = new CollectionExamples(ExampleClient::create());
 *
 * Real-life usage: a write method takes **one collection record** as `$data` (a
 * merchandising/ERP definition, defaulting to {@see SAMPLE}). Collection/product
 * ids come from Shopify (params). Typical loop:
 *
 *   foreach ($source->collections() as $record) {
 *       $ex->createManual($record);
 *   }
 *
 * Operations shown in this file:
 *   - createManual($data)               — a collection you fill by hand
 *   - createSmart($data)                — a rule-based collection (auto-membership)
 *   - update($collectionId, $data)      — collectionUpdate (partial)
 *   - delete($collectionId)             — collectionDelete (deletedCollectionId)
 *   - addProducts($collectionId, $ids)  — add to a manual collection (SYNC)
 *   - removeProducts($collectionId,$ids)— remove (ASYNC job; shows polling)
 *   - listProducts($collectionId)       — the collection's products
 *
 * Key points:
 *   - `CollectionInput::rule(column, relation, condition)` makes it smart;
 *     `disjunctive(false)` ANDs the rules, `true` ORs them.
 *   - Membership asymmetry: `addProducts` is synchronous; `removeProducts` /
 *     `reorderProducts` run as **async jobs** — poll with `collections()->job($id)`.
 *
 * Related: ProductExamples, MetafieldDefinitionExamples.
 */
final class CollectionExamples
{
    /** One collection record, shaped like a merchandising/ERP definition. */
    private const SAMPLE = [
        'title'       => 'Summer',
        'description' => '<p>Warm-weather picks.</p>',
        'sortOrder'   => 'BEST_SELLING',
        'rule'        => ['column' => 'TAG', 'relation' => 'EQUALS', 'condition' => 'sale'],
    ];

    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * Create a manual collection from the record (you add products yourself).
     *
     * @param array<string,mixed> $data a collection record (defaults to {@see SAMPLE})
     */
    public function createManual(array $data = self::SAMPLE): array
    {
        return $this->shopify->collections()->create(
            CollectionInput::make($data['title'])
                ->descriptionHtml($data['description'])
                ->sortOrder($data['sortOrder']),
        );
    }

    /**
     * Create a smart collection from the record's rule (auto-membership).
     *
     * @param array<string,mixed> $data a collection record (uses its `rule`)
     */
    public function createSmart(array $data = self::SAMPLE): array
    {
        $rule = $data['rule'];

        return $this->shopify->collections()->create(
            CollectionInput::make($data['title'])
                ->disjunctive(false)
                ->rule($rule['column'], $rule['relation'], $rule['condition']),
        );
    }

    /**
     * Partial update of a collection (here, its description).
     *
     * @param array<string,mixed> $data the record carrying the new values
     */
    public function update(string $collectionId, array $data = self::SAMPLE): array
    {
        return $this->shopify->collections()->update($collectionId, CollectionInput::make()->descriptionHtml($data['description']));
    }

    /**
     * Delete a collection; returns the deleted id in `deletedCollectionId`.
     */
    public function delete(string $collectionId): array
    {
        return $this->shopify->collections()->delete($collectionId);
    }

    /**
     * Add products to a manual collection (synchronous). Ids may be numeric or
     * GIDs — the SDK coerces them to Product GIDs.
     *
     * @param list<string> $productIds product ids (from Shopify / your id map)
     */
    public function addProducts(string $collectionId, array $productIds): array
    {
        return $this->shopify->collections()->addProducts($collectionId, $productIds);
    }

    /**
     * Remove products — asynchronous. Returns a job; this shows how to poll it to
     * completion via `job()`.
     *
     * @param list<string> $productIds
     */
    public function removeProducts(string $collectionId, array $productIds): array
    {
        $result = $this->shopify->collections()->removeProducts($collectionId, $productIds);

        if ($result['job'] !== null) {
            while (!($this->shopify->collections()->job($result['job']['id'])['done'] ?? true)) {
                usleep(500_000);
            }
        }

        return $result;
    }

    /**
     * The products currently in a collection.
     */
    public function listProducts(string $collectionId): array
    {
        return $this->shopify->collections()->products($collectionId);
    }
}
