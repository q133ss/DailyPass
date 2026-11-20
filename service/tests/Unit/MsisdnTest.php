<?php

namespace Tests\Unit;

use App\Support\Msisdn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MsisdnTest extends TestCase
{
    #[DataProvider('validNumbers')]
    public function test_accepts_tel_with_digits(string $value): void
    {
        $this->assertTrue(Msisdn::isValid($value));
    }

    public static function validNumbers(): array
    {
        return [
            ['tel:1000000001'],
            ['tel:123456'],
            ['tel:123456789012345'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_rejects_junk(mixed $value): void
    {
        $this->assertFalse(Msisdn::isValid($value));
    }

    public static function invalidNumbers(): array
    {
        return [
            ['1000000001'],
            ['tel:+1000000001'],
            ['tel:abc'],
            ['tel:12345'],
            ['tel:1234567890123456'],
            ['tel:12 34'],
            [null],
            [12345678],
        ];
    }

    public function test_masks_all_but_last_four_digits(): void
    {
        $this->assertSame('tel:******0001', Msisdn::mask('tel:1000000001'));
        $this->assertSame('tel:***', Msisdn::mask('tel:masked-alias'));
    }
}
