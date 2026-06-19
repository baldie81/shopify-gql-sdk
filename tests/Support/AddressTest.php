<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Support;

use Baldie81\ShopifyGraphQL\Support\Address;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase
{
    public function testBuildsMailingAddressInput(): void
    {
        $address = Address::make()
            ->firstName('Jane')->lastName('Doe')
            ->address1('1 Rue de la Paix')->city('Paris')->zip('75002')->countryCode('FR');

        self::assertSame([
            'firstName'   => 'Jane',
            'lastName'    => 'Doe',
            'address1'    => '1 Rue de la Paix',
            'city'        => 'Paris',
            'zip'         => '75002',
            'countryCode' => 'FR',
        ], $address->toArray());
    }
}
