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
        $this->actAsAdmin();
    }

    private function body(array $extra = []): array
    {
        return ['term_id' => $this->term->id, 'from' => '2027-01-03', 'to' => '2027-01-14', 'start_time' => '08:00', 'duration_minutes' => 90, 'slots_per_day' => 1, 'grade_per_day' => 1, 'invigilators' => 1, ...$extra];
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
        $this->assertSame('08:00:00', $math->first()->starts_at);
        $this->assertSame('09:30:00', $math->first()->ends_at);
        // Only working days (Sunday to Thursday): no Friday (8th) or Saturday (9th).
        $this->assertEmpty($rows->filter(fn ($s) => in_array($s->exam_date->toDateString(), ['2027-01-08', '2027-01-09']))->all());
    }

    public function test_invigilators_are_free_not_the_subjects_own_teacher_and_not_in_two_rooms_at_once(): void
    {
        $this->postJson('/api/exams/generate', $this->body(['invigilators' => 2]))->assertOk()->assertJsonPath('data.warnings', []);
        $math = ExamSession::where('subject_id', $this->math->id)->with('invigilators')->get();
        foreach ($math->whereIn('classroom_id', [$this->a->id, $this->b->id]) as $s) {
            $this->assertCount(2, $s->invigilators);
            $this->assertFalse($s->invigilators->contains('id', $this->teachers[0]->id)); // Teacher 1 teaches it to them
        }
        $slot = ExamSession::with('invigilators')->get()->groupBy(fn ($s) => $s->exam_date->toDateString().$s->starts_at);
        foreach ($slot as $sessions) {
            $ids = $sessions->flatMap(fn ($s) => $s->invigilators->pluck('id'));
            $this->assertSame($ids->count(), $ids->unique()->count()); // nobody watches two rooms at the same time
        }
    }

    public function test_it_warns_when_there_are_too_few_free_teachers_and_refuses_when_there_are_too_few_days(): void
    {
        $r = $this->postJson('/api/exams/generate', $this->body(['invigilators' => 4]))->assertOk()->json('data.warnings');
        $this->assertNotEmpty($r); // 3 rooms x 4 teachers needs 12 free teachers; there are 6
        $this->postJson('/api/exams/generate', $this->body(['from' => '2027-01-03', 'to' => '2027-01-04']))->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->postJson('/api/exams/generate', $this->body(['from' => '2027-01-08', 'to' => '2027-01-09']))->assertUnprocessable()->assertJsonValidationErrors('from'); // Friday and Saturday only
        $this->postJson('/api/exams/generate', $this->body(['to' => '2027-01-04', 'slots_per_day' => 2, 'grade_per_day' => 2]))->assertOk(); // two exams a day: 3 subjects fit in 2 days
    }

    public function test_a_dry_run_saves_nothing_and_a_second_run_replaces_the_first(): void
    {
        $this->postJson('/api/exams/generate', $this->body(['dry_run' => true]))->assertOk()->assertJsonPath('data.dry_run', true);
        $this->assertSame(0, ExamSession::count());
        $this->postJson('/api/exams/generate', $this->body())->assertOk();
        $this->postJson('/api/exams/generate', $this->body(['start_time' => '09:00', 'exclude_dates' => ['2027-01-03']]))->assertOk();
        $this->assertSame(8, ExamSession::count());
        $this->assertSame(0, ExamSession::whereDate('exam_date', '2027-01-03')->count());
        $this->assertSame(0, ExamSession::where('starts_at', '08:00:00')->count());
        $this->postJson('/api/exams/generate', $this->body(['grade_level_ids' => [$this->g2->id]]))->assertOk()->assertJsonPath('data.sessions', 2); // only grade 2 is redone
        $this->assertSame(8, ExamSession::count());
    }

    public function test_an_exam_can_be_moved_by_hand_but_not_onto_a_clash(): void
    {
        $this->postJson('/api/exams/generate', $this->body())->assertOk();
        $math = ExamSession::where('classroom_id', $this->a->id)->where('subject_id', $this->math->id)->first();
        $other = ExamSession::where('classroom_id', $this->a->id)->where('subject_id', $this->arabic->id)->first();
        $this->putJson("/api/exams/{$math->id}", ['exam_date' => $other->exam_date->toDateString(), 'starts_at' => '08:30', 'ends_at' => '10:00'])->assertUnprocessable()->assertJsonValidationErrors('exam_date'); // same section, overlapping
        $this->putJson("/api/exams/{$math->id}", ['exam_date' => '2027-01-13', 'starts_at' => '10:00', 'ends_at' => '09:00'])->assertUnprocessable();
        $this->putJson("/api/exams/{$math->id}", ['exam_date' => '2027-01-14', 'starts_at' => '11:00', 'ends_at' => '12:30'])->assertOk()->assertJsonPath('data.date', '2027-01-14')->assertJsonPath('data.starts_at', '11:00');

        // A teacher already watching another room then cannot be put in this one.
        $busy = ExamSession::where('id', '!=', $math->id)->whereDate('exam_date', '2027-01-14')->first() ?? ExamSession::where('id', '!=', $math->id)->first();
        $busy->invigilators()->sync([$this->teachers[5]->id]);
        $busy->update(['exam_date' => '2027-01-14', 'starts_at' => '11:30:00', 'ends_at' => '12:00:00']);
        $this->putJson("/api/exams/{$math->id}", ['invigilator_ids' => [$this->teachers[5]->id]])->assertUnprocessable()->assertJsonValidationErrors('invigilator_ids');
        $this->putJson("/api/exams/{$math->id}", ['invigilator_ids' => [$this->teachers[4]->id]])->assertOk();
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
