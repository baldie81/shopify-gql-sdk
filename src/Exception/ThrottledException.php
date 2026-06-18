<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Exception;

use Throwable;

/**
 * The request was throttled by Shopify's calculated query-cost limiter (a
 * top-level error with `extensions.code = THROTTLED`, or an HTTP 429). Carries
 * the `extensions.cost` block so callers can inspect the throttle status.
 */
final class ThrottledException extends GraphQLException
{
    /**
     * @param array<string,mixed> $cost the `extensions.cost` block, if present
     */
    public function __construct(
        string                $message,
        int                   $statusCode = 200,
        ?Throwable            $previous = null,
        public readonly array $cost = [],
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * @param list<array<string,mixed>> $errors
     * @param array<string,mixed>       $extensions the response `extensions` block
     */
    public static function fromResponse(array $errors, array $extensions): self
    {
        $message = (string) ($errors[0]['message'] ?? 'Shopify throttled the request.');

        return new self($message, 200, cost: $extensions['cost'] ?? []);
    }
}
