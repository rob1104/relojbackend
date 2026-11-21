<?php

namespace App\Models;

use App\Traits\AuditableActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use AuditableActivity;

    protected string $activityLogName = 'employees';
    protected string $activityModelLabel = 'empleado';
    protected $fillable = [
        'biotime_id',
        'biotime_pk',
        'needs_push',
        'name',
        'first_name',
        'last_name',
        'nickname',
        'card_no',
        'device_password',
        'department',
        'company_id',
        'device_privilege',
        'area',
        'app_status',
        'fingerprint',
        'face',
        'palm',
        'vl_face',
        'position',
        'hire_date',
        'gender',
        'birthday',
        'emp_type',
        'verify_mode',
        'ssn',
        'mobile',
        'contact_tel',
        'email',
        'city',
        'address',
        'enable_att',
        'enable_overtime',
        'enable_holiday',
        'notes',
        'custom_ref_slots',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'custom_ref_slots' => 'array',
            'active' => 'boolean',
            'needs_push' => 'boolean',
            'enable_att' => 'boolean',
            'enable_overtime' => 'boolean',
            'enable_holiday' => 'boolean',
            'hire_date' => 'date:Y-m-d',
            'birthday' => 'date:Y-m-d',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
    }

    public function correctionLogs(): HasMany
    {
        return $this->hasMany(AttendanceCorrectionLog::class);
    }
}
