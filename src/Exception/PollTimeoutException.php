<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Exception;

/**
 * Polling gave up before the bulk operation reached a terminal status. The
 * operation is still running on Shopify's side — `$operation` is its last-seen
 * state, so the caller can keep polling its `id` or cancel it.
 */
final class PollTimeoutException extends ShopifyException
{
    /**
     * @param array<string,mixed> $operation the last-seen bulk operation
     */
    public function __construct(string $message, public readonly array $operation = [])
    {
        parent::__construct($message);
    }
}
