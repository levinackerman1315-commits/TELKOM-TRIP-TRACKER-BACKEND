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
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login' => 'datetime',
        'password' => 'hashed',
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