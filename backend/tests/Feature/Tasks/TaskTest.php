<?php

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Academic\Models\Subject;
use App\Modules\Tasks\Models\Task;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class TaskTest extends AcademicTestCase
{
    private User $principal;

    private User $head;

    private User $mathTeacher;

    private User $artTeacher;

    protected function setUp(): void
    {
        parent::setUp();
        $math = Subject::factory()->create(['code' => 'MTH', 'name_en' => 'Math']);
        $art = Subject::factory()->create(['code' => 'ART', 'name_en' => 'Art']);
        $this->principal = User::factory()->staff(StaffType::Principal)->create(['name' => 'Principal']);
        $this->head = User::factory()->staff(StaffType::HeadOfDepartment, ['subject_id' => $math->id])->create(['name' => 'Head']);
        $this->mathTeacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $math->id])->create(['name' => 'Math teacher']);
        $this->artTeacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $art->id])->create(['name' => 'Art teacher']);
    }

    private function body(array $users, array $extra = []): array
    {
        return ['title' => 'Prepare the exam', 'priority' => 'high', 'due_on' => now()->addDays(3)->toDateString(), 'user_ids' => $users, ...$extra];
    }

    private function notice(User $user, string $kind): int
    {
        return DB::table('notifications')->where('notifiable_id', $user->id)->where('data', 'like', "%$kind%")->count();
    }

    public function test_management_gives_a_task_to_any_teacher_who_is_told_and_moves_it_to_done(): void
    {
        Sanctum::actingAs($this->principal);
        $id = $this->postJson('/api/tasks', $this->body([$this->mathTeacher->id, $this->artTeacher->id]))->assertCreated()->assertJsonPath('data.total', 2)->json('data.id');
        $this->assertSame(1, $this->notice($this->artTeacher, 'task_new'));

        Sanctum::actingAs($this->artTeacher);
        $this->getJson('/api/tasks/mine')->assertOk()->assertJsonPath('open', 1)->assertJsonPath('data.0.my_status', 'todo');
        $this->putJson("/api/tasks/$id/status", ['status' => 'doing', 'note' => 'Started'])->assertOk()->assertJsonPath('data.my_status', 'doing');
        $this->assertSame(0, $this->notice($this->principal, 'task_done'));
        $this->putJson("/api/tasks/$id/status", ['status' => 'done'])->assertOk()->assertJsonPath('data.done_count', 1);
        $this->assertSame(1, $this->notice($this->principal, 'task_done'));
        $this->getJson('/api/tasks/mine')->assertJsonPath('open', 0);

        Sanctum::actingAs($this->principal);
        $this->getJson('/api/tasks/given')->assertOk()->assertJsonPath('data.0.assignees.1.status', 'done');
    }

    public function test_a_head_of_department_only_gives_tasks_to_their_own_department(): void
    {
        Sanctum::actingAs($this->head);
        $this->getJson('/api/tasks/people')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Math teacher');
        $this->postJson('/api/tasks', $this->body([$this->artTeacher->id]))->assertStatus(422)->assertJsonValidationErrors('user_ids');
        $this->postJson('/api/tasks', $this->body([$this->mathTeacher->id]))->assertCreated();
        // Management sees the head's task too; the head sees only their own.
        Sanctum::actingAs($this->principal);
        $this->postJson('/api/tasks', $this->body([$this->artTeacher->id]))->assertCreated();
        $this->getJson('/api/tasks/given')->assertJsonCount(2, 'data');
        Sanctum::actingAs($this->head);
        $this->getJson('/api/tasks/given')->assertJsonCount(1, 'data');
    }

    public function test_an_ordinary_teacher_cannot_give_tasks_nor_touch_other_peoples_tasks(): void
    {
        Sanctum::actingAs($this->principal);
        $id = $this->postJson('/api/tasks', $this->body([$this->mathTeacher->id]))->json('data.id');

        Sanctum::actingAs($this->artTeacher);
        $this->postJson('/api/tasks', $this->body([$this->mathTeacher->id]))->assertForbidden();
        $this->getJson('/api/tasks/given')->assertForbidden();
        $this->getJson('/api/tasks/people')->assertForbidden();
        $this->putJson("/api/tasks/$id/status", ['status' => 'done'])->assertForbidden();
        $this->putJson("/api/tasks/$id", $this->body([$this->artTeacher->id]))->assertForbidden();
        $this->deleteJson("/api/tasks/$id")->assertForbidden();
        $this->getJson('/api/tasks/mine')->assertJsonCount(0, 'data');
    }

    public function test_the_giver_edits_adds_and_removes_people_without_losing_progress_and_deletes(): void
    {
        Sanctum::actingAs($this->principal);
        $id = $this->postJson('/api/tasks', $this->body([$this->mathTeacher->id]))->json('data.id');
        Sanctum::actingAs($this->mathTeacher);
        $this->putJson("/api/tasks/$id/status", ['status' => 'doing'])->assertOk();

        Sanctum::actingAs($this->principal);
        $this->putJson("/api/tasks/$id", $this->body([$this->mathTeacher->id, $this->artTeacher->id], ['title' => 'New title']))->assertOk()
            ->assertJsonPath('data.title', 'New title')->assertJsonPath('data.total', 2)->assertJsonPath('data.assignees.0.status', 'doing');
        $this->assertSame(1, $this->notice($this->artTeacher, 'task_new'));
        $this->assertSame(1, $this->notice($this->mathTeacher, 'task_new'));
        $this->putJson("/api/tasks/$id", $this->body([$this->artTeacher->id]))->assertOk()->assertJsonPath('data.total', 1);
        $this->deleteJson("/api/tasks/$id")->assertNoContent();
        $this->assertSame(0, Task::count());
    }

    public function test_the_daily_reminder_tells_people_about_tasks_due_tomorrow_and_overdue_ones(): void
    {
        Sanctum::actingAs($this->principal);
        $this->postJson('/api/tasks', $this->body([$this->mathTeacher->id], ['title' => 'Soon', 'due_on' => '2026-11-11']))->assertCreated();
        $this->postJson('/api/tasks', $this->body([$this->mathTeacher->id], ['title' => 'Late', 'due_on' => '2026-11-09']))->assertCreated();
        $this->artisan('tasks:remind', ['--date' => '2026-11-10'])->assertSuccessful();
        $this->assertSame(1, $this->notice($this->mathTeacher, 'task_reminder'));
        $this->assertSame(1, $this->notice($this->mathTeacher, 'task_overdue'));
    }

    public function test_a_title_and_at_least_one_person_are_required(): void
    {
        Sanctum::actingAs($this->principal);
        $this->postJson('/api/tasks', $this->body([], ['title' => '']))->assertStatus(422)->assertJsonValidationErrors(['title', 'user_ids']);
    }
}
