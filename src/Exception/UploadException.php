<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Exception;

/**
 * A staged file upload failed — either Shopify refused to create the staged
 * target, or pushing the bytes to it failed.
 */
final class UploadException extends ShopifyException
{
}
