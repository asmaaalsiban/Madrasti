<?php

namespace Tests\Unit;

use App\Shared\Import\Numbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImportNumbersTest extends TestCase
{
    /** @return array<string, array{mixed, ?float}> */
    public static function cases(): array
    {
        return [
            'plain' => ['20', 20.0], 'decimal point' => ['17.5', 17.5], 'decimal comma' => ['17,5', 17.5],
            'thousands comma' => ['1,200', 1200.0], 'thousands and decimals' => ['1,200,000.50', 1200000.5],
            'arabic digits' => ['١٢٠٠', 1200.0], 'arabic decimal' => ['١٧٫٥', 17.5], 'arabic thousands' => ['١٬٢٠٠', 1200.0],
            'spaces' => [' 1 200 ', 1200.0], 'number' => [12.5, 12.5], 'empty' => ['', null], 'text' => ['abc', null], 'letter' => ['غ', null],
        ];
    }

    #[DataProvider('cases')]
    public function test_it_reads_what_schools_type(mixed $value, ?float $expected): void
    {
        $this->assertSame($expected, Numbers::parse($value));
    }
}
