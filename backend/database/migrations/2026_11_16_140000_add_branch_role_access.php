<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_role', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->primary(['branch_id', 'role_id']);
        });

        $direct = DB::table('branch_user')
            ->join('model_has_roles', 'branch_user.user_id', '=', 'model_has_roles.model_id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('roles.guard_name', 'web')
            ->whereIn('roles.name', ['supervisor', 'cajero'])
            ->select('branch_user.branch_id', 'roles.id as role_id');
        $cashiers = DB::table('cash_register_user')
            ->join('cash_registers', 'cash_registers.id', '=', 'cash_register_user.cash_register_id')
            ->join('model_has_roles', 'cash_register_user.user_id', '=', 'model_has_roles.model_id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('roles.guard_name', 'web')
            ->where('roles.name', 'cajero')
            ->select('cash_registers.branch_id', 'roles.id as role_id');

        DB::table('branch_role')->insertUsing(['branch_id', 'role_id'], $direct->union($cashiers));
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_role');
    }
};
