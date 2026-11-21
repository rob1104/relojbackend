<?php

namespace App\Models;

use App\Traits\AuditableActivity;
use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    use AuditableActivity;

    protected string $activityLogName = 'catalogs';
    protected string $activityModelLabel = 'área';
    protected $fillable = [
        'code',
        'biotime_pk',
        'needs_push',
        'name',
        'parent_area',
        'device_count',
        'employee_count',
        'resigned_count',
    ];

    protected function casts(): array
    {
        return ['needs_push' => 'boolean'];
    }
}
