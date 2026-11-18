<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\MediaKeys;

use Baldie81\ShopifyGraphQL\Exception\MediaKeyStoreException;
use Baldie81\ShopifyGraphQL\Exception\ShopifyException;
use Baldie81\ShopifyGraphQL\GraphQL\Client;

/**
 * Keeps the key map in a JSON metafield on the product itself (by default
 * `shopify_gql_sdk.media_keys`), so it travels with the store: any machine or
 * process syncing the product sees the same map, with nothing to deploy or back
 * up. The default store.
 *
 * The metafield has no definition, so it isn't shown as a field on the product
 * page in the admin and isn't exposed to the storefront.
 */
final class MetafieldMediaKeyStore implements MediaKeyStore
{
    public const string DEFAULT_NAMESPACE = 'shopify_gql_sdk';
    public const string DEFAULT_KEY       = 'media_keys';

    /** Response alias the map is selected under when read alongside a product's media. */
    public const string ALIAS = 'mediaKeys';

    /**
     * @throws \InvalidArgumentException for a namespace/key Shopify wouldn't accept
     */
    public function __construct(
        private readonly Client $gql,
        private readonly string $namespace = self::DEFAULT_NAMESPACE,
        private readonly string $key = self::DEFAULT_KEY,
    ) {
        foreach (['namespace' => $namespace, 'key' => $key] as $what => $value) {
            if (preg_match('/^[A-Za-z0-9_$:\-]{2,255}$/', $value) !== 1) {
                throw new \InvalidArgumentException("Invalid metafield {$what} \"{$value}\".");
            }
        }
    }

    public function load(string $productGid): array
    {
        $query = 'query ($id: ID!) { product(id: $id) { ' . $this->selection() . ' } }';

        try {
            $product = $this->gql->execute($query, ['id' => $productGid])['product'] ?? null;
        } catch (ShopifyException $e) {
            throw new MediaKeyStoreException('Could not read the media key metafield: ' . $e->getMessage(), 0, $e);
        }

        return $this->parse(is_array($product) ? $product : null);
    }

    /**
     * The selection that reads the map, for embedding in a `product { … }`
     * query that is being run anyway (so reading the map costs no extra request).
     * Pair with {@see parse()}.
     */
    public function selection(): string
    {
        // Namespace and key are restricted to a safe character set in the constructor.
        return self::ALIAS . ': metafield(namespace: "' . $this->namespace . '", key: "' . $this->key . '") { value }';
    }

    /**
     * Extract the map from a product node that selected {@see selection()}.
     *
     * @param array<string,mixed>|null $product
     * @return array<string,string>
     */
    public function parse(?array $product): array
    {
        $value = $product[self::ALIAS]['value'] ?? null;
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return []; // not ours / unreadable: treat as "no map" and rebuild it
        }

        $map = [];
        foreach ($decoded as $key => $mediaId) {
            if (is_string($mediaId) && $mediaId !== '') {
                $map[(string) $key] = $mediaId;
            }
        }

        return $map;
    }

    public function save(string $productGid, array $map): void
    {
        $mutation = 'mutation ($metafields: [MetafieldsSetInput!]!) {'
            . ' metafieldsSet(metafields: $metafields) { metafields { id } userErrors { field message code } } }';

        $this->run($mutation, ['metafields' => [[
            'ownerId'   => $productGid,
            'namespace' => $this->namespace,
            'key'       => $this->key,
            'type'      => 'json',
            // Cast so an empty map is stored as `{}`, not `[]`.
            'value'     => (string) json_encode((object) $map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]]], 'metafieldsSet', 'write');
    }

    public function forget(string $productGid): void
    {
        $mutation = 'mutation ($metafields: [MetafieldIdentifierInput!]!) {'
            . ' metafieldsDelete(metafields: $metafields) { deletedMetafields { key } userErrors { field message } } }';

        $this->run($mutation, ['metafields' => [[
            'ownerId'   => $productGid,
            'namespace' => $this->namespace,
            'key'       => $this->key,
        ]]], 'metafieldsDelete', 'delete');
    }

    /**
     * @param array<string,mixed> $variables
     */
    private function run(string $mutation, array $variables, string $field, string $verb): void
    {
        try {
            $result = $this->gql->execute($mutation, $variables)[$field] ?? [];
        } catch (ShopifyException $e) {
            throw new MediaKeyStoreException("Could not {$verb} the media key metafield: " . $e->getMessage(), 0, $e);
        }

        $userErrors = $result['userErrors'] ?? [];
        if ($userErrors !== []) {
            throw new MediaKeyStoreException(
                "Could not {$verb} the media key metafield: " . implode('; ', array_column($userErrors, 'message')),
            );
        }
    }
}
