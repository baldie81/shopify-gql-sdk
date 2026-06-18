<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

use Baldie81\ShopifyGraphQL\Enum\ProductTranslatableField;

/**
 * A single translation to register (`TranslationInput`).
 *
 * Every translation must carry the `translatableContentDigest` of the *source*
 * content it translates. You can set it explicitly, but normally you leave it
 * unset and let {@see \Baldie81\ShopifyGraphQL\Resource\Translations} fill it in
 * from the resource's current translatable content.
 *
 *   Translation::make('fr', ProductTranslatableField::Title, 'Chapeau');
 *   Translation::make('fr', ProductTranslatableField::Description, '<p>…</p>')->forMarket($marketGid);
 */
final class Translation implements Arrayable
{
    private function __construct(
        private readonly string $locale,
        private readonly string $key,
        private readonly string $value,
        private readonly ?string $digest = null,
        private readonly ?string $marketId = null,
    ) {}

    public static function make(string $locale, ProductTranslatableField|string $key, string $value, ?string $digest = null): self
    {
        return new self($locale, $key instanceof ProductTranslatableField ? $key->value : $key, $value, $digest);
    }

    public function withDigest(string $digest): self
    {
        return new self($this->locale, $this->key, $this->value, $digest, $this->marketId);
    }

    public function forMarket(string $marketId): self
    {
        return new self($this->locale, $this->key, $this->value, $this->digest, $marketId);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function hasDigest(): bool
    {
        return $this->digest !== null;
    }

    public function toArray(): array
    {
        $input = ['locale' => $this->locale, 'key' => $this->key, 'value' => $this->value];

        if ($this->digest !== null) {
            $input['translatableContentDigest'] = $this->digest;
        }
        if ($this->marketId !== null) {
            $input['marketId'] = $this->marketId;
        }

        return $input;
    }
}
