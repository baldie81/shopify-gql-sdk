<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Enum;

/**
 * Bulk operation status, mirroring the Shopify Admin GraphQL
 * `BulkOperationStatus` enum.
 */
enum BulkOperationStatus: string
{
    case Created   = 'CREATED';
    case Running   = 'RUNNING';
    case Completed = 'COMPLETED';
    case Canceling = 'CANCELING';
    case Canceled  = 'CANCELED';
    case Failed    = 'FAILED';
    case Expired   = 'EXPIRED';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Canceled, self::Failed, self::Expired => true,
            default => false,
        };
    }
}
