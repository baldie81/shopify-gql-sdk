<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

/**
 * Fluent builder for a `CollectionInput` (collectionCreate / collectionUpdate).
 *
 * Manual collection:
 *   CollectionInput::make('Summer')->descriptionHtml('<p>Hot stuff</p>');
 *
 * Smart (rule-based) collection — add one or more rules:
 *   CollectionInput::make('On sale')->disjunctive(false)
 *       ->rule('TAG', 'EQUALS', 'sale');
 */
final class CollectionInput implements Arrayable
{
    /** @var array<string,mixed> */
    private array $fields = [];

    /** @var list<array<string,string>> */
    private array $rules = [];

    private ?bool $disjunctive = null;

    /** @var list<array<string,mixed>> */
    private array $metafields = [];

    public static function make(?string $title = null): self
    {
        $input = new self();
        if ($title !== null) {
            $input->fields['title'] = $title;
        }

        return $input;
    }

    public function title(string $title): self
    {
        $this->fields['title'] = $title;

        return $this;
    }

    public function descriptionHtml(string $html): self
    {
        $this->fields['descriptionHtml'] = $html;

        return $this;
    }

    public function handle(string $handle): self
    {
        $this->fields['handle'] = $handle;

        return $this;
    }

    /**
     * Product sort order (`CollectionSortOrder`, e.g. "MANUAL", "BEST_SELLING",
     * "ALPHA_ASC", "PRICE_DESC").
     */
    public function sortOrder(string $sortOrder): self
    {
        $this->fields['sortOrder'] = $sortOrder;

        return $this;
    }

    public function templateSuffix(string $templateSuffix): self
    {
        $this->fields['templateSuffix'] = $templateSuffix;

        return $this;
    }

    public function seo(?string $title = null, ?string $description = null): self
    {
        $seo = [];
        if ($title !== null) {
            $seo['title'] = $title;
        }
        if ($description !== null) {
            $seo['description'] = $description;
        }
        $this->fields['seo'] = $seo;

        return $this;
    }

    public function image(string $src, ?string $altText = null): self
    {
        $image = ['src' => $src];
        if ($altText !== null) {
            $image['altText'] = $altText;
        }
        $this->fields['image'] = $image;

        return $this;
    }

    /**
     * Seed manual membership at create time.
     *
     * @param list<string> $productIds
     */
    public function products(array $productIds): self
    {
        $this->fields['products'] = array_values($productIds);

        return $this;
    }

    /**
     * Add a smart-collection rule. `$column` is a `CollectionRuleColumn` (e.g.
     * "TAG", "TITLE", "TYPE", "VENDOR", "VARIANT_PRICE"); `$relation` a
     * `CollectionRuleRelation` (e.g. "EQUALS", "CONTAINS", "GREATER_THAN").
     */
    public function rule(string $column, string $relation, string $condition): self
    {
        $this->rules[] = ['column' => $column, 'relation' => $relation, 'condition' => $condition];

        return $this;
    }

    /**
     * Whether rules are OR'd (`true`) or AND'd (`false`, the default).
     */
    public function disjunctive(bool $disjunctive = true): self
    {
        $this->disjunctive = $disjunctive;

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
        if ($this->rules !== []) {
            $input['ruleSet'] = ['appliedDisjunctively' => $this->disjunctive ?? false, 'rules' => $this->rules];
        }
        if ($this->metafields !== []) {
            $input['metafields'] = $this->metafields;
        }

        return $input;
    }
}
