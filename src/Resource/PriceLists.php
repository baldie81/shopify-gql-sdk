<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use Baldie81\ShopifyGraphQL\Support\Gid;
use Baldie81\ShopifyGraphQL\Support\PriceListInput;

/**
 * Price lists — contextual / market / B2B pricing. Create/update/delete a price
 * list (relative `parent` adjustment vs. a catalog), then override individual
 * variants with fixed prices.
 *
 * @link https://shopify.dev/docs/api/admin-graphql/latest/objects/PriceList Shopify Admin GraphQL: PriceList
 * @link https://shopify.dev/docs/api/admin-graphql/latest/mutations/priceListCreate Shopify Admin GraphQL: priceListCreate
 * @link https://shopify.dev/docs/api/admin-graphql/latest/mutations/priceListFixedPricesAdd Shopify Admin GraphQL: priceListFixedPricesAdd
 */
final class PriceLists extends AbstractResource
{
    /** priceListFixedPrices* cap per call. */
    private const MAX_PRICES = 250;

    private const FIELDS = 'id name currency parent { adjustment { type value } } catalog { id title }';

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $id): ?array
    {
        $query = 'query ($id: ID!) { priceList(id: $id) { ' . self::FIELDS . ' } }';

        return $this->gql->execute($query, ['id' => Gid::priceList($id)])['priceList'] ?? null;
    }

    /**
     * @return array{nodes:list<array<string,mixed>>,pageInfo:array<string,mixed>}
     */
    public function list(int $first = 50, ?string $after = null): array
    {
        $first = max(1, min($first, self::MAX_PAGE_SIZE));
        $data  = $this->gql->execute(self::listQuery(), ['first' => $first, 'after' => $after]);

        return $data['priceLists'] ?? ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(int $pageSize = self::MAX_PAGE_SIZE): array
    {
        return $this->paginateNodes(self::listQuery(), [], 'priceLists', $pageSize);
    }

    /**
     * @param PriceListInput|array<string,mixed> $input
     * @return array{priceList:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function create(PriceListInput|array $input): array
    {
        $mutation = 'mutation ($input: PriceListCreateInput!) {'
            . ' priceListCreate(input: $input) { priceList { ' . self::FIELDS . ' } userErrors { field code message } } }';

        return $this->gql->execute($mutation, ['input' => $this->payload($input)])['priceListCreate'] ?? ['priceList' => null, 'userErrors' => []];
    }

    /**
     * @param PriceListInput|array<string,mixed> $input
     * @return array{priceList:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function update(string $id, PriceListInput|array $input): array
    {
        $mutation = 'mutation ($id: ID!, $input: PriceListUpdateInput!) {'
            . ' priceListUpdate(id: $id, input: $input) { priceList { ' . self::FIELDS . ' } userErrors { field code message } } }';

        return $this->gql->execute($mutation, ['id' => Gid::priceList($id), 'input' => $this->payload($input)])['priceListUpdate'] ?? ['priceList' => null, 'userErrors' => []];
    }

    /**
     * @return array{deletedId:?string,userErrors:list<array<string,mixed>>}
     */
    public function delete(string $id): array
    {
        $mutation = 'mutation ($id: ID!) { priceListDelete(id: $id) { deletedId userErrors { field code message } } }';

        return $this->gql->execute($mutation, ['id' => Gid::priceList($id)])['priceListDelete'] ?? ['deletedId' => null, 'userErrors' => []];
    }

    /**
     * Set fixed prices on variants (`priceListFixedPricesAdd`, chunked at 250).
     * Each entry: `['variantId' => …, 'price' => ['amount' => '9.99', 'currencyCode' => 'EUR'], 'compareAtPrice' => …?]`.
     * `variantId` is coerced to a ProductVariant GID.
     *
     * @param list<array<string,mixed>> $prices
     * @return array{prices:list<array<string,mixed>>,userErrors:list<array<string,mixed>>}
     */
    public function setPrices(string $id, array $prices): array
    {
        $mutation = 'mutation ($priceListId: ID!, $prices: [PriceListPriceInput!]!) {'
            . ' priceListFixedPricesAdd(priceListId: $priceListId, prices: $prices) {'
            . ' prices { variant { id } price { amount currencyCode } compareAtPrice { amount currencyCode } } userErrors { field code message } } }';

        $merged = ['prices' => [], 'userErrors' => []];
        foreach (array_chunk(array_map([self::class, 'coercePrice'], array_values($prices)), self::MAX_PRICES) as $chunk) {
            $result = $this->gql->execute($mutation, ['priceListId' => Gid::priceList($id), 'prices' => $chunk])['priceListFixedPricesAdd'] ?? [];
            foreach ($result['prices'] ?? [] as $price) {
                $merged['prices'][] = $price;
            }
            foreach ($result['userErrors'] ?? [] as $error) {
                $merged['userErrors'][] = $error;
            }
        }

        return $merged;
    }

    /**
     * Add/update and delete fixed prices in one call (`priceListFixedPricesUpdate`).
     * More than 250 prices or ids are sent in several calls (250 of each per
     * call) and the results merged; `priceList` is the last one returned.
     *
     * @param list<array<string,mixed>> $pricesToAdd
     * @param list<string>              $variantIdsToDelete
     * @return array{priceList:?array<string,mixed>,pricesAdded:list<array<string,mixed>>,deletedFixedPriceVariantIds:list<string>,userErrors:list<array<string,mixed>>}
     */
    public function updatePrices(string $id, array $pricesToAdd, array $variantIdsToDelete = []): array
    {
        $mutation = 'mutation ($priceListId: ID!, $pricesToAdd: [PriceListPriceInput!]!, $variantIdsToDelete: [ID!]!) {'
            . ' priceListFixedPricesUpdate(priceListId: $priceListId, pricesToAdd: $pricesToAdd, variantIdsToDelete: $variantIdsToDelete) {'
            . ' priceList { id } pricesAdded { variant { id } price { amount currencyCode } } deletedFixedPriceVariantIds userErrors { field code message } } }';

        $adds    = array_chunk(array_map([self::class, 'coercePrice'], array_values($pricesToAdd)), self::MAX_PRICES);
        $deletes = array_chunk(array_values(array_map(static fn (int|string $v): string => Gid::variant($v), $variantIdsToDelete)), self::MAX_PRICES);

        $merged = ['priceList' => null, 'pricesAdded' => [], 'deletedFixedPriceVariantIds' => [], 'userErrors' => []];
        for ($i = 0, $calls = max(count($adds), count($deletes), 1); $i < $calls; $i++) {
            $result = $this->gql->execute($mutation, [
                'priceListId'        => Gid::priceList($id),
                'pricesToAdd'        => $adds[$i] ?? [],
                'variantIdsToDelete' => $deletes[$i] ?? [],
            ])['priceListFixedPricesUpdate'] ?? [];

            if (isset($result['priceList'])) {
                $merged['priceList'] = $result['priceList'];
            }
            foreach (['pricesAdded', 'deletedFixedPriceVariantIds', 'userErrors'] as $list) {
                foreach ($result[$list] ?? [] as $entry) {
                    $merged[$list][] = $entry;
                }
            }
        }

        return $merged;
    }

    /**
     * Remove fixed prices for the given variants (`priceListFixedPricesDelete`,
     * chunked at 250).
     *
     * @param list<string> $variantIds
     * @return array{deletedFixedPriceVariantIds:list<string>,userErrors:list<array<string,mixed>>}
     */
    public function deletePrices(string $id, array $variantIds): array
    {
        $mutation = 'mutation ($priceListId: ID!, $variantIds: [ID!]!) {'
            . ' priceListFixedPricesDelete(priceListId: $priceListId, variantIds: $variantIds) {'
            . ' deletedFixedPriceVariantIds userErrors { field code message } } }';

        $gids = array_values(array_map(static fn (int|string $v): string => Gid::variant($v), $variantIds));

        $merged = ['deletedFixedPriceVariantIds' => [], 'userErrors' => []];
        foreach ($gids === [] ? [[]] : array_chunk($gids, self::MAX_PRICES) as $chunk) {
            $result = $this->gql->execute($mutation, ['priceListId' => Gid::priceList($id), 'variantIds' => $chunk])['priceListFixedPricesDelete'] ?? [];
            foreach (['deletedFixedPriceVariantIds', 'userErrors'] as $list) {
                foreach ($result[$list] ?? [] as $entry) {
                    $merged[$list][] = $entry;
                }
            }
        }

        return $merged;
    }

    /**
     * The fixed prices currently on a price list.
     *
     * @return list<array<string,mixed>>
     */
    public function prices(string $id, ?int $first = null): array
    {
        $query = 'query ($id: ID!, $first: Int!, $after: String) { priceList(id: $id) { prices(first: $first, after: $after) {'
            . ' nodes { variant { id } price { amount currencyCode } compareAtPrice { amount currencyCode } }'
            . ' pageInfo { hasNextPage endCursor } } } }';

        return $this->paginateNodes($query, ['id' => Gid::priceList($id)], ['priceList', 'prices'], limit: $first);
    }

    /**
     * @param array<string,mixed> $price
     * @return array<string,mixed>
     */
    private static function coercePrice(array $price): array
    {
        if (isset($price['variantId'])) {
            $price['variantId'] = Gid::variant($price['variantId']);
        }

        return $price;
    }

    private static function listQuery(): string
    {
        return 'query ($first: Int!, $after: String) {'
            . ' priceLists(first: $first, after: $after) {'
            . ' nodes { ' . self::FIELDS . ' } pageInfo { hasNextPage endCursor } } }';
    }
}
