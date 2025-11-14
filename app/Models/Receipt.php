<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Receipt extends Model
{
    use HasFactory;

    protected $primaryKey = 'receipt_id';
    public $timestamps = false;

    protected $fillable = [
        'trip_id', 'advance_id', 'receipt_number', 'receipt_date',
        'amount', 'category', 'merchant_name', 'description',
        'file_path', 'file_name', 'file_size', 'is_verified',
        'verified_by', 'verified_at', 'verification_notes', 'uploaded_at'
    ];

    protected $casts = [
        'receipt_date' => 'date',
        'amount' => 'decimal:2',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'uploaded_at' => 'datetime',
    ];

    public function trip()
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function advance()
    {
        return $this->belongsTo(Advance::class, 'advance_id', 'advance_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by', 'user_id');
    }
}