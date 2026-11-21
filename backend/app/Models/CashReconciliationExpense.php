<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashReconciliationExpense extends Model
{
    protected $fillable = ['expense_type_id', 'type_name', 'detail', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
