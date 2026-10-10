<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    protected $signature = 'central:create-admin';

    protected $description = 'Provisiona o único administrador com senha interativa (nunca por argumento).';

    public function handle(): int
    {
        if (User::exists()) {
            $this->error('Administrador já provisionado.');

            return self::FAILURE;
        }
        $name = $this->ask('Nome');
        $email = $this->ask('E-mail');
        $password = $this->secret('Senha (mínimo 12 caracteres)');
        $confirmation = $this->secret('Confirme a senha');
        if (! is_string($name) || trim($name) === '' || strlen($name) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! is_string($password) || strlen($password) < 12 || $password !== $confirmation) {
            $this->error('Dados inválidos. Nenhum usuário criado.');

            return self::FAILURE;
        }
        $lock = Cache::lock('central:create-admin', 30);
        if (! $lock->get()) {
            $this->error('Provisionamento já em andamento.');

            return self::FAILURE;
        }
        try {
            if (User::exists()) {
                $this->error('Administrador já provisionado.');

                return self::FAILURE;
            }
            DB::transaction(function () use ($name, $email, $password) {
                $u = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password)]);
                DB::table('audit_events')->insert(['user_id' => $u->id, 'event' => 'admin_created', 'created_at' => now()]);
            });
        } finally {
            $lock->release();
        }
        $this->info('Administrador criado.');

        return self::SUCCESS;
    }
}
