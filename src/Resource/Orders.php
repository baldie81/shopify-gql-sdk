<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use Baldie81\ShopifyGraphQL\Support\Gid;

/**
 * Orders — read/search, fulfillment, and refunds. Orders aren't created here
 * (Shopify creates them via checkout or draft orders); this is the management
 * surface. `setMetafields()` is handy for writing integration state back onto an
 * order — e.g. an ERP id, a sync status flag, and a success/failure message.
 */
final class Orders extends AbstractResource
{
    private const FIELDS = 'id legacyResourceId name createdAt processedAt displayFinancialStatus displayFulfillmentStatus'
        . ' currentTotalPriceSet { shopMoney { amount currencyCode } } customer { id email }';

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $id): ?array
    {
        $query = 'query ($id: ID!) { order(id: $id) { ' . self::FIELDS . ' } }';

        return $this->gql->execute($query, ['id' => Gid::order($id)])['order'] ?? null;
    }

    /**
     * One page of an order search (`$query` is Shopify syntax, e.g.
     * `financial_status:paid` or `fulfillment_status:unfulfilled`).
     *
     * @return array{nodes:list<array<string,mixed>>,pageInfo:array<string,mixed>}
     */
    public function search(string $query = '', int $first = 50, ?string $after = null): array
    {
        $first = max(1, min($first, self::MAX_PAGE_SIZE));
        $data  = $this->gql->execute(self::listQuery(), ['first' => $first, 'after' => $after, 'query' => $query]);

        return $data['orders'] ?? ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function searchAll(string $query = '', int $pageSize = self::MAX_PAGE_SIZE): array
    {
        return $this->paginateNodes(self::listQuery(), ['query' => $query], 'orders', $pageSize);
    }

    /**
     * Fulfill an order's open fulfillment orders (all line items), optionally with
     * tracking. `$tracking` is `['number' => …, 'company' => …, 'url' => …]`.
     *
     * @param array<string,mixed>|null $tracking
     * @return array{fulfillment:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function fulfill(string $id, ?array $tracking = null, bool $notifyCustomer = false): array
    {
        $foQuery = 'query ($id: ID!) { order(id: $id) { fulfillmentOrders(first: 50) { nodes { id status } } } }';
        $nodes   = $this->gql->execute($foQuery, ['id' => Gid::order($id)])['order']['fulfillmentOrders']['nodes'] ?? [];

        $lineItemsByFulfillmentOrder = [];
        foreach ($nodes as $fo) {
            if (in_array($fo['status'] ?? '', ['OPEN', 'IN_PROGRESS', 'SCHEDULED'], true)) {
                $lineItemsByFulfillmentOrder[] = ['fulfillmentOrderId' => $fo['id']];
            }
        }
        if ($lineItemsByFulfillmentOrder === []) {
            return ['fulfillment' => null, 'userErrors' => [['message' => 'No fulfillable fulfillment orders.']]];
        }

        $fulfillment = ['lineItemsByFulfillmentOrder' => $lineItemsByFulfillmentOrder, 'notifyCustomer' => $notifyCustomer];
        if ($tracking !== null) {
            $fulfillment['trackingInfo'] = $tracking;
        }

        $mutation = 'mutation ($fulfillment: FulfillmentInput!) {'
            . ' fulfillmentCreate(fulfillment: $fulfillment) {'
            . ' fulfillment { id status trackingInfo { number company url } } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['fulfillment' => $fulfillment])['fulfillmentCreate'] ?? ['fulfillment' => null, 'userErrors' => []];
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

    private static function listQuery(): string
    {
        return 'query ($first: Int!, $after: String, $query: String) {'
            . ' orders(first: $first, after: $after, query: $query) {'
            . ' nodes { ' . self::FIELDS . ' } pageInfo { hasNextPage endCursor } } }';
    }
}
