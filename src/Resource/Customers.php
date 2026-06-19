<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use Baldie81\ShopifyGraphQL\Support\Address;
use Baldie81\ShopifyGraphQL\Support\CustomerInput;
use Baldie81\ShopifyGraphQL\Support\Gid;

/**
 * Customers — get / search / create / update, plus metafields (e.g. an ERP
 * customer id). Mirrors the Products read/write shape.
 */
final class Customers extends AbstractResource
{
    private const ADDRESS_BODY = 'id firstName lastName company address1 address2 city provinceCode countryCodeV2 zip phone';

    private const FIELDS = 'id legacyResourceId email firstName lastName phone numberOfOrders createdAt updatedAt'
        . ' defaultAddress { ' . self::ADDRESS_BODY . ' }';

    /**
     * Fetch a customer. Pass `$withAddresses` to include the full address list
     * (otherwise just `defaultAddress` comes back).
     *
     * @return array<string,mixed>|null
     */
    public function get(string $id, bool $withAddresses = false): ?array
    {
        $query = 'query ($id: ID!) { customer(id: $id) { ' . self::selection($withAddresses) . ' } }';

        return $this->gql->execute($query, ['id' => Gid::customer($id)])['customer'] ?? null;
    }

    /**
     * One page of a customer search (`$query` is Shopify syntax, e.g.
     * `email:jane@example.com` or `tag:vip`). Pass `$withAddresses` to include
     * each customer's full address list.
     *
     * @return array{nodes:list<array<string,mixed>>,pageInfo:array<string,mixed>}
     */
    public function search(string $query = '', int $first = 50, ?string $after = null, bool $withAddresses = false): array
    {
        $first = max(1, min($first, self::MAX_PAGE_SIZE));
        $data  = $this->gql->execute(self::listQuery($withAddresses), ['first' => $first, 'after' => $after, 'query' => $query]);

        return $data['customers'] ?? ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function searchAll(string $query = '', int $pageSize = self::MAX_PAGE_SIZE, bool $withAddresses = false): array
    {
        return $this->paginateNodes(self::listQuery($withAddresses), ['query' => $query], 'customers', $pageSize);
    }

    /**
     * Create a customer (`customerCreate`). Accepts a {@see CustomerInput} or raw array.
     *
     * @param CustomerInput|array<string,mixed> $input
     * @return array{customer:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function create(CustomerInput|array $input): array
    {
        $mutation = 'mutation ($input: CustomerInput!) {'
            . ' customerCreate(input: $input) { customer { ' . self::FIELDS . ' } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['input' => $this->payload($input)])['customerCreate'] ?? ['customer' => null, 'userErrors' => []];
    }

    /**
     * Update a customer (`customerUpdate`); the id is injected into the input.
     *
     * @param CustomerInput|array<string,mixed> $input
     * @return array{customer:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function update(string $id, CustomerInput|array $input): array
    {
        $mutation = 'mutation ($input: CustomerInput!) {'
            . ' customerUpdate(input: $input) { customer { ' . self::FIELDS . ' } userErrors { field message } } }';

        $payload = ['id' => Gid::customer($id)] + $this->payload($input);

        return $this->gql->execute($mutation, ['input' => $payload])['customerUpdate'] ?? ['customer' => null, 'userErrors' => []];
    }

    /**
     * Set metafields on a customer (e.g. an ERP reference).
     *
     * @param list<\Baldie81\ShopifyGraphQL\Support\Metafield|array<string,mixed>> $metafields
     * @return array{metafields:list<array<string,mixed>>,userErrors:list<array<string,mixed>>}
     */
    public function setMetafields(string $id, array $metafields): array
    {
        return $this->setMetafieldsFor(Gid::customer($id), $metafields);
    }

    /**
     * A customer's addresses.
     *
     * @return list<array<string,mixed>>
     */
    public function addresses(string $customerId, int $first = 50): array
    {
        $query = 'query ($id: ID!, $first: Int!) {'
            . ' customer(id: $id) { addresses(first: $first) { id firstName lastName company address1 address2 city provinceCode countryCodeV2 zip phone } } }';

        return $this->gql->execute($query, ['id' => Gid::customer($customerId), 'first' => $first])['customer']['addresses'] ?? [];
    }

    /**
     * Add an address to a customer (`customerAddressCreate`), optionally as default.
     *
     * @param Address|array<string,mixed> $address
     * @return array{address:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function addAddress(string $customerId, Address|array $address, bool $setAsDefault = false): array
    {
        $mutation = 'mutation ($customerId: ID!, $address: MailingAddressInput!, $setAsDefault: Boolean) {'
            . ' customerAddressCreate(customerId: $customerId, address: $address, setAsDefault: $setAsDefault) {'
            . ' address { id } userErrors { field message } } }';

        return $this->gql->execute($mutation, [
            'customerId'   => Gid::customer($customerId),
            'address'      => $this->payload($address),
            'setAsDefault' => $setAsDefault,
        ])['customerAddressCreate'] ?? ['address' => null, 'userErrors' => []];
    }

    /**
     * Update one of a customer's addresses (`customerAddressUpdate`).
     *
     * @param Address|array<string,mixed> $address
     * @return array{address:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function updateAddress(string $customerId, string $addressId, Address|array $address, bool $setAsDefault = false): array
    {
        $mutation = 'mutation ($customerId: ID!, $addressId: ID!, $address: MailingAddressInput!, $setAsDefault: Boolean) {'
            . ' customerAddressUpdate(customerId: $customerId, addressId: $addressId, address: $address, setAsDefault: $setAsDefault) {'
            . ' address { id } userErrors { field message } } }';

        return $this->gql->execute($mutation, [
            'customerId'   => Gid::customer($customerId),
            'addressId'    => $addressId,
            'address'      => $this->payload($address),
            'setAsDefault' => $setAsDefault,
        ])['customerAddressUpdate'] ?? ['address' => null, 'userErrors' => []];
    }

    /**
     * Delete one of a customer's addresses (`customerAddressDelete`).
     *
     * @return array{deletedAddressId:?string,userErrors:list<array<string,mixed>>}
     */
    public function deleteAddress(string $customerId, string $addressId): array
    {
        $mutation = 'mutation ($customerId: ID!, $addressId: ID!) {'
            . ' customerAddressDelete(customerId: $customerId, addressId: $addressId) {'
            . ' deletedAddressId userErrors { field message } } }';

        return $this->gql->execute($mutation, [
            'customerId' => Gid::customer($customerId),
            'addressId'  => $addressId,
        ])['customerAddressDelete'] ?? ['deletedAddressId' => null, 'userErrors' => []];
    }

    private static function selection(bool $withAddresses): string
    {
        return self::FIELDS . ($withAddresses ? ' addresses(first: 50) { ' . self::ADDRESS_BODY . ' }' : '');
    }

    private static function listQuery(bool $withAddresses): string
    {
        return 'query ($first: Int!, $after: String, $query: String) {'
            . ' customers(first: $first, after: $after, query: $query) {'
            . ' nodes { ' . self::selection($withAddresses) . ' } pageInfo { hasNextPage endCursor } } }';
    }
}
