<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BreakRule extends Model
{
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
