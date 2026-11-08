<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\GradeLevelSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Models\TimetableEntry;
use App\Modules\Scheduling\Services\PeriodSetup;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class FullTimetableTest extends AcademicTestCase
{
    private AcademicYear $year;

    private GradeLevel $grade;

    private Classroom $a;

    private Classroom $b;

    private Subject $math;

    private Subject $art;

    private User $sara;

    private User $omar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::factory()->create(['is_current' => true]);
        $this->grade = GradeLevel::factory()->create(['sort_order' => 7, 'periods_per_day' => 6]);
        $this->a = Classroom::factory()->create(['name' => '7-A', 'grade_level_id' => $this->grade->id, 'academic_year_id' => $this->year->id]);
        $this->b = Classroom::factory()->create(['name' => '7-B', 'grade_level_id' => $this->grade->id, 'academic_year_id' => $this->year->id]);
        $this->math = Subject::factory()->create(['name_en' => 'Math']);
        $this->art = Subject::factory()->create(['name_en' => 'Art']);
        $this->sara = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id, 'grade_from_id' => $this->grade->id, 'grade_to_id' => $this->grade->id])->create(['name' => 'Sara']);
        $this->omar = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->art->id])->create(['name' => 'Omar']);
        TeachingAssignment::create(['classroom_id' => $this->a->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->sara->id, 'weekly_periods' => 2]);
        app(PeriodSetup::class)->apply(['days' => [0, 1], 'periods_per_day' => 7, 'starts_at' => '08:00', 'period_minutes' => 45, 'gap_minutes' => 5]);
        $this->actAsAdmin();
    }

    private function place(Classroom $room, int $day, int $number, Subject $subject, User $teacher): TimetableEntry
    {
        return TimetableEntry::create(['academic_year_id' => $this->year->id, 'classroom_id' => $room->id, 'period_id' => Period::where('day_of_week', $day)->where('number', $number)->value('id'), 'subject_id' => $subject->id, 'teacher_id' => $teacher->id]);
    }

    /** @return list<string> */
    private function types(): array
    {
        return array_column($this->getJson('/api/timetable/check')->assertOk()->json('data.issues'), 'type');
    }

    public function test_the_overview_has_every_section_with_its_lessons(): void
    {
        $this->place($this->a, 0, 1, $this->math, $this->sara);
        $r = $this->getJson('/api/timetable/overview')->assertOk()->json('data');
        $this->assertSame(['7-A', '7-B'], array_column($r['classrooms'], 'name'));
        $this->assertCount(14, $r['periods']);
        $this->assertSame('Sara', $r['entries'][0]['teacher']['name']);
    }

    public function test_the_checker_finds_missing_lessons_and_subjects_without_a_teacher(): void
    {
        GradeLevelSubject::create(['grade_level_id' => $this->grade->id, 'subject_id' => $this->art->id, 'weekly_periods' => 1]);
        $this->place($this->a, 0, 1, $this->math, $this->sara); // 1 of the 2 planned
        $types = $this->types();
        $this->assertContains('periods_missing', $types);
        $this->assertContains('subject_without_teacher', $types);
        $this->assertContains('no_homeroom', $types);
    }

    public function test_the_checker_finds_extra_lessons_unqualified_teachers_unavailable_periods_and_lessons_beyond_the_grade(): void
    {
        foreach ([[0, 1], [0, 2], [1, 1]] as [$d, $n]) {
            $this->place($this->a, $d, $n, $this->math, $this->sara); // 3 placed, 2 planned
        }
        $this->place($this->a, 1, 7, $this->math, $this->sara); // the 7th period of a grade with 6
        DB::table('teacher_unavailabilities')->insert(['teacher_id' => $this->sara->id, 'period_id' => Period::where('day_of_week', 0)->where('number', 1)->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        TeachingAssignment::create(['classroom_id' => $this->b->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->omar->id, 'weekly_periods' => 1]); // Omar teaches Art, not Math

        $types = $this->types();
        foreach (['periods_extra', 'teacher_unavailable', 'beyond_grade_periods', 'teacher_not_qualified'] as $expected) {
            $this->assertContains($expected, $types, $expected);
        }
    }

    public function test_a_lesson_whose_teacher_is_not_the_assigned_one_is_an_error_and_a_teacher_over_the_load_is_a_warning(): void
    {
        $this->place($this->a, 0, 1, $this->math, $this->omar); // the section's Math is Sara's
        DB::table('school_settings')->insert(['key' => 'max_teacher_load', 'value' => json_encode(1), 'created_at' => now(), 'updated_at' => now()]);
        $issues = $this->getJson('/api/timetable/check')->json('data.issues');
        $this->assertContains('lesson_without_assignment', array_column($issues, 'type'));
        $this->assertContains('teacher_overloaded', array_column($issues, 'type'));
        $this->assertSame('error', collect($issues)->firstWhere('type', 'lesson_without_assignment')['severity']);
    }

    public function test_a_clean_timetable_has_no_errors_and_the_screen_data_is_for_the_admin_only(): void
    {
        foreach ([[0, 1], [0, 2]] as [$d, $n]) {
            $this->place($this->a, $d, $n, $this->math, $this->sara);
        }
        $this->assertSame(0, $this->getJson('/api/timetable/check')->json('data.counts.error'));

        Sanctum::actingAs($this->sara);
        $this->getJson('/api/timetable/overview')->assertForbidden();
        $this->getJson('/api/timetable/check')->assertForbidden();
    }

    public function test_the_full_timetable_downloads_as_an_excel_sheet(): void
    {
        $this->a->update(['homeroom_teacher_id' => $this->omar->id]);
        $this->place($this->a, 0, 1, $this->math, $this->sara);
        $response = $this->get('/api/timetable/export')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Read the file back: the school's form, with the homeroom teacher, the section, and the lesson in its place.
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($response->baseResponse->getFile()->getPathname());
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn ($c) => $c->getValue(), $row->getCells());
            }
            break;
        }
        $reader->close();
        $flat = array_merge(...$rows);
        $this->assertContains('Lesson Timetable', $flat);
        $this->assertContains('Omar', $flat);          // the homeroom teacher of 7-A
        $this->assertContains('7-A', $flat);
        $this->assertContains('Math', $flat);
        $this->assertContains('Sara', $flat);
        $this->assertContains('1st', $flat);           // the period names of the form
    }
}
