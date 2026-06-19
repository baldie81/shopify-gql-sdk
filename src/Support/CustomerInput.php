<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

/**
 * Fluent builder for a `CustomerInput` (customerCreate / customerUpdate). Unset
 * fields are omitted, so the same builder serves create and partial update.
 *
 *   CustomerInput::make('jane@example.com')->firstName('Jane')->lastName('Doe')
 *       ->tags(['vip'])->metafield(Metafield::text('erp', 'customer_id', 'C-1'));
 */
final class CustomerInput implements Arrayable
{
    /** @var array<string,mixed> */
    private array $fields = [];

    /** @var list<array<string,mixed>> */
    private array $metafields = [];

    public static function make(?string $email = null): self
    {
        $input = new self();
        if ($email !== null) {
            $input->fields['email'] = $email;
        }

        return $input;
    }

    public function email(string $email): self
    {
        $this->fields['email'] = $email;

        return $this;
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

    public function phone(string $phone): self
    {
        $this->fields['phone'] = $phone;

        return $this;
    }

    public function note(string $note): self
    {
        $this->fields['note'] = $note;

        return $this;
    }

    /**
     * The customer's preferred locale (e.g. "fr", "en").
     */
    public function locale(string $locale): self
    {
        $this->fields['locale'] = $locale;

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

    public function taxExempt(bool $taxExempt = true): self
    {
        $this->fields['taxExempt'] = $taxExempt;

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
        if ($this->metafields !== []) {
            $input['metafields'] = $this->metafields;
        }

        return $input;
    }
}
