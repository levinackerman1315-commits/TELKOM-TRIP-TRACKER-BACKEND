<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Settlement extends Model
{
    use HasFactory;

    protected $primaryKey = 'settlement_id';
    public $timestamps = false;

    protected $fillable = [
        'trip_id', 'settlement_number', 'total_advance', 'total_receipts',
        'balance', 'settlement_type', 'settlement_amount', 'settlement_date',
        'status', 'processed_by', 'processed_at', 'transfer_reference', 'notes', 'created_at'
    ];

    protected $casts = [
        'total_advance' => 'decimal:2',
        'total_receipts' => 'decimal:2',
        'balance' => 'decimal:2',
        'settlement_amount' => 'decimal:2',
        'settlement_date' => 'date',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function trip()
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by', 'user_id');
    }
}