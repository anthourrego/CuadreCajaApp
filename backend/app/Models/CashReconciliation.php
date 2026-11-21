<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashReconciliation extends Model
{
    protected $fillable = ['cash_register_id', 'branch_id', 'date', 'sales', 'cash', 'bank', 'qr', 'created_by', 'updated_by', 'version'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['sales' => 'decimal:2', 'cash' => 'decimal:2', 'bank' => 'decimal:2', 'qr' => 'decimal:2', 'expenses_total' => 'decimal:2'];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(CashReconciliationExpense::class);
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
