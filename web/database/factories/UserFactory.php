<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => 'test-only-password', // hashed cast 自动加密
            'display_name' => fake()->name(),
            'real_name' => null,
            'role' => UserRole::NormalUser,
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'activated_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function pendingEmailVerification(): static
    {
        return $this->state(fn () => [
            'status' => UserStatus::PendingEmailVerification,
            'email_verified_at' => null,
            'activated_at' => null,
        ]);
    }

    public function pendingActivation(): static
    {
        return $this->state(fn () => [
            'status' => UserStatus::PendingActivation,
            'email_verified_at' => now(),
            'activated_at' => null,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Disabled]);
    }

    public function role(UserRole $role, ?string $realName = null): static
    {
        return $this->state(fn () => [
            'role' => $role,
            'real_name' => $realName ?? ($role->requiresRealName() ? fake()->name() : null),
        ]);
    }
}
