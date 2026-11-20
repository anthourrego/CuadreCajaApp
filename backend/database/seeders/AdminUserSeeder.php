<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use LogicException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $username = 'admin';
        $email = 'admin@cuadrecaja.test';
        $existing = User::query()
            ->where('username', $username)
            ->orWhere('email', $email)
            ->first();

        if ($existing !== null) {
            if (! $existing->isAdmin()) {
                throw new LogicException('El usuario o correo reservado para el administrador inicial ya pertenece a una cuenta no administradora.');
            }

            $this->command?->info('El administrador inicial ya existe; no se modificó su cuenta.');

            return;
        }

        $password = Str::random(48);
        $role = Role::where('name', 'admin')->where('guard_name', 'web')->firstOrFail();

        User::create([
            'name' => 'Administrador',
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->command?->info('Se creó el administrador inicial.');
        $this->command?->line("Usuario: {$username}");
        $this->command?->line("Contraseña inicial: {$password}");
        $this->command?->warn('Guarda esta contraseña ahora; no se volverá a mostrar.');
    }
}
