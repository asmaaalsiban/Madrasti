<?php

declare(strict_types=1);

namespace App\Shared\Import;

/**
 * Works out which column of a school's own spreadsheet holds which field, from the header words (Arabic or English,
 * with or without "the", hamza, spaces...) and, for a plain "name" column, from the script of the values in it.
 */
class ColumnMatcher
{
    /** Header text in a form that ignores case, diacritics, hamza/alef forms, ta marbuta, spaces and punctuation. */
    public function norm(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0640}]/u', '', $text) ?? $text; // diacritics, tatweel
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? '';
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<?string>>  $samples  a few rows, to tell an Arabic name column from an English one
     * @param  array<string, array{en: string, ar: string, aliases: list<string>}>  $columns
     * @param  ?array{keys: array{0: string, 1: string}, aliases: list<string>}  $genericName  a plain "name" header can be either of two fields
     * @return array<int, ?string> file column index => field key (null: no field)
     */
    public function suggest(array $headers, array $samples, array $columns, ?array $genericName = null): array
    {
        $names = []; // normalized name => [field, strength]
        foreach ($columns as $key => $column) {
            foreach ([$key, $column['en'], $column['ar'], ...$column['aliases']] as $name) {
                $names[$this->norm($name)] = $key;
            }
        }

        $result = array_fill_keys(array_keys($headers), null);
        $taken = [];

        // Pass 1: the header is exactly a known name.
        foreach ($headers as $i => $header) {
            $key = $names[$this->norm($header)] ?? null;
            if ($key !== null && ! isset($taken[$key])) {
                $result[$i] = $key;
                $taken[$key] = true;
            }
        }

        // Pass 2: the header contains a known name; the longest name wins ("parent name" beats "name").
        $byLength = $names;
        uksort($byLength, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($headers as $i => $header) {
            if ($result[$i] !== null) {
                continue;
            }
            $norm = $this->norm($header);
            foreach ($byLength as $name => $key) {
                if (mb_strlen($name) >= 3 && ! isset($taken[$key]) && str_contains($norm, $name)) {
                    $result[$i] = $key;
                    $taken[$key] = true;
                    break;
                }
            }
        }

        // Pass 3: a plain "name" column: Arabic letters in the values mean the Arabic name, Latin letters the English one.
        if ($genericName !== null) {
            [$arabic, $latin] = $genericName['keys'];
            $aliases = array_map(fn ($a) => $this->norm($a), $genericName['aliases']);
            foreach ($headers as $i => $header) {
                if ($result[$i] !== null || ! in_array($this->norm($header), $aliases, true)) {
                    continue;
                }
                $key = $this->script(array_column($samples, $i)) === 'latin' ? $latin : $arabic;
                if (isset($taken[$key])) {
                    $key = $key === $arabic ? $latin : $arabic;
                }
                if (! isset($taken[$key])) {
                    $result[$i] = $key;
                    $taken[$key] = true;
                }
            }
        }

        return $result;
    }

    /** 'arabic' or 'latin', by the letters in the values. */
    public function script(array $values): string
    {
        $text = implode(' ', array_filter($values, 'is_string'));
        $arabic = preg_match_all('/\p{Arabic}/u', $text);
        $latin = preg_match_all('/\p{Latin}/u', $text);

        return $latin > $arabic ? 'latin' : 'arabic';
    }
}
