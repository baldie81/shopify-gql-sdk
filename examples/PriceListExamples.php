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
 * Real-life usage: a write method takes **one pricing record** as `$data` (an ERP
 * price-book entry, defaulting to {@see SAMPLE}). Catalog / price-list / variant
 * ids come from Shopify (params). Typical loop:
 *
 *   foreach ($erp->priceBook() as $row) {
 *       $ex->setFixedPrices($priceListId, $row['variantId'], $row);
 *   }
 *
 * Model: a price list is tied to a catalog's context (market / company / app) and
 * carries a relative `parent` adjustment (e.g. −20%); you can then override
 * individual variants with explicit FIXED prices.
 *
 * Operations shown in this file:
 *   - create($catalogId, $data)              — priceListCreate (currency + adjustment)
 *   - list()                                 — all price lists (paginated)
 *   - delete($priceListId)                   — priceListDelete (deletedId)
 *   - setFixedPrices($priceListId,$variantId,$data) — override a variant's price
 *   - updatePrices($priceListId, $add, $del) — add + delete fixed prices in one call
 *   - readPrices($priceListId)               — the fixed prices on a list
 *
 * Notes:
 *   - Needs a catalog/markets context — a Markets or B2B setup on the store.
 *   - Fixed-price writes **auto-chunk at 250** and coerce variant ids to GIDs.
 *   - `setFixedPrices` replaces any existing fixed price for those variants; the
 *     currency must match the list's currency.
 *
 * Related: VariantExamples (base prices), ProductExamples.
 */
final class PriceListExamples
{
    /** One pricing record, shaped like an ERP price-book entry. */
    private const SAMPLE = [
        'name'           => 'Wholesale EUR',
        'currency'       => 'EUR',
        'discountPct'    => 20,
        'price'          => '15.00',
        'compareAtPrice' => '19.90',
    ];

    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * Create a price list from the record, attached to a catalog (markets/B2B id).
     *
     * @param array<string,mixed> $data a pricing record (defaults to {@see SAMPLE})
     */
    public function create(string $catalogId, array $data = self::SAMPLE): array
    {
        return $this->shopify->priceLists()->create(
            PriceListInput::make($data['name'])
                ->currency($data['currency'])
                ->catalogId($catalogId)
                ->adjustment('PERCENTAGE_DECREASE', (float) $data['discountPct']),
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
     * Override a variant with the record's fixed price (replaces any existing fixed
     * price). Variant ids may be numeric or GIDs.
     *
     * @param array<string,mixed> $data a pricing record (uses `price`/`compareAtPrice`/`currency`)
     */
    public function setFixedPrices(string $priceListId, string $variantId, array $data = self::SAMPLE): array
    {
        return $this->shopify->priceLists()->setPrices($priceListId, [
            [
                'variantId'      => $variantId,
                'price'          => ['amount' => $data['price'], 'currencyCode' => $data['currency']],
                'compareAtPrice' => ['amount' => $data['compareAtPrice'], 'currencyCode' => $data['currency']],
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
