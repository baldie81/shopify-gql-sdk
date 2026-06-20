<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;
use Baldie81\ShopifyGraphQL\Support\Translation;

/**
 * TRANSLATIONS — localise any translatable resource (products, collections,
 * metafields, …). Reached via `$shopify->translations()`.
 *
 *   $ex = new TranslationExamples(ExampleClient::create());
 *
 * Real-life usage: the resource id comes from Shopify; the translations come from
 * your PIM/TMS and are passed as `$data` (locale => field => value), defaulting to
 * {@see SAMPLE}. Typical loop:
 *
 *   foreach ($tms->translationsByProduct() as $productId => $byLocale) {
 *       $ex->register($productId, $byLocale);
 *   }
 *
 * Operations shown in this file:
 *   - translatableContent($productId)  — what's translatable (+ digests)
 *   - setLocale($productId, $data)     — set one locale via a key => value map
 *   - register($productId, $data)      — register several locales at once
 *   - remove($productId)               — drop translations for keys + locales
 *
 * Notes:
 *   - Keyed by the resource's translatable field keys (`title`, `body_html`, …).
 *   - Each translation needs the source field's **content digest**; the SDK looks
 *     these up and fills them for you (`register`/`set`).
 *   - The target locale must be a **published/added alternate** locale on the shop.
 *
 * Related: ProductExamples (base content), MetafieldDefinitionExamples.
 */
final class TranslationExamples
{
    /** Translations as they might arrive from a PIM/TMS: locale => field => value. */
    private const SAMPLE = [
        'fr' => ['title' => 'Tee en coton bio', 'body_html' => '<p>Coton bio doux.</p>'],
        'es' => ['title' => 'Camiseta de algodón orgánico'],
    ];

    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * What can be translated on a resource, with each field's current digest.
     */
    public function translatableContent(string $productId): array
    {
        return $this->shopify->translations()->translatableContent($productId);
    }

    /**
     * Set translations for ONE locale via a simple key => value map.
     *
     * @param array<string,string> $values field => value (falls back to the SAMPLE locale)
     */
    public function setLocale(string $productId, string $locale = 'fr', array $values = []): array
    {
        if ($values === []) {
            $values = self::SAMPLE[$locale] ?? [];
        }

        return $this->shopify->translations()->set($productId, $locale, $values);
    }

    /**
     * Register translations for several locales at once (digests resolved for you).
     *
     * @param array<string,array<string,string>> $data locale => field => value
     */
    public function register(string $productId, array $data = self::SAMPLE): array
    {
        $translations = [];
        foreach ($data as $locale => $fields) {
            foreach ($fields as $key => $value) {
                $translations[] = Translation::make($locale, $key, $value);
            }
        }

        return $this->shopify->translations()->register($productId, $translations);
    }

    /**
     * Remove translations for the given field keys + locales (here the French
     * title) — useful when a source value changes and a stale translation lingers.
     */
    public function remove(string $productId): array
    {
        return $this->shopify->translations()->remove($productId, ['title'], ['fr']);
    }
}
