<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Trip extends Model
{
    use HasFactory;

    protected $table = 'trips';
    protected $primaryKey = 'trip_id';

    protected $fillable = [
        'user_id',
        'trip_number',
        'destination',
        'purpose',
        'start_date',
        'end_date',
        'duration',
        'estimated_budget',
        'status',
        'extended_end_date',
        'extension_reason',
        'extension_requested_at',
        'total_advance',
        'total_expenses',
        'balance',
        'submitted_at',
        'completed_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'extended_end_date' => 'date',
        'extension_requested_at' => 'datetime',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'estimated_budget' => 'decimal:2',
        'total_advance' => 'decimal:2',
        'total_expenses' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    // ✅ RELASI - PASTIKAN SEMUA ADA
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function advances()
    {
        return $this->hasMany(Advance::class, 'trip_id', 'trip_id');
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class, 'trip_id', 'trip_id');
    }

    public function reviews()
    {
        return $this->hasMany(TripReview::class, 'trip_id', 'trip_id');
    }

    public function settlement()
    {
        return $this->hasOne(Settlement::class, 'trip_id', 'trip_id');
    }

    // ✅ INI YANG PENTING - RELASI HISTORY
    public function history()
    {
        return $this->hasMany(TripStatusHistory::class, 'trip_id', 'trip_id')
                    ->orderBy('changed_at', 'asc');
    }

    // ✅ ALIAS untuk backward compatibility
    public function statusHistory()
    {
        return $this->history();
    }
}