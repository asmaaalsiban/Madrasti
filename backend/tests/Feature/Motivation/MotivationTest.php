<?php

namespace Tests\Feature\Motivation;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Motivation\Models\Badge;
use App\Modules\Motivation\Models\PointAward;
use App\Modules\Motivation\Models\PointCategory;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class MotivationTest extends AcademicTestCase
{
    private User $teacher;

    private Classroom $a;

    private Classroom $b;

    private Student $ali;

    private Student $omar;

    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $math = Subject::factory()->create();
        $this->teacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $math->id])->create();
        $this->a = Classroom::factory()->create(['name' => '5-A', 'academic_year_id' => $year->id]);
        $this->b = Classroom::factory()->create(['name' => '5-B', 'academic_year_id' => $year->id]);
        TeachingAssignment::create(['classroom_id' => $this->a->id, 'subject_id' => $math->id, 'teacher_id' => $this->teacher->id, 'weekly_periods' => 4]);
        $this->ali = $this->enroll('S-1', 'علي', $this->a, $year);
        $this->omar = $this->enroll('S-2', 'عمر', $this->a, $year);
        $this->parent = User::factory()->role(Role::Guardian)->create();
        $this->ali->guardians()->attach($this->parent->id);
        $now = now();
        PointCategory::create(['name_ar' => 'مساعدة', 'name_en' => 'Helping', 'points' => 2]);
        Badge::create(['name_ar' => 'نجم', 'name_en' => 'Rising star', 'icon' => '🌟', 'points_required' => 25]);
        Badge::create(['name_ar' => 'مبدع', 'name_en' => 'Creative', 'icon' => '🎨']);
    }

    private function enroll(string $no, string $name, Classroom $room, AcademicYear $year): Student
    {
        $s = Student::create(['student_number' => $no, 'name_ar' => $name, 'name_en' => $name, 'gender' => 'male']);
        Enrollment::create(['student_id' => $s->id, 'classroom_id' => $room->id, 'academic_year_id' => $year->id]);

        return $s;
    }

    private function give(array $students, ?int $categoryId = null, array $extra = [])
    {
        return $this->postJson('/api/motivation/awards', ['student_ids' => collect($students)->pluck('id')->all(), 'point_category_id' => $categoryId, ...$extra]);
    }

    public function test_a_teacher_gives_points_to_many_students_with_a_reason(): void
    {
        Sanctum::actingAs($this->teacher);
        $category = PointCategory::where('points', 2)->first();
        $this->give([$this->ali, $this->omar], $category->id)->assertCreated()->assertJsonPath('data.points', 2);

        $this->assertSame(2, PointAward::where('student_id', $this->ali->id)->sum('points'));
        $row = collect($this->getJson('/api/motivation/classroom?classroom_id='.$this->a->id)->assertOk()->json('data.students'))->firstWhere('id', $this->ali->id);
        $this->assertSame(2, $row['total']);
        $this->assertSame(2, $row['week']);
    }

    public function test_a_free_amount_needs_a_label_and_negative_points_take_away(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->give([$this->ali], null, ['points' => 3])->assertUnprocessable()->assertJsonValidationErrors('point_category_id');
        $this->give([$this->ali], null, ['points' => 5, 'note' => 'Science fair'])->assertCreated();
        $this->give([$this->ali], null, ['points' => -2, 'note' => 'Shouting'])->assertCreated();
        $this->give([$this->ali], null, ['points' => 0, 'note' => 'x'])->assertUnprocessable();
        $this->assertSame(3, PointAward::where('student_id', $this->ali->id)->sum('points'));
    }

    public function test_a_teacher_cannot_give_points_to_a_section_they_do_not_teach(): void
    {
        $year = AcademicYear::first();
        $other = $this->enroll('S-3', 'خالد', $this->b, $year);
        Sanctum::actingAs($this->teacher);
        $this->give([$this->ali, $other], null, ['points' => 1, 'note' => 'Good'])->assertForbidden();
        $this->assertSame(0, PointAward::count());
        $this->getJson('/api/motivation/classroom?classroom_id='.$this->b->id)->assertForbidden();
    }

    public function test_reaching_a_total_earns_the_badge_once_and_notifies_the_family(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->give([$this->ali], null, ['points' => 20, 'note' => 'Project'])->assertCreated();
        $this->assertSame(0, DB::table('student_badges')->count());
        $this->give([$this->ali], null, ['points' => 5, 'note' => 'Quiz'])->assertCreated();
        $this->give([$this->ali], null, ['points' => 1, 'note' => 'More'])->assertCreated();

        $this->assertSame(1, DB::table('student_badges')->where('student_id', $this->ali->id)->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->parent->id)->where('data', 'like', '%badge_earned%')->count());
    }

    public function test_this_weeks_leaders_are_suggested_and_ties_share_it(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->give([$this->ali], null, ['points' => 4, 'note' => 'a']);
        $this->give([$this->omar], null, ['points' => 1, 'note' => 'b']);
        $leaders = fn () => collect($this->getJson('/api/motivation/classroom?classroom_id='.$this->a->id)->json('data.students'))->where('is_leader', true)->pluck('id')->all();
        $this->assertSame([$this->ali->id], $leaders());

        $this->give([$this->omar], null, ['points' => 3, 'note' => 'c']);
        $this->assertEqualsCanonicalizing([$this->ali->id, $this->omar->id], $leaders());

        PointAward::query()->update(['awarded_at' => now()->subWeeks(2)]);
        $this->assertSame([], $leaders());
    }

    public function test_the_teacher_crowns_one_star_a_week_and_the_family_is_told_once(): void
    {
        Sanctum::actingAs($this->teacher);
        $crown = fn (Student $s) => $this->putJson('/api/motivation/star', ['classroom_id' => $this->a->id, 'student_id' => $s->id, 'reason' => 'Kind']);
        $crown($this->ali)->assertOk();
        $crown($this->ali)->assertOk();
        $crown($this->omar)->assertOk();

        $this->assertSame(1, DB::table('star_of_week')->count());
        $star = fn () => collect($this->getJson('/api/motivation/classroom?classroom_id='.$this->a->id)->json('data.students'))->firstWhere('is_star', true)['id'];
        $this->assertSame($this->omar->id, $star());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->parent->id)->where('data', 'like', '%star_of_week%')->count());

        $this->putJson('/api/motivation/star', ['classroom_id' => $this->b->id, 'student_id' => $this->ali->id])->assertForbidden();
        Sanctum::actingAs($this->parent);
        $this->getJson("/api/students/{$this->omar->id}/motivation")->assertForbidden();
    }

    public function test_only_the_giver_or_admin_can_undo_an_award(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->give([$this->ali], null, ['points' => 2, 'note' => 'a']);
        $award = PointAward::first();

        $other = User::factory()->staff(StaffType::Teacher)->create();
        Sanctum::actingAs($other);
        $this->deleteJson("/api/motivation/awards/{$award->id}")->assertForbidden();

        Sanctum::actingAs($this->teacher);
        $this->deleteJson("/api/motivation/awards/{$award->id}")->assertNoContent();
        $this->assertSame(0, PointAward::count());
    }

    public function test_a_teacher_hands_a_badge_by_hand_and_not_twice(): void
    {
        Sanctum::actingAs($this->teacher);
        $badge = Badge::whereNull('points_required')->first();
        $this->postJson("/api/motivation/students/{$this->ali->id}/badges", ['badge_id' => $badge->id])->assertCreated();
        $this->postJson("/api/motivation/students/{$this->ali->id}/badges", ['badge_id' => $badge->id])->assertUnprocessable();
    }

    public function test_students_and_parents_see_their_own_points_and_nobody_elses(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->give([$this->ali], null, ['points' => 3, 'note' => 'Nice']);

        Sanctum::actingAs($this->parent);
        $this->getJson("/api/students/{$this->ali->id}/motivation")->assertOk()->assertJsonPath('data.total', 3)->assertJsonPath('data.awards.0.note', 'Nice')->assertJsonPath('data.next_badge.points_required', 25);
        $this->getJson("/api/students/{$this->omar->id}/motivation")->assertForbidden();
        $this->postJson('/api/motivation/awards', ['student_ids' => [$this->ali->id], 'points' => 5, 'note' => 'x'])->assertForbidden();

        $kid = User::factory()->role(Role::Student)->create();
        $this->ali->update(['user_id' => $kid->id]);
        Sanctum::actingAs($kid);
        $this->getJson("/api/students/{$this->ali->id}/motivation")->assertOk();
        $this->getJson("/api/students/{$this->omar->id}/motivation")->assertForbidden();
    }

    public function test_the_admin_manages_reasons_and_badges_and_teachers_cannot(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/motivation/categories', ['name_ar' => 'x', 'name_en' => 'x', 'points' => 1])->assertForbidden();
        $this->postJson('/api/motivation/badges', ['name_ar' => 'x', 'name_en' => 'x', 'icon' => '⭐'])->assertForbidden();

        $this->actAsAdmin();
        $id = $this->postJson('/api/motivation/categories', ['name_ar' => 'قراءة', 'name_en' => 'Reading', 'points' => 2])->assertCreated()->json('data.id');
        $this->putJson("/api/motivation/categories/$id", ['name_ar' => 'قراءة', 'name_en' => 'Reading', 'points' => 3, 'is_active' => false])->assertOk();
        $this->postJson('/api/motivation/categories', ['name_ar' => 'x', 'name_en' => 'x', 'points' => 0])->assertUnprocessable();
        $bid = $this->postJson('/api/motivation/badges', ['name_ar' => 'قارئ', 'name_en' => 'Reader', 'icon' => '📚', 'points_required' => 50])->assertCreated()->json('data.id');
        $this->deleteJson("/api/motivation/badges/$bid")->assertNoContent();
        $this->deleteJson("/api/motivation/categories/$id")->assertNoContent();

        Sanctum::actingAs($this->teacher);
        $this->assertFalse(collect($this->getJson('/api/motivation/categories')->json('data'))->contains('name_en', 'Reading'));
    }
}
