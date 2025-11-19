<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdvanceStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'advance_status_history';
    protected $primaryKey = 'history_id';
    public $timestamps = false;

    protected $fillable = [
        'advance_id', 'old_status', 'new_status', 'changed_by', 'notes', 'changed_at'
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    // ✅ Relation ke Advance
    public function advance()
    {
        return $this->belongsTo(Advance::class, 'advance_id', 'advance_id');
    }

    // ✅ Relation ke User (yang melakukan perubahan status)
    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by', 'user_id');
    }
}