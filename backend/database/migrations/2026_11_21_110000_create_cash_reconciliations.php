<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('sales', 14, 2);
            $table->decimal('cash', 14, 2);
            $table->decimal('bank', 14, 2);
            $table->decimal('qr', 14, 2);
            $table->decimal('expenses_total', 16, 2)->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['cash_register_id', 'date']);
        });

        Schema::create('cash_reconciliation_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_reconciliation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_type_id')->constrained()->restrictOnDelete();
            $table->string('type_name');
            $table->string('detail');
            $table->decimal('amount', 14, 2);
            $table->timestamps();
        });

        foreach (['reconciliations.view', 'reconciliations.create', 'reconciliations.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', ['reconciliations.view', 'reconciliations.create', 'reconciliations.update'])
            ->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Schema::dropIfExists('cash_reconciliation_expenses');
        Schema::dropIfExists('cash_reconciliations');
    }
};
