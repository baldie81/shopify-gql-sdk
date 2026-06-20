<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;
use Baldie81\ShopifyGraphQL\Support\PriceListInput;

/**
 * PRICE LISTS — contextual / market / B2B pricing. Reached via
 * `$shopify->priceLists()`.
 *
 *   $ex = new PriceListExamples(ExampleClient::create());
 *
 * Model: a price list is tied to a catalog's context (market / company / app) and
 * carries a relative `parent` adjustment (e.g. −20% vs. the base price). You can
 * then override individual variants with explicit FIXED prices.
 *
 * Operations shown in this file:
 *   - create()         — priceListCreate (currency + catalog + adjustment)
 *   - list()           — all price lists (paginated)
 *   - delete()         — priceListDelete (returns deletedId)
 *   - setFixedPrices() — priceListFixedPricesAdd (override variants)
 *   - updatePrices()   — add + delete fixed prices in one call
 *   - readPrices()     — the fixed prices on a list
 *
 * Notes:
 *   - Needs a catalog/markets context — i.e. a Markets or B2B setup on the store.
 *   - Fixed-price writes **auto-chunk at 250** and coerce variant ids to GIDs.
 *   - `setFixedPrices` replaces any existing fixed price for those variants; the
 *     price currency must match the list's currency.
 *
 * Related: VariantExamples (base prices), ProductExamples.
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
