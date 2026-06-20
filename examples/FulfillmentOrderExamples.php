<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;

/**
 * FULFILLMENT ORDERS — the fulfillment-order lifecycle beneath
 * `orders()->fulfill()`. Reached via `$shopify->fulfillmentOrders()`.
 *
 *   $ex = new FulfillmentOrderExamples(ExampleClient::create());
 *
 * Real-life usage: fulfillment-order ids come from Shopify (`forOrder()`); the
 * operation details (hold reason, reschedule date, …) are passed as `$data`
 * (defaults provided). Typical loop:
 *
 *   foreach ($shopify->fulfillmentOrders()->forOrder($orderId) as $fo) {
 *       $ex->holdThenRelease($fo['id'], $erp->holdInstruction($fo['id']));
 *   }
 *
 * Operations shown in this file:
 *   - forOrder($orderId) / get($foId)        — list an order's FOs / read one
 *   - holdThenRelease($foId, $data)          — hold then releaseHold
 *   - move($foId, $newLocationId)            — reassign to another location
 *   - reschedule($foId, $data)               — change a SCHEDULED FO's fulfil-at
 *   - cancel($foId) / closeIncomplete($foId, $data)
 *   - requestFulfillment($foId, $data) / acceptFulfillment($foId) — 3PL flow
 *
 * Also on the resource (not shown here): open(), merge(), split(),
 * rejectFulfillmentRequest(), and the cancellation-request trio.
 *
 * Notes:
 *   - Most mutations require a compatible state (e.g. you can only hold an OPEN
 *     one); incompatible calls come back in `userErrors`, not as exceptions.
 *   - Use this for holds / multi-location / partial / 3PL fulfilment; for the
 *     plain "ship the whole order" case use OrderExamples::fulfill().
 *
 * Related: OrderExamples.
 */
final class FulfillmentOrderExamples
{
    /** Operation details, as they might come from your ERP/ops system. */
    private const SAMPLE = [
        'holdReason'   => 'INVENTORY_OUT_OF_STOCK',
        'reasonNotes'  => 'Awaiting restock',
        'holdHandle'   => 'erp-sync',
        'fulfillAt'    => '2026-07-01T10:00:00Z',
        'closeMessage' => 'Cannot fulfil remaining items',
        'requestNote'  => 'Please ship ASAP',
    ];

    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * The fulfillment orders for an order — each is work at one location/service.
     * Start here to get the ids the operations below need.
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
     * Place a hold from the instruction, then release it. `handle` lets one app
     * place several holds and release them individually.
     *
     * @param array<string,mixed> $data a hold instruction (defaults to {@see SAMPLE})
     */
    public function holdThenRelease(string $fulfillmentOrderId, array $data = self::SAMPLE): array
    {
        $hold = $this->shopify->fulfillmentOrders()->hold(
            $fulfillmentOrderId,
            reason: $data['holdReason'],
            reasonNotes: $data['reasonNotes'],
            handle: $data['holdHandle'],
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
     * Reschedule a *scheduled* fulfillment order to the instruction's datetime.
     *
     * @param array<string,mixed> $data uses its `fulfillAt` (ISO-8601)
     */
    public function reschedule(string $fulfillmentOrderId, array $data = self::SAMPLE): array
    {
        return $this->shopify->fulfillmentOrders()->reschedule($fulfillmentOrderId, $data['fulfillAt']);
    }

    /**
     * Cancel a fulfillment order; Shopify may return a `replacementFulfillmentOrder`.
     */
    public function cancel(string $fulfillmentOrderId): array
    {
        return $this->shopify->fulfillmentOrders()->cancel($fulfillmentOrderId);
    }

    /**
     * Mark a fulfillment order incomplete (`close`) with the instruction's reason.
     *
     * @param array<string,mixed> $data uses its `closeMessage`
     */
    public function closeIncomplete(string $fulfillmentOrderId, array $data = self::SAMPLE): array
    {
        return $this->shopify->fulfillmentOrders()->close($fulfillmentOrderId, $data['closeMessage']);
    }

    /**
     * Third-party fulfillment service — step 1: request fulfillment from the
     * assigned service (optionally notifying the customer when it ships).
     *
     * @param array<string,mixed> $data uses its `requestNote`
     */
    public function requestFulfillment(string $fulfillmentOrderId, array $data = self::SAMPLE): array
    {
        return $this->shopify->fulfillmentOrders()->submitFulfillmentRequest(
            $fulfillmentOrderId,
            message: $data['requestNote'],
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
