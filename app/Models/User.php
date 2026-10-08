<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role', // clinician | admin
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class, 'assigned_clinician_id');
    }

    public function alertThresholds(): HasMany
    {
        return $this->hasMany(AlertThreshold::class, 'clinician_id');
    }

    public function resolvedAlerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'resolved_by');
    }

    public function reportsGenerated(): HasMany
    {
        return $this->hasMany(Report::class, 'generated_by');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
