<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TripStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'trip_status_history';
    protected $primaryKey = 'history_id';
    public $timestamps = false;

    protected $fillable = [
        'trip_id', 'old_status', 'new_status', 'changed_by', 'notes', 'changed_at'
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function trip()
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function changer()
    {
        return $this->belongsTo(User::class, 'changed_by', 'user_id');
    }
}