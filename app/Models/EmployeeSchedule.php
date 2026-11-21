<?php

namespace App\Models;

use App\Traits\AuditableActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSchedule extends Model
{
    use AuditableActivity;

    protected string $activityLogName = 'schedules';
    protected string $activityModelLabel = 'horario de empleado';
    protected $fillable = [
        'biotime_pk',
        'needs_push',
        'employee_id',
        'biotime_emp_pk',
        'biotime_emp_code',
        'shift_id',
        'biotime_shift_pk',
        'shift_name',
        'timetable_name',
        'check_in',
        'check_out',
        'break_rule_id',
        'break_rule_name',
        'break_mode',
        'break_minutes',
        'break_start_time',
        'break_end_time',
        'auto_deduct_break',
        'start_date',
        'end_date',
        'assigned_by_dept',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'needs_push' => 'boolean',
            'assigned_by_dept' => 'boolean',
            'auto_deduct_break' => 'boolean',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
