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
 * Real-life usage: a write method takes **one stock row** as `$data` (an ERP/WMS
 * figure, defaulting to {@see SAMPLE}). Inventory-item and location ids come from
 * Shopify (params). Typical loop:
 *
 *   foreach ($erp->stockLevels() as $row) {
 *       $ex->setQuantities($row['inventoryItemId'], $row['locationId'], $row);
 *   }
 *
 * Operations shown in this file:
 *   - locations($first)                             — store locations
 *   - setQuantities($itemId, $locId, $data)         — set ABSOLUTE on-hand ("now N")
 *   - adjustQuantities($itemId, $locId, $data)      — change by a DELTA (±N)
 *   - setWithIdempotencyKey($itemId, $locId, $data) — same, retry-safe key
 *   - activate($itemId, $locId, $data)              — stock an item the first time
 *
 * Prerequisites & semantics:
 *   - An item must be **tracked** and **stocked** at the location before a quantity
 *     sticks — `activate()` stocks it if needed.
 *   - `setQuantities` is NOT product-scoped: one call can stock items across many
 *     products. All methods **auto-chunk at 250** entries.
 *
 * Cross-version behaviour (handled internally — your calls never change):
 *   - On API **2026-04+** the SDK attaches the mandatory `@idempotent` directive
 *     (a UUID per call/chunk; supply your own with `idempotencyKey:`), and
 *     `setQuantities` resolves the now-required `changeFromQuantity`
 *     (compare-and-swap) by reading current values first. On ≤2026-01 it sends
 *     `ignoreCompareQuantity` instead.
 *
 * Related: VariantExamples (mark a variant tracked), ProductExamples.
 */
final class InventoryExamples
{
    /** One stock row, shaped like an ERP/WMS figure. */
    private const SAMPLE = [
        'onHand'     => 100,
        'adjustment' => -5,
        'seed'       => 10,
        'syncRunId'  => 'nightly-2026-06-20',
    ];

    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * The store's locations (pick one to stock against).
     */
    public function locations(int $first = 50): array
    {
        return $this->shopify->inventory()->locations($first);
    }

    /**
     * Push the record's absolute on-hand figure. One call can span many products.
     *
     * @param array<string,mixed> $data a stock row (defaults to {@see SAMPLE})
     */
    public function setQuantities(string $inventoryItemId, string $locationId, array $data = self::SAMPLE): array
    {
        return $this->shopify->inventory()->setQuantities([
            ['inventoryItemId' => $inventoryItemId, 'locationId' => $locationId, 'quantity' => $data['onHand']],
        ]);
    }

    /**
     * Adjust by a delta (e.g. apply an ERP correction without knowing the total).
     *
     * @param array<string,mixed> $data a stock row (uses its `adjustment`)
     */
    public function adjustQuantities(string $inventoryItemId, string $locationId, array $data = self::SAMPLE): array
    {
        return $this->shopify->inventory()->adjustQuantities([
            ['inventoryItemId' => $inventoryItemId, 'locationId' => $locationId, 'delta' => $data['adjustment']],
        ]);
    }

    /**
     * Pass an idempotencyKey (e.g. your ERP sync-run id) for retry-safe writes —
     * the SDK also generates one automatically on 2026-04+ when you omit it.
     *
     * @param array<string,mixed> $data a stock row (uses `onHand` + `syncRunId`)
     */
    public function setWithIdempotencyKey(string $inventoryItemId, string $locationId, array $data = self::SAMPLE): array
    {
        return $this->shopify->inventory()->setQuantities(
            [['inventoryItemId' => $inventoryItemId, 'locationId' => $locationId, 'quantity' => $data['onHand']]],
            idempotencyKey: $data['syncRunId'],
        );
    }

    /**
     * Stock a tracked item at a location for the first time, seeding a quantity.
     *
     * @param array<string,mixed> $data a stock row (uses its `seed`)
     */
    public function activate(string $inventoryItemId, string $locationId, array $data = self::SAMPLE): array
    {
        return $this->shopify->inventory()->activate($inventoryItemId, $locationId, available: $data['seed']);
    }
}
