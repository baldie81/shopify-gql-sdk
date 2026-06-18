<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Enum;

/**
 * Product status, mirroring the Shopify Admin GraphQL `ProductStatus` enum.
 */
enum ProductStatus: string
{
    case Active   = 'ACTIVE';
    case Archived = 'ARCHIVED';
    case Draft    = 'DRAFT';
}
