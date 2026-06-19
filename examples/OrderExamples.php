<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;
use Baldie81\ShopifyGraphQL\Support\Metafield;

/**
 * Orders — read/search, fulfillment, refunds, and writing integration state back
 * onto the order with metafields. Orders aren't *created* here (that's checkout
 * or draft orders); this is the management surface.
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
