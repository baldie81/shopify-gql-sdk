<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Enum;

/**
 * Bulk operation type, mirroring the Shopify Admin GraphQL `BulkOperationType`
 * enum — whether an operation runs a query (export) or a mutation (import).
 */
enum BulkOperationType: string
{
    case Query    = 'QUERY';
    case Mutation = 'MUTATION';
}
