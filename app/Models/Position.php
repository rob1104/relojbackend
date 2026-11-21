<?php

namespace App\Models;

use App\Traits\AuditableActivity;
use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    use AuditableActivity;

    protected string $activityLogName = 'catalogs';
    protected string $activityModelLabel = 'cargo / puesto';
    protected $fillable = [
        'code',
        'biotime_pk',
        'needs_push',
        'name',
        'parent_position',
        'employee_count',
        'terminated_count',
    ];

    protected function casts(): array
    {
        return ['needs_push' => 'boolean'];
    }
}
