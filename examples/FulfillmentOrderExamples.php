<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;

/**
 * Fulfillment-order lifecycle — the layer beneath `orders()->fulfill()`.
 *
 * A FulfillmentOrder is the unit of fulfillment work for some of an order's line
 * items at one location / fulfillment service. This class shows the full
 * lifecycle: reads, holds, moving between locations, close/cancel/reschedule, and
 * the third-party fulfillment-service request flow. Most mutations need the
 * fulfillment order to be in a compatible state (e.g. you can only hold an OPEN
 * one) — they return `userErrors` otherwise.
 */
final class FulfillmentOrderExamples
{
    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * The fulfillment orders for an order — each represents work at one location
     * or fulfillment service. Start here to get ids for the operations below.
     */
    public function forOrder(string $orderId): array
    {
        return $this->shopify->fulfillmentOrders()->forOrder($orderId);
    }

    /**
     * Read one fulfillment order: status, assigned location, holds and line items.
     */
    public function get(string $fulfillmentOrderId): ?array
    {
        return $this->shopify->fulfillmentOrders()->get($fulfillmentOrderId);
    }

    /**
     * Put a fulfillment order on hold (e.g. awaiting stock), then release it.
     * `handle` lets one app place several holds and release them individually.
     */
    public function holdThenRelease(string $fulfillmentOrderId): array
    {
        $hold = $this->shopify->fulfillmentOrders()->hold(
            $fulfillmentOrderId,
            reason: 'INVENTORY_OUT_OF_STOCK',
            reasonNotes: 'Awaiting restock',
            handle: 'erp-sync',
        );

        // Omit holdIds to release every hold on the fulfillment order.
        $release = $this->shopify->fulfillmentOrders()->releaseHold($fulfillmentOrderId);

        return ['hold' => $hold, 'release' => $release];
    }

    /**
     * Move (all unfulfilled items of) a fulfillment order to another location.
     * The location id may be numeric or a GID.
     */
    public function move(string $fulfillmentOrderId, string $newLocationId): array
    {
        return $this->shopify->fulfillmentOrders()->move($fulfillmentOrderId, $newLocationId);
    }

    /**
     * Reschedule a *scheduled* fulfillment order to a future ISO-8601 datetime.
     */
    public function reschedule(string $fulfillmentOrderId): array
    {
        return $this->shopify->fulfillmentOrders()->reschedule($fulfillmentOrderId, '2026-07-01T10:00:00Z');
    }

    /**
     * Cancel a fulfillment order; Shopify may return a `replacementFulfillmentOrder`.
     */
    public function cancel(string $fulfillmentOrderId): array
    {
        return $this->shopify->fulfillmentOrders()->cancel($fulfillmentOrderId);
    }

    /**
     * Mark a fulfillment order incomplete (`close`) — e.g. when remaining items
     * can't be fulfilled — with an optional reason.
     */
    public function closeIncomplete(string $fulfillmentOrderId): array
    {
        return $this->shopify->fulfillmentOrders()->close($fulfillmentOrderId, 'Cannot fulfil remaining items');
    }

    /**
     * Third-party fulfillment service — step 1: request fulfillment from the
     * assigned service (optionally notifying the customer when it ships).
     */
    public function requestFulfillment(string $fulfillmentOrderId): array
    {
        return $this->shopify->fulfillmentOrders()->submitFulfillmentRequest(
            $fulfillmentOrderId,
            message: 'Please ship ASAP',
            notifyCustomer: true,
        );
    }

    /**
     * Third-party fulfillment service — step 2: the service accepts the request
     * (use `rejectFulfillmentRequest` to decline instead).
     */
    public function acceptFulfillment(string $fulfillmentOrderId): array
    {
        return $this->shopify->fulfillmentOrders()->acceptFulfillmentRequest($fulfillmentOrderId, 'Accepted');
    }
}
