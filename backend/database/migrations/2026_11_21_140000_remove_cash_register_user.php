<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_register_user')) {
            return;
        }

        if (DB::table('cash_register_user')->exists()) {
            throw new RuntimeException('La tabla cash_register_user contiene asignaciones; revísalas antes de eliminarla.');
        }

        Schema::drop('cash_register_user');
    }

    public function down(): void
    {
        throw new RuntimeException('La eliminación de cash_register_user requiere una reversión manual.');
    }
};
