<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_admin_with_a_hashed_password(): void
    {
        $this->artisan('app:create-admin')
            ->expectsQuestion('Nombre del administrador', 'Administrador')
            ->expectsQuestion('Nombre de usuario', 'Admin.test')
            ->expectsQuestion('Correo electrónico', 'admin@example.test')
            ->expectsQuestion('Contraseña', 'password-seguro-123')
            ->expectsQuestion('Confirma la contraseña', 'password-seguro-123')
            ->assertSuccessful();

        $user = User::where('email', 'admin@example.test')->firstOrFail();

        $this->assertSame('admin.test', $user->username);
        $this->assertTrue($user->isAdmin());
        $this->assertTrue(Hash::check('password-seguro-123', $user->password));
    }

    public function test_it_rejects_mismatched_passwords_without_creating_a_user(): void
    {
        $this->artisan('app:create-admin')
            ->expectsQuestion('Nombre del administrador', 'Administrador')
            ->expectsQuestion('Nombre de usuario', 'admin.test')
            ->expectsQuestion('Correo electrónico', 'admin@example.test')
            ->expectsQuestion('Contraseña', 'password-seguro-123')
            ->expectsQuestion('Confirma la contraseña', 'otra-password-123')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);
    }
}
