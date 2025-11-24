<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $table = 'users';
    protected $primaryKey = 'user_id';
    public $timestamps = true;

    protected $fillable = [
        'nik',
        'name',
        'email',
        'password',
        'role',
        'phone',
        'department',
        'position',
        'office_location',
        'area',
        'regional',
        'area_code',
        'bank_account',           // ✅ For advance transfers
        'bank_name',              // ✅ For advance transfers
        'is_active',
        'password_changed_at',    // ✅ Track password changes
        'must_change_password',   // ✅ Force password change for new users
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'must_change_password' => 'boolean',
        'password_changed_at' => 'datetime',
    ];

    protected $attributes = [
        'is_active' => 1,
        'must_change_password' => true,
    ];

    // JWT Methods
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'user_id' => $this->user_id,
            'role' => $this->role,
            'email' => $this->email,
            'must_change_password' => $this->must_change_password,
        ];
    }

    // Relationships
    public function trips()
    {
        return $this->hasMany(Trip::class, 'user_id', 'user_id');
    }

    public function advances()
    {
        return $this->hasMany(Advance::class, 'user_id', 'user_id');
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class, 'user_id', 'user_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'user_id', 'user_id');
    }
}