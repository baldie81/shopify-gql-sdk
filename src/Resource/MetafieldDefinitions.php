<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use Baldie81\ShopifyGraphQL\Enum\MetafieldType;

/**
 * Metafield definitions (`metafieldDefinitionCreate`).
 *
 * Setting a metafield *value* ({@see Products::setMetafields()}) does not create
 * a *definition* — the structured, admin-visible declaration of a metafield.
 * Definitions are store-wide per (ownerType, namespace, key); create them once.
 */
final class MetafieldDefinitions extends AbstractResource
{
    /**
     * Create a definition from a raw `MetafieldDefinitionInput`. Re-creating an
     * existing (ownerType, namespace, key) returns a `TAKEN` userError rather
     * than throwing.
     *
     * @param array<string,mixed> $definition
     * @return array{definition:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function create(array $definition): array
    {
        $mutation = 'mutation ($definition: MetafieldDefinitionInput!) {'
            . ' metafieldDefinitionCreate(definition: $definition) {'
            . ' createdDefinition { id name namespace key ownerType type { name } }'
            . ' userErrors { field message code } } }';

        $result = $this->gql->execute($mutation, ['definition' => $definition])['metafieldDefinitionCreate'] ?? [];

        return [
            'definition' => $result['createdDefinition'] ?? null,
            'userErrors' => $result['userErrors'] ?? [],
        ];
    }

    /**
     * Convenience for a product-owned definition.
     *
     * @param array<string,mixed> $extra extra MetafieldDefinitionInput fields (e.g. `description`, `validations`)
     * @return array{definition:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function defineProduct(string $namespace, string $key, MetafieldType|string $type, string $name, array $extra = []): array
    {
        return $this->create([
            'ownerType' => 'PRODUCT',
            'namespace' => $namespace,
            'key'       => $key,
            'type'      => $type instanceof MetafieldType ? $type->value : $type,
            'name'      => $name,
        ] + $extra);
    }
}
