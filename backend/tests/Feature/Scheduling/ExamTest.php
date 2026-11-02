<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\Term;
use App\Modules\Scheduling\Models\ExamSession;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Services\PeriodSetup;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class ExamTest extends AcademicTestCase
{
    private Term $term;

    private GradeLevel $g1;

    private GradeLevel $g2;

    private Classroom $a;

    private Classroom $b;

    private Classroom $c;

    /** @var list<User> */
    private array $teachers = [];

    private Subject $math;

    private Subject $arabic;

    private Subject $science;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true]);
        $this->term = Term::create(['academic_year_id' => $year->id, 'name' => 'Term 1', 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-31']);
        $this->g1 = GradeLevel::factory()->create(['sort_order' => 1]);
        $this->g2 = GradeLevel::factory()->create(['sort_order' => 2]);
        $this->a = Classroom::factory()->create(['name' => '1-A', 'grade_level_id' => $this->g1->id, 'academic_year_id' => $year->id]);
        $this->b = Classroom::factory()->create(['name' => '1-B', 'grade_level_id' => $this->g1->id, 'academic_year_id' => $year->id]);
        $this->c = Classroom::factory()->create(['name' => '2-A', 'grade_level_id' => $this->g2->id, 'academic_year_id' => $year->id]);
        [$this->math, $this->arabic, $this->science] = [Subject::factory()->create(), Subject::factory()->create(), Subject::factory()->create()];
        foreach ([[$this->g1, $this->math], [$this->g1, $this->arabic], [$this->g1, $this->science], [$this->g2, $this->math], [$this->g2, $this->arabic]] as [$g, $s]) {
            DB::table('grade_level_subjects')->insert(['grade_level_id' => $g->id, 'subject_id' => $s->id, 'weekly_periods' => 3, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (range(1, 6) as $i) {
            $this->teachers[] = User::factory()->staff(StaffType::Teacher)->create(['name' => "Teacher $i"]);
        }
        // Teacher 1 teaches maths to both sections of grade 1.
        foreach ([$this->a, $this->b] as $room) {
            DB::table('classroom_subject_teacher')->insert(['classroom_id' => $room->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teachers[0]->id, 'weekly_periods' => 3, 'created_at' => now(), 'updated_at' => now()]);
        }
        // The school day: periods 1-7 on Sunday to Thursday (period 2 is 08:35-09:20, period 3 09:25-10:10).
        app(PeriodSetup::class)->apply(['days' => [0, 1, 2, 3, 4], 'periods_per_day' => 7, 'starts_at' => '07:45', 'period_minutes' => 45, 'gap_minutes' => 5, 'long_break_after' => 3, 'long_break_minutes' => 20]);
        // Sections 1-A and 1-B have their own teachers in periods 2 and 3 every day; 2-A has a teacher in period 2 only.
        $lessons = [[$this->a, 2, 1], [$this->a, 3, 2], [$this->b, 2, 3], [$this->b, 3, 4], [$this->c, 2, 5]];
        foreach (Period::whereIn('number', [2, 3])->get() as $period) {
            foreach ($lessons as [$room, $number, $teacher]) {
                if ($period->number === $number) {
                    DB::table('timetable_entries')->insert(['academic_year_id' => $room->academic_year_id, 'classroom_id' => $room->id, 'period_id' => $period->id, 'subject_id' => $this->arabic->id, 'teacher_id' => $this->teachers[$teacher]->id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        $this->actAsAdmin();
    }

    private function body(array $extra = []): array
    {
        return ['term_id' => $this->term->id, 'from' => '2027-01-03', 'to' => '2027-01-14', 'first_period' => 2, 'periods_per_exam' => 2, 'slots_per_day' => 1, 'grade_per_day' => 1, ...$extra];
    }

    public function test_each_grade_sits_one_subject_per_day_and_all_its_sections_sit_it_together(): void
    {
        $this->postJson('/api/exams/generate', $this->body())->assertOk()->assertJsonPath('data.sessions', 8)->assertJsonPath('data.grades', 2); // grade 1: 3 subjects x 2 sections, grade 2: 2 subjects
        $rows = ExamSession::all();
        foreach ([$this->g1, $this->g2] as $g) {
            $mine = $rows->whereIn('classroom_id', Classroom::where('grade_level_id', $g->id)->pluck('id'));
            $this->assertSame($mine->pluck('exam_date')->map->toDateString()->unique()->count(), $mine->pluck('subject_id')->unique()->count()); // one subject a day
        }
        $math = $rows->where('subject_id', $this->math->id)->whereIn('classroom_id', [$this->a->id, $this->b->id]);
        $this->assertSame(1, $math->pluck('exam_date')->map->toDateString()->unique()->count()); // both sections of grade 1 together
        $this->assertSame('08:35:00', $math->first()->starts_at); // the school's own times for periods 2 to 3
        $this->assertSame('10:10:00', $math->first()->ends_at);
        $this->assertSame([2, 3], [$math->first()->period_from, $math->first()->period_to]);
        // Only working days (Sunday to Thursday): no Friday (8th) or Saturday (9th).
        $this->assertEmpty($rows->filter(fn ($s) => in_array($s->exam_date->toDateString(), ['2027-01-08', '2027-01-09']))->all());
    }

    public function test_the_teachers_who_already_have_the_section_in_those_periods_watch_the_exam(): void
    {
        $this->postJson('/api/exams/generate', $this->body())->assertOk();
        $watchers = fn (Classroom $room) => ExamSession::where('classroom_id', $room->id)->with('invigilators')->get()->map(fn ($s) => $s->invigilators->pluck('id')->sort()->values()->all())->unique()->values()->all();
        // 1-A: the teachers of periods 2 and 3 (Teacher 2 and 3); 1-B: Teacher 4 and 5.
        $this->assertSame([[$this->teachers[1]->id, $this->teachers[2]->id]], $watchers($this->a));
        $this->assertSame([[$this->teachers[3]->id, $this->teachers[4]->id]], $watchers($this->b));
    }

    public function test_a_period_with_no_teacher_on_the_timetable_gets_a_free_teacher_or_a_warning(): void
    {
        $r = $this->postJson('/api/exams/generate', $this->body(['grade_level_ids' => [$this->g2->id]]))->assertOk()->json('data');
        $this->assertSame([], $r['warnings']);
        $exam = ExamSession::where('classroom_id', $this->c->id)->with('invigilators')->first();
        $ids = $exam->invigilators->pluck('id')->all();
        $this->assertContains($this->teachers[5]->id, $ids); // period 2 is hers
        $this->assertCount(2, $ids); // period 3 has none on the timetable: a stand-in who has no lesson then
        $standIn = collect($ids)->reject(fn ($id) => $id === $this->teachers[5]->id)->first();
        $this->assertNotContains($standIn, [$this->teachers[1]->id, $this->teachers[2]->id, $this->teachers[3]->id, $this->teachers[4]->id]); // not teaching another section in period 3
        // Without stand-ins the missing period is only a warning.
        $off = $this->postJson('/api/exams/generate', $this->body(['grade_level_ids' => [$this->g2->id], 'fill_missing' => false]))->assertOk()->json('data.warnings');
        $this->assertNotEmpty($off);
        $this->assertCount(1, ExamSession::where('classroom_id', $this->c->id)->first()->invigilators);
    }

    public function test_an_exam_that_needs_more_periods_than_the_day_has_is_refused_and_too_few_days_too(): void
    {
        $this->postJson('/api/exams/generate', $this->body(['first_period' => 7, 'periods_per_exam' => 2]))->assertUnprocessable()->assertJsonValidationErrors('from'); // no 7th and 8th period
        $this->postJson('/api/exams/generate', $this->body(['from' => '2027-01-03', 'to' => '2027-01-04']))->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->postJson('/api/exams/generate', $this->body(['from' => '2027-01-08', 'to' => '2027-01-09']))->assertUnprocessable()->assertJsonValidationErrors('from'); // Friday and Saturday only
        $this->postJson('/api/exams/generate', $this->body(['to' => '2027-01-04', 'periods_per_exam' => 1, 'slots_per_day' => 2, 'grade_per_day' => 2]))->assertOk(); // two exams a day (periods 2 and 3): 3 subjects fit in 2 days
    }

    public function test_a_dry_run_saves_nothing_and_a_second_run_replaces_the_first(): void
    {
        $this->postJson('/api/exams/generate', $this->body(['dry_run' => true]))->assertOk()->assertJsonPath('data.dry_run', true);
        $this->assertSame(0, ExamSession::count());
        $this->postJson('/api/exams/generate', $this->body())->assertOk();
        $this->postJson('/api/exams/generate', $this->body(['first_period' => 3, 'periods_per_exam' => 1, 'exclude_dates' => ['2027-01-03']]))->assertOk();
        $this->assertSame(8, ExamSession::count());
        $this->assertSame(0, ExamSession::whereDate('exam_date', '2027-01-03')->count());
        $this->assertSame(0, ExamSession::where('starts_at', '08:35:00')->count()); // now period 3 only (09:25)
        $this->postJson('/api/exams/generate', $this->body(['grade_level_ids' => [$this->g2->id]]))->assertOk()->assertJsonPath('data.sessions', 2); // only grade 2 is redone
        $this->assertSame(8, ExamSession::count());
    }

    public function test_an_exam_can_be_moved_to_other_periods_or_times_but_not_onto_a_clash(): void
    {
        $this->postJson('/api/exams/generate', $this->body())->assertOk();
        $math = ExamSession::where('classroom_id', $this->a->id)->where('subject_id', $this->math->id)->first();
        $other = ExamSession::where('classroom_id', $this->a->id)->where('subject_id', $this->arabic->id)->first();
        $this->putJson("/api/exams/{$math->id}", ['exam_date' => $other->exam_date->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('exam_date'); // same section, same periods
        $this->putJson("/api/exams/{$math->id}", ['exam_date' => '2027-01-13', 'starts_at' => '10:00', 'ends_at' => '09:00'])->assertUnprocessable();
        $this->putJson("/api/exams/{$math->id}", ['exam_date' => '2027-01-14', 'period_from' => 8, 'period_to' => 8])->assertUnprocessable()->assertJsonValidationErrors('period_from');

        // Moved to the periods 1 to 1 of another day: the school's times, and whoever has 1-A then (nobody: no lesson in period 1) watches.
        $r = $this->putJson("/api/exams/{$math->id}", ['exam_date' => '2027-01-14', 'period_from' => 2, 'period_to' => 2])->assertOk()->json('data');
        $this->assertSame(['08:35', '09:20', 2, 2], [$r['starts_at'], $r['ends_at'], $r['period_from'], $r['period_to']]);
        $this->assertSame([$this->teachers[1]->id], collect($r['invigilators'])->pluck('id')->all()); // the period-2 teacher of 1-A
        // A time set by hand is no longer tied to periods.
        $r = $this->putJson("/api/exams/{$math->id}", ['starts_at' => '11:00', 'ends_at' => '12:30'])->assertOk()->json('data');
        $this->assertNull($r['period_from']);

        // A teacher already watching another room then cannot be put in this one.
        $busy = ExamSession::where('id', '!=', $math->id)->first();
        $busy->invigilators()->sync([$this->teachers[5]->id]);
        $busy->update(['exam_date' => '2027-01-14', 'starts_at' => '11:30:00', 'ends_at' => '12:00:00']);
        $this->putJson("/api/exams/{$math->id}", ['invigilator_ids' => [$this->teachers[5]->id]])->assertUnprocessable()->assertJsonValidationErrors('invigilator_ids');
        $this->putJson("/api/exams/{$math->id}", ['invigilator_ids' => [$this->teachers[0]->id]])->assertOk();
        $this->deleteJson("/api/exams/{$math->id}")->assertNoContent();
        $this->assertNull(ExamSession::find($math->id));
    }

    public function test_students_parents_and_teachers_see_their_own_exams_and_get_told(): void
    {
        $student = Student::create(['student_number' => 'E-1', 'name_ar' => 'علي', 'name_en' => 'Ali']);
        $studentUser = User::factory()->create(['role' => Role::Student]);
        $student->update(['user_id' => $studentUser->id]);
        Enrollment::create(['student_id' => $student->id, 'classroom_id' => $this->a->id, 'academic_year_id' => $this->a->academic_year_id]);
        $parent = User::factory()->create(['role' => Role::Guardian]);
        $student->guardians()->attach($parent->id);
        $stranger = User::factory()->create(['role' => Role::Guardian]);

        $this->postJson('/api/exams/generate', $this->body())->assertOk();
        $this->assertGreaterThan(0, DB::table('notifications')->where('notifiable_id', $studentUser->id)->where('data', 'like', '%exam_schedule%')->count());
        $this->assertGreaterThan(0, DB::table('notifications')->where('notifiable_id', $parent->id)->where('data', 'like', '%exam_schedule%')->count());

        Sanctum::actingAs($studentUser);
        $mine = $this->getJson('/api/exams/me')->assertOk()->json('data');
        $this->assertCount(3, $mine); // grade 1 has three subjects
        $this->assertSame(['1-A'], collect($mine)->pluck('classroom.name')->unique()->all());
        Sanctum::actingAs($parent);
        $this->getJson("/api/students/{$student->id}/exams")->assertOk()->assertJsonCount(3, 'data');
        Sanctum::actingAs($stranger);
        $this->getJson("/api/students/{$student->id}/exams")->assertForbidden();

        $watcher = ExamSession::with('invigilators')->get()->first(fn ($s) => $s->invigilators->isNotEmpty())->invigilators->first();
        Sanctum::actingAs(User::find($watcher->id));
        $teacher = $this->getJson('/api/exams/me')->assertOk()->json('data');
        $this->assertNotEmpty($teacher['watching']);
        $this->assertGreaterThan(0, DB::table('notifications')->where('notifiable_id', $watcher->id)->where('data', 'like', '%exam_duty%')->count());
        // The maths teacher sees the maths exams of their sections.
        Sanctum::actingAs($this->teachers[0]);
        $this->assertCount(2, $this->getJson('/api/exams/me')->json('data.teaching'));
    }

    public function test_only_the_principal_builds_and_edits_the_timetable_and_the_list_is_filtered(): void
    {
        $this->postJson('/api/exams/generate', $this->body())->assertOk();
        $this->getJson('/api/exams?term_id='.$this->term->id.'&grade_level_id='.$this->g2->id)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/exams')->assertOk()->assertJsonCount(8, 'data'); // defaults to the running or next term
        foreach ([$this->teachers[0], User::factory()->create(['role' => Role::Guardian])] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/exams')->assertForbidden();
            $this->postJson('/api/exams/generate', $this->body())->assertForbidden();
            $this->deleteJson('/api/exams/'.ExamSession::value('id'))->assertForbidden();
        }
    }
}
