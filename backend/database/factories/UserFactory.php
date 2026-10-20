<?php

namespace Database\Factories;

use App\Models\User;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('05########'),
            'password' => static::$password ??= Hash::make('password'),
            'role' => Role::Teacher,
            'is_active' => true,
        ];
    }

    public function role(Role $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    /** A member of the school staff: account role and staff profile follow the type. */
    public function staff(StaffType $type = StaffType::Teacher, array $profile = []): static
    {
        return $this->state(fn () => ['role' => $type->accountRole()])
            ->afterCreating(fn (User $user) => $user->staffProfile()->create([...['staff_type' => $type], ...$profile]));
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
