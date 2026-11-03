<?php

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyRecipient;
use App\Modules\Tasks\Models\Task;
use App\Shared\Attachments\Attachment;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class AttachmentTest extends AcademicTestCase
{
    private User $principal;

    private User $sara;

    private User $omar;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->principal = User::factory()->staff(StaffType::Principal)->create(['name' => 'Principal']);
        $this->sara = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Sara']);
        $this->omar = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Omar']);
    }

    private function task(): array
    {
        Sanctum::actingAs($this->principal);
        $data = $this->postJson('/api/tasks', ['title' => 'Plan', 'priority' => 'normal', 'user_ids' => [$this->sara->id]])->assertCreated()->json('data');

        return [$data['id'], $data['assignees'][0]['id']];
    }

    private function pdf(string $name = 'plan.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, 'application/pdf');
    }

    public function test_the_giver_attaches_a_file_to_a_task_and_only_its_people_open_it(): void
    {
        [$task] = $this->task();
        $id = $this->postJson("/api/attachments/task/$task", ['files' => [$this->pdf()]])->assertCreated()->assertJsonPath('data.0.name', 'plan.pdf')->assertJsonPath('data.0.is_image', false)->json('data.0.id');
        Storage::assertExists(Attachment::findOrFail($id)->path);

        Sanctum::actingAs($this->sara);
        $this->getJson('/api/tasks/mine')->assertJsonPath('data.0.attachments.0.name', 'plan.pdf');
        $this->get("/api/attachments/$id")->assertOk()->assertHeader('content-disposition', 'attachment; filename=plan.pdf');
        $this->postJson("/api/attachments/task/$task", ['files' => [$this->pdf('x.pdf')]])->assertForbidden();
        $this->deleteJson("/api/attachments/$id")->assertForbidden();

        Sanctum::actingAs($this->omar);
        $this->get("/api/attachments/$id")->assertNotFound();
    }

    public function test_an_assignee_hands_in_files_that_only_they_the_giver_and_management_see(): void
    {
        [$task, $part] = $this->task();
        Sanctum::actingAs($this->sara);
        $img = $this->postJson("/api/attachments/task_part/$part", ['files' => [UploadedFile::fake()->image('proof.png'), $this->pdf('report.pdf')]])->assertCreated()->assertJsonPath('data.0.is_image', true)->json('data.0.id');
        $this->getJson('/api/tasks/mine')->assertJsonPath('data.0.assignees.0.attachments.1.name', 'report.pdf')->assertJsonPath('data.0.my_part_id', $part);

        Sanctum::actingAs($this->principal);
        $this->getJson('/api/tasks/given')->assertJsonCount(2, 'data.0.assignees.0.attachments');
        $this->get("/api/attachments/$img")->assertOk();
        $this->postJson("/api/attachments/task_part/$part", ['files' => [$this->pdf()]])->assertForbidden();

        Sanctum::actingAs($this->omar);
        $this->get("/api/attachments/$img")->assertNotFound();
    }

    public function test_only_allowed_types_and_a_limited_number_of_files(): void
    {
        [$task] = $this->task();
        $this->postJson("/api/attachments/task/$task", ['files' => [UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')]])->assertStatus(422);
        $this->postJson("/api/attachments/task/$task", ['files' => array_map(fn ($i) => $this->pdf("f$i.pdf"), range(1, 11))])->assertStatus(422);
        $this->postJson("/api/attachments/task/$task", ['files' => array_map(fn ($i) => $this->pdf("f$i.pdf"), range(1, 8))])->assertCreated();
        $this->postJson("/api/attachments/task/$task", ['files' => array_map(fn ($i) => $this->pdf("g$i.pdf"), range(1, 3))])->assertStatus(422);
        $this->postJson('/api/attachments/other/1', ['files' => [$this->pdf()]])->assertNotFound();
    }

    public function test_removing_a_file_or_deleting_the_task_deletes_the_files_from_the_disk(): void
    {
        [$task, $part] = $this->task();
        $a = $this->postJson("/api/attachments/task/$task", ['files' => [$this->pdf()]])->json('data.0.id');
        Sanctum::actingAs($this->sara);
        $b = $this->postJson("/api/attachments/task_part/$part", ['files' => [$this->pdf('mine.pdf')]])->json('data.0.id');
        Sanctum::actingAs($this->principal);
        $pathA = Attachment::find($a)->path;
        $pathB = Attachment::find($b)->path;
        $this->deleteJson("/api/attachments/$a")->assertNoContent();
        Storage::assertMissing($pathA);
        Storage::assertExists($pathB);
        $this->deleteJson("/api/tasks/$task")->assertNoContent();
        Storage::assertMissing($pathB);
        $this->assertSame(0, Attachment::count());
        $this->assertSame(0, Task::count());
    }

    public function test_a_survey_or_approval_can_carry_an_image_that_its_recipients_see(): void
    {
        $parent = User::factory()->role(Role::Guardian)->create();
        $stranger = User::factory()->role(Role::Guardian)->create();
        $survey = Survey::create(['created_by' => $this->principal->id, 'type' => 'approval', 'title' => 'Trip', 'audience' => 'all', 'respondents' => 'parents']);
        SurveyRecipient::create(['survey_id' => $survey->id, 'user_id' => $parent->id]);

        Sanctum::actingAs($this->principal);
        $id = $this->postJson("/api/attachments/survey/{$survey->id}", ['files' => [UploadedFile::fake()->image('trip.jpg')]])->assertCreated()->json('data.0.id');
        $this->getJson("/api/surveys/{$survey->id}")->assertJsonPath('data.attachments.0.name', 'trip.jpg');

        Sanctum::actingAs($parent);
        $this->getJson('/api/my-surveys')->assertJsonPath('data.0.attachments.0.id', $id);
        $this->get("/api/attachments/$id")->assertOk();
        $this->postJson("/api/attachments/survey/{$survey->id}", ['files' => [$this->pdf()]])->assertForbidden();

        Sanctum::actingAs($stranger);
        $this->get("/api/attachments/$id")->assertNotFound();

        Sanctum::actingAs($this->principal);
        $this->deleteJson("/api/surveys/{$survey->id}")->assertNoContent();
        $this->assertSame(0, Attachment::count());
    }
}
