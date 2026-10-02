<?php

namespace Tests\Unit;

use App\Rules\Iban;
use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    public function test_money_and_rate_formatting(): void
    {
        $this->assertSame("1\u{00A0}234\u{00A0}567,50\u{00A0}₼", money(1234567.5));
        $this->assertSame("12,00\u{00A0}USD", money(12, 'USD', false));
        $this->assertSame('1,7000', rate_fmt(1.7));
        $this->assertSame('0,021091', rate_fmt(0.021091));
    }

    public function test_parse_number_accepts_local_and_excel_formats(): void
    {
        $this->assertSame(1234.56, parse_number('1 234,56'));
        $this->assertSame(1234.56, parse_number('1,234.56'));
        $this->assertSame(1234.56, parse_number('1.234,56'));
        $this->assertSame(-50.0, parse_number('-50'));
        $this->assertSame(1500.0, parse_number('1 500 ₼'));
        $this->assertNull(parse_number(''));
        $this->assertNull(parse_number('abc'));
    }

    public function test_iban_checksum(): void
    {
        $this->assertTrue(Iban::valid('GB82 WEST 1234 5698 7654 32'));
        $this->assertFalse(Iban::valid('GB82 WEST 1234 5698 7654 33'));
        $this->assertFalse(Iban::valid('AZ21NABZ0000000013700000'), 'AZ IBAN must be 28 chars');
    }

    public function test_azerbaijani_case_folding(): void
    {
        $this->assertSame('istanbul', az_lower('İstanbul'));
        $this->assertSame('ılıq', az_lower('ILIQ'));
    }
}
