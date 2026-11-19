<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
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
