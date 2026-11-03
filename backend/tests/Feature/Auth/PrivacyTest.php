<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Modules\Auth\Models\DataRequest;
use App\Shared\Enums\Role;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class PrivacyTest extends AcademicTestCase
{
    private User $parent;

    private User $stranger;

    private Student $child;

    protected function setUp(): void
    {
        parent::setUp();
        $this->child = Student::create(['student_number' => 'S-1', 'name_ar' => 'طالب', 'name_en' => 'Student One', 'gender' => 'male']);
        $this->parent = User::factory()->role(Role::Guardian)->create(['name' => 'Abu Ali', 'email' => 'abu@example.test']);
        $this->stranger = User::factory()->role(Role::Guardian)->create();
        $this->child->guardians()->attach($this->parent->id, ['relation' => 'father']);
    }

    public function test_a_person_downloads_a_copy_of_their_own_data_and_a_parent_of_their_childs(): void
    {
        DB::table('behavior_notes')->insert([
            ['student_id' => $this->child->id, 'teacher_id' => User::factory()->create()->id, 'type' => 'positive', 'note' => 'Helped a friend', 'visible_to_parent' => true, 'noted_on' => '2026-11-01', 'created_at' => now(), 'updated_at' => now()],
            ['student_id' => $this->child->id, 'teacher_id' => User::factory()->create()->id, 'type' => 'negative', 'note' => 'Private note of the school', 'visible_to_parent' => false, 'noted_on' => '2026-11-02', 'created_at' => now(), 'updated_at' => now()],
        ]);
        Sanctum::actingAs($this->parent);
        $mine = $this->getJson('/api/privacy/export')->assertOk()->assertHeader('content-disposition')->assertJsonPath('account.email', 'abu@example.test')->assertJsonPath('children.0.student_number', 'S-1');
        $this->assertStringContainsString('attachment; filename="my-data-', $mine->headers->get('content-disposition'));

        $child = $this->getJson("/api/privacy/export?student_id={$this->child->id}")->assertOk()->assertJsonPath('student.name_en', 'Student One')->assertJsonPath('guardians.0.name', 'Abu Ali')->assertJsonCount(1, 'behavior');
        $this->assertStringNotContainsString('Private note of the school', $child->getContent()); // only what the family may see
    }

    public function test_nobody_downloads_the_data_of_a_child_that_is_not_theirs(): void
    {
        Sanctum::actingAs($this->stranger);
        $this->getJson("/api/privacy/export?student_id={$this->child->id}")->assertForbidden();
        $this->postJson('/api/privacy/requests', ['type' => 'deletion', 'student_id' => $this->child->id])->assertForbidden();
    }

    public function test_a_parent_asks_for_deletion_once_the_principal_is_told_and_answers_and_the_parent_reads_the_answer(): void
    {
        $principal = User::factory()->role(Role::Admin)->create();
        Sanctum::actingAs($this->parent);
        $id = $this->postJson('/api/privacy/requests', ['type' => 'deletion', 'student_id' => $this->child->id, 'message' => 'We are moving abroad'])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson('/api/privacy/requests', ['type' => 'deletion', 'student_id' => $this->child->id])->assertStatus(422); // already waiting
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $principal->id)->where('data', 'like', '%data_request%')->count());
        $this->getJson('/api/privacy/requests/all')->assertForbidden();
        $this->getJson('/api/privacy/requests')->assertJsonPath('data.0.message', 'We are moving abroad')->assertJsonPath('children.0.name_en', 'Student One');

        Sanctum::actingAs($principal);
        $this->getJson('/api/privacy/requests/all')->assertOk()->assertJsonPath('pending', 1)->assertJsonPath('data.0.user.name', 'Abu Ali')->assertJsonPath('data.0.student.name_en', 'Student One');
        $this->putJson("/api/privacy/requests/$id", ['status' => 'done'])->assertStatus(422); // an answer is needed
        $this->putJson("/api/privacy/requests/$id", ['status' => 'refused', 'answer' => 'Payment records must be kept for 7 years'])->assertOk()->assertJsonPath('data.status', 'refused');
        $this->putJson("/api/privacy/requests/$id", ['status' => 'done', 'answer' => 'x'])->assertStatus(422); // decided once
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->parent->id)->where('data', 'like', '%data_request_answered%')->count());

        Sanctum::actingAs($this->parent);
        $this->getJson('/api/privacy/requests')->assertJsonPath('data.0.answer', 'Payment records must be kept for 7 years');
        $this->assertSame(1, DataRequest::count());
    }
}
