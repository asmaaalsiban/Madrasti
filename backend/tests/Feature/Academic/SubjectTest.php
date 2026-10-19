<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\Subject;
use App\Shared\Enums\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

class SubjectTest extends AcademicTestCase
{
    public function test_admin_can_crud_subject(): void
    {
        $this->actAsAdmin();

        $id = $this->postJson('/api/subjects', ['code' => 'MTH', 'name_ar' => 'رياضيات', 'name_en' => 'Math'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'MTH')
            ->json('data.id');

        $this->getJson('/api/subjects')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonStructure(['data', 'meta', 'links']);
        $this->getJson("/api/subjects/$id")->assertOk()->assertJsonPath('data.name_en', 'Math');
        $this->putJson("/api/subjects/$id", ['code' => 'MTH', 'name_ar' => 'رياضيات', 'name_en' => 'Maths'])
            ->assertOk()->assertJsonPath('data.name_en', 'Maths');
        $this->deleteJson("/api/subjects/$id")->assertNoContent();
        $this->assertDatabaseMissing('subjects', ['id' => $id]);
    }

    public function test_search_filters_by_name_and_code(): void
    {
        $this->actAsAdmin();
        Subject::factory()->create(['code' => 'MTH', 'name_en' => 'Math', 'name_ar' => 'رياضيات']);
        Subject::factory()->create(['code' => 'SCI', 'name_en' => 'Science', 'name_ar' => 'علوم']);

        $this->getJson('/api/subjects?search=sci')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'SCI');
        $this->getJson('/api/subjects?search=رياضيات')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'MTH');
        $this->getJson('/api/subjects?search=zzz')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_code_must_be_unique_but_may_keep_own(): void
    {
        $this->actAsAdmin();
        $math = Subject::factory()->create(['code' => 'MTH']);
        Subject::factory()->create(['code' => 'SCI']);
        $body = ['code' => 'MTH', 'name_ar' => 'ر', 'name_en' => 'M'];

        $this->putJson("/api/subjects/{$math->id}", $body)->assertOk();
        $this->putJson("/api/subjects/{$math->id}", [...$body, 'code' => 'SCI'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/subjects', $body)->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_validation_errors_are_translated(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/subjects', [], ['Accept-Language' => 'ar'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', __('validation.required', ['attribute' => 'code'], 'ar'));
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_is_forbidden(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->getJson('/api/subjects')->assertForbidden();
        $this->postJson('/api/subjects', ['code' => 'X', 'name_ar' => 'x', 'name_en' => 'x'])->assertForbidden();
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->getJson('/api/subjects')->assertUnauthorized();
        $this->postJson('/api/subjects', [])->assertUnauthorized();
    }
}
