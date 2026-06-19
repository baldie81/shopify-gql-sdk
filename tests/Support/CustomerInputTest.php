<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Support;

use Baldie81\ShopifyGraphQL\Support\CustomerInput;
use Baldie81\ShopifyGraphQL\Support\Metafield;
use PHPUnit\Framework\TestCase;

final class CustomerInputTest extends TestCase
{
    public function testBuildsInputWithMetafields(): void
    {
        $input = CustomerInput::make('jane@example.com')
            ->firstName('Jane')
            ->lastName('Doe')
            ->tags(['vip', 'wholesale'])
            ->metafield(Metafield::text('erp', 'customer_id', 'C-1'));

        self::assertSame([
            'email'      => 'jane@example.com',
            'firstName'  => 'Jane',
            'lastName'   => 'Doe',
            'tags'       => ['vip', 'wholesale'],
            'metafields' => [['namespace' => 'erp', 'key' => 'customer_id', 'type' => 'single_line_text_field', 'value' => 'C-1']],
        ], $input->toArray());
    }

    public function testOmitsUnsetFields(): void
    {
        self::assertSame(['email' => 'x@example.com'], CustomerInput::make('x@example.com')->toArray());
    }
}
