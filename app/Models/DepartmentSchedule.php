<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentSchedule extends Model
{
    protected $fillable = [
        'department_id',
        'department_code',
        'department_name',
        'shift_id',
        'biotime_shift_pk',
        'shift_name',
        'timetable_name',
        'check_in',
        'check_out',
        'start_date',
        'end_date',
        'employees_assigned_count',
        'synced_count',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
