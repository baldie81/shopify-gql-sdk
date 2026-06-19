<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use Baldie81\ShopifyGraphQL\Support\Gid;

/**
 * Fulfillment-order lifecycle management — the layer beneath {@see Orders::fulfill()}.
 *
 * A FulfillmentOrder is the unit of fulfillment work for some of an order's line
 * items at one location / fulfillment service. This resource covers the full
 * lifecycle: holds, moves, close/cancel/open/reschedule, merge/split, and the
 * third-party fulfillment-service request flow (submit/accept/reject for both
 * fulfillment and cancellation).
 */
final class FulfillmentOrders extends AbstractResource
{
    private const FIELDS = 'id status requestStatus';

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $id): ?array
    {
        $query = 'query ($id: ID!) { fulfillmentOrder(id: $id) { ' . self::FIELDS
            . ' assignedLocation { location { id name } }'
            . ' fulfillmentHolds { id reason reasonNotes }'
            . ' lineItems(first: 50) { nodes { id remainingQuantity totalQuantity lineItem { id title sku } } } } }';

        return $this->gql->execute($query, ['id' => Gid::fulfillmentOrder($id)])['fulfillmentOrder'] ?? null;
    }

    /**
     * The fulfillment orders belonging to an order.
     *
     * @return list<array<string,mixed>>
     */
    public function forOrder(string $orderId, int $first = self::MAX_PAGE_SIZE): array
    {
        $first = max(1, min($first, self::MAX_PAGE_SIZE));
        $query = 'query ($id: ID!, $first: Int!) { order(id: $id) { fulfillmentOrders(first: $first) {'
            . ' nodes { ' . self::FIELDS . ' assignedLocation { location { id name } } } } } }';

        return $this->gql->execute($query, ['id' => Gid::order($orderId), 'first' => $first])['order']['fulfillmentOrders']['nodes'] ?? [];
    }

    /**
     * Place a hold. `$reason` is a `FulfillmentHoldReason` (e.g.
     * "INVENTORY_OUT_OF_STOCK", "AWAITING_PAYMENT", "OTHER"). `$handle` lets an
     * app place multiple holds (release them by id later).
     *
     * @param list<array<string,mixed>>|null $lineItems optional partial-hold line items
     * @return array{fulfillmentOrder:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function hold(string $id, string $reason, ?string $reasonNotes = null, ?string $handle = null, bool $notifyMerchant = false, ?array $lineItems = null): array
    {
        $hold = ['reason' => $reason, 'notifyMerchant' => $notifyMerchant];
        if ($reasonNotes !== null) {
            $hold['reasonNotes'] = $reasonNotes;
        }
        if ($handle !== null) {
            $hold['handle'] = $handle;
        }
        if ($lineItems !== null) {
            $hold['fulfillmentOrderLineItems'] = $lineItems;
        }

        $mutation = 'mutation ($id: ID!, $hold: FulfillmentOrderHoldInput!) {'
            . ' fulfillmentOrderHold(id: $id, fulfillmentHold: $hold) { fulfillmentOrder { ' . self::FIELDS . ' } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['id' => Gid::fulfillmentOrder($id), 'hold' => $hold])['fulfillmentOrderHold'] ?? ['fulfillmentOrder' => null, 'userErrors' => []];
    }

    /**
     * Release holds. Omit `$holdIds` to release all holds on the fulfillment order.
     *
     * @param list<string>|null $holdIds
     * @return array{fulfillmentOrder:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function releaseHold(string $id, ?array $holdIds = null, ?string $externalId = null): array
    {
        $mutation = 'mutation ($id: ID!, $holdIds: [ID!], $externalId: String) {'
            . ' fulfillmentOrderReleaseHold(id: $id, holdIds: $holdIds, externalId: $externalId) { fulfillmentOrder { ' . self::FIELDS . ' } userErrors { field message } } }';

        return $this->gql->execute($mutation, [
            'id'         => Gid::fulfillmentOrder($id),
            'holdIds'    => $holdIds,
            'externalId' => $externalId,
        ])['fulfillmentOrderReleaseHold'] ?? ['fulfillmentOrder' => null, 'userErrors' => []];
    }

    /**
     * Move the fulfillment order (or specific line items) to another location.
     *
     * @param list<array<string,mixed>>|null $lineItems leave null to move all unfulfilled items
     * @return array{movedFulfillmentOrder:?array<string,mixed>,originalFulfillmentOrder:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function move(string $id, string $newLocationId, ?array $lineItems = null): array
    {
        $mutation = 'mutation ($id: ID!, $newLocationId: ID!, $lineItems: [FulfillmentOrderLineItemInput!]) {'
            . ' fulfillmentOrderMove(id: $id, newLocationId: $newLocationId, fulfillmentOrderLineItems: $lineItems) {'
            . ' movedFulfillmentOrder { ' . self::FIELDS . ' } originalFulfillmentOrder { ' . self::FIELDS . ' } userErrors { field message } } }';

        return $this->gql->execute($mutation, [
            'id'            => Gid::fulfillmentOrder($id),
            'newLocationId' => Gid::isGid($newLocationId) ? $newLocationId : Gid::of('Location', $newLocationId),
            'lineItems'     => $lineItems,
        ])['fulfillmentOrderMove'] ?? ['movedFulfillmentOrder' => null, 'originalFulfillmentOrder' => null, 'userErrors' => []];
    }

    /**
     * Mark the fulfillment order incomplete (`fulfillmentOrderClose`).
     *
     * @return array{fulfillmentOrder:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function close(string $id, ?string $message = null): array
    {
        return $this->withMessage('fulfillmentOrderClose', $id, $message);
    }

    /**
     * @return array{fulfillmentOrder:?array<string,mixed>,replacementFulfillmentOrder:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function cancel(string $id): array
    {
        $mutation = 'mutation ($id: ID!) { fulfillmentOrderCancel(id: $id) {'
            . ' fulfillmentOrder { ' . self::FIELDS . ' } replacementFulfillmentOrder { id } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['id' => Gid::fulfillmentOrder($id)])['fulfillmentOrderCancel'] ?? ['fulfillmentOrder' => null, 'replacementFulfillmentOrder' => null, 'userErrors' => []];
    }

    /**
     * @return array{fulfillmentOrder:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function open(string $id): array
    {
        $mutation = 'mutation ($id: ID!) { fulfillmentOrderOpen(id: $id) { fulfillmentOrder { ' . self::FIELDS . ' } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['id' => Gid::fulfillmentOrder($id)])['fulfillmentOrderOpen'] ?? ['fulfillmentOrder' => null, 'userErrors' => []];
    }

    /**
     * Reschedule a scheduled fulfillment order. `$fulfillAt` is an ISO-8601 datetime.
     *
     * @return array{fulfillmentOrder:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function reschedule(string $id, string $fulfillAt): array
    {
        $mutation = 'mutation ($id: ID!, $fulfillAt: DateTime!) {'
            . ' fulfillmentOrderReschedule(id: $id, fulfillAt: $fulfillAt) { fulfillmentOrder { ' . self::FIELDS . ' } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['id' => Gid::fulfillmentOrder($id), 'fulfillAt' => $fulfillAt])['fulfillmentOrderReschedule'] ?? ['fulfillmentOrder' => null, 'userErrors' => []];
    }

    /**
     * Merge fulfillment orders. Pass the raw `FulfillmentOrderMergeInput` list,
     * each `['mergeIntents' => [['fulfillmentOrderId' => …, 'fulfillmentOrderLineItems' => [...]]]]`.
     *
     * @param list<array<string,mixed>> $mergeInputs
     * @return array{fulfillmentOrderMerges:list<array<string,mixed>>,userErrors:list<array<string,mixed>>}
     */
    public function merge(array $mergeInputs): array
    {
        $mutation = 'mutation ($inputs: [FulfillmentOrderMergeInput!]!) {'
            . ' fulfillmentOrderMerge(fulfillmentOrderMergeInputs: $inputs) {'
            . ' fulfillmentOrderMerges { fulfillmentOrder { id } } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['inputs' => array_values($mergeInputs)])['fulfillmentOrderMerge'] ?? ['fulfillmentOrderMerges' => [], 'userErrors' => []];
    }

    /**
     * Split fulfillment orders. Pass the raw `FulfillmentOrderSplitInput` list,
     * each `['fulfillmentOrderId' => …, 'fulfillmentOrderLineItems' => [...]]`.
     *
     * @param list<array<string,mixed>> $splitInputs
     * @return array{fulfillmentOrderSplits:list<array<string,mixed>>,userErrors:list<array<string,mixed>>}
     */
    public function split(array $splitInputs): array
    {
        $mutation = 'mutation ($inputs: [FulfillmentOrderSplitInput!]!) {'
            . ' fulfillmentOrderSplit(fulfillmentOrderSplits: $inputs) {'
            . ' fulfillmentOrderSplits { fulfillmentOrder { id } } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['inputs' => array_values($splitInputs)])['fulfillmentOrderSplit'] ?? ['fulfillmentOrderSplits' => [], 'userErrors' => []];
    }

    // --- Third-party fulfillment-service request flow ---

    /**
     * Request fulfillment from the assigned fulfillment service.
     *
     * @param list<array<string,mixed>>|null $lineItems
     * @return array{originalFulfillmentOrder:?array<string,mixed>,submittedFulfillmentOrder:?array<string,mixed>,unsubmittedFulfillmentOrder:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function submitFulfillmentRequest(string $id, ?string $message = null, ?array $lineItems = null, bool $notifyCustomer = false): array
    {
        $mutation = 'mutation ($id: ID!, $message: String, $lineItems: [FulfillmentOrderLineItemInput!], $notifyCustomer: Boolean) {'
            . ' fulfillmentOrderSubmitFulfillmentRequest(id: $id, message: $message, fulfillmentOrderLineItems: $lineItems, notifyCustomer: $notifyCustomer) {'
            . ' originalFulfillmentOrder { ' . self::FIELDS . ' } submittedFulfillmentOrder { id } unsubmittedFulfillmentOrder { id } userErrors { field message } } }';

        return $this->gql->execute($mutation, [
            'id'             => Gid::fulfillmentOrder($id),
            'message'        => $message,
            'lineItems'      => $lineItems,
            'notifyCustomer' => $notifyCustomer,
        ])['fulfillmentOrderSubmitFulfillmentRequest'] ?? ['originalFulfillmentOrder' => null, 'submittedFulfillmentOrder' => null, 'unsubmittedFulfillmentOrder' => null, 'userErrors' => []];
    }

    /** @return array{fulfillmentOrder:?array<string,mixed>,userErrors:list<array<string,mixed>>} */
    public function acceptFulfillmentRequest(string $id, ?string $message = null): array
    {
        return $this->withMessage('fulfillmentOrderAcceptFulfillmentRequest', $id, $message);
    }

    /** @return array{fulfillmentOrder:?array<string,mixed>,userErrors:list<array<string,mixed>>} */
    public function rejectFulfillmentRequest(string $id, ?string $message = null): array
    {
        return $this->withMessage('fulfillmentOrderRejectFulfillmentRequest', $id, $message);
    }

    /** @return array{fulfillmentOrder:?array<string,mixed>,userErrors:list<array<string,mixed>>} */
    public function submitCancellationRequest(string $id, ?string $message = null): array
    {
        return $this->withMessage('fulfillmentOrderSubmitCancellationRequest', $id, $message);
    }

    /** @return array{fulfillmentOrder:?array<string,mixed>,userErrors:list<array<string,mixed>>} */
    public function acceptCancellationRequest(string $id, ?string $message = null): array
    {
        return $this->withMessage('fulfillmentOrderAcceptCancellationRequest', $id, $message);
    }

    /** @return array{fulfillmentOrder:?array<string,mixed>,userErrors:list<array<string,mixed>>} */
    public function rejectCancellationRequest(string $id, ?string $message = null): array
    {
        return $this->withMessage('fulfillmentOrderRejectCancellationRequest', $id, $message);
    }

    /**
     * Shared shape for `(id, message) -> { fulfillmentOrder, userErrors }` mutations.
     *
     * @return array{fulfillmentOrder:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    private function withMessage(string $field, string $id, ?string $message): array
    {
        $mutation = 'mutation ($id: ID!, $message: String) {'
            . ' ' . $field . '(id: $id, message: $message) { fulfillmentOrder { ' . self::FIELDS . ' } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['id' => Gid::fulfillmentOrder($id), 'message' => $message])[$field] ?? ['fulfillmentOrder' => null, 'userErrors' => []];
    }
}
