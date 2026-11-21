<?php

namespace App\Models;

use App\Traits\AuditableActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use AuditableActivity;

    protected string $activityLogName = 'companies';
    protected string $activityModelLabel = 'empresa / razón social';
    protected $fillable = [
        'business_name',
        'rfc',
        'imss_employer_registration',
        'workplace_address',
        'device_identifier',
        'legal_representative',
        'notes',
        'active',
        'week_start_day',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'week_start_day' => 'integer',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
