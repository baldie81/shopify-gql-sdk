<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use Baldie81\ShopifyGraphQL\Enum\ProductTranslatableField;
use Baldie81\ShopifyGraphQL\Support\Translation;

/**
 * Translations for any translatable resource (products, collections, and —
 * when publicly accessible — metafields, identified by their own GID).
 *
 * Shopify requires each registered translation to carry the
 * `translatableContentDigest` of the source content, so the flow is always:
 * read the resource's translatable content (keys + digests), then register
 * translations against those digests. {@see set()} does both for you; the lower
 * level {@see register()} fills in any missing digests automatically.
 *
 * @link https://shopify.dev/docs/api/admin-graphql/latest/mutations/translationsRegister Shopify Admin GraphQL: translationsRegister
 * @link https://shopify.dev/docs/api/admin-graphql/latest/objects/TranslatableResource Shopify Admin GraphQL: TranslatableResource
 */
final class Translations extends AbstractResource
{
    /**
     * The resource's translatable content: each entry has `key`, `value`,
     * `digest`, and `locale` (the source locale).
     *
     * @return list<array<string,mixed>>
     */
    public function translatableContent(string $resourceId): array
    {
        $query = 'query ($resourceId: ID!) {'
            . ' translatableResource(resourceId: $resourceId) {'
            . ' resourceId translatableContent { key value digest locale } } }';

        return $this->gql->execute($query, ['resourceId' => $resourceId])['translatableResource']['translatableContent'] ?? [];
    }

    /**
     * Convenience map of translatable key => current source digest.
     *
     * @return array<string,string>
     */
    public function digests(string $resourceId): array
    {
        $map = [];
        foreach ($this->translatableContent($resourceId) as $content) {
            if (isset($content['key'], $content['digest'])) {
                $map[(string) $content['key']] = (string) $content['digest'];
            }
        }

        return $map;
    }

    /**
     * Translate fields in one call: looks up the source digests, builds the
     * inputs, and registers them. `$values` is keyed by translatable key
     * (use {@see ProductTranslatableField}'s value, e.g. `'body_html'` for the
     * description). Keys that aren't translatable on the resource are returned in
     * `skipped` rather than sent (which would error).
     *
     * @param array<string,string> $values key => translated value
     * @return array{translations:list<array<string,mixed>>,userErrors:list<array<string,mixed>>,digests:array<string,string>,skipped:list<string>}
     */
    public function set(string $resourceId, string $locale, array $values, ?string $marketId = null): array
    {
        $digests = $this->digests($resourceId);

        $inputs  = [];
        $skipped = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if (!isset($digests[$key])) {
                $skipped[] = $key;
                continue;
            }
            $input = [
                'locale'                    => $locale,
                'key'                       => $key,
                'value'                     => (string) $value,
                'translatableContentDigest' => $digests[$key],
            ];
            if ($marketId !== null) {
                $input['marketId'] = $marketId;
            }
            $inputs[] = $input;
        }

        $result = $inputs === []
            ? ['translations' => [], 'userErrors' => [], 'digests' => []]
            : $this->runRegister($resourceId, $inputs);
        $result['skipped'] = $skipped;

        return $result;
    }

    /**
     * Register translations from {@see Translation} builders or raw arrays. Any
     * input missing `translatableContentDigest` is filled from the resource's
     * current content before sending.
     *
     * @param list<Translation|array<string,mixed>> $translations
     * @return array{translations:list<array<string,mixed>>,userErrors:list<array<string,mixed>>,digests:array<string,string>}
     */
    public function register(string $resourceId, array $translations): array
    {
        $inputs = $this->payloads($translations);

        $needsDigest = false;
        foreach ($inputs as $input) {
            if (!isset($input['translatableContentDigest'])) {
                $needsDigest = true;
                break;
            }
        }

        if ($needsDigest) {
            $digests = $this->digests($resourceId);
            foreach ($inputs as &$input) {
                if (!isset($input['translatableContentDigest']) && isset($input['key'], $digests[$input['key']])) {
                    $input['translatableContentDigest'] = $digests[$input['key']];
                }
            }
            unset($input);
        }

        return $this->runRegister($resourceId, $inputs);
    }

    /**
     * Remove translations for the given keys and locales.
     *
     * @param list<ProductTranslatableField|string> $keys
     * @param list<string>                          $locales
     * @return array{translations:list<array<string,mixed>>,userErrors:list<array<string,mixed>>}
     */
    public function remove(string $resourceId, array $keys, array $locales): array
    {
        $mutation = 'mutation ($resourceId: ID!, $keys: [String!]!, $locales: [String!]!) {'
            . ' translationsRemove(resourceId: $resourceId, translationKeys: $keys, locales: $locales) {'
            . ' translations { key } userErrors { field message } } }';

        $keys = array_map(
            static fn ($key): string => $key instanceof ProductTranslatableField ? $key->value : (string) $key,
            $keys,
        );

        $data = $this->gql->execute($mutation, [
            'resourceId' => $resourceId,
            'keys'       => array_values($keys),
            'locales'    => array_values($locales),
        ]);

        return $data['translationsRemove'] ?? ['translations' => [], 'userErrors' => []];
    }

    /**
     * @param list<array<string,mixed>> $inputs
     * @return array{translations:list<array<string,mixed>>,userErrors:list<array<string,mixed>>,digests:array<string,string>}
     */
    private function runRegister(string $resourceId, array $inputs): array
    {
        $mutation = 'mutation ($resourceId: ID!, $translations: [TranslationInput!]!) {'
            . ' translationsRegister(resourceId: $resourceId, translations: $translations) {'
            . ' translations { locale key value updatedAt } userErrors { field message } } }';

        $data   = $this->gql->execute($mutation, ['resourceId' => $resourceId, 'translations' => array_values($inputs)]);
        $result = $data['translationsRegister'] ?? ['translations' => [], 'userErrors' => []];

        // Surface the digest each translation was registered against, so callers
        // can persist it (e.g. to detect "outdated" translations later).
        $digestByKey = [];
        foreach ($inputs as $input) {
            if (isset($input['key'])) {
                $digestByKey[(string) $input['key']] = (string) ($input['translatableContentDigest'] ?? '');
            }
        }
        foreach ($result['translations'] ?? [] as &$translation) {
            $key = $translation['key'] ?? null;
            if ($key !== null && isset($digestByKey[$key])) {
                $translation['translatableContentDigest'] = $digestByKey[$key];
            }
        }
        unset($translation);

        $result['digests'] = $digestByKey;

        return $result;
    }
}
