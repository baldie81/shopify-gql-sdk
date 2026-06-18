<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

/**
 * A value object that renders itself as the variables payload a GraphQL
 * operation expects. Write methods accept `Arrayable|array`, so a builder or a
 * raw array can be passed interchangeably.
 */
interface Arrayable
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(): array;
}
