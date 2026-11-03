<?php

namespace Tests\Feature\People;

use App\Models\User;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class StaffVisibilityTest extends AcademicTestCase
{
    public function test_the_principal_hides_screens_from_chosen_staff_and_they_see_it_in_their_account(): void
    {
        $principal = User::factory()->staff(StaffType::Principal)->create();
        $sara = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Sara']);
        $omar = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Omar']);
        Sanctum::actingAs($principal);

        $this->getJson('/api/staff-visibility')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.hidden', [])->assertJsonPath('features.0', 'chat');
        $this->putJson('/api/staff-visibility', ['user_ids' => [$sara->id, $omar->id], 'hidden' => ['grades', 'procurement', 'grades']])->assertOk()->assertJsonPath('updated', 2);
        $this->putJson('/api/staff-visibility', ['user_ids' => [$omar->id], 'hidden' => []])->assertOk();

        $this->getJson('/api/staff-visibility')->assertJsonPath('data.0.name', 'Omar')->assertJsonPath('data.0.hidden', [])->assertJsonPath('data.1.hidden', ['grades', 'procurement']);

        Sanctum::actingAs($sara);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.hidden_features', ['grades', 'procurement']);
    }

    public function test_unknown_screens_are_refused_and_only_the_principal_can_change_them(): void
    {
        $principal = User::factory()->staff(StaffType::Principal)->create();
        $sara = User::factory()->staff(StaffType::Teacher)->create();
        Sanctum::actingAs($principal);
        $this->putJson('/api/staff-visibility', ['user_ids' => [$sara->id], 'hidden' => ['nonsense']])->assertStatus(422);
        $this->putJson('/api/staff-visibility', ['user_ids' => [$principal->id], 'hidden' => ['grades']])->assertOk()->assertJsonPath('updated', 0);

        Sanctum::actingAs($sara);
        $this->putJson('/api/staff-visibility', ['user_ids' => [$sara->id], 'hidden' => ['grades']])->assertForbidden();
        $this->getJson('/api/staff-visibility')->assertForbidden();
    }
}
