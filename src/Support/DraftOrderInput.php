<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

/**
 * Fluent builder for a `DraftOrderInput` (draftOrderCreate / draftOrderUpdate).
 *
 *   DraftOrderInput::make()
 *       ->customer($customerId)
 *       ->lineItem($variantId, 2)
 *       ->customLineItem('Gift wrap', '5.00')
 *       ->useCustomerDefaultAddress()
 *       ->note('Phone order');
 */
final class DraftOrderInput implements Arrayable
{
    /** @var array<string,mixed> */
    private array $fields = [];

    /** @var list<array<string,mixed>> */
    private array $lineItems = [];

    /** @var list<array<string,mixed>> */
    private array $metafields = [];

    public static function make(): self
    {
        return new self();
    }

    /**
     * Add a catalog line item (a product variant) with a quantity.
     */
    public function lineItem(string $variantId, int $quantity = 1): self
    {
        $this->lineItems[] = ['variantId' => Gid::variant($variantId), 'quantity' => $quantity];

        return $this;
    }

    /**
     * Add a custom (non-catalog) line item with a title and unit price.
     */
    public function customLineItem(string $title, string|float $unitPrice, int $quantity = 1): self
    {
        $this->lineItems[] = [
            'title'             => $title,
            'originalUnitPrice' => number_format((float) $unitPrice, 2, '.', ''),
            'quantity'          => $quantity,
        ];

        return $this;
    }

    /**
     * Attach a customer (`purchasingEntity.customerId` — the modern field).
     */
    public function customer(string $customerId): self
    {
        $this->fields['purchasingEntity'] = ['customerId' => Gid::customer($customerId)];

        return $this;
    }

    public function email(string $email): self
    {
        $this->fields['email'] = $email;

        return $this;
    }

    public function note(string $note): self
    {
        $this->fields['note'] = $note;

        return $this;
    }

    /**
     * @param list<string> $tags
     */
    public function tags(array $tags): self
    {
        $this->fields['tags'] = array_values($tags);

        return $this;
    }

    /**
     * @param array<string,mixed> $address a MailingAddressInput
     */
    public function shippingAddress(array $address): self
    {
        $this->fields['shippingAddress'] = $address;

        return $this;
    }

    /**
     * @param array<string,mixed> $address a MailingAddressInput
     */
    public function billingAddress(array $address): self
    {
        $this->fields['billingAddress'] = $address;

        return $this;
    }

    public function useCustomerDefaultAddress(bool $use = true): self
    {
        $this->fields['useCustomerDefaultAddress'] = $use;

        return $this;
    }

    /**
     * Order-level discount (`DraftOrderAppliedDiscountInput`), e.g.
     * `['valueType' => 'PERCENTAGE', 'value' => 10.0, 'title' => 'Loyalty']`.
     *
     * @param array<string,mixed> $discount
     */
    public function appliedDiscount(array $discount): self
    {
        $this->fields['appliedDiscount'] = $discount;

        return $this;
    }

    /**
     * @param Metafield|array<string,mixed> $metafield
     */
    public function metafield(Metafield|array $metafield): self
    {
        $this->metafields[] = $metafield instanceof Arrayable ? $metafield->toArray() : $metafield;

        return $this;
    }

    /**
     * @param list<Metafield|array<string,mixed>> $metafields
     */
    public function metafields(array $metafields): self
    {
        foreach ($metafields as $metafield) {
            $this->metafield($metafield);
        }

        return $this;
    }

    public function set(string $field, mixed $value): self
    {
        $this->fields[$field] = $value;

        return $this;
    }

    public function toArray(): array
    {
        $input = $this->fields;
        if ($this->lineItems !== []) {
            $input['lineItems'] = $this->lineItems;
        }
        if ($this->metafields !== []) {
            $input['metafields'] = $this->metafields;
        }

        return $input;
    }
}
