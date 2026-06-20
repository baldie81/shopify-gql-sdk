<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;

/**
 * INVENTORY — stock levels per inventory item, per location. Reached via
 * `$shopify->inventory()`.
 *
 *   $ex = new InventoryExamples(ExampleClient::create());
 *
 * Operations shown in this file:
 *   - locations()             — the store's locations (pick one to stock against)
 *   - setQuantities()         — set ABSOLUTE quantities ("this item now has N")
 *   - adjustQuantities()      — change by a DELTA (±N)
 *   - setWithIdempotencyKey() — same, with a caller-supplied retry-safe key
 *   - activate()              — stock a tracked item at a location for the first time
 *
 * Prerequisites & semantics:
 *   - An item must be **tracked** (`VariantInput::tracked()`) and **stocked** at the
 *     location before a quantity sticks — `activate()` stocks it if needed.
 *   - `setQuantities` is NOT product-scoped: one call can stock items across many
 *     products. All methods **auto-chunk at 250** entries.
 *   - Quantity "name" defaults to `available` (other states: `on_hand`, …).
 *
 * Cross-version behaviour (handled internally — your calls never change):
 *   - On API **2026-04+** the SDK attaches the now-mandatory `@idempotent`
 *     directive (a UUID per call/chunk; supply your own with `idempotencyKey:`),
 *     and `setQuantities` resolves the now-required `changeFromQuantity`
 *     (compare-and-swap) by reading current values first. On ≤2026-01 it sends
 *     `ignoreCompareQuantity` instead.
 *
 * Related: VariantExamples (mark a variant tracked), ProductExamples.
 */
final class InventoryExamples
{
    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * The store's locations (pick one to stock against).
     */
    public function locations(): array
    {
        return $this->shopify->inventory()->locations();
    }

    /**
     * Set absolute available quantities. One call can span many products.
     */
    public function setQuantities(string $inventoryItemId, string $locationId): array
    {
        return $this->shopify->inventory()->setQuantities([
            ['inventoryItemId' => $inventoryItemId, 'locationId' => $locationId, 'quantity' => 100],
        ]);
    }

    /**
     * Adjust by a delta (e.g. decrement after a manual correction).
     */
    public function adjustQuantities(string $inventoryItemId, string $locationId): array
    {
        return $this->shopify->inventory()->adjustQuantities([
            ['inventoryItemId' => $inventoryItemId, 'locationId' => $locationId, 'delta' => -5],
        ]);
    }

    /**
     * Pass an idempotencyKey for retry-safe writes (the SDK also generates one
     * automatically on 2026-04+ when you omit it).
     */
    public function setWithIdempotencyKey(string $inventoryItemId, string $locationId): array
    {
        return $this->shopify->inventory()->setQuantities(
            [['inventoryItemId' => $inventoryItemId, 'locationId' => $locationId, 'quantity' => 50]],
            idempotencyKey: 'nightly-sync-2026-06-19',
        );
    }

    /**
     * Stock a tracked item at a location for the first time, seeding a quantity.
     */
    public function activate(string $inventoryItemId, string $locationId): array
    {
        return $this->shopify->inventory()->activate($inventoryItemId, $locationId, available: 10);
    }
}
