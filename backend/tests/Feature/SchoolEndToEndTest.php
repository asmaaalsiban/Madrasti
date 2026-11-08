<?php

namespace Tests\Feature;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Scheduling\Models\TimetableEntry;
use App\Modules\Scheduling\Services\PeriodSetup;
use Tests\Feature\Academic\AcademicTestCase;

/**
 * A whole school, set up the way the admin does it through the API: kindergarten and grades 1-3 with class teachers,
 * grades 4-6 with subject teachers (one teacher with two subjects for different grades), and two secondary tracks.
 * Then the assignment, the timetable, the checker, both Excel forms and the cover plan.
 */
class SchoolEndToEndTest extends AcademicTestCase
{
    private AcademicYear $year;

    /** @var array<string, Subject> */
    private array $s = [];

    /** @var array<string, GradeLevel> */
    private array $g = [];

    /** @var list<Classroom> */
    private array $rooms = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::factory()->create(['name' => '2026/2027', 'is_current' => true]);
        app(PeriodSetup::class)->apply(['days' => [0, 1, 2, 3, 4], 'periods_per_day' => 7, 'starts_at' => '07:45', 'period_minutes' => 45, 'gap_minutes' => 5]);
        foreach (['arabic' => 'Arabic', 'math' => 'Math', 'science' => 'Science', 'english' => 'English', 'computer' => 'Computer', 'islamic' => 'Islamic', 'sport' => 'Sport', 'art' => 'Art'] as $k => $n) {
            $this->s[$k] = Subject::factory()->create(['code' => strtoupper(substr($k, 0, 3)), 'name_en' => $n, 'name_ar' => $n]);
        }
        foreach ([['kg', 1], ['g1', 2], ['g2', 3], ['g3', 4], ['g4', 5], ['g5', 6], ['health', 11], ['business', 11]] as [$k, $order]) {
            $this->g[$k] = GradeLevel::factory()->create(['code' => $k, 'name_en' => $k, 'sort_order' => $order, 'track' => in_array($k, ['health', 'business'], true) ? $k : null]);
        }
        $this->actAsAdmin();
    }

    /** @var list<array{grade_level_id: int, subject_id: int, weekly_periods: int}> */
    private array $cells = [];

    /**
     * Adds a plan to the curriculum and saves the WHOLE curriculum, as the curriculum page does (it replaces what was there).
     *
     * @param  array<string, int>  $plan  subject key => periods a week
     */
    private function curriculum(array $grades, array $plan): void
    {
        foreach ($grades as $grade) {
            foreach ($plan as $subject => $periods) {
                $this->cells[$this->g[$grade]->id.'-'.$subject] = ['grade_level_id' => $this->g[$grade]->id, 'subject_id' => $this->s[$subject]->id, 'weekly_periods' => $periods];
            }
        }
        $this->putJson('/api/curriculum', ['cells' => array_values($this->cells), 'periods_per_week' => 35])->assertOk();
    }

    private function staff(string $name, array $extra = [])
    {
        static $n = 0;
        $n++;

        return $this->postJson('/api/staff', ['name' => $name, 'email' => "t$n@school.test", 'staff_type' => 'teacher', ...$extra])->assertCreated()->json('data.id');
    }

    private function section(string $grade, string $name, ?int $homeroom = null): Classroom
    {
        return $this->rooms[] = Classroom::factory()->create(['name' => $name, 'grade_level_id' => $this->g[$grade]->id, 'academic_year_id' => $this->year->id, 'homeroom_teacher_id' => $homeroom]);
    }

    public function test_a_whole_school_from_set_up_to_the_exports(): void
    {
        // The curriculum: the early grades by a class teacher except English and Computer; grades 4-5 by subject teachers; tracks.
        $early = ['arabic' => 8, 'math' => 6, 'science' => 3, 'islamic' => 3, 'sport' => 2, 'art' => 2, 'english' => 4, 'computer' => 2];
        $this->curriculum(['kg', 'g1', 'g2', 'g3'], $early);
        $this->curriculum(['g4', 'g5'], ['arabic' => 6, 'math' => 5, 'science' => 4, 'islamic' => 3, 'sport' => 2, 'english' => 5, 'computer' => 2]);
        foreach (['kg', 'g1', 'g2', 'g3'] as $grade) {
            $this->putJson('/api/curriculum/class-teachers', ['grade_level_id' => $this->g[$grade]->id, 'class_teacher_grade' => true, 'specialist_subject_ids' => [$this->s['english']->id, $this->s['computer']->id]])->assertOk();
        }
        // Setting the curriculum again must not lose what was marked for specialists.
        $this->curriculum(['g1'], $early);
        $this->assertTrue((bool) collect($this->getJson('/api/curriculum')->json('data.cells'))->first(fn ($c) => $c['grade_level_id'] === $this->g['g1']->id && $c['subject_id'] === $this->s['english']->id)['specialist']);

        // Class teachers: chosen as the homeroom teacher of their section; nothing else to tick.
        foreach (['kg', 'g1', 'g2', 'g3'] as $grade) {
            foreach (['A', 'B'] as $letter) {
                $id = $this->staff("Class $grade$letter", ['is_class_teacher' => true]);
                $this->section($grade, "$grade-$letter", $id);
            }
        }
        // Subject teachers: one teacher teaches Math in grades 4-5 and Computer in grade 4 only (a second subject for fewer grades).
        $english = $this->staff('English teacher', ['subject_id' => $this->s['english']->id]);
        $computer = $this->staff('Computer teacher', ['subject_id' => $this->s['computer']->id]);
        $mathAndComputer = $this->staff('Math+Computer', ['subject_id' => $this->s['math']->id, 'extra_subjects' => [['subject_id' => $this->s['computer']->id, 'grade_ids' => [$this->g['g4']->id]]]]);
        foreach (['arabic', 'science', 'islamic', 'sport'] as $subject) {
            $this->staff(ucfirst($subject).' teacher', ['subject_id' => $this->s[$subject]->id]);
        }
        $this->staff('Math teacher 2', ['subject_id' => $this->s['math']->id]);
        $this->staff('Arabic teacher 2', ['subject_id' => $this->s['arabic']->id]);
        foreach (['g4', 'g5'] as $grade) {
            foreach (['A', 'B'] as $letter) {
                $this->section($grade, "$grade-$letter");
            }
        }
        // Secondary tracks: their own curriculum, sections and a teacher.
        $this->curriculum(['health', 'business'], ['arabic' => 4, 'math' => 4, 'english' => 5]);
        $this->section('health', 'H-A');
        $this->section('business', 'B-A');

        // The first automatic assignment: a preview, then for real.
        $preview = $this->postJson('/api/teaching-assignments/auto', ['max_load' => 28, 'dry_run' => true])->assertOk()->json('data');
        $this->assertSame([], $preview['class_teacher_problems'], 'every class-teacher section has its class teacher');
        $applied = $this->postJson('/api/teaching-assignments/auto', ['max_load' => 28])->assertOk()->json('data');
        $this->assertSame([], $applied['class_teacher_problems']);

        // Early grades: the class teacher has every subject but English and Computer; those have specialists.
        $room = Classroom::where('name', 'g1-A')->firstOrFail();
        $teacherOf = fn (string $subject) => TeachingAssignment::where('classroom_id', $room->id)->where('subject_id', $this->s[$subject]->id)->value('teacher_id');
        $this->assertSame($room->homeroom_teacher_id, $teacherOf('arabic'));
        $this->assertSame($room->homeroom_teacher_id, $teacherOf('math'));
        $this->assertSame($english, $teacherOf('english'));
        $this->assertContains($teacherOf('computer'), [$computer, $mathAndComputer]);
        // Running it again changes nothing.
        $this->assertSame(0, $this->postJson('/api/teaching-assignments/auto', ['max_load' => 28])->json('data.assigned'));

        // This school has one English teacher for 34 periods: what cannot be covered is REPORTED (the shortage), never hidden or silently dropped.
        $this->assertSame(['English'], collect($applied['shortages'])->pluck('subject')->unique()->values()->all());
        $this->assertGreaterThan(0, $applied['shortages'][0]['teachers_missing']);
        $this->assertSame(['English'], collect($applied['unassigned'])->pluck('subject')->unique()->values()->all());

        // The timetable.
        $gen = $this->postJson('/api/timetable/generate', ['seed' => 7])->assertOk()->json('data');
        $placed = TimetableEntry::count();
        $this->assertGreaterThan(0, $placed);
        $this->assertSame(0, $this->getJson('/api/timetable/check')->json('data.counts.error'), 'the generator never breaks a rule');
        // Nobody is in two places: no teacher has two lessons in a period (joint lessons were not used).
        $this->assertSame(0, TimetableEntry::query()->selectRaw('teacher_id, period_id, count(*) c')->groupBy('teacher_id', 'period_id')->havingRaw('count(*) > 1')->get()->count());
        // A class teacher's week is in their own section.
        $classTeacher = $room->homeroom_teacher_id;
        $this->assertSame([$room->id], TimetableEntry::where('teacher_id', $classTeacher)->pluck('classroom_id')->unique()->values()->all());
        $this->assertSame(30, TimetableEntry::where('classroom_id', $room->id)->count()); // the whole plan: 8+6+3+3+2+2 by the class teacher, 4 English and 2 Computer by the specialists

        // Full timetable, both Excel forms, the checker's list.
        $overview = $this->getJson('/api/timetable/overview')->assertOk()->json('data');
        $this->assertCount(count($this->rooms), $overview['classrooms']);
        $this->get('/api/timetable/export')->assertOk();
        $this->get('/api/teaching-assignments/export')->assertOk();
        $this->getJson('/api/staff')->assertOk();

        // Cover: the class teacher of g1-A is away on a Sunday: every lesson of that day gets suggestions, and one can be chosen.
        $plan = $this->getJson("/api/cover/plan?teacher_id=$classTeacher&date=2026-11-08")->assertOk()->json('data'); // a Sunday
        $this->assertNotEmpty($plan);
        $first = $plan[0];
        if ($first['suggestions'] !== []) {
            $this->postJson('/api/cover', ['date' => '2026-11-08', 'period_id' => $first['period']['id'], 'classroom_id' => $first['classroom']['id'], 'cover_teacher_id' => $first['suggestions'][0]['teacher']['id']])->assertCreated();
        }
        $this->assertSame($placed, $gen['placed']);
    }
}
