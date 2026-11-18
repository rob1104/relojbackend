<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSchedule extends Model
{
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
