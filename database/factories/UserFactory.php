<?php

namespace Database\Factories;

use App\Models\Orgao;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'orgao_id' => Orgao::factory(),
            'ativo' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function inativo(): static
    {
        return $this->state(fn () => ['ativo' => false]);
    }

    /** Usuário com um perfil já atribuído. O administrador geral não pertence a órgão. */
    public function comPerfil(string $perfil): static
    {
        return $this
            ->state(fn () => $perfil === 'administrador-geral' ? ['orgao_id' => null] : [])
            ->afterCreating(fn (User $user) => $user->assignRole($perfil));
    }

    public function comDoisFatores(): static
    {
        return $this->state(fn () => [
            'two_factor_secret' => encrypt('SEGREDO-DE-TESTE'),
            'two_factor_recovery_codes' => encrypt(json_encode(['codigo-1', 'codigo-2'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
