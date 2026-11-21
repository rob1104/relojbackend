<?php

namespace App\Models;

use App\Traits\AuditableActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    use AuditableActivity;

    protected string $activityLogName = 'imports';
    protected string $activityModelLabel = 'lote de importación';
    protected $fillable = [
        'user_id',
        'company_id',
        'filenames',
        'source_type',
        'total_rows',
        'inserted_events',
        'duplicate_events',
        'employees_touched',
        'date_from',
        'date_to',
        'notes',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'filenames' => 'array',
            'date_from' => 'date:Y-m-d',
            'date_to' => 'date:Y-m-d',
            'is_visible' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }
}
