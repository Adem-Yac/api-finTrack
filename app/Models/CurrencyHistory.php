<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurrencyHistory extends Model
{
    protected $table = 'currency_history';

    protected $fillable = [
        'base',
        'quote',
        'provider',
        'market',
        'rate_date',
        'rate',
        'buy',
        'sell',
    ];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'rate' => 'decimal:6',
            'buy' => 'decimal:6',
            'sell' => 'decimal:6',
        ];
    }
}
