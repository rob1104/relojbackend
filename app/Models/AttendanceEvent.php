<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceEvent extends Model
{
    protected $fillable = [
        'employee_id',
        'biotime_id',
        'import_batch_id',
        'event_date',
        'event_time',
        'attendance_status',
        'temperature',
        'mask',
        'data_source',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date:Y-m-d',
            'is_visible' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }
}
