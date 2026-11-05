<?php

declare(strict_types=1);

namespace App\Shared\Import;

/** Reads the numbers a school types in a spreadsheet: Arabic digits, "1,200" (thousands) and "17,5" (decimal comma). */
class Numbers
{
    public static function parse(mixed $value): ?float
    {
        $text = trim(strtr((string) $value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => ',', ' ' => '', "\u{00A0}" => '']));
        if ($text === '') {
            return null;
        }
        // "1,200" or "1,200,000.50": the commas group thousands. Any other comma is a decimal comma ("17,5").
        $text = preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $text) === 1 ? str_replace(',', '', $text) : str_replace(',', '.', $text);

        return is_numeric($text) ? (float) $text : null;
    }
}
