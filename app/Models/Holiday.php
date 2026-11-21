<?php

namespace App\Models;

use App\Traits\AuditableActivity;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use AuditableActivity;

    protected string $activityLogName = 'holidays';
    protected string $activityModelLabel = 'día festivo';
    protected $fillable = [
        'biotime_pk',
        'needs_push',
        'name',
        'start_date',
        'duration_days',
        'department_name',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'needs_push' => 'boolean',
            'start_date' => 'date:Y-m-d',
            'duration_days' => 'integer',
        ];
    }
}
