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
 * Real-life usage: a write method takes **one customer record** as `$data` (an
 * ERP/CRM payload, defaulting to {@see SAMPLE}). Customer/address ids come from
 * Shopify (params). Typical loop:
 *
 *   foreach ($erp->customers() as $record) {
 *       $ex->create($record);
 *   }
 *
 * Operations shown in this file:
 *   - create($data)                          — customerCreate (+ ERP-id metafield)
 *   - update($customerId, $data)             — customerUpdate; partial
 *   - get($customerId)                       — read one (withAddresses optional)
 *   - searchByTag($tag)                      — searchAll by tag (addresses inline)
 *   - setMetafields($customerId, $data)      — upsert customer metafields
 *   - addAddress($customerId, $data)         — customerAddressCreate (Address builder)
 *   - listAddresses($customerId)             — a customer's addresses
 *   - updateAddress / deleteAddress          — by address id
 *
 * Notes:
 *   - `CustomerInput` only sends the fields you set, so one builder serves create
 *     and partial update.
 *   - Reads include `defaultAddress` cheaply; pass `withAddresses: true` for the
 *     full list.
 *   - Addresses use the **dedicated** mutations (create/update/delete) — Shopify
 *     deprecated setting addresses inline on the customer.
 *
 * Related: OrderExamples, MetafieldDefinitionExamples.
 */
final class CustomerExamples
{
    /** One customer record, shaped like an ERP/CRM payload. */
    private const SAMPLE = [
        'email'     => 'jane@example.com',
        'firstName' => 'Jane',
        'lastName'  => 'Doe',
        'tags'      => ['vip'],
        'note'      => 'Imported from ERP',
        'erpId'     => 'C-1001',
        'address'   => [
            'address1'     => '500 Congress Ave',
            'city'         => 'Austin',
            'provinceCode' => 'TX',
            'zip'          => '78701',
            'countryCode'  => 'US',
        ],
    ];

    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * Create a customer from one ERP record, with the ERP id as a metafield.
     *
     * @param array<string,mixed> $data a customer record (defaults to {@see SAMPLE})
     */
    public function create(array $data = self::SAMPLE): array
    {
        return $this->shopify->customers()->create(
            CustomerInput::make($data['email'])
                ->firstName($data['firstName'])
                ->lastName($data['lastName'])
                ->tags($data['tags'])
                ->note($data['note'])
                ->metafield(Metafield::text('erp', 'customer_id', $data['erpId'])),
        );
    }

    /**
     * Partial update — here updating the note. The id is injected for you.
     *
     * @param array<string,mixed> $data the record carrying the new values
     */
    public function update(string $customerId, array $data = self::SAMPLE): array
    {
        return $this->shopify->customers()->update($customerId, CustomerInput::make()->note($data['note']));
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
     * Search customers by tag, walking every page (addresses included inline).
     */
    public function searchByTag(string $tag = 'vip'): array
    {
        return $this->shopify->customers()->searchAll("tag:{$tag}", withAddresses: true);
    }

    /**
     * Upsert the ERP id onto the customer as a metafield.
     *
     * @param array<string,mixed> $data a customer record (uses its `erpId`)
     */
    public function setMetafields(string $customerId, array $data = self::SAMPLE): array
    {
        return $this->shopify->customers()->setMetafields($customerId, [
            Metafield::text('erp', 'customer_id', $data['erpId']),
        ]);
    }

    // --- Addresses (customerAddressCreate / Update / Delete) ---

    /**
     * Add the record's address and make it the default. `Address` uses Shopify's
     * code fields (`countryCode`, `provinceCode`).
     *
     * @param array<string,mixed> $data a customer record (uses its `address`)
     */
    public function addAddress(string $customerId, array $data = self::SAMPLE): array
    {
        $a = $data['address'];

        return $this->shopify->customers()->addAddress(
            $customerId,
            Address::make()
                ->firstName($data['firstName'])->lastName($data['lastName'])
                ->address1($a['address1'])->city($a['city'])
                ->provinceCode($a['provinceCode'])->zip($a['zip'])->countryCode($a['countryCode']),
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
     *
     * @param array<string,mixed> $data a record carrying the new address values
     */
    public function updateAddress(string $customerId, string $addressId, array $data = self::SAMPLE): array
    {
        return $this->shopify->customers()->updateAddress($customerId, $addressId, Address::make()->city($data['address']['city']));
    }

    /**
     * Delete one of the customer's addresses by id.
     */
    public function deleteAddress(string $customerId, string $addressId): array
    {
        return $this->shopify->customers()->deleteAddress($customerId, $addressId);
    }
}
