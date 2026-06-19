<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;
use Baldie81\ShopifyGraphQL\Support\Address;
use Baldie81\ShopifyGraphQL\Support\CustomerInput;
use Baldie81\ShopifyGraphQL\Support\Metafield;

/**
 * Customers — create / read / update, metafields (e.g. an ERP customer id), and
 * addresses via the modern dedicated address mutations.
 *
 * `CustomerInput` is a fluent builder; only the fields you set are sent, so the
 * same builder works for both create and partial update. Addresses are managed
 * separately (Shopify deprecated setting them inline on the customer).
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
