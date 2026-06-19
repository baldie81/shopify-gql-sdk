<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

/**
 * Fluent builder for a `MailingAddressInput` (customer addresses). Uses the
 * code fields Shopify prefers — `countryCode` (e.g. "US", "GR") and
 * `provinceCode` (e.g. "CA", "I") rather than free-text names.
 *
 *   Address::make()->firstName('Jane')->lastName('Doe')
 *       ->address1('1 Rue de la Paix')->city('Paris')->zip('75002')->countryCode('FR');
 */
final class Address implements Arrayable
{
    /** @var array<string,mixed> */
    private array $fields = [];

    public static function make(): self
    {
        return new self();
    }

    public function firstName(string $firstName): self
    {
        $this->fields['firstName'] = $firstName;

        return $this;
    }

    public function lastName(string $lastName): self
    {
        $this->fields['lastName'] = $lastName;

        return $this;
    }

    public function company(string $company): self
    {
        $this->fields['company'] = $company;

        return $this;
    }

    public function address1(string $address1): self
    {
        $this->fields['address1'] = $address1;

        return $this;
    }

    public function address2(string $address2): self
    {
        $this->fields['address2'] = $address2;

        return $this;
    }

    public function city(string $city): self
    {
        $this->fields['city'] = $city;

        return $this;
    }

    /**
     * Province/region code (e.g. "CA", "NY", "I").
     */
    public function provinceCode(string $provinceCode): self
    {
        $this->fields['provinceCode'] = $provinceCode;

        return $this;
    }

    /**
     * Two-letter country code (e.g. "US", "GR", "FR").
     */
    public function countryCode(string $countryCode): self
    {
        $this->fields['countryCode'] = $countryCode;

        return $this;
    }

    public function zip(string $zip): self
    {
        $this->fields['zip'] = $zip;

        return $this;
    }

    public function phone(string $phone): self
    {
        $this->fields['phone'] = $phone;

        return $this;
    }

    public function set(string $field, mixed $value): self
    {
        $this->fields[$field] = $value;

        return $this;
    }

    public function toArray(): array
    {
        return $this->fields;
    }
}
