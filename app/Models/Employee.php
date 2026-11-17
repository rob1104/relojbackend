<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'biotime_id',
        'name',
        'department',
        'position',
        'notes',
        'custom_ref_slots',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'custom_ref_slots' => 'array',
            'active' => 'boolean',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
    }

    public function correctionLogs(): HasMany
    {
        return $this->hasMany(AttendanceCorrectionLog::class);
    }
}
