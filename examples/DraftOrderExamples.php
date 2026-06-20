<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;
use Baldie81\ShopifyGraphQL\Support\DraftOrderInput;

/**
 * DRAFT ORDERS — the API path for *creating* orders (manual / phone / B2B).
 * Reached via `$shopify->draftOrders()`.
 *
 *   $ex = new DraftOrderExamples(ExampleClient::create());
 *
 * Real-life usage: a write method takes **one manual-order record** as `$data`
 * (e.g. a phone order from your back office, defaulting to {@see SAMPLE}). The
 * customer/variant ids reference Shopify objects. Flow: build a draft, then
 * `complete()` it to turn it into a real Order:
 *
 *   $created = $ex->create($record);
 *   $ex->complete($created['draftOrder']['id']);   // -> draftOrder.order.id
 *
 * Operations shown in this file:
 *   - create($data)              — draftOrderCreate (catalog + custom line items)
 *   - get($id, $fields)          — read one (with optional selection override)
 *   - search($query)             — search draft orders (paginated)
 *   - update($id, $data)         — draftOrderUpdate (partial)
 *   - calculate($data)           — totals without saving
 *   - complete($id, $pending)    — turn the draft into a real Order
 *   - delete($id)                — draftOrderDelete
 *
 * Notes:
 *   - The customer is attached via `purchasingEntity` (DraftOrderInput's
 *     `customerId` is deprecated) — `DraftOrderInput::customer()` handles that.
 *   - `complete(paymentPending: true)` marks the new order payment-pending;
 *     otherwise it's marked paid. The result carries the created `order { id }`.
 *   - Reads accept a `$fields` selection override (caller-supplied selection set).
 *
 * Related: OrderExamples (manage the resulting order), CustomerExamples.
 */
final class DraftOrderExamples
{
    /** One manual-order record (ids reference Shopify objects). */
    private const SAMPLE = [
        'customerId' => 'gid://shopify/Customer/1',
        'variantId'  => 'gid://shopify/ProductVariant/1',
        'quantity'   => 2,
        'customItem' => ['title' => 'Gift wrap', 'price' => '5.00'],
        'note'       => 'Phone order',
    ];

    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * Create a draft order from one manual-order record (catalog line + a custom
     * line item + a customer).
     *
     * @param array<string,mixed> $data a manual-order record (defaults to {@see SAMPLE})
     */
    public function create(array $data = self::SAMPLE): array
    {
        $input = DraftOrderInput::make()
            ->customer($data['customerId'])
            ->lineItem($data['variantId'], $data['quantity'])
            ->customLineItem($data['customItem']['title'], $data['customItem']['price'])
            ->note($data['note'])
            ->useCustomerDefaultAddress();

        return $this->shopify->draftOrders()->create($input);
    }

    /**
     * Read one draft order. `$fields` overrides the default selection set.
     */
    public function get(string $draftOrderId, ?string $fields = null): ?array
    {
        return $this->shopify->draftOrders()->get($draftOrderId, $fields);
    }

    /**
     * Search draft orders (e.g. still-open ones), walking every page.
     */
    public function search(string $query = 'status:open'): array
    {
        return $this->shopify->draftOrders()->searchAll($query);
    }

    /**
     * Partial update — here updating the note.
     *
     * @param array<string,mixed> $data the record carrying the new values
     */
    public function update(string $draftOrderId, array $data = self::SAMPLE): array
    {
        return $this->shopify->draftOrders()->update($draftOrderId, DraftOrderInput::make()->note($data['note']));
    }

    /**
     * Calculate totals for a prospective draft order without saving it.
     *
     * @param array<string,mixed> $data a manual-order record
     */
    public function calculate(array $data = self::SAMPLE): array
    {
        return $this->shopify->draftOrders()->calculate(
            DraftOrderInput::make()->lineItem($data['variantId'], $data['quantity']),
        );
    }

    /**
     * Complete the draft, turning it into a real Order (the result has `order.id`).
     */
    public function complete(string $draftOrderId, bool $paymentPending = false): array
    {
        return $this->shopify->draftOrders()->complete($draftOrderId, $paymentPending);
    }

    /**
     * Delete a draft order; returns the deleted id in `deletedId`.
     */
    public function delete(string $draftOrderId): array
    {
        return $this->shopify->draftOrders()->delete($draftOrderId);
    }
}
