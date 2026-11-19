<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $fillable = [
        'name',
        'biotime_pk',
        'needs_push',
        'timetable',
        'unit',
        'cycle',
        'auto_shift',
        'work_weekend',
        'weekly_schedule',
    ];

    protected function casts(): array
    {
        return [
            'needs_push' => 'boolean',
            'work_weekend' => 'boolean',
            'weekly_schedule' => 'array',
        ];
    }
}
