<?php

namespace Tests\Feature\TeacherTools;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Academic\Models\Term;
use App\Modules\TeacherTools\Models\BehaviorCriterion;
use App\Modules\TeacherTools\Models\BehaviorStudentCriterion;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class BehaviorSpecialTest extends AcademicTestCase
{
    private User $teacher;

    private User $colleague;

    private Classroom $room;

    private Student $ali;

    private Student $sami;

    private User $parent;

    /** @var list<int> */
    private array $general;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        Term::create(['academic_year_id' => $year->id, 'name' => 'First term', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']);
        $math = Subject::factory()->create();
        $art = Subject::factory()->create();
        $this->teacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $math->id])->create(['name' => 'Sara']);
        $this->colleague = User::factory()->staff(StaffType::Teacher, ['subject_id' => $art->id])->create(['name' => 'Omar']);
        $this->room = Classroom::factory()->create(['name' => '5-A', 'academic_year_id' => $year->id]);
        TeachingAssignment::create(['classroom_id' => $this->room->id, 'subject_id' => $math->id, 'teacher_id' => $this->teacher->id, 'weekly_periods' => 4]);
        TeachingAssignment::create(['classroom_id' => $this->room->id, 'subject_id' => $art->id, 'teacher_id' => $this->colleague->id, 'weekly_periods' => 2]);
        foreach (['ali', 'sami'] as $key) {
            $s = Student::create(['student_number' => "S-$key", 'name_ar' => $key, 'name_en' => $key, 'gender' => 'male']);
            Enrollment::create(['student_id' => $s->id, 'classroom_id' => $this->room->id, 'academic_year_id' => $year->id]);
            $this->{$key} = $s;
        }
        $this->parent = User::factory()->role(Role::Guardian)->create();
        $this->ali->guardians()->attach($this->parent->id);
        $this->general = BehaviorCriterion::orderBy('id')->pluck('id')->all();
    }

    private function special(Student $student, string $name = 'Raises his hand')
    {
        return $this->postJson("/api/behavior/students/{$student->id}/special", ['name' => $name]);
    }

    private function evaluate(Student $student, array $scores, array $special = [], array $extra = [])
    {
        return $this->putJson('/api/behavior/evaluations', ['student_id' => $student->id, 'evaluated_on' => '2026-10-07', 'scores' => $scores, 'special_scores' => $special, ...$extra]);
    }

    public function test_a_teacher_writes_criteria_for_one_student_and_the_others_do_not_get_them(): void
    {
        Sanctum::actingAs($this->teacher);
        $id = $this->special($this->ali, '  Raises his hand ')->assertCreated()->assertJsonPath('data.name', 'Raises his hand')->json('data.id');
        $this->special($this->ali, 'Stays in his seat')->assertCreated();

        $this->assertSame(['Raises his hand', 'Stays in his seat'], array_column($this->getJson("/api/behavior/students/{$this->ali->id}/special")->assertOk()->json('data'), 'name'));
        $this->assertSame([], $this->getJson("/api/behavior/students/{$this->sami->id}/special")->json('data')); // another child of the same section
        $this->special($this->ali, 'RAISES HIS HAND')->assertUnprocessable()->assertJsonValidationErrors('name'); // no duplicates
        $this->putJson("/api/behavior/special/$id", ['name' => 'Raises his hand politely'])->assertOk()->assertJsonPath('data.name', 'Raises his hand politely');
        $this->special($this->ali, '')->assertUnprocessable();
    }

    public function test_every_teacher_of_the_student_shares_the_criteria(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->special($this->ali);

        Sanctum::actingAs($this->colleague);
        $this->assertSame(['Raises his hand'], array_column($this->getJson("/api/behavior/students/{$this->ali->id}/special")->json('data'), 'name'));
    }

    public function test_the_special_scores_are_saved_with_the_weekly_evaluation_and_replaced_on_edit(): void
    {
        Sanctum::actingAs($this->teacher);
        $hand = $this->special($this->ali, 'Raises his hand')->json('data.id');
        $seat = $this->special($this->ali, 'Stays in his seat')->json('data.id');

        $saved = $this->evaluate($this->ali, [$this->general[0] => 4], [$hand => 3, $seat => 5])->assertOk()->json('data');
        $this->assertEquals([$hand => 3, $seat => 5], $saved['special_scores']);
        $this->assertEquals([$this->general[0] => 4], $saved['scores']); // the general criteria are untouched

        $this->evaluate($this->ali, [$this->general[0] => 4], [$hand => 5, $seat => null])->assertOk();
        $week = collect($this->getJson('/api/behavior/week?classroom_id='.$this->room->id.'&date=2026-10-07')->json('data.students'));
        $this->assertEquals([$hand => 5], $week->firstWhere('id', $this->ali->id)['evaluation']['special_scores']);
        $this->assertSame([2, 0], [$week->firstWhere('id', $this->ali->id)['special_count'], $week->firstWhere('id', $this->sami->id)['special_count']]);
        $this->assertNull($week->firstWhere('id', $this->sami->id)['evaluation']);
    }

    public function test_an_evaluation_may_have_only_special_scores(): void
    {
        Sanctum::actingAs($this->teacher);
        $hand = $this->special($this->ali)->json('data.id');

        $this->evaluate($this->ali, [], [$hand => 4])->assertOk();
        $this->evaluate($this->ali, [], [$hand => null], ['evaluated_on' => '2026-10-14'])->assertUnprocessable(); // still nothing to save
    }

    public function test_a_special_score_for_another_students_hidden_or_unknown_criterion_is_refused(): void
    {
        Sanctum::actingAs($this->teacher);
        $alis = $this->special($this->ali)->json('data.id');
        $samis = $this->special($this->sami, 'Shares toys')->json('data.id');

        $this->evaluate($this->ali, [$this->general[0] => 3], [$samis => 4])->assertUnprocessable()->assertJsonValidationErrors('special_scores'); // Sami's criterion
        $this->evaluate($this->ali, [$this->general[0] => 3], [99999 => 4])->assertUnprocessable();
        $this->evaluate($this->ali, [$this->general[0] => 3], [$alis => 6])->assertUnprocessable(); // 1-5 only

        $this->putJson("/api/behavior/special/$alis", ['is_active' => false])->assertOk();
        $this->evaluate($this->ali, [$this->general[0] => 3], [$alis => 4])->assertUnprocessable(); // hidden
        $this->assertSame(0, DB::table('behavior_special_scores')->count());
    }

    public function test_a_used_criterion_is_hidden_not_deleted_and_an_unused_one_is_removed(): void
    {
        Sanctum::actingAs($this->teacher);
        $used = $this->special($this->ali, 'Used')->json('data.id');
        $spare = $this->special($this->ali, 'Spare')->json('data.id');
        $this->evaluate($this->ali, [], [$used => 4])->assertOk();

        $this->deleteJson("/api/behavior/special/$spare")->assertNoContent();
        $this->deleteJson("/api/behavior/special/$used")->assertNoContent();

        $this->assertNull(BehaviorStudentCriterion::find($spare));
        $this->assertFalse(BehaviorStudentCriterion::find($used)->is_active);
        $this->assertSame([], $this->getJson("/api/behavior/students/{$this->ali->id}/special")->json('data'));
        $this->assertSame(1, DB::table('behavior_special_scores')->count()); // its history stays
    }

    public function test_the_results_show_each_special_criterion_with_its_average_and_weekly_scores(): void
    {
        Sanctum::actingAs($this->teacher);
        $hand = $this->special($this->ali, 'Raises his hand')->json('data.id');
        $this->evaluate($this->ali, [], [$hand => 2], ['evaluated_on' => '2026-10-07'])->assertOk();
        $this->evaluate($this->ali, [], [$hand => 4], ['evaluated_on' => '2026-10-14'])->assertOk();
        Sanctum::actingAs($this->colleague);
        $this->evaluate($this->ali, [], [$hand => 3], ['evaluated_on' => '2026-10-14'])->assertOk(); // another teacher, same week

        $special = $this->getJson("/api/students/{$this->ali->id}/behavior")->assertOk()->json('data.special');
        $this->assertCount(1, $special);
        $this->assertEquals(['Raises his hand', 3], [$special[0]['name'], $special[0]['average']]);
        $this->assertSame([2, 4, 3], array_column($special[0]['history'], 'score'));
        $this->assertContains('Omar', array_column($special[0]['history'], 'teacher'));

        $this->assertSame([], $this->getJson("/api/students/{$this->sami->id}/behavior")->json('data.special')); // nothing for the others
    }

    public function test_the_family_sees_the_special_evaluation_only_when_it_was_shared_with_them(): void
    {
        Sanctum::actingAs($this->teacher);
        $hand = $this->special($this->ali)->json('data.id');
        $this->evaluate($this->ali, [], [$hand => 5], ['evaluated_on' => '2026-10-07', 'visible_to_parent' => true])->assertOk();
        $this->evaluate($this->ali, [], [$hand => 1], ['evaluated_on' => '2026-10-14', 'visible_to_parent' => false])->assertOk();

        $this->assertSame([5, 1], array_column($this->getJson("/api/students/{$this->ali->id}/behavior")->json('data.special.0.history'), 'score')); // staff see both

        Sanctum::actingAs($this->parent);
        $family = $this->getJson("/api/students/{$this->ali->id}/behavior")->assertOk()->json('data.special');
        $this->assertSame([5], array_column($family[0]['history'], 'score'));
        $this->assertEquals(5, $family[0]['average']);
        $this->getJson("/api/behavior/students/{$this->ali->id}/special")->assertForbidden(); // managing them is for staff
        $this->postJson("/api/behavior/students/{$this->ali->id}/special", ['name' => 'x'])->assertForbidden();
    }

    public function test_only_staff_who_may_evaluate_the_student_manage_their_criteria(): void
    {
        Sanctum::actingAs($this->teacher);
        $id = $this->special($this->ali)->json('data.id');

        $outsider = User::factory()->staff(StaffType::Teacher)->create();
        Sanctum::actingAs($outsider);
        $this->getJson("/api/behavior/students/{$this->ali->id}/special")->assertForbidden();
        $this->special($this->ali, 'Hijack')->assertForbidden();
        $this->putJson("/api/behavior/special/$id", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/behavior/special/$id")->assertForbidden();

        $this->actAsAdmin();
        $this->getJson("/api/behavior/students/{$this->ali->id}/special")->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/behavior/special/$id")->assertNoContent();
    }
}
