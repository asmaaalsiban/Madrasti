<?php

declare(strict_types=1);

namespace App\Shared\Export;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Builds an Excel (.xlsx) download from a header row and rows of plain values. The sheet reads right-to-left in Arabic. */
class XlsxExport
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<list<scalar|null>>  $rows
     */
    public function download(string $filename, string $sheetName, array $headers, iterable $rows): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $writer = new Writer();
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $sheetName) ?: 'Sheet1', 0, 31));
        $sheet->setSheetView((new SheetView())->setRightToLeft(app()->getLocale() === 'ar'));

        $bold = (new Style())->setFontBold();
        $writer->addRow(new Row(array_map(fn (string $h) => Cell::fromValue($h, $bold), $headers)));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return response()->download($path, $this->safeName($filename).'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** A file name with no path characters, whatever the title. */
    private function safeName(string $name): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $name), '_') ?: 'export';
    }
}
