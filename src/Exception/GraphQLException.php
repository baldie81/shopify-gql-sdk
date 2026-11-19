<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Exception;

/**
 * A GraphQL-level failure: a non-2xx HTTP status, malformed JSON, or a
 * top-level `errors[]` array (query validation, etc.). Note these are distinct
 * from a mutation's `userErrors`, which are business-validation results returned
 * as data, not thrown.
 *
 * Shopify can answer with both: `data` for what it could resolve and `errors`
 * for what it couldn't — typically a field the app isn't approved to read
 * (protected customer data), returned as null. That partial result is kept in
 * {@see $data}.
 */
class GraphQLException extends ShopifyException
{
    /**
     * @param list<array<string,mixed>> $errors the raw GraphQL `errors[]`
     * @param array<string,mixed>|null  $data   the `data` returned alongside the errors, if any
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        ?\Throwable $previous = null,
        public readonly array $errors = [],
        public readonly ?string $rawBody = null,
        public readonly ?array $data = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param list<array<string,mixed>> $errors
     * @param array<string,mixed>|null  $data
     */
    public static function fromErrors(array $errors, int $statusCode = 200, ?array $data = null): self
    {
        $messages = array_map(
            static fn (array $e): string => (string) ($e['message'] ?? 'Unknown GraphQL error'),
            $errors,
        );

        return new self(
            $messages === [] ? 'GraphQL error' : implode('; ', $messages),
            $statusCode,
            errors: $errors,
            data: $data,
        );
    }
}
