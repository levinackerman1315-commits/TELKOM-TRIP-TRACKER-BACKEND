<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Advance extends Model
{
    use HasFactory;

    protected $table = 'advances';
    protected $primaryKey = 'advance_id';

    protected $fillable = [
        'trip_id',
        'advance_number',
        'request_type',
        'requested_amount',
        'approved_amount',
        'request_reason',
        'rejection_reason',
        'notes',
        'supporting_document_path',
        'supporting_document_name',
        'status',
        'requested_by',
        'requested_at',
        'approved_by_area',
        'approved_at_area',
        'approved_by_regional',
        'approved_at_regional',
        'transfer_date',
        'transfer_reference'
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'approved_at_area' => 'datetime',
        'approved_at_regional' => 'datetime',
        'transfer_date' => 'date',
        'requested_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2'
    ];

    // ✅ Relation ke Trip
    public function trip()
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    // ✅ FIX: Ganti dari employee_id ke requested_by!
    public function employee()
    {
        return $this->belongsTo(User::class, 'requested_by', 'user_id');
    }

    // ✅ Alias untuk employee (lebih jelas)
    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by', 'user_id');
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
        return $this->hasMany(AdvanceStatusHistory::class, 'advance_id', 'advance_id')
                    ->orderBy('changed_at', 'desc');
    }
}