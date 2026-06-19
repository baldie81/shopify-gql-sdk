<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;

/**
 * Inventory — locations, absolute sets, delta adjustments, and activating an
 * item at a location.
 *
 * Cross-version note: on API 2026-04+ the SDK adds the mandatory `@idempotent`
 * directive and resolves the required `changeFromQuantity` for `setQuantities`
 * automatically — your calls don't change. Methods auto-chunk at 250.
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
