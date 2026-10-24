<?php

namespace Tests\Feature\Communication;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Communication\Models\Announcement;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class AnnouncementTest extends AcademicTestCase
{
    private Classroom $a;

    private Classroom $b;

    private User $teacherA;

    private User $teacherB;

    private User $studentA;

    private User $studentB;

    private User $parentA;

    private User $parentB;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $math = Subject::factory()->create();
        $this->a = Classroom::factory()->create(['name' => '5-A', 'academic_year_id' => $year->id]);
        $this->b = Classroom::factory()->create(['name' => '5-B', 'academic_year_id' => $year->id]);
        $this->teacherA = User::factory()->staff(StaffType::Teacher, ['subject_id' => $math->id])->create();
        $this->teacherB = User::factory()->staff(StaffType::Teacher, ['subject_id' => $math->id])->create();
        TeachingAssignment::create(['classroom_id' => $this->a->id, 'subject_id' => $math->id, 'teacher_id' => $this->teacherA->id, 'weekly_periods' => 4]);
        TeachingAssignment::create(['classroom_id' => $this->b->id, 'subject_id' => $math->id, 'teacher_id' => $this->teacherB->id, 'weekly_periods' => 4]);

        foreach (['a' => $this->a, 'b' => $this->b] as $key => $room) {
            $user = User::factory()->role(Role::Student)->create();
            $student = Student::create(['student_number' => "S-$key", 'name_ar' => "طالب $key", 'name_en' => "Student $key", 'gender' => 'male', 'user_id' => $user->id]);
            Enrollment::create(['student_id' => $student->id, 'classroom_id' => $room->id, 'academic_year_id' => $year->id]);
            $parent = User::factory()->role(Role::Guardian)->create();
            $student->guardians()->attach($parent->id);
            $this->{'student'.strtoupper($key)} = $user;
            $this->{'parent'.strtoupper($key)} = $parent;
        }
    }

    private function make(array $override = []): Announcement
    {
        return Announcement::create(['author_id' => User::factory()->role(Role::Admin)->create()->id, 'title' => 'Hello', 'body' => 'Text', 'audience' => 'all', ...$override]);
    }

    private function titles(User $user): array
    {
        Sanctum::actingAs($user);

        return collect($this->getJson('/api/announcements')->assertOk()->json('data'))->pluck('title')->all();
    }

    public function test_the_admin_publishes_to_everyone_to_a_role_or_to_a_section(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/announcements', ['title' => 'Holiday', 'body' => 'School is closed', 'audience' => 'all'])->assertCreated()->assertJsonPath('data.audience', 'all');
        $this->postJson('/api/announcements', ['title' => 'Staff', 'body' => 'Meeting', 'audience' => 'role', 'audience_role' => 'teacher'])->assertCreated();
        $this->postJson('/api/announcements', ['title' => 'Trip', 'body' => '5-A only', 'audience' => 'classroom', 'classroom_id' => $this->a->id, 'is_pinned' => true])->assertCreated()->assertJsonPath('data.classroom.name', '5-A');

        $this->assertSame(3, Announcement::count());
    }

    public function test_the_audience_must_be_complete(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/announcements', ['title' => 'x', 'body' => 'y', 'audience' => 'role'])->assertUnprocessable()->assertJsonValidationErrors('audience_role');
        $this->postJson('/api/announcements', ['title' => 'x', 'body' => 'y', 'audience' => 'classroom'])->assertUnprocessable()->assertJsonValidationErrors('classroom_id');
        $this->postJson('/api/announcements', ['title' => '', 'body' => 'y', 'audience' => 'all'])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson('/api/announcements', ['title' => 'x', 'body' => 'y', 'audience' => 'all', 'expires_at' => '2020-01-01'])->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    }

    public function test_a_teacher_publishes_only_to_a_section_they_teach(): void
    {
        Sanctum::actingAs($this->teacherA);

        $this->postJson('/api/announcements', ['title' => 'Quiz', 'body' => 'Tomorrow', 'audience' => 'classroom', 'classroom_id' => $this->a->id])->assertCreated();
        $this->postJson('/api/announcements', ['title' => 'Quiz', 'body' => 'Tomorrow', 'audience' => 'classroom', 'classroom_id' => $this->b->id])->assertUnprocessable()->assertJsonValidationErrors('classroom_id');
        $this->postJson('/api/announcements', ['title' => 'All', 'body' => 'x', 'audience' => 'all'])->assertUnprocessable()->assertJsonValidationErrors('audience');
        $this->postJson('/api/announcements', ['title' => 'Role', 'body' => 'x', 'audience' => 'role', 'audience_role' => 'parent'])->assertUnprocessable();
        $this->assertSame(1, Announcement::count());
    }

    public function test_students_and_parents_cannot_publish(): void
    {
        foreach ([$this->studentA, $this->parentA] as $user) {
            Sanctum::actingAs($user);
            $this->postJson('/api/announcements', ['title' => 'x', 'body' => 'y', 'audience' => 'all'])->assertForbidden();
        }
    }

    public function test_everyone_sees_what_is_meant_for_them(): void
    {
        $this->make(['title' => 'For all']);
        $this->make(['title' => 'For parents', 'audience' => 'role', 'audience_role' => 'parent']);
        $this->make(['title' => 'For teachers', 'audience' => 'role', 'audience_role' => 'teacher']);
        $this->make(['title' => 'For 5-A', 'audience' => 'classroom', 'classroom_id' => $this->a->id]);

        $this->assertEqualsCanonicalizing(['For all', 'For 5-A'], $this->titles($this->studentA));
        $this->assertEqualsCanonicalizing(['For all'], $this->titles($this->studentB));
        $this->assertEqualsCanonicalizing(['For all', 'For parents', 'For 5-A'], $this->titles($this->parentA));
        $this->assertEqualsCanonicalizing(['For all', 'For parents'], $this->titles($this->parentB));
        $this->assertEqualsCanonicalizing(['For all', 'For teachers', 'For 5-A'], $this->titles($this->teacherA));
        $this->assertEqualsCanonicalizing(['For all', 'For teachers'], $this->titles($this->teacherB));
        $this->assertCount(4, $this->titles(User::factory()->role(Role::Admin)->create()));
    }

    public function test_a_parent_with_children_in_two_sections_sees_both_sections_announcements(): void
    {
        $this->make(['title' => 'For 5-A', 'audience' => 'classroom', 'classroom_id' => $this->a->id]);
        $this->make(['title' => 'For 5-B', 'audience' => 'classroom', 'classroom_id' => $this->b->id]);
        Student::where('student_number', 'S-b')->first()->guardians()->attach($this->parentA->id);

        $this->assertEqualsCanonicalizing(['For 5-A', 'For 5-B'], $this->titles($this->parentA));
    }

    public function test_the_author_always_sees_their_own_and_expired_ones_are_hidden_and_pinned_come_first(): void
    {
        $this->make(['title' => 'Old', 'expires_at' => now()->subDay()]);
        $this->make(['title' => 'Normal']);
        $this->make(['title' => 'Pinned', 'is_pinned' => true, 'created_at' => now()->subDays(3)]);

        $this->assertSame(['Pinned', 'Normal'], $this->titles($this->studentA));
    }

    public function test_the_unread_counter_and_marking_as_read(): void
    {
        $one = $this->make(['title' => 'One']);
        $this->make(['title' => 'Two']);
        Sanctum::actingAs($this->studentA);

        $this->getJson('/api/announcements/unread-count')->assertOk()->assertJsonPath('data.count', 2);
        $this->postJson("/api/announcements/{$one->id}/read")->assertOk();
        $this->postJson("/api/announcements/{$one->id}/read")->assertOk(); // idempotent
        $this->assertSame(1, DB::table('announcement_reads')->count());
        $this->getJson('/api/announcements/unread-count')->assertJsonPath('data.count', 1);
        $this->assertSame([false, true], collect($this->getJson('/api/announcements')->json('data'))->pluck('read')->sort()->values()->all());
    }

    public function test_one_cannot_mark_an_announcement_that_is_not_theirs(): void
    {
        $hidden = $this->make(['audience' => 'classroom', 'classroom_id' => $this->a->id]);
        Sanctum::actingAs($this->studentB);

        $this->postJson("/api/announcements/{$hidden->id}/read")->assertNotFound();
    }

    public function test_the_author_or_an_admin_edits_and_deletes_and_nobody_else(): void
    {
        Sanctum::actingAs($this->teacherA);
        $id = $this->postJson('/api/announcements', ['title' => 'Quiz', 'body' => 'x', 'audience' => 'classroom', 'classroom_id' => $this->a->id])->json('data.id');

        $this->putJson("/api/announcements/$id", ['title' => 'Quiz moved', 'body' => 'x', 'audience' => 'classroom', 'classroom_id' => $this->a->id])->assertOk()->assertJsonPath('data.title', 'Quiz moved');

        Sanctum::actingAs($this->teacherB);
        $this->putJson("/api/announcements/$id", ['title' => 'Hacked', 'body' => 'x', 'audience' => 'classroom', 'classroom_id' => $this->b->id])->assertForbidden();
        $this->deleteJson("/api/announcements/$id")->assertForbidden();

        $this->actAsAdmin();
        $this->deleteJson("/api/announcements/$id")->assertNoContent();
        $this->assertSame(0, Announcement::count());
    }

    public function test_the_options_list_the_audiences_the_user_may_publish_to(): void
    {
        Sanctum::actingAs($this->teacherA);
        $this->getJson('/api/announcements/options')->assertOk()->assertJsonPath('data.can_publish_all', false)->assertJsonCount(1, 'data.classrooms');

        $this->actAsAdmin();
        $this->getJson('/api/announcements/options')->assertJsonPath('data.can_publish_all', true)->assertJsonCount(2, 'data.classrooms');

        Sanctum::actingAs($this->studentA);
        $this->getJson('/api/announcements/options')->assertForbidden();
    }
}
