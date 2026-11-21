<?php

namespace App\Models;

use App\Traits\AuditableActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLeave extends Model
{
    use AuditableActivity;

    protected string $activityLogName = 'leaves';
    protected string $activityModelLabel = 'incidencia / permiso';
    protected $fillable = [
        'biotime_pk',
        'needs_push',
        'employee_id',
        'biotime_emp_pk',
        'biotime_emp_code',
        'leave_type',
        'biotime_category_id',
        'category_name',
        'imss_folio',
        'incapacity_type',
        'start_date',
        'end_date',
        'days_count',
        'status',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'needs_push' => 'boolean',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'days_count' => 'float',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Calcula los días de vacaciones que corresponden por ley según Art. 76 LFT (Vacaciones Dignas).
     */
    public static function lftVacationDaysForYears(int $years): int
    {
        if ($years < 1) {
            return 0;
        }
        if ($years === 1) {
            return 12;
        }
        if ($years === 2) {
            return 14;
        }
        if ($years === 3) {
            return 16;
        }
        if ($years === 4) {
            return 18;
        }
        if ($years === 5) {
            return 20;
        }
        $extraBrackets = intdiv($years - 6, 5) + 1;

        return 20 + ($extraBrackets * 2);
    }
}
