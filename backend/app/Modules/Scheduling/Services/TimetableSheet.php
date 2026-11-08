<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Scheduling\Models\DutyAssignment;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Models\TimetableEntry;
use App\Shared\Enums\StaffType;
use App\Shared\Export\SheetStyles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The school's timetable on the paper form schools hand in: the title block, a row with the homeroom teacher of every
 * section, the sections side by side (a subject column and a teacher column each), a block of periods for every day, the
 * duty column and the signature block. Many sections are split over several sheets so each one prints on one A3 page.
 */
class TimetableSheet
{
    use SheetStyles;

    private const SECTIONS_PER_SHEET = 12;

    public function download(): BinaryFileResponse
    {
        $ar = app()->getLocale() === 'ar';
        $year = AcademicYear::where('is_current', true)->first();
        $classrooms = $year ? Classroom::where('academic_year_id', $year->id)->with(['gradeLevel', 'homeroomTeacher:id,name'])->get()->sortBy(fn (Classroom $c) => sprintf('%05d|%s', $c->gradeLevel?->sort_order ?? 0, $c->name))->values() : collect();
        $periods = Period::orderBy('day_of_week')->orderBy('number')->get();
        $cell = $year ? TimetableEntry::where('academic_year_id', $year->id)->with(['subject', 'teacher:id,name'])->get()->keyBy(fn ($e) => $e->classroom_id.'-'.$e->period_id) : collect();
        $days = $periods->groupBy('day_of_week');
        $duty = $this->duty();
        $info = [
            'year' => $year?->name ?? '',
            'school' => (string) (tenant()?->name ?? ''),
            'principal' => (string) (DB::table('users')->join('staff_profiles', 'staff_profiles.user_id', '=', 'users.id')->where('staff_profiles.staff_type', StaffType::Principal->value)->orderBy('users.id')->value('users.name') ?? ''),
        ];

        $path = tempnam(sys_get_temp_dir(), 'tt');
        $writer = new Writer();
        $writer->getOptions()->setPageSetup(new PageSetup(PageOrientation::LANDSCAPE, PaperSize::A3, 1, 1));
        $writer->openToFile($path);

        $chunks = $classrooms->chunk(self::SECTIONS_PER_SHEET);
        if ($chunks->isEmpty()) {
            $chunks = collect([collect()]);
        }
        $widest = max(1, $chunks->map->count()->max()) * 2 + 5;
        $writer->getOptions()->setColumnWidth(11, 1);
        $writer->getOptions()->setColumnWidth(7, 2);
        $writer->getOptions()->setColumnWidthForRange(15.5, 3, $widest);

        foreach ($chunks->values() as $i => $rooms) {
            if ($i > 0) {
                $writer->addNewSheetAndMakeItCurrent();
            }
            $sheet = $writer->getCurrentSheet();
            $sheet->setName(__('scheduling.sheet_name', ['n' => $i + 1]));
            $sheet->setSheetView((new SheetView())->setRightToLeft($ar)->setShowGridLines(false)->setFreezeRow(10)->setFreezeColumn('C'));
            $this->sheet($writer, $i, $rooms, $days, $cell, $duty, $info);
        }
        $writer->close();

        return response()->download($path, 'timetable.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /**
     * @param  Collection<int, Classroom>  $rooms
     * @param  Collection<int, Collection<int, Period>>  $days
     * @param  Collection<string, TimetableEntry>  $cell
     * @param  array<int, list<string>>  $duty  day of week => names
     * @param  array{year: string, school: string, principal: string}  $info
     */
    private function sheet(Writer $writer, int $index, Collection $rooms, Collection $days, Collection $cell, array $duty, array $info): void
    {
        $ar = app()->getLocale() === 'ar';
        $cols = 2 + $rooms->count() * 2 + 2; // day, period, two per section, two for the duty column
        $last = $cols - 1;
        $o = $writer->getOptions();
        $row = 1;
        $add = function (array $values, Style $style, ?float $height = null) use ($writer, &$row): void {
            $r = Row::fromValues($values, $style);
            if ($height !== null) {
                $r->setHeight($height);
            }
            $writer->addRow($r);
            $row++;
        };
        $blank = fn () => array_fill(0, $cols, '');
        $line = function (string $text, Style $style, float $height) use ($blank, $o, $index, &$row, $last, $add): void {
            $v = $blank();
            $v[0] = $text;
            $o->mergeCells(0, $row, $last, $row, $index);
            $add($v, $style, $height);
        };

        $plain = fn (int $size = 11, bool $bold = false) => $this->style($size, $bold);
        $line(__('scheduling.sheet.bismillah'), $plain(12), 22);
        $line(__('scheduling.sheet.title'), $this->style(20, true, self::NAVY), 36);
        $line(__('scheduling.sheet.year', ['year' => $info['year']]), $plain(12, true), 22);
        $line($info['school'], $plain(14, true), 26);
        $line(__('scheduling.sheet.directorate', ['directorate' => app(\App\Modules\Academic\Services\SchoolDetails::class)->line('directorate'), 'district' => app(\App\Modules\Academic\Services\SchoolDetails::class)->line('district')]), $plain(11), 22);
        $line('', $plain(), 8);

        // Homeroom teachers, the sections, then the column titles.
        $head = $this->style(11, true, 'FFFFFF', self::NAVY, true);
        $homeroom = $blank();
        $names = $blank();
        $titles = $blank();
        $homeroom[0] = __('scheduling.sheet.homeroom');
        $names[0] = __('scheduling.sheet.section');
        $o->mergeCells(0, $row, 1, $row, $index);
        $o->mergeCells(0, $row + 1, 1, $row + 1, $index);
        $titles[0] = __('scheduling.day');
        $titles[1] = __('scheduling.period');
        foreach ($rooms->values() as $k => $room) {
            $c = 2 + $k * 2;
            $homeroom[$c] = $room->homeroomTeacher?->name ?? '';
            $names[$c] = $room->name;
            $titles[$c] = __('scheduling.sheet.subject');
            $titles[$c + 1] = __('scheduling.sheet.teacher');
            $o->mergeCells($c, $row, $c + 1, $row, $index);
            $o->mergeCells($c, $row + 1, $c + 1, $row + 1, $index);
        }
        $dutyCol = 2 + $rooms->count() * 2;
        $homeroom[$dutyCol] = __('scheduling.sheet.duty'); // one cell over the three header rows
        $o->mergeCells($dutyCol, $row, $dutyCol + 1, $row + 2, $index);
        $add($homeroom, $head, 30);
        $add($names, $head, 26);
        $add($titles, $this->style(10, true, self::NAVY, self::TINT, true), 22);

        // A block of periods for every day.
        $border = $this->border();
        foreach ($days as $dayNo => $periods) {
            $first = $row;
            $dayNames = $duty[$dayNo] ?? [];
            foreach ($periods->values() as $n => $period) {
                $v = $blank();
                $v[0] = $n === 0 ? __('scheduling.days.'.$dayNo) : '';
                $v[1] = $this->ordinal($period->number);
                $styles = [new Style(), new Style()];
                foreach ($rooms->values() as $k => $room) {
                    $c = 2 + $k * 2;
                    $e = $cell[$room->id.'-'.$period->id] ?? null;
                    $v[$c] = $e ? ($ar ? $e->subject->name_ar : $e->subject->name_en) : '';
                    $v[$c + 1] = $e?->teacher->name ?? '';
                }
                $v[$dutyCol] = $dayNames[$n] ?? '';
                $o->mergeCells($dutyCol, $row, $dutyCol + 1, $row, $index);
                $cells = [];
                foreach ($v as $c => $value) {
                    $isDay = $c === 0;
                    $isNum = $c === 1;
                    $section = $c >= 2 && $c < $dutyCol ? intdiv($c - 2, 2) : null;
                    $style = $isDay ? $this->style(12, true, self::NAVY, self::TINT, true) : ($isNum ? $this->style(10, true, self::NAVY, self::SAND, true) : $this->style(10, $section !== null && ($c - 2) % 2 === 0, '1F2937', $section !== null && $section % 2 === 1 ? 'F8FAFC' : 'FFFFFF', true));
                    $cells[] = Cell::fromValue($value, $style);
                }
                $r = new Row($cells);
                $r->setHeight(30);
                $writer->addRow($r);
                $row++;
            }
            $o->mergeCells(0, $first, 0, $row - 1, $index);
        }

        // The signature block of the paper form.
        $line('', $plain(), 14);
        $third = intdiv($cols, 3);
        $sig = fn (string $a, string $b, string $c) => (function () use ($blank, $o, $index, &$row, $cols, $third, $add, $a, $b, $c): void {
            $v = $blank();
            $v[0] = $a;
            $v[$third] = $b;
            $v[$third * 2] = $c;
            $o->mergeCells(0, $row, $third - 1, $row, $index);
            $o->mergeCells($third, $row, $third * 2 - 1, $row, $index);
            $o->mergeCells($third * 2, $row, $cols - 1, $row, $index);
            $add($v, $this->style(11, true, '111827', null, false, false), 30);
        })();
        $dots = '....................';
        $sig(__('scheduling.sheet.checked').' '.$dots, __('scheduling.sheet.director_edu'), __('scheduling.sheet.principal', ['name' => $info['principal'] ?: $dots]));
        $sig(__('scheduling.sheet.signature').': '.$dots, __('scheduling.sheet.approved'), __('scheduling.sheet.signature').': '.$dots);
        $sig(__('scheduling.sheet.date').': ___ / ___ / ______', __('scheduling.sheet.official_stamp'), __('scheduling.sheet.school_stamp'));
    }

    /** The people on duty each weekday: the next date of that weekday that has a duty roster. @return array<int, list<string>> */
    private function duty(): array
    {
        $rows = DutyAssignment::where('date', '>=', now()->toDateString())->orderBy('date')->with('user:id,name')->get();
        $byDay = [];
        foreach ($rows->groupBy(fn ($d) => $d->date->dayOfWeek) as $dow => $group) {
            $firstDate = $group->first()->date->toDateString();
            $byDay[(int) $dow] = $group->filter(fn ($d) => $d->date->toDateString() === $firstDate)->map(fn ($d) => $d->user->name)->unique()->values()->all();
        }

        return $byDay;
    }

    private function ordinal(int $n): string
    {
        $list = trans('scheduling.ordinals');

        return is_array($list) && isset($list[$n]) ? $list[$n] : (string) $n;
    }
}
