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
 * Operations shown in this file:
 *   - createManual()   — a collection you fill by hand
 *   - createSmart()    — a rule-based collection (auto-membership)
 *   - update()         — collectionUpdate (partial)
 *   - delete()         — collectionDelete (returns deletedCollectionId)
 *   - addProducts()    — add to a manual collection (SYNC)
 *   - removeProducts() — remove (ASYNC job; this method shows polling via job())
 *   - listProducts()   — the collection's products
 *
 * Key points:
 *   - Manual vs smart: `CollectionInput::rule(column, relation, condition)` makes
 *     it smart; `disjunctive(false)` ANDs the rules, `true` ORs them.
 *   - Membership asymmetry: `addProducts` is synchronous and returns the
 *     collection, but `removeProducts`/`reorderProducts` run as **async jobs** —
 *     poll the returned job with `collections()->job($id)` until `done`.
 *   - Product ids may be numeric or GIDs.
 *
 * Related: ProductExamples, MetafieldDefinitionExamples (collection metafields).
 */
final class CollectionExamples
{
    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * Create a manual collection (you add products yourself).
     */
    public function createManual(): array
    {
        return $this->shopify->collections()->create(
            CollectionInput::make('Summer')
                ->descriptionHtml('<p>Warm-weather picks.</p>')
                ->sortOrder('BEST_SELLING'),
        );
    }

    /**
     * Create a smart collection — products tagged "sale" join automatically.
     * `disjunctive(false)` ANDs the rules (true would OR them).
     */
    public function createSmart(): array
    {
        return $this->shopify->collections()->create(
            CollectionInput::make('On sale')
                ->disjunctive(false)
                ->rule('TAG', 'EQUALS', 'sale'),
        );
    }

    /**
     * Partial update of a collection (here, its description).
     */
    public function update(string $collectionId): array
    {
        return $this->shopify->collections()->update($collectionId, CollectionInput::make()->descriptionHtml('<p>Updated.</p>'));
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
     * GIDs — the SDK coerces them to ProductGIDs.
     *
     * @param list<string> $productIds
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
