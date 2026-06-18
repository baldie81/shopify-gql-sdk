<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Exception;

/**
 * Base type for every error the SDK raises, so callers can catch one type
 * instead of leaking Guzzle exceptions.
 */
abstract class ShopifyException extends \RuntimeException
{
}
