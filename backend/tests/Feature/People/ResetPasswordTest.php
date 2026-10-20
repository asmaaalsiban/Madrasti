<?php

namespace Tests\Feature\People;

use App\Models\User;
use App\Shared\Enums\Role;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Academic\AcademicTestCase;

class ResetPasswordTest extends AcademicTestCase
{
    #[DataProvider('resettableRoles')]
    public function test_admin_resets_a_user_back_to_the_default_password(Role $role): void
    {
        $this->actAsAdmin();
        $user = User::factory()->role($role)->create(['password' => 'TheirOwnPass1', 'must_change_password' => false]);
        $user->createToken('web');

        $this->postJson("/api/users/{$user->id}/reset-password")->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check(config('accounts.default_password'), $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertSame(0, $user->tokens()->count());
    }

    /**
     * @return array<string, array{0: Role}>
     */
    public static function resettableRoles(): array
    {
        return ['teacher' => [Role::Teacher], 'student' => [Role::Student], 'parent' => [Role::Guardian]];
    }

    public function test_an_admin_account_cannot_be_reset_this_way(): void
    {
        $this->actAsAdmin();
        $other = User::factory()->role(Role::Admin)->create(['password' => 'AdminOwnPass1']);

        $this->postJson("/api/users/{$other->id}/reset-password")->assertForbidden();
        $this->assertTrue(Hash::check('AdminOwnPass1', $other->fresh()->password));
    }

    public function test_unknown_or_deleted_users_are_not_found(): void
    {
        $this->actAsAdmin();
        $gone = User::factory()->role(Role::Student)->create();
        $gone->delete();

        $this->postJson('/api/users/99999/reset-password')->assertNotFound();
        $this->postJson("/api/users/{$gone->id}/reset-password")->assertNotFound();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_is_forbidden(Role $role): void
    {
        $victim = User::factory()->role(Role::Student)->create(['password' => 'TheirOwnPass1']);
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->postJson("/api/users/{$victim->id}/reset-password")->assertForbidden();
        $this->assertTrue(Hash::check('TheirOwnPass1', $victim->fresh()->password));
    }
}
