<?php

namespace Database\Factories;

use App\Models\Perfil;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
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

    /** Flag para o state semPerfil: criado sem perfil nenhum. */
    protected static bool $semPerfil = false;

    /** Flag para o state membro: entra no quadro, mas não faz triagem. */
    protected static bool $membro = false;

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

    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            // Um usuário de fábrica navega pelo sistema inteiro: sem perfil ele
            // tomaria 403 do middleware de permissão em todas as telas. Admin
            // tem todas as permissões — o papel dos testes é exercitar telas,
            // não a autorização (que tem testes próprios).
            if (static::$semPerfil) {
                static::$semPerfil = false;

                return;
            }

            (new PerfilPermissaoSeeder)->run();

            $slug = 'admin';

            if (static::$membro) {
                static::$membro = false;
                $slug = 'membro';
            }

            $user->perfis()->attach(Perfil::where('slug', $slug)->value('id'));
        });
    }

    /**
     * Usuário que trabalha no quadro mas não organiza o trabalho dos outros:
     * abre, comenta, bloqueia e move as próprias tarefas, e não prioriza nem
     * direciona.
     */
    public function membro(): static
    {
        static::$membro = true;

        return $this;
    }

    /**
     * Usuário sem perfil nenhum — para testar a negação do middleware de
     * permissão. (Opcional, fora do padrão do factory.)
     */
    public function semPerfil(): static
    {
        static::$semPerfil = true;

        return $this;
    }
}
