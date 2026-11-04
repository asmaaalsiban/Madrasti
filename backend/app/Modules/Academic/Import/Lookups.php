<?php

declare(strict_types=1);

namespace App\Modules\Academic\Import;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Subject;
use App\Shared\Import\ColumnMatcher;

/** Finds the records a spreadsheet row points to by the words a school writes (a name, a code, an email), once per import. */
trait Lookups
{
    private ?ColumnMatcher $matcher = null;

    /** @var array<string, mixed> loaded once per importer (one importer lives for one request) */
    private array $memo = [];

    private function norm(string $value): string
    {
        return ($this->matcher ??= new ColumnMatcher())->norm($value);
    }

    /** The section by its name, in the current academic year (or the given year); "Grade 5" words are ignored. */
    protected function classroomNamed(string $name, ?AcademicYear $year = null): ?Classroom
    {
        $yearId = $year?->id ?? AcademicYear::where('is_current', true)->value('id');
        $this->memo['rooms'.$yearId] ??= Classroom::where('academic_year_id', $yearId)->get();
        $strip = fn (string $v) => str_replace(['الصف', 'شعبه', 'class', 'grade'], '', $this->norm($v));

        return $this->memo['rooms'.$yearId]->first(fn (Classroom $c) => $this->norm($c->name) === $this->norm($name) || $strip($c->name) === $strip($name));
    }

    protected function gradeLevelNamed(string $name): ?GradeLevel
    {
        return ($this->memo['grades'] ??= GradeLevel::all())->first(fn (GradeLevel $g) => in_array($this->norm($name), [$this->norm($g->name_ar), $this->norm($g->name_en), $this->norm((string) $g->code)], true));
    }

    /** A subject by its code or either name. */
    protected function subjectNamed(string $value): ?Subject
    {
        return ($this->memo['subjects'] ??= Subject::all())->first(fn (Subject $s) => in_array($this->norm($value), [$this->norm($s->code), $this->norm($s->name_ar), $this->norm($s->name_en)], true));
    }

    /** A staff member by email, phone or name. */
    protected function staffNamed(string $value): ?User
    {
        $all = $this->memo['staff'] ??= User::whereHas('staffProfile')->get();
        $wanted = $this->norm($value);

        return $all->first(fn (User $u) => $u->email !== null && mb_strtolower($u->email) === mb_strtolower(trim($value)))
            ?? $all->first(fn (User $u) => $u->phone !== null && $this->norm($u->phone) === $wanted)
            ?? $all->first(fn (User $u) => $this->norm($u->name) === $wanted);
    }

    protected function academicYearNamed(string $name): ?AcademicYear
    {
        return AcademicYear::all()->first(fn (AcademicYear $y) => $this->norm($y->name) === $this->norm($name));
    }
}
