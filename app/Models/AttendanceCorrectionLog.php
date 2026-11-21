<?php

namespace App\Models;

use App\Traits\AuditableActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrectionLog extends Model
{
    use AuditableActivity;

    protected string $activityLogName = 'attendance';
    protected string $activityModelLabel = 'bitácora de corrección';

    protected $fillable = [
        'employee_id',
        'biotime_id',
        'user_id',
        'work_date',
        'action',
        'previous_slots',
        'new_slots',
        'original_events',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'previous_slots' => 'array',
            'new_slots' => 'array',
            'original_events' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
