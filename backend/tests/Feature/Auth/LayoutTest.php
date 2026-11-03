<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Shared\Enums\Role;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class LayoutTest extends AcademicTestCase
{
    public function test_everybody_arranges_their_own_screens_and_can_go_back_to_the_default(): void
    {
        $mine = User::factory()->role(Role::Guardian)->create();
        $other = User::factory()->role(Role::Guardian)->create();
        Sanctum::actingAs($mine);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.layout', []);

        $this->putJson('/api/auth/layout', ['surface' => 'parent_home', 'order' => ['items' => ['fees', 'grades', 'fees']]])->assertOk()->assertJsonPath('data.parent_home.items', ['fees', 'grades']);
        $this->getJson('/api/auth/me')->assertJsonPath('data.layout.parent_home.items', ['fees', 'grades'])->assertJsonPath('data.layout.parent_home.groups', []);
        Sanctum::actingAs($other);
        $this->getJson('/api/auth/me')->assertJsonPath('data.layout', []); // nobody else is affected

        Sanctum::actingAs($mine);
        $this->putJson('/api/auth/layout', ['surface' => 'parent_home', 'order' => null])->assertOk();
        $this->getJson('/api/auth/me')->assertJsonPath('data.layout', []);
    }

    public function test_the_size_of_the_icons_and_a_colour_for_each_are_kept_and_checked(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::Guardian)->create());
        $this->putJson('/api/auth/layout', ['surface' => 'parent_home', 'order' => ['items' => ['fees'], 'size' => 'large', 'colors' => ['fees' => '#DC2626']]])->assertOk()
            ->assertJsonPath('data.parent_home.size', 'large')->assertJsonPath('data.parent_home.colors.fees', '#dc2626');
        $this->putJson('/api/auth/layout', ['surface' => 'parent_home', 'order' => ['size' => 'huge']])->assertStatus(422);
        $this->putJson('/api/auth/layout', ['surface' => 'parent_home', 'order' => ['colors' => ['fees' => 'red']]])->assertStatus(422);
        $this->putJson('/api/auth/layout', ['surface' => 'parent_home', 'order' => ['colors' => ['fees' => 'url(javascript:alert(1))']]])->assertStatus(422);
        $this->getJson('/api/auth/me')->assertJsonPath('data.layout.parent_home.colors.fees', '#dc2626'); // a refused change keeps what was saved
    }

    public function test_only_known_surfaces_and_short_lists_are_accepted(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $this->putJson('/api/auth/layout', ['surface' => 'secret_page', 'order' => ['items' => ['a']]])->assertStatus(422);
        $this->putJson('/api/auth/layout', ['surface' => 'admin_nav', 'order' => ['items' => array_map(fn ($i) => "k$i", range(1, 200))]])->assertStatus(422);
        $this->putJson('/api/auth/layout', ['surface' => 'admin_nav', 'order' => ['groups' => ['money', 'people'], 'items' => ['/admin/finance']]])->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();
    }
}
