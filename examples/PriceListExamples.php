<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;
use Baldie81\ShopifyGraphQL\Support\PriceListInput;

/**
 * Price lists — contextual / market / B2B pricing.
 *
 * A price list is tied to a catalog's context and carries a relative `parent`
 * adjustment (e.g. −20%); you can then override individual variants with *fixed*
 * prices. Fixed-price writes auto-chunk at 250 and coerce variant ids to GIDs.
 */
final class PriceListExamples
{
    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * Create a EUR price list at 20% off, attached to a catalog (the markets/B2B
     * catalog id). Result: `['priceList' => ['id' => …], 'userErrors' => [...]]`.
     */
    public function create(string $catalogId): array
    {
        return $this->shopify->priceLists()->create(
            PriceListInput::make('Wholesale EUR')
                ->currency('EUR')
                ->catalogId($catalogId)
                ->adjustment('PERCENTAGE_DECREASE', 20),
        );
    }

    /**
     * All price lists in the store (cursor pagination handled).
     */
    public function list(): array
    {
        return $this->shopify->priceLists()->all();
    }

    /**
     * Delete a price list; returns the deleted id in `deletedId`.
     */
    public function delete(string $priceListId): array
    {
        return $this->shopify->priceLists()->delete($priceListId);
    }

    /**
     * Override variants with fixed prices (replaces any existing fixed price for
     * those variants). Variant ids may be numeric or GIDs.
     */
    public function setFixedPrices(string $priceListId, string $variantId): array
    {
        return $this->shopify->priceLists()->setPrices($priceListId, [
            [
                'variantId'      => $variantId,
                'price'          => ['amount' => '15.00', 'currencyCode' => 'EUR'],
                'compareAtPrice' => ['amount' => '19.90', 'currencyCode' => 'EUR'],
            ],
        ]);
    }

    /**
     * Add some fixed prices and remove others in a single call.
     *
     * @param list<array<string,mixed>> $pricesToAdd        PriceListPriceInput entries
     * @param list<string>              $variantIdsToDelete variants to drop back to the adjustment
     */
    public function updatePrices(string $priceListId, array $pricesToAdd, array $variantIdsToDelete): array
    {
        return $this->shopify->priceLists()->updatePrices($priceListId, $pricesToAdd, $variantIdsToDelete);
    }

    /**
     * Read the fixed prices currently set on a price list.
     */
    public function readPrices(string $priceListId): array
    {
        return $this->shopify->priceLists()->prices($priceListId);
    }
}
