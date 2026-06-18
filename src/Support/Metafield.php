<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

use Baldie81\ShopifyGraphQL\Enum\MetafieldType;

/**
 * A single metafield to set (`MetafieldInput` / `MetafieldsSetInput` minus the
 * owner, which the resource injects). Shopify stores every value as a string, so
 * the builder serialises by PHP type: bools become `true`/`false`, scalars are
 * stringified, and arrays/objects are JSON-encoded (for `json`, `list.*`, and
 * the structured types like `dimension`/`weight`/`rating`/`money`). Pass a string
 * to bypass serialisation entirely.
 *
 *   Metafield::make('specs', 'capacity', MetafieldType::NumberInteger, 25);
 *   Metafield::json('specs', 'matrix', ['rows' => 3, 'cols' => 4]);
 *   Metafield::make('specs', 'volume', MetafieldType::Volume, ['value' => 1.5, 'unit' => 'LITERS']);
 */
final class Metafield implements Arrayable
{
    private function __construct(
        private readonly string $namespace,
        private readonly string $key,
        private readonly string $type,
        private readonly string $value,
    ) {}

    public static function make(string $namespace, string $key, MetafieldType|string $type, mixed $value): self
    {
        return new self(
            $namespace,
            $key,
            $type instanceof MetafieldType ? $type->value : $type,
            self::stringify($value),
        );
    }

    public static function text(string $namespace, string $key, string $value): self
    {
        return self::make($namespace, $key, MetafieldType::SingleLineText, $value);
    }

    public static function integer(string $namespace, string $key, int $value): self
    {
        return self::make($namespace, $key, MetafieldType::NumberInteger, $value);
    }

    public static function boolean(string $namespace, string $key, bool $value): self
    {
        return self::make($namespace, $key, MetafieldType::Boolean, $value);
    }

    public static function json(string $namespace, string $key, mixed $value): self
    {
        return self::make($namespace, $key, MetafieldType::Json, $value);
    }

    /**
     * A reference to another resource by GID (defaults to a product reference).
     */
    public static function reference(
        string $namespace,
        string $key,
        string $gid,
        MetafieldType|string $type = MetafieldType::ProductReference,
    ): self {
        return self::make($namespace, $key, $type, $gid);
    }

    public function toArray(): array
    {
        return [
            'namespace' => $this->namespace,
            'key'       => $this->key,
            'type'      => $this->type,
            'value'     => $this->value,
        ];
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value)            => $value,
            is_bool($value)              => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            $value === null              => '',
            default                      => (string) json_encode($value),
        };
    }
}
