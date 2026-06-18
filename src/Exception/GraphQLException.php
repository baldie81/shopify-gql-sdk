<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Exception;

/**
 * A GraphQL-level failure: a non-2xx HTTP status, malformed JSON, or a
 * top-level `errors[]` array (query validation, etc.). Note these are distinct
 * from a mutation's `userErrors`, which are business-validation results returned
 * as data, not thrown.
 */
class GraphQLException extends ShopifyException
{
    /**
     * @param list<array<string,mixed>> $errors the raw GraphQL `errors[]`
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        ?\Throwable $previous = null,
        public readonly array $errors = [],
        public readonly ?string $rawBody = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param list<array<string,mixed>> $errors
     */
    public static function fromErrors(array $errors, int $statusCode = 200): self
    {
        $messages = array_map(
            static fn (array $e): string => (string) ($e['message'] ?? 'Unknown GraphQL error'),
            $errors,
        );

        return new self(
            $messages === [] ? 'GraphQL error' : implode('; ', $messages),
            $statusCode,
            errors: $errors,
        );
    }
}
