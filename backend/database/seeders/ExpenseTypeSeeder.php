<?php

namespace Database\Seeders;

use App\Models\ExpenseType;
use Illuminate\Database\Seeder;

class ExpenseTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'fletes' => 'Fletes',
            'gasto_local' => 'Gasto Local',
            'devolucion' => 'Devolución',
            'vale' => 'Vale',
        ] as $key => $name) {
            ExpenseType::firstOrCreate(['seed_key' => $key], ['name' => $name]);
        }
    }
}
