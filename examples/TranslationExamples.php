<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;
use Baldie81\ShopifyGraphQL\Support\Translation;

/**
 * Translations — translate any translatable resource (products, collections,
 * metafields…). The SDK fills the required content digests for you.
 *
 * The locale must be a *published/added* alternate locale on the shop.
 */
final class TranslationExamples
{
    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * What can be translated on a resource, with each field's current digest.
     */
    public function translatableContent(string $productId): array
    {
        return $this->shopify->translations()->translatableContent($productId);
    }

    /**
     * Set translations for one locale via a simple key => value map.
     */
    public function setFrench(string $productId): array
    {
        return $this->shopify->translations()->set($productId, 'fr', [
            'title'     => 'Tee d’exemple SDK',
            'body_html' => '<p>Créé via le SDK.</p>',
        ]);
    }

    /**
     * Register several locale translations at once (digests resolved automatically).
     */
    public function registerMany(string $productId): array
    {
        return $this->shopify->translations()->register($productId, [
            Translation::make('fr', 'title', 'Tee d’exemple SDK'),
            Translation::make('es', 'title', 'Camiseta de ejemplo SDK'),
        ]);
    }

    /**
     * Remove translations for the given field keys + locales (here the French
     * title). Useful when a source value changes and a stale translation lingers.
     */
    public function remove(string $productId): array
    {
        return $this->shopify->translations()->remove($productId, ['title'], ['fr']);
    }
}
