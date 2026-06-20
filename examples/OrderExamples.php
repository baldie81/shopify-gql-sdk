<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;
use Baldie81\ShopifyGraphQL\Support\Metafield;

/**
 * ORDERS — the management surface for orders. Reached via `$shopify->orders()`.
 *
 *   $ex = new OrderExamples(ExampleClient::create());
 *
 * Operations shown in this file:
 *   - get()               — read one order (totals, statuses, customer)
 *   - findPaidUnfulfilled()— search with Shopify order query syntax (paginated)
 *   - fulfill()           — fulfil all open fulfillment orders, with tracking
 *   - refund()            — partial/whole refund (returns money via `transactions`)
 *   - writeErpState()     — write ERP sync state back onto the order via metafields
 *
 * Important model notes:
 *   - Orders are **not created here** — they come from checkout, or you create a
 *     DRAFT order and complete it. This class is for managing existing orders.
 *   - `fulfill()` is the simple "ship the whole order" path. The deeper
 *     fulfillment-order lifecycle (holds, moves, partial/line-item fulfilment,
 *     3PL request flow) lives in FulfillmentOrderExamples.
 *   - A `refund()` moves real money only when you pass `transactions` (a gateway
 *     REFUND against the order's parent transaction); without them it records an
 *     accounting-only refund. On API **2026-04+** the SDK adds the mandatory
 *     `@idempotent` directive automatically.
 *   - Order metafields are API-visible immediately, but only show in the **admin**
 *     once an ORDER metafield *definition* exists — see MetafieldDefinitionExamples.
 *
 * Related: FulfillmentOrderExamples, MetafieldDefinitionExamples, CustomerExamples.
 */
final class OrderExamples
{
    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * Read one order — name, totals, financial + fulfillment status, customer
     * (numeric id or GID). Returns null if the order doesn't exist.
     */
    public function get(string $orderId): ?array
    {
        return $this->shopify->orders()->get($orderId);
    }

    /**
     * Search with Shopify order query syntax.
     */
    public function findPaidUnfulfilled(): array
    {
        return $this->shopify->orders()->searchAll('financial_status:paid AND fulfillment_status:unfulfilled');
    }

    /**
     * Fulfill all open fulfillment orders, with tracking. `notifyCustomer` sends
     * the shipping-confirmation email.
     */
    public function fulfill(string $orderId): array
    {
        return $this->shopify->orders()->fulfill(
            $orderId,
            ['number' => '1Z999AA10123456784', 'company' => 'UPS', 'url' => 'https://www.ups.com/track?tracknum=1Z999AA10123456784'],
            notifyCustomer: true,
        );
    }

    /**
     * Partial refund of one line-item unit. `transactions` actually returns the
     * money via the gateway; omit it to record an accounting-only refund.
     * On 2026-04+ the SDK adds the mandatory `@idempotent` directive for you.
     */
    public function refund(string $orderId, string $lineItemId, string $gateway, string $parentTransactionId, string $amount): array
    {
        return $this->shopify->orders()->refund($orderId, [
            'note'            => 'Damaged item',
            'notify'          => false,
            'refundLineItems' => [['lineItemId' => $lineItemId, 'quantity' => 1, 'restockType' => 'NO_RESTOCK']],
            'transactions'    => [[
                'orderId'  => $orderId,
                'gateway'  => $gateway,
                'kind'     => 'REFUND',
                'amount'   => $amount,
                'parentId' => $parentTransactionId,
            ]],
        ]);
    }

    /**
     * Write ERP sync state onto the order (define these as ORDER metafield
     * definitions so they show in the admin — see MetafieldDefinitionExamples).
     */
    public function writeErpState(string $orderId, string $erpOrderId): array
    {
        return $this->shopify->orders()->setMetafields($orderId, [
            Metafield::text('erp', 'order_id', $erpOrderId),
            Metafield::boolean('erp', 'synced', true),
            Metafield::text('erp', 'message', 'Sent to ERP OK'),
        ]);
    }
}
