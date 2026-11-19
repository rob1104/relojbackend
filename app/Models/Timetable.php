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
        'is_24h',
        'check_in',
        'check_out',
        'cross_day',
        'in_ahead_margin',
        'in_above_margin',
        'out_ahead_margin',
        'out_above_margin',
        'work_time_minutes',
        'break_minutes',
        'break_rule_id',
        'break_rule_ids',
        'break_mode',
        'auto_deduct_break',
        'work_day',
        'work_type',
        'in_required',
        'out_required',
        'allow_late',
        'allow_leave_early',
        'early_in_mode',
        'early_in_minutes',
        'late_out_mode',
        'late_out_minutes',
        'overtime_level',
        'overtime_level1_hours',
        'overtime_level2_hours',
        'overtime_level3_hours',
    ];

    protected function casts(): array
    {
        return [
            'work_day' => 'float',
            'needs_push' => 'boolean',
            'auto_deduct_break' => 'boolean',
            'is_24h' => 'boolean',
            'cross_day' => 'integer',
            'break_rule_ids' => 'array',
            'early_in_minutes' => 'integer',
            'late_out_minutes' => 'integer',
            'overtime_level1_hours' => 'float',
            'overtime_level2_hours' => 'float',
            'overtime_level3_hours' => 'float',
        ];
    }
}
