<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Timetable extends Model
{
    protected $fillable = [
        'name',
        'biotime_pk',
        'needs_push',
        'type',
        'check_in',
        'check_out',
        'in_ahead_margin',
        'in_above_margin',
        'out_ahead_margin',
        'out_above_margin',
        'work_time_minutes',
        'break_minutes',
        'break_rule_id',
        'break_mode',
        'auto_deduct_break',
        'work_day',
        'work_type',
        'in_required',
        'out_required',
        'allow_late',
        'allow_leave_early',
    ];

    protected function casts(): array
    {
        return [
            'work_day' => 'float',
            'needs_push' => 'boolean',
            'auto_deduct_break' => 'boolean',
        ];
    }
}
