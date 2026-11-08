<?php

declare(strict_types=1);

namespace App\Modules\Academic\Services;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\People\Models\StaffProfile;
use App\Shared\Export\SheetStyles;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The subject distribution table: every member of staff with what they studied, and for every section the subject they teach
 * there and its periods a week, then their total load. It follows the paper form of the Ministry (title block, qualifications,
 * a Subject and a Periods column per section, the total, the notes). Many sections are split over several sheets.
 */
class DistributionSheet
{
    use SheetStyles;

    private const SECTIONS_PER_SHEET = 14;

    private const FIXED = 8; // no., name, experience, degree, specialization, university, graduation year, courses; then the sections

    public function download(): BinaryFileResponse
    {
        $ar = app()->getLocale() === 'ar';
        $year = AcademicYear::where('is_current', true)->first();
        $classrooms = $year ? Classroom::where('academic_year_id', $year->id)->with('gradeLevel')->get()->sortBy(fn (Classroom $c) => sprintf('%05d|%s', $c->gradeLevel?->sort_order ?? 0, $c->name))->values() : collect();
        $assignments = TeachingAssignment::whereIn('classroom_id', $classrooms->pluck('id'))->with('subject')->get()->groupBy('teacher_id');
        $people = User::whereHas('staffProfile')->with('staffProfile.subject')->get()
            ->sortBy(fn (User $u) => sprintf('%d|%s|%s', $u->staffProfile->staff_type->category() === 'teaching' ? 1 : 0, $u->staffProfile->subject?->name_en ?? '', $u->name))->values();
        $info = ['school' => (string) (tenant()?->name ?? ''), 'date' => now()->format('d / m / Y')];

        $path = tempnam(sys_get_temp_dir(), 'ds');
        $writer = new Writer();
        $writer->getOptions()->setPageSetup(new PageSetup(PageOrientation::LANDSCAPE, PaperSize::A3, 1, 1));
        $writer->openToFile($path);
        $chunks = $classrooms->chunk(self::SECTIONS_PER_SHEET);
        if ($chunks->isEmpty()) {
            $chunks = collect([collect()]);
        }
        $writer->getOptions()->setColumnWidth(5, 1);
        $writer->getOptions()->setColumnWidth(26, 2);
        $writer->getOptions()->setColumnWidthForRange(10, 3, 3);
        $writer->getOptions()->setColumnWidthForRange(13, 4, 4);
        $writer->getOptions()->setColumnWidthForRange(16, 5, 6);
        $writer->getOptions()->setColumnWidthForRange(9, 7, 7);
        $writer->getOptions()->setColumnWidthForRange(18, 8, 8);
        $writer->getOptions()->setColumnWidthForRange(13, 9, self::FIXED + max(1, $chunks->map->count()->max()) * 2 + 2);

        foreach ($chunks->values() as $i => $rooms) {
            if ($i > 0) {
                $writer->addNewSheetAndMakeItCurrent();
            }
            $sheet = $writer->getCurrentSheet();
            $sheet->setName(__('academic.distribution.sheet_name', ['n' => $i + 1]));
            $sheet->setSheetView((new SheetView())->setRightToLeft($ar)->setShowGridLines(false)->setFreezeRow(8)->setFreezeColumn('C'));
            $this->sheet($writer, $i, $rooms, $people, $assignments, $info, $ar);
        }
        $writer->close();

        return response()->download($path, 'distribution.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Classroom>  $rooms
     * @param  \Illuminate\Support\Collection<int, User>  $people
     * @param  \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, TeachingAssignment>>  $assignments
     * @param  array{school: string, date: string}  $info
     */
    private function sheet(Writer $writer, int $index, $rooms, $people, $assignments, array $info, bool $ar): void
    {
        $o = $writer->getOptions();
        $cols = self::FIXED + $rooms->count() * 2 + 2; // the sections, the total, the notes
        $last = $cols - 1;
        $totalCol = self::FIXED + $rooms->count() * 2;
        $notesCol = $totalCol + 1;
        $row = 1;
        $blank = fn () => array_fill(0, $cols, '');
        $add = function (array $values, $style, float $height) use ($writer, &$row): void {
            $writer->addRow(Row::fromValues($values, $style)->setHeight($height));
            $row++;
        };
        $line = function (string $text, $style, float $height) use ($blank, $o, $index, &$row, $last, $add): void {
            $v = $blank();
            $v[0] = $text;
            $o->mergeCells(0, $row, $last, $row, $index);
            $add($v, $style, $height);
        };

        $line(__('academic.distribution.title', ['date' => $info['date']]), $this->style(18, true, self::NAVY), 34);
        $line(__('academic.distribution.school', ['school' => $info['school']]), $this->style(13, true), 24);
        $line(__('academic.distribution.directorate', ['directorate' => app(SchoolDetails::class)->line('directorate'), 'district' => app(SchoolDetails::class)->line('district')]), $this->style(11), 22);
        $line('', $this->style(), 8);

        // Header: three rows (the sections span two columns each).
        $head = $this->style(11, true, 'FFFFFF', self::NAVY, true);
        $sub = $this->style(10, true, self::NAVY, self::TINT, true);
        $r1 = $blank();
        $r2 = $blank();
        $r3 = $blank();
        foreach ([0 => 'no', 1 => 'teacher'] as $c => $key) {
            $r1[$c] = __('academic.distribution.'.$key);
            $o->mergeCells($c, $row, $c, $row + 2, $index);
        }
        $r1[2] = __('academic.distribution.qualification');
        $o->mergeCells(2, $row, 6, $row, $index);
        foreach ([2 => 'experience', 3 => 'degree', 4 => 'specialization', 5 => 'university', 6 => 'grad_year'] as $c => $key) {
            $r2[$c] = __('academic.distribution.'.$key);
            $o->mergeCells($c, $row + 1, $c, $row + 2, $index);
        }
        $r1[7] = __('academic.distribution.courses');
        $o->mergeCells(7, $row, 7, $row + 2, $index);
        foreach ($rooms->values() as $k => $room) {
            $c = self::FIXED + $k * 2;
            $r1[$c] = $room->name;
            $o->mergeCells($c, $row, $c + 1, $row + 1, $index);
            $r3[$c] = __('academic.distribution.subject');
            $r3[$c + 1] = __('academic.distribution.periods');
        }
        $r1[$totalCol] = __('academic.distribution.total');
        $r1[$notesCol] = __('academic.distribution.notes');
        $o->mergeCells($totalCol, $row, $totalCol, $row + 2, $index);
        $o->mergeCells($notesCol, $row, $notesCol, $row + 2, $index);
        $add($r1, $head, 26);
        $add($r2, $sub, 22);
        $add($r3, $sub, 22);

        $body = fn (int $c, int $n) => $c <= 1 ? $this->style(10, $c === 1, '111827', $n % 2 ? 'F8FAFC' : 'FFFFFF', true) : $this->style(10, false, '1F2937', $n % 2 ? 'F8FAFC' : 'FFFFFF', true);
        foreach ($people as $n => $user) {
            /** @var StaffProfile $p */
            $p = $user->staffProfile;
            $mine = $assignments->get($user->id, collect());
            $byRoom = $mine->groupBy('classroom_id');
            $depth = max(1, (int) $byRoom->map->count()->max());
            $load = (int) $mine->sum('weekly_periods');
            $first = $row;
            for ($d = 0; $d < $depth; $d++) {
                $v = $blank();
                if ($d === 0) {
                    $v[0] = $n + 1;
                    $v[1] = $user->name;
                    $v[2] = $p->experience_years ?? '';
                    $v[3] = $p->degree ? __('academic.distribution.degrees.'.$p->degree) : '';
                    $v[4] = $p->specialization ?? '';
                    $v[5] = $p->university ?? '';
                    $v[6] = $p->graduation_year ?? '';
                    $v[7] = $p->courses ?? '';
                    $v[$totalCol] = $load > 0 ? $load : '';
                    $v[$notesCol] = $p->staff_type->category() === 'teaching' ? '' : __('academic.staff_types.'.$p->staff_type->value);
                }
                foreach ($rooms->values() as $k => $room) {
                    $a = ($byRoom->get($room->id) ?? collect())->values()->get($d);
                    $c = self::FIXED + $k * 2;
                    $v[$c] = $a ? ($ar ? $a->subject->name_ar : $a->subject->name_en) : '';
                    $v[$c + 1] = $a ? $a->weekly_periods : '';
                }
                $cells = [];
                foreach ($v as $c => $value) {
                    $cells[] = \OpenSpout\Common\Entity\Cell::fromValue($value, $c === $totalCol ? $this->style(11, true, self::NAVY, self::SAND, true) : $body($c, $n));
                }
                $writer->addRow((new Row($cells))->setHeight(24));
                $row++;
            }
            if ($depth > 1) {
                foreach ([0, 1, 2, 3, 4, 5, 6, 7, $totalCol, $notesCol] as $c) {
                    $o->mergeCells($c, $first, $c, $row - 1, $index);
                }
            }
        }
        $line('', $this->style(), 12);
        $v = $blank();
        $v[0] = __('academic.distribution.footer');
        $o->mergeCells(0, $row, $last, $row, $index);
        $add($v, $this->style(10, false, '6B7280'), 40);
    }
}
