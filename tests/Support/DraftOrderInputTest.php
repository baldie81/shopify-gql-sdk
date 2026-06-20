<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Support;

use Baldie81\ShopifyGraphQL\Support\DraftOrderInput;
use PHPUnit\Framework\TestCase;

final class DraftOrderInputTest extends TestCase
{
    public function testBuildsLineItemsAndPurchasingEntity(): void
    {
        $input = DraftOrderInput::make()
            ->customer('5')
            ->lineItem('99', 2)
            ->customLineItem('Gift wrap', 5, 1)
            ->note('Phone order')
            ->useCustomerDefaultAddress();

        self::assertSame([
            'purchasingEntity'          => ['customerId' => 'gid://shopify/Customer/5'],
            'note'                      => 'Phone order',
            'useCustomerDefaultAddress' => true,
            'lineItems'                 => [
                ['variantId' => 'gid://shopify/ProductVariant/99', 'quantity' => 2],
                ['title' => 'Gift wrap', 'originalUnitPrice' => '5.00', 'quantity' => 1],
            ],
        ], $input->toArray());
    }
}
