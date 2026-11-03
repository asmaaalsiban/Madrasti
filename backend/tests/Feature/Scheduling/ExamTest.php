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
use Illuminate\Support\Carbon;
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
        return ['term_id' => $this->term->id, 'kind' => 'monthly', 'round' => 1, 'from' => '2027-01-03', 'to' => '2027-01-14', 'first_period' => 2, 'periods_per_exam' => 2, 'slots_per_day' => 1, 'grade_per_day' => 1, ...$extra];
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

    private function finalBody(array $extra = []): array
    {
        return ['term_id' => $this->term->id, 'kind' => 'final', 'from' => '2027-01-17', 'to' => '2027-01-28', 'start_time' => '09:00', 'duration_minutes' => 120, 'gap_minutes' => 30, 'slots_per_day' => 1, 'grade_per_day' => 1, 'invigilators' => 2, ...$extra];
    }

    public function test_final_exams_have_their_own_times_and_free_teachers_watch(): void
    {
        $this->postJson('/api/exams/generate', $this->finalBody())->assertOk()->assertJsonPath('data.sessions', 8)->assertJsonPath('data.warnings', []);
        $rows = ExamSession::where('kind', 'final')->with('invigilators')->get();
        $this->assertSame(['09:00:00'], $rows->pluck('starts_at')->unique()->values()->all());
        $this->assertSame(['11:00:00'], $rows->pluck('ends_at')->unique()->values()->all());
        $this->assertNull($rows->first()->period_from); // not tied to lesson periods
        $this->assertSame(0, $rows->first()->round);
        foreach ($rows as $s) {
            $this->assertCount(2, $s->invigilators);
        }
        // Maths of grade 1 is taught by Teacher 1: they do not watch their own subject's exam.
        $math = $rows->where('subject_id', $this->math->id)->whereIn('classroom_id', [$this->a->id, $this->b->id]);
        foreach ($math as $s) {
            $this->assertFalse($s->invigilators->contains('id', $this->teachers[0]->id));
        }
        // Nobody watches two rooms at the same time.
        foreach ($rows->groupBy(fn ($s) => $s->exam_date->toDateString().$s->starts_at) as $group) {
            $ids = $group->flatMap(fn ($s) => $s->invigilators->pluck('id'));
            $this->assertSame($ids->count(), $ids->unique()->count());
        }
        // Too few free teachers for the rooms is only a warning.
        $warn = $this->postJson('/api/exams/generate', $this->finalBody(['invigilators' => 5]))->assertOk()->json('data.warnings');
        $this->assertNotEmpty($warn);
    }

    public function test_monthly_rounds_and_the_final_exams_live_side_by_side_and_each_run_only_replaces_its_own(): void
    {
        $this->postJson('/api/exams/generate', $this->body(['round' => 1]))->assertOk();
        $this->postJson('/api/exams/generate', $this->body(['round' => 2, 'from' => '2027-02-01', 'to' => '2027-02-18']))->assertOk();
        $this->postJson('/api/exams/generate', $this->finalBody(['from' => '2027-03-01', 'to' => '2027-03-18']))->assertOk();
        $this->assertSame(24, ExamSession::count()); // 8 + 8 + 8: the same subjects in each round
        $this->postJson('/api/exams/generate', $this->body(['round' => 1, 'first_period' => 3, 'periods_per_exam' => 1]))->assertOk(); // redo round 1 only
        $this->assertSame(24, ExamSession::count());
        $this->assertSame(8, ExamSession::where('kind', 'monthly')->where('round', 2)->count());
        $this->assertSame(8, ExamSession::where('kind', 'final')->count());
        $this->assertSame(0, ExamSession::where('kind', 'monthly')->where('round', 1)->where('period_from', 2)->count());

        $this->getJson('/api/exams?term_id='.$this->term->id.'&kind=final')->assertOk()->assertJsonCount(8, 'data')->assertJsonPath('data.0.kind', 'final');
        $this->getJson('/api/exams?term_id='.$this->term->id.'&kind=monthly&round=2')->assertOk()->assertJsonCount(8, 'data');
        $this->getJson('/api/exams?term_id='.$this->term->id)->assertOk()->assertJsonCount(24, 'data');
    }

    public function test_a_final_exam_is_moved_by_time_not_by_periods_and_each_kind_needs_its_own_settings(): void
    {
        $this->postJson('/api/exams/generate', $this->finalBody())->assertOk();
        $final = ExamSession::where('kind', 'final')->first();
        $this->putJson("/api/exams/{$final->id}", ['period_from' => 2])->assertUnprocessable()->assertJsonValidationErrors('period_from');
        $this->putJson("/api/exams/{$final->id}", ['starts_at' => '10:00', 'ends_at' => '12:00'])->assertOk()->assertJsonPath('data.starts_at', '10:00');
        $this->postJson('/api/exams/generate', ['term_id' => $this->term->id, 'kind' => 'final', 'from' => '2027-01-17', 'to' => '2027-01-28', 'slots_per_day' => 1, 'grade_per_day' => 1])->assertUnprocessable()->assertJsonValidationErrors(['start_time', 'duration_minutes', 'invigilators']);
        $this->postJson('/api/exams/generate', ['term_id' => $this->term->id, 'kind' => 'monthly', 'from' => '2027-01-17', 'to' => '2027-01-28', 'slots_per_day' => 1, 'grade_per_day' => 1])->assertUnprocessable()->assertJsonValidationErrors(['round', 'first_period', 'periods_per_exam']);
    }

    /** True when any teacher is in two exam rooms at overlapping moments (watching only the periods they were given counts). */
    private function doubleBooked(): bool
    {
        $slots = [];
        foreach (ExamSession::with('invigilators')->get() as $s) {
            foreach ($s->invigilators as $u) {
                $from = $s->period_from !== null && $u->pivot->period_from !== null ? Period::where('day_of_week', $s->exam_date->dayOfWeek)->where('number', $u->pivot->period_from)->value('starts_at') : $s->starts_at;
                $to = $s->period_from !== null && $u->pivot->period_to !== null ? Period::where('day_of_week', $s->exam_date->dayOfWeek)->where('number', $u->pivot->period_to)->value('ends_at') : $s->ends_at;
                $slots[$u->id][] = [$s->exam_date->toDateString(), $from, $to];
            }
        }
        foreach ($slots as $list) {
            foreach ($list as $i => $a) {
                foreach (array_slice($list, $i + 1) as $b) {
                    if ($a[0] === $b[0] && $a[1] < $b[2] && $a[2] > $b[1]) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function test_a_teacher_who_has_two_sections_in_consecutive_periods_watches_each_in_its_own_period(): void
    {
        // Teacher 2 now teaches 1-A in period 2 and 1-B in period 3 (instead of Teacher 4 there).
        DB::table('timetable_entries')->where('classroom_id', $this->b->id)->whereIn('period_id', Period::where('number', 3)->pluck('id'))->update(['teacher_id' => $this->teachers[1]->id]);
        DB::table('timetable_entries')->where('classroom_id', $this->a->id)->whereIn('period_id', Period::where('number', 2)->pluck('id'))->update(['teacher_id' => $this->teachers[1]->id]);
        $this->postJson('/api/exams/generate', $this->body())->assertOk()->assertJsonPath('data.warnings', []);
        $a = ExamSession::where('classroom_id', $this->a->id)->first()->invigilators->firstWhere('id', $this->teachers[1]->id);
        $b = ExamSession::where('classroom_id', $this->b->id)->first()->invigilators->firstWhere('id', $this->teachers[1]->id);
        $this->assertSame([2, 2], [$a->pivot->period_from, $a->pivot->period_to]); // 1-A during period 2 only
        $this->assertSame([3, 3], [$b->pivot->period_from, $b->pivot->period_to]); // 1-B during period 3 only
        $this->assertFalse($this->doubleBooked());
        $list = $this->getJson('/api/exams?term_id='.$this->term->id.'&kind=monthly')->json('data');
        $mine = collect($list)->firstWhere('classroom.name', '1-B')['invigilators'];
        $this->assertSame(3, collect($mine)->firstWhere('id', $this->teachers[1]->id)['period_from']);
    }

    public function test_final_exams_generated_grade_by_grade_never_put_a_teacher_in_two_rooms_at_once(): void
    {
        $this->postJson('/api/exams/generate', $this->finalBody(['grade_level_ids' => [$this->g1->id], 'invigilators' => 3]))->assertOk(); // 2 rooms x 3 = all 6 teachers on the first day
        $r = $this->postJson('/api/exams/generate', $this->finalBody(['grade_level_ids' => [$this->g2->id], 'invigilators' => 3]))->assertOk()->json('data'); // same day and time: nobody is free
        $this->assertNotEmpty($r['warnings']);
        $this->assertFalse($this->doubleBooked());
    }

    public function test_a_stand_in_for_a_period_without_a_teacher_is_not_already_watching_elsewhere(): void
    {
        $this->postJson('/api/exams/generate', $this->finalBody(['from' => '2027-01-03', 'to' => '2027-01-06', 'start_time' => '08:35', 'duration_minutes' => 90, 'invigilators' => 1, 'grade_level_ids' => [$this->g1->id]]))->assertOk();
        // Monthly for grade 2 (period 3 has no timetable teacher): the stand-in must not be someone watching a final exam at that time.
        $this->postJson('/api/exams/generate', $this->body(['from' => '2027-01-03', 'to' => '2027-01-04', 'grade_level_ids' => [$this->g2->id], 'periods_per_exam' => 1, 'first_period' => 3, 'slots_per_day' => 1, 'round' => 1]))->assertOk();
        $this->assertFalse($this->doubleBooked());
    }

    public function test_all_the_exams_of_a_type_can_be_deleted_in_one_go(): void
    {
        $this->postJson('/api/exams/generate', $this->body(['round' => 1]))->assertOk();
        $this->postJson('/api/exams/generate', $this->body(['round' => 2, 'from' => '2027-02-01', 'to' => '2027-02-18']))->assertOk();
        $this->postJson('/api/exams/generate', $this->finalBody(['from' => '2027-03-01', 'to' => '2027-03-18']))->assertOk();
        $q = fn (array $extra) => '/api/exams?'.http_build_query(['term_id' => $this->term->id] + $extra);
        $this->deleteJson($q(['kind' => 'monthly', 'round' => 2, 'grade_level_id' => $this->g2->id]))->assertOk()->assertJsonPath('data.deleted', 2);
        $this->assertSame(22, ExamSession::count());
        $this->deleteJson($q(['kind' => 'monthly']))->assertOk()->assertJsonPath('data.deleted', 14); // both monthly rounds, the finals stay
        $this->assertSame(8, ExamSession::count());
        $this->assertSame(8, ExamSession::where('kind', 'final')->count());
        $this->deleteJson($q([]))->assertOk()->assertJsonPath('data.deleted', 8);
        $this->assertSame(0, ExamSession::count());
        $this->deleteJson('/api/exams')->assertUnprocessable(); // a term is required
    }

    public function test_all_the_exams_move_together_by_days_by_periods_or_by_start_time(): void
    {
        $this->postJson('/api/exams/generate', $this->body())->assertOk();
        $first = ExamSession::orderBy('exam_date')->first()->exam_date->toDateString();
        $q = '/api/exams?'.http_build_query(['term_id' => $this->term->id, 'kind' => 'monthly', 'round' => 1]);
        $this->putJson($q, ['shift_days' => 7])->assertOk()->assertJsonPath('data.changed', 8);
        $this->assertSame(Carbon::parse($first)->addDays(7)->toDateString(), ExamSession::orderBy('exam_date')->first()->exam_date->toDateString());
        $this->assertEmpty(ExamSession::all()->filter(fn ($s) => in_array($s->exam_date->dayOfWeek, [5, 6]))->all()); // still only working days

        // To periods 3 only: times follow the school day and the watchers become the period-3 teachers.
        $this->putJson($q, ['period_from' => 3, 'period_to' => 3])->assertOk();
        $a = ExamSession::where('classroom_id', $this->a->id)->first()->load('invigilators');
        $this->assertSame([3, 3], [$a->period_from, $a->period_to]);
        $this->assertSame('09:25:00', $a->starts_at);
        $this->assertSame([$this->teachers[2]->id], $a->invigilators->pluck('id')->all());

        $this->postJson('/api/exams/generate', $this->finalBody(['from' => '2027-03-01', 'to' => '2027-03-18']))->assertOk();
        $f = '/api/exams?'.http_build_query(['term_id' => $this->term->id, 'kind' => 'final']);
        $this->putJson($f, ['start_time' => '10:30'])->assertOk()->assertJsonPath('data.changed', 8);
        $final = ExamSession::where('kind', 'final')->first();
        $this->assertSame(['10:30:00', '12:30:00'], [$final->starts_at, $final->ends_at]); // the 2 hours are kept
    }

    public function test_a_bulk_change_that_would_clash_or_land_on_a_day_off_changes_nothing(): void
    {
        $this->postJson('/api/exams/generate', $this->body(['round' => 1]))->assertOk();
        $this->postJson('/api/exams/generate', $this->body(['round' => 2, 'from' => '2027-01-17', 'to' => '2027-01-28']))->assertOk();
        $before = ExamSession::orderBy('id')->get()->map(fn ($s) => $s->id.$s->exam_date->toDateString().$s->starts_at)->all();
        $q = fn (int $round) => '/api/exams?'.http_build_query(['term_id' => $this->term->id, 'kind' => 'monthly', 'round' => $round]);
        $this->putJson($q(2), ['shift_days' => -14])->assertUnprocessable()->assertJsonValidationErrors('shift_days'); // onto round 1's days: the same sections twice at the same time
        $this->putJson($q(2), ['shift_days' => 5])->assertUnprocessable()->assertJsonValidationErrors('shift_days'); // lands on a Friday
        $this->putJson($q(2), [])->assertUnprocessable();
        $this->assertSame($before, ExamSession::orderBy('id')->get()->map(fn ($s) => $s->id.$s->exam_date->toDateString().$s->starts_at)->all());
    }

    public function test_only_the_principal_deletes_or_changes_exams_in_bulk(): void
    {
        $this->postJson('/api/exams/generate', $this->body())->assertOk();
        Sanctum::actingAs($this->teachers[0]);
        $this->deleteJson('/api/exams?term_id='.$this->term->id)->assertForbidden();
        $this->putJson('/api/exams?term_id='.$this->term->id, ['shift_days' => 1])->assertForbidden();
        $this->assertSame(8, ExamSession::count());
    }
}
