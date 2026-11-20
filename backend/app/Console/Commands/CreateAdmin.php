<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Crea un usuario administrador inicial.';

    public function handle(): int
    {
        $name = text('Nombre del administrador');
        $username = strtolower(trim(text('Nombre de usuario')));
        $email = text('Correo electrónico');
        $userPassword = password('Contraseña');
        $passwordConfirmation = password('Confirma la contraseña');

        if ($userPassword !== $passwordConfirmation) {
            $this->components->error('Las contraseñas no coinciden.');

            return self::FAILURE;
        }

        $validator = Validator::make([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => $userPassword,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9._-]+$/', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $role = Role::findOrCreate('admin', 'web');

        $user = User::create([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => $userPassword,
            'role_id' => $role->id,
        ]);

        $this->components->info("Administrador {$user->username} creado.");

        return self::SUCCESS;
    }
}
