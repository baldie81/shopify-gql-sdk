<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Exception;

/**
 * A connection/timeout/TLS failure — no usable HTTP response came back.
 */
final class TransportException extends ShopifyException
{
}
