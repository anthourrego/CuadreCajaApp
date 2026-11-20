<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->char('code', 2)->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('municipalities', function (Blueprint $table) {
            $table->char('code', 5)->primary();
            $table->char('department_code', 2);
            $table->string('name');
            $table->string('type');
            $table->timestamps();
            $table->foreign('department_code')->references('code')->on('departments')->restrictOnDelete();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->char('municipality_code', 5)->nullable();
            $table->foreign('municipality_code')->references('code')->on('municipalities')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropForeign(['municipality_code']);
            $table->dropColumn('municipality_code');
        });
        Schema::dropIfExists('municipalities');
        Schema::dropIfExists('departments');
    }
};
