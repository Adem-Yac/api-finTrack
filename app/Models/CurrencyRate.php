<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurrencyRate extends Model
{
    protected $fillable = [
        'base',
        'quote',
        'provider',
        'market',
        'rate',
        'buy',
        'sell',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'buy' => 'decimal:6',
            'sell' => 'decimal:6',
            'fetched_at' => 'datetime',
        ];
    }
}
