<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use Baldie81\ShopifyGraphQL\Support\DraftOrderInput;
use Baldie81\ShopifyGraphQL\Support\Gid;

/**
 * Draft orders — the API path for *creating* orders (manual/phone/B2B). Build a
 * draft, then `complete()` it to turn it into a real Order.
 *
 * Reads accept an optional `$fields` selection override (caller-supplied
 * selection set); pass null for the lean default.
 *
 * @link https://shopify.dev/docs/api/admin-graphql/latest/objects/DraftOrder Shopify Admin GraphQL: DraftOrder
 * @link https://shopify.dev/docs/api/admin-graphql/latest/mutations/draftOrderCreate Shopify Admin GraphQL: draftOrderCreate
 * @link https://shopify.dev/docs/api/admin-graphql/latest/mutations/draftOrderComplete Shopify Admin GraphQL: draftOrderComplete
 */
final class DraftOrders extends AbstractResource
{
    private const FIELDS = 'id name status invoiceUrl totalPriceSet { shopMoney { amount currencyCode } } createdAt customer { id email }';

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $id, ?string $fields = null): ?array
    {
        $query = 'query ($id: ID!) { draftOrder(id: $id) { ' . ($fields ?? self::FIELDS) . ' } }';

        return $this->gql->execute($query, ['id' => Gid::draftOrder($id)])['draftOrder'] ?? null;
    }

    /**
     * @return array{nodes:list<array<string,mixed>>,pageInfo:array<string,mixed>}
     */
    public function search(string $query = '', int $first = 50, ?string $after = null, ?string $fields = null): array
    {
        $first = max(1, min($first, self::MAX_PAGE_SIZE));
        $data  = $this->gql->execute(self::listQuery($fields), ['first' => $first, 'after' => $after, 'query' => $query]);

        return $data['draftOrders'] ?? ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function searchAll(string $query = '', int $pageSize = self::MAX_PAGE_SIZE, ?string $fields = null): array
    {
        return $this->paginateNodes(self::listQuery($fields), ['query' => $query], 'draftOrders', $pageSize);
    }

    /**
     * Create a draft order. Accepts a {@see DraftOrderInput} or raw array.
     *
     * @param DraftOrderInput|array<string,mixed> $input
     * @return array{draftOrder:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function create(DraftOrderInput|array $input): array
    {
        $mutation = 'mutation ($input: DraftOrderInput!) {'
            . ' draftOrderCreate(input: $input) { draftOrder { ' . self::FIELDS . ' } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['input' => $this->payload($input)])['draftOrderCreate'] ?? ['draftOrder' => null, 'userErrors' => []];
    }

    /**
     * @param DraftOrderInput|array<string,mixed> $input
     * @return array{draftOrder:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function update(string $id, DraftOrderInput|array $input): array
    {
        $mutation = 'mutation ($id: ID!, $input: DraftOrderInput!) {'
            . ' draftOrderUpdate(id: $id, input: $input) { draftOrder { ' . self::FIELDS . ' } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['id' => Gid::draftOrder($id), 'input' => $this->payload($input)])['draftOrderUpdate'] ?? ['draftOrder' => null, 'userErrors' => []];
    }

    /**
     * @return array{deletedId:?string,userErrors:list<array<string,mixed>>}
     */
    public function delete(string $id): array
    {
        $mutation = 'mutation ($input: DraftOrderDeleteInput!) {'
            . ' draftOrderDelete(input: $input) { deletedId userErrors { field message } } }';

        return $this->gql->execute($mutation, ['input' => ['id' => Gid::draftOrder($id)]])['draftOrderDelete'] ?? ['deletedId' => null, 'userErrors' => []];
    }

    /**
     * Complete a draft order, turning it into a real Order. Pass
     * `paymentPending: true` to mark the order as payment-pending; otherwise it's
     * marked paid. The completed draft order carries the new `order { id }`.
     *
     * @return array{draftOrder:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function complete(string $id, bool $paymentPending = false): array
    {
        $mutation = 'mutation ($id: ID!, $paymentPending: Boolean) {'
            . ' draftOrderComplete(id: $id, paymentPending: $paymentPending) {'
            . ' draftOrder { id name status order { id legacyResourceId name } } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['id' => Gid::draftOrder($id), 'paymentPending' => $paymentPending])['draftOrderComplete'] ?? ['draftOrder' => null, 'userErrors' => []];
    }

    /**
     * Calculate totals for a draft order without saving it.
     *
     * @param DraftOrderInput|array<string,mixed> $input
     * @return array{calculatedDraftOrder:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function calculate(DraftOrderInput|array $input): array
    {
        $mutation = 'mutation ($input: DraftOrderInput!) {'
            . ' draftOrderCalculate(input: $input) {'
            . ' calculatedDraftOrder { totalPriceSet { shopMoney { amount currencyCode } } subtotalPriceSet { shopMoney { amount } } } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['input' => $this->payload($input)])['draftOrderCalculate'] ?? ['calculatedDraftOrder' => null, 'userErrors' => []];
    }

    /**
     * @param list<\Baldie81\ShopifyGraphQL\Support\Metafield|array<string,mixed>> $metafields
     * @return array{metafields:list<array<string,mixed>>,userErrors:list<array<string,mixed>>}
     */
    public function setMetafields(string $id, array $metafields): array
    {
        return $this->setMetafieldsFor(Gid::draftOrder($id), $metafields);
    }

    private static function listQuery(?string $fields): string
    {
        return 'query ($first: Int!, $after: String, $query: String) {'
            . ' draftOrders(first: $first, after: $after, query: $query) {'
            . ' nodes { ' . ($fields ?? self::FIELDS) . ' } pageInfo { hasNextPage endCursor } } }';
    }
}
