<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use Baldie81\ShopifyGraphQL\Support\Gid;

/**
 * Orders — read/search, fulfillment, and refunds. Orders aren't created here
 * (Shopify creates them via checkout or draft orders); this is the management
 * surface. `setMetafields()` is handy for writing integration state back onto an
 * order — e.g. an ERP id, a sync status flag, and a success/failure message.
 *
 * @link https://shopify.dev/docs/api/admin-graphql/latest/objects/Order Shopify Admin GraphQL: Order
 * @link https://shopify.dev/docs/api/admin-graphql/latest/mutations/fulfillmentCreate Shopify Admin GraphQL: fulfillmentCreate
 * @link https://shopify.dev/docs/api/admin-graphql/latest/mutations/refundCreate Shopify Admin GraphQL: refundCreate
 */
final class Orders extends AbstractResource
{
    private const FIELDS = 'id legacyResourceId name createdAt processedAt displayFinancialStatus displayFulfillmentStatus'
        . ' currentTotalPriceSet { shopMoney { amount currencyCode } } customer { id email }';

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $id, ?string $fields = null): ?array
    {
        $query = 'query ($id: ID!) { order(id: $id) { ' . ($fields ?? self::FIELDS) . ' } }';

        return $this->gql->execute($query, ['id' => Gid::order($id)])['order'] ?? null;
    }

    /**
     * One page of an order search (`$query` is Shopify syntax, e.g.
     * `financial_status:paid` or `fulfillment_status:unfulfilled`).
     *
     * @return array{nodes:list<array<string,mixed>>,pageInfo:array<string,mixed>}
     */
    public function search(string $query = '', int $first = 50, ?string $after = null, ?string $fields = null): array
    {
        $first = max(1, min($first, self::MAX_PAGE_SIZE));
        $data  = $this->gql->execute(self::listQuery($fields), ['first' => $first, 'after' => $after, 'query' => $query]);

        return $data['orders'] ?? ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function searchAll(string $query = '', int $pageSize = self::MAX_PAGE_SIZE, ?string $fields = null): array
    {
        return $this->paginateNodes(self::listQuery($fields), ['query' => $query], 'orders', $pageSize);
    }

    /**
     * Fulfill an order's open fulfillment orders (all line items), optionally with
     * tracking. `$tracking` is `['number' => …, 'company' => …, 'url' => …]`.
     *
     * Only `OPEN` and `IN_PROGRESS` fulfillment orders are fulfilled. `SCHEDULED`
     * ones (later deliveries of a subscription or pre-order) aren't due yet and
     * are left alone, as are those on hold.
     *
     * Shopify only accepts fulfillment orders assigned to the same location in
     * one `fulfillmentCreate`, so an order split across locations is fulfilled
     * with one call per location (each carrying the same tracking). Every
     * fulfillment created is listed under `fulfillments`; `fulfillment` is the
     * first of them. If one location fails the others are still fulfilled — its
     * errors are in `userErrors`.
     *
     * @param array<string,mixed>|null $tracking
     * @return array{fulfillment:?array<string,mixed>,fulfillments:list<array<string,mixed>>,userErrors:list<array<string,mixed>>}
     */
    public function fulfill(string $id, ?array $tracking = null, bool $notifyCustomer = false): array
    {
        $foQuery = 'query ($id: ID!, $first: Int!, $after: String) { order(id: $id) {'
            . ' fulfillmentOrders(first: $first, after: $after) { nodes { id status assignedLocation { location { id } } }'
            . ' pageInfo { hasNextPage endCursor } } } }';
        $nodes   = $this->paginateNodes($foQuery, ['id' => Gid::order($id)], ['order', 'fulfillmentOrders']);

        // One fulfillment per assigned location, in the order the locations appear.
        $byLocation = [];
        foreach ($nodes as $fo) {
            if (in_array($fo['status'] ?? '', ['OPEN', 'IN_PROGRESS'], true)) {
                $location                = (string) ($fo['assignedLocation']['location']['id'] ?? '');
                $byLocation[$location][] = ['fulfillmentOrderId' => $fo['id']];
            }
        }
        if ($byLocation === []) {
            return ['fulfillment' => null, 'fulfillments' => [], 'userErrors' => [['message' => 'No fulfillable fulfillment orders.']]];
        }

        $mutation = 'mutation ($fulfillment: FulfillmentInput!) {'
            . ' fulfillmentCreate(fulfillment: $fulfillment) {'
            . ' fulfillment { id status trackingInfo { number company url } } userErrors { field message } } }';

        $fulfillments = [];
        $userErrors   = [];
        foreach ($byLocation as $lineItemsByFulfillmentOrder) {
            $fulfillment = ['lineItemsByFulfillmentOrder' => $lineItemsByFulfillmentOrder, 'notifyCustomer' => $notifyCustomer];
            if ($tracking !== null) {
                $fulfillment['trackingInfo'] = $tracking;
            }

            $result = $this->gql->execute($mutation, ['fulfillment' => $fulfillment])['fulfillmentCreate'] ?? [];
            if (isset($result['fulfillment'])) {
                $fulfillments[] = $result['fulfillment'];
            }
            foreach ($result['userErrors'] ?? [] as $error) {
                $userErrors[] = $error;
            }
        }

        return ['fulfillment' => $fulfillments[0] ?? null, 'fulfillments' => $fulfillments, 'userErrors' => $userErrors];
    }

    /**
     * Refund an order (`refundCreate`). Pass the RefundInput body (minus
     * `orderId`, which is injected) — e.g. `refundLineItems`, `note`, `notify`,
     * `shipping`, `transactions`.
     *
     * @param array<string,mixed> $input
     * @return array{refund:?array<string,mixed>,order:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function refund(string $id, array $input = [], ?string $idempotencyKey = null): array
    {
        [$idem, $varDef, $directive] = $this->idempotencyParts();

        $mutation = 'mutation ($input: RefundInput!' . $varDef . ') {'
            . ' refundCreate(input: $input)' . $directive . ' {'
            . ' refund { id totalRefundedSet { shopMoney { amount currencyCode } } } order { id } userErrors { field message } } }';

        $variables = ['input' => ['orderId' => Gid::order($id)] + $input];
        if ($idem) {
            $variables['idempotencyKey'] = $idempotencyKey ?? $this->newIdempotencyKey();
        }

        return $this->gql->execute($mutation, $variables)['refundCreate'] ?? ['refund' => null, 'order' => null, 'userErrors' => []];
    }

    /**
     * Set metafields on an order — e.g. ERP integration state (id, status flag,
     * message). Upserted by namespace+key, chunked at 25.
     *
     * @param list<\Baldie81\ShopifyGraphQL\Support\Metafield|array<string,mixed>> $metafields
     * @return array{metafields:list<array<string,mixed>>,userErrors:list<array<string,mixed>>}
     */
    public function setMetafields(string $id, array $metafields): array
    {
        return $this->setMetafieldsFor(Gid::order($id), $metafields);
    }

    private static function listQuery(?string $fields = null): string
    {
        return 'query ($first: Int!, $after: String, $query: String) {'
            . ' orders(first: $first, after: $after, query: $query) {'
            . ' nodes { ' . ($fields ?? self::FIELDS) . ' } pageInfo { hasNextPage endCursor } } }';
    }
}
