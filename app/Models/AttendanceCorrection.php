<?php

namespace App\Models;

use App\Traits\AuditableActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrection extends Model
{
    use AuditableActivity;

    protected string $activityLogName = 'attendance';
    protected string $activityModelLabel = 'corrección de asistencia';
    protected $fillable = [
        'employee_id',
        'biotime_id',
        'work_date',
        'slots',
        'reason',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'slots' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
