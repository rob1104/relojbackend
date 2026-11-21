<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Traits\CausesActivity;
use App\Traits\AuditableActivity;

#[Fillable([
    'name',
    'username',
    'email',
    'password',
    'role',
    'position',
    'department',
    'company_id',
    'phone',
    'active',
    'last_login_at',
    'notes',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, AuditableActivity, CausesActivity;

    protected string $activityLogName = 'users';
    protected string $activityModelLabel = 'usuario';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function correctionLogs(): HasMany
    {
        return $this->hasMany(AttendanceCorrectionLog::class, 'user_id');
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class, 'user_id');
    }
}
