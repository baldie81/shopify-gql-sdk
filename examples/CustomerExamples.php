<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;
use Baldie81\ShopifyGraphQL\Support\Address;
use Baldie81\ShopifyGraphQL\Support\CustomerInput;
use Baldie81\ShopifyGraphQL\Support\Metafield;

/**
 * CUSTOMERS — customer records, their metafields, and mailing addresses. Reached
 * via `$shopify->customers()`.
 *
 *   $ex = new CustomerExamples(ExampleClient::create());
 *
 * Operations shown in this file:
 *   - create()        — customerCreate from a CustomerInput builder (+ metafield)
 *   - update()        — customerUpdate; partial, id injected for you
 *   - get()           — read one (withAddresses: true to include the full list)
 *   - searchByTag()   — searchAll with Shopify query syntax (paginated)
 *   - setMetafields() — upsert customer metafields (e.g. an ERP id)
 *   - addAddress()    — customerAddressCreate (Address builder, optional default)
 *   - listAddresses() — a customer's addresses (each with its id)
 *   - updateAddress() / deleteAddress() — by address id
 *
 * Notes:
 *   - `CustomerInput` only sends the fields you set, so one builder serves both
 *     create and partial update.
 *   - Reads include `defaultAddress` cheaply; pass `withAddresses: true` for the
 *     full list (or call `addresses()` on demand).
 *   - Addresses use the **dedicated** mutations (create/update/delete) — Shopify
 *     deprecated setting addresses inline on the customer.
 *   - IDs may be numeric or GIDs; writes report problems in `userErrors`.
 *
 * Related: OrderExamples (a customer's orders), MetafieldDefinitionExamples
 * (surface customer metafields in the admin).
 */
final class CustomerExamples
{
    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * Create a customer with name, a tag and an inline ERP metafield.
     * Result: `['customer' => ['id' => …], 'userErrors' => [...]]`.
     */
    public function create(): array
    {
        return $this->shopify->customers()->create(
            CustomerInput::make('jane@example.com')
                ->firstName('Jane')
                ->lastName('Doe')
                ->tags(['vip'])
                ->metafield(Metafield::text('erp', 'customer_id', 'C-1001')),
        );
    }

    /**
     * Partial update — here just appending a note. The id is injected for you.
     */
    public function update(string $customerId): array
    {
        return $this->shopify->customers()->update($customerId, CustomerInput::make()->note('Called 2026-06-19'));
    }

    /**
     * Read a customer. `withAddresses: true` includes the full address list;
     * otherwise reads stay lean and only `defaultAddress` comes back.
     */
    public function get(string $customerId): ?array
    {
        return $this->shopify->customers()->get($customerId, withAddresses: true);
    }

    /**
     * Search with Shopify query syntax, walking every page. Pass `withAddresses`
     * to include each customer's full address list inline.
     */
    public function searchByTag(): array
    {
        return $this->shopify->customers()->searchAll('tag:vip', withAddresses: true);
    }

    /**
     * Upsert metafields on the customer (create-or-update by namespace+key).
     */
    public function setMetafields(string $customerId): array
    {
        return $this->shopify->customers()->setMetafields($customerId, [
            Metafield::text('erp', 'customer_id', 'C-1001'),
        ]);
    }

    // --- Addresses (customerAddressCreate / Update / Delete) ---

    /**
     * Add a mailing address and make it the default. `Address` is a builder that
     * uses Shopify's code fields (`countryCode`, `provinceCode`).
     * Result: `['address' => ['id' => …], 'userErrors' => [...]]`.
     */
    public function addAddress(string $customerId): array
    {
        return $this->shopify->customers()->addAddress(
            $customerId,
            Address::make()
                ->firstName('Jane')->lastName('Doe')
                ->address1('500 Congress Ave')->city('Austin')->provinceCode('TX')->zip('78701')->countryCode('US'),
            setAsDefault: true,
        );
    }

    /**
     * List a customer's addresses (each with its id, for update/delete).
     */
    public function listAddresses(string $customerId): array
    {
        return $this->shopify->customers()->addresses($customerId);
    }

    /**
     * Update one address (partial — only the fields you set change).
     */
    public function updateAddress(string $customerId, string $addressId): array
    {
        return $this->shopify->customers()->updateAddress($customerId, $addressId, Address::make()->city('Dallas'));
    }

    /**
     * Delete one of the customer's addresses by id.
     */
    public function deleteAddress(string $customerId, string $addressId): array
    {
        return $this->shopify->customers()->deleteAddress($customerId, $addressId);
    }
}
