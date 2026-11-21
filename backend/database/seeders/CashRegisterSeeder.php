<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;
use LogicException;

class CashRegisterSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::where('code', '001')->firstOrFail();

        if ($branch->cashRegisters()->where('code', '001')->exists()) {
            return;
        }

        if (! $branch->is_active) {
            throw new LogicException('No se puede crear la caja inicial porque la sucursal principal está inactiva.');
        }

        $branch->cashRegisters()->firstOrCreate(
            ['code' => '001'],
            ['name' => 'Caja principal'],
        );
    }
}
