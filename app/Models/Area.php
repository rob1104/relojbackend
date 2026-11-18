<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
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
