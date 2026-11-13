<?php

declare(strict_types=1);

namespace App\Shared\Handover;

use App\Shared\Export\XlsxExport;
use Generator;
use Illuminate\Database\Query\Builder;

/** What every section needs to write one Excel file: bilingual headings, streamed rows, one place for the file names. */
final class HandoverSheet
{
    /** "Arabic / English" (an empty group is a top-level key of lang/handover.php), so the file reads the same whichever language the school's staff read. */
    public static function label(string $group, string $key): string
    {
        $path = $group === '' ? "handover.$key" : "handover.$group.$key";

        return trans($path, [], 'ar').' / '.trans($path, [], 'en');
    }

    /**
     * Writes `$dir/$stem.xlsx`. The headings come from the column keys; the sheet is named in the current language.
     *
     * @param  list<string>  $columns  keys of handover.cols
     * @param  iterable<list<scalar|null>>  $rows
     */
    public static function write(string $dir, string $stem, array $columns, iterable $rows, string $sheetSuffix = '', ?string $file = null): void
    {
        $headers = array_map(fn (string $c) => self::label('cols', $c), $columns);
        app(XlsxExport::class)->write($dir.'/'.($file ?? $stem).'.xlsx', trim(__('handover.files.'.$stem).' '.$sheetSuffix), $headers, $rows);
    }

    /**
     * Streams a query one row at a time through `$map`, so a big table never sits in memory.
     *
     * @param  callable(object): list<scalar|null>  $map
     */
    public static function stream(Builder $query, callable $map): Generator
    {
        foreach ($query->cursor() as $row) {
            yield $map($row);
        }
    }

    public static function money(mixed $v): float
    {
        return round((float) $v, 2);
    }

    /** Both names of a thing that has an Arabic and an English one. */
    public static function both(?string $ar, ?string $en): string
    {
        return trim($ar.' / '.$en, ' /');
    }

    public static function date(mixed $v): ?string
    {
        return $v === null ? null : substr((string) $v, 0, 10);
    }
}
