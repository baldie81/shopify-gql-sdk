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
 * Real-life usage: the order id comes from Shopify; the write payload comes from
 * elsewhere and is passed as `$data` (defaults provided). Mixed provenance here:
 *   - fulfill() takes a **shipment record** (tracking) from your 3PL/ERP;
 *   - writeErpState() takes the **ERP result** to stamp back onto the order;
 *   - refund() takes a **refund instruction** whose gateway/transaction/line-item
 *     ids reference the order you fetched from Shopify.
 *
 *   foreach ($shopify->orders()->searchAll('fulfillment_status:unfulfilled') as $o) {
 *       $ex->fulfill($o['id'], $threePl->shipmentFor($o['name']));
 *   }
 *
 * Operations shown in this file:
 *   - get($orderId)                — read one order
 *   - search($query)               — search orders (paginated)
 *   - fulfill($orderId, $shipment) — fulfil open fulfillment orders, with tracking
 *   - refund($orderId, $refund)    — partial/whole refund (returns money)
 *   - writeErpState($orderId, $erp)— stamp ERP sync state onto the order (metafields)
 *
 * Model notes:
 *   - Orders are **not created here** — they come from checkout or draft orders.
 *   - `fulfill()` is the "ship the whole order" path; the deeper fulfillment-order
 *     lifecycle lives in FulfillmentOrderExamples.
 *   - `refund()` moves real money only when `transactions` are present (a gateway
 *     REFUND against the parent transaction). On API **2026-04+** the SDK adds the
 *     mandatory `@idempotent` directive automatically.
 *   - Order metafields only show in the admin once an ORDER *definition* exists —
 *     see MetafieldDefinitionExamples.
 *
 * Related: FulfillmentOrderExamples, MetafieldDefinitionExamples, CustomerExamples.
 */
final class OrderExamples
{
    /** A shipment record, as it might arrive from a 3PL/ERP. */
    private const SHIPMENT = [
        'number'  => '1Z999AA10123456784',
        'company' => 'UPS',
        'url'     => 'https://www.ups.com/track?tracknum=1Z999AA10123456784',
    ];

    /** A refund instruction (ids/gateway reference the fetched Shopify order). */
    private const REFUND = [
        'lineItemId'          => 'gid://shopify/LineItem/123',
        'quantity'            => 1,
        'restockType'         => 'NO_RESTOCK',
        'note'                => 'Damaged item',
        'gateway'             => 'Bank Deposit',
        'parentTransactionId' => 'gid://shopify/OrderTransaction/123',
        'amount'              => '40.00',
    ];

    /** The result of pushing the order to the ERP. */
    private const ERP_RESULT = [
        'orderId' => 'SO-1001',
        'synced'  => true,
        'message' => 'Sent to ERP OK',
    ];

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
     * Search orders with Shopify query syntax, walking every page. The default
     * finds paid-but-unfulfilled orders.
     */
    public function search(string $query = 'financial_status:paid AND fulfillment_status:unfulfilled'): array
    {
        return $this->shopify->orders()->searchAll($query);
    }

    /**
     * Fulfil all open fulfillment orders with the shipment's tracking.
     * `notifyCustomer` sends the shipping-confirmation email.
     *
     * @param array<string,mixed> $data a shipment record (defaults to {@see SHIPMENT})
     */
    public function fulfill(string $orderId, array $data = self::SHIPMENT): array
    {
        return $this->shopify->orders()->fulfill(
            $orderId,
            ['number' => $data['number'], 'company' => $data['company'], 'url' => $data['url']],
            notifyCustomer: true,
        );
    }

    /**
     * Apply a refund instruction. `transactions` returns the money via the
     * gateway; omit it to record an accounting-only refund.
     *
     * @param array<string,mixed> $data a refund instruction (defaults to {@see REFUND})
     */
    public function refund(string $orderId, array $data = self::REFUND): array
    {
        return $this->shopify->orders()->refund($orderId, [
            'note'            => $data['note'],
            'notify'          => false,
            'refundLineItems' => [['lineItemId' => $data['lineItemId'], 'quantity' => $data['quantity'], 'restockType' => $data['restockType']]],
            'transactions'    => [[
                'orderId'  => $orderId,
                'gateway'  => $data['gateway'],
                'kind'     => 'REFUND',
                'amount'   => $data['amount'],
                'parentId' => $data['parentTransactionId'],
            ]],
        ]);
    }

    /**
     * Stamp the ERP result back onto the order (define these as ORDER metafield
     * definitions so they show in the admin — see MetafieldDefinitionExamples).
     *
     * @param array<string,mixed> $data the ERP result (defaults to {@see ERP_RESULT})
     */
    public function writeErpState(string $orderId, array $data = self::ERP_RESULT): array
    {
        return $this->shopify->orders()->setMetafields($orderId, [
            Metafield::text('erp', 'order_id', $data['orderId']),
            Metafield::boolean('erp', 'synced', $data['synced']),
            Metafield::text('erp', 'message', $data['message']),
        ]);
    }
}
