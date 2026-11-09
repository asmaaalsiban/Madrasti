<?php

namespace Tests\Feature\Discipline;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Discipline\Models\Warning;
use App\Modules\Discipline\Models\WarningRule;
use App\Modules\Discipline\Services\WarningRules;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class WarningRuleTest extends AcademicTestCase
{
    private Student $sami;

    private Student $lina;

    private User $parent;

    private User $teacher;

    private Classroom $room;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $this->room = Classroom::factory()->create(['academic_year_id' => $year->id]);
        [$this->sami, $this->lina] = array_map(function ($n) use ($year) {
            $s = Student::create(['student_number' => $n, 'name_ar' => $n, 'name_en' => $n, 'gender' => 'male']);
            Enrollment::create(['student_id' => $s->id, 'classroom_id' => $this->room->id, 'academic_year_id' => $year->id]);

            return $s;
        }, ['Sami', 'Lina']);
        $this->parent = User::factory()->role(Role::Guardian)->create();
        $this->sami->guardians()->attach($this->parent->id);
        $this->teacher = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Sara']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function absent(Student $s, string ...$dates): void
    {
        foreach ($dates as $d) {
            DB::table('student_attendances')->insert(['student_id' => $s->id, 'classroom_id' => $this->room->id, 'date' => $d, 'status' => 'absent', 'marked_by' => $this->teacher->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function rule(array $extra = []): WarningRule
    {
        return WarningRule::create(['subject_type' => 'student', 'name' => '3 days absent in a month', 'metric' => 'absent_days', 'threshold' => 3, 'window_days' => 30, 'category' => 'absence', 'level' => 2, 'title' => 'Absent too often', 'auto_send' => true, 'is_active' => true, ...$extra]);
    }

    public function test_a_school_with_no_rule_gets_no_warning(): void
    {
        $this->absent($this->sami, '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05');
        $this->assertSame(['created' => 0, 'pending' => 0], app(WarningRules::class)->evaluate());
        $this->assertSame(0, Warning::count());
    }

    public function test_a_rule_warns_only_the_student_who_reached_the_number_and_tells_the_parent(): void
    {
        app()->setLocale('en'); // the text of a rule without its own is written in the school's language
        $this->rule();
        $this->absent($this->sami, '2026-11-02', '2026-11-03', '2026-11-04');
        $this->absent($this->lina, '2026-11-02', '2026-11-03'); // two: not enough

        $this->assertSame(['created' => 1, 'pending' => 0], app(WarningRules::class)->evaluate());
        $w = Warning::firstOrFail();
        $this->assertSame($this->sami->id, $w->student_id);
        $this->assertSame('rule', $w->kind);
        $this->assertSame('active', $w->status);
        $this->assertSame(2, $w->level);
        $this->assertStringContainsString('3 days absent in the last 30 days', $w->body);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->parent->id)->where('data', 'like', '%warning_student_parent%')->count());
    }

    public function test_running_it_again_or_the_next_day_does_not_repeat_the_warning_and_an_old_absence_does_not_count(): void
    {
        $this->rule(['window_days' => 7]);
        $this->absent($this->sami, '2026-11-01', '2026-11-02', '2026-11-03'); // 9 days ago at most: outside a window of 7 days ending 11-10 starts 11-04
        $this->assertSame(0, app(WarningRules::class)->evaluate()['created']);

        $this->absent($this->sami, '2026-11-05', '2026-11-06', '2026-11-09');
        $this->assertSame(1, app(WarningRules::class)->evaluate()['created']);
        $this->assertSame(0, app(WarningRules::class)->evaluate()['created']); // same day
        Carbon::setTestNow('2026-11-12 09:00:00');
        $this->assertSame(0, app(WarningRules::class)->evaluate()['created']); // still inside the window
        $this->assertSame(1, Warning::count());
    }

    public function test_a_warning_the_admin_withdrew_is_not_raised_again_in_the_same_window(): void
    {
        $this->rule();
        $this->absent($this->sami, '2026-11-02', '2026-11-03', '2026-11-04');
        app(WarningRules::class)->evaluate();
        Warning::firstOrFail()->update(['status' => 'withdrawn']);
        $this->assertSame(['created' => 0, 'pending' => 0], app(WarningRules::class)->evaluate());
    }

    public function test_a_rule_that_waits_for_approval_notifies_the_admin_and_not_the_parent(): void
    {
        $this->rule(['auto_send' => false]);
        $this->absent($this->sami, '2026-11-02', '2026-11-03', '2026-11-04');
        $admin = User::factory()->role(Role::Admin)->create();

        $this->assertSame(['created' => 0, 'pending' => 1], app(WarningRules::class)->evaluate());
        $this->assertSame('pending', Warning::firstOrFail()->status);
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $this->parent->id)->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $admin->id)->where('data', 'like', '%warnings_pending%')->count());

        Sanctum::actingAs($admin);
        $this->postJson('/api/warnings/'.Warning::firstOrFail()->id.'/approve')->assertOk();
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->parent->id)->where('data', 'like', '%warning_student_parent%')->count());
    }

    public function test_behaviour_notes_and_staff_lateness_have_their_own_measures(): void
    {
        $this->rule(['name' => 'Behaviour', 'metric' => 'negative_notes', 'threshold' => 2, 'category' => 'behavior']);
        foreach (['2026-11-03', '2026-11-05'] as $d) {
            DB::table('behavior_notes')->insert(['student_id' => $this->lina->id, 'teacher_id' => $this->teacher->id, 'type' => 'negative', 'note' => 'x', 'noted_on' => $d, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->rule(['subject_type' => 'staff', 'name' => 'Late', 'metric' => 'late_count', 'threshold' => 2, 'category' => 'late', 'level' => 1, 'title' => 'Late again']);
        foreach (['2026-11-04', '2026-11-06'] as $d) {
            DB::table('staff_attendances')->insert(['user_id' => $this->teacher->id, 'date' => $d, 'status' => 'late', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->assertSame(2, app(WarningRules::class)->evaluate()['created']);
        $this->assertSame($this->lina->id, Warning::where('subject_type', 'student')->value('student_id'));
        $this->assertSame($this->teacher->id, Warning::where('subject_type', 'staff')->value('user_id'));
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->teacher->id)->where('data', 'like', '%warning_staff%')->count());
    }

    public function test_the_admin_manages_rules_and_runs_them_and_a_staff_rule_cannot_use_a_student_measure(): void
    {
        $this->actAsAdmin();
        $body = ['subject_type' => 'student', 'name' => 'Absent', 'metric' => 'absent_days', 'threshold' => 3, 'window_days' => 30, 'category' => 'absence', 'level' => 1, 'title' => 'Absent', 'auto_send' => true];
        $id = $this->postJson('/api/warnings/rules', $body)->assertCreated()->json('data.id');
        $this->postJson('/api/warnings/rules', [...$body, 'subject_type' => 'staff', 'metric' => 'negative_notes'])->assertStatus(422)->assertJsonValidationErrors('metric');
        $this->putJson("/api/warnings/rules/$id", [...$body, 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->putJson("/api/warnings/rules/$id", [...$body, 'is_active' => true])->assertOk();

        $this->absent($this->sami, '2026-11-02', '2026-11-03', '2026-11-04');
        $this->postJson('/api/warnings/rules/run')->assertOk()->assertJsonPath('data.created', 1);
        $this->getJson('/api/warnings/rules')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/warnings/rules/$id")->assertNoContent();
        $this->assertSame(1, Warning::count()); // what it raised stays

        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/warnings/rules')->assertForbidden();
    }
}
