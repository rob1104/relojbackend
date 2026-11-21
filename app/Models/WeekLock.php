<?php

namespace App\Models;

use App\Traits\AuditableActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeekLock extends Model
{
    use AuditableActivity;

    protected string $activityLogName = 'payroll';
    protected string $activityModelLabel = 'cierre de semana';

    protected $fillable = [
        'company_id',
        'week_start',
        'week_end',
        'locked_by',
        'locked_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'date:Y-m-d',
            'week_end' => 'date:Y-m-d',
            'locked_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
