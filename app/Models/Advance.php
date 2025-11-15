<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Advance extends Model
{
    use HasFactory;

    protected $primaryKey = 'advance_id';
    
    // ✅ FIX: Enable timestamps
    public $timestamps = true; // ← UBAH DARI false JADI true!

    protected $fillable = [
        'trip_id', 'advance_number', 'request_type', 'requested_amount',
        'approved_amount', 'status', 'request_reason', 'transfer_date',
        'transfer_reference', 'requested_at', 'approved_by_area',
        'approved_at_area', 'approved_by_regional', 'approved_at_regional',
        'rejection_reason', 'notes'
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'transfer_date' => 'date',
        'requested_at' => 'datetime',
        'approved_at_area' => 'datetime',
        'approved_at_regional' => 'datetime',
        // ✅ TAMBAH INI untuk fix "Invalid Date"
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships
    public function trip()
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function approverArea()
    {
        return $this->belongsTo(User::class, 'approved_by_area', 'user_id');
    }

    public function approverRegional()
    {
        return $this->belongsTo(User::class, 'approved_by_regional', 'user_id');
    }

    public function statusHistory()
    {
        return $this->hasMany(AdvanceStatusHistory::class, 'advance_id', 'advance_id');
    }
}