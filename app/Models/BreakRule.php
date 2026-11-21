<?php

namespace App\Models;

use App\Traits\AuditableActivity;
use Illuminate\Database\Eloquent\Model;

class BreakRule extends Model
{
    use AuditableActivity;

    protected string $activityLogName = 'catalogs';
    protected string $activityModelLabel = 'regla de descanso';
    protected $fillable = [
        'name',
        'biotime_pk',
        'needs_push',
        'start_time',
        'end_time',
        'duration_minutes',
        'end_margin',
        'minimum_duration',
        'multiple_punch',
        'calculation_type',
    ];

    protected function casts(): array
    {
        return ['needs_push' => 'boolean'];
    }
}
