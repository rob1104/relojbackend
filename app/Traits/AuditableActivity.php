<?php

namespace App\Traits;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

trait AuditableActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        $logName = property_exists($this, 'activityLogName') ? $this->activityLogName : 'models';
        $except = property_exists($this, 'activityLogExcept') ? $this->activityLogExcept : ['password', 'remember_token'];

        return LogOptions::defaults()
            ->useLogName($logName)
            ->logFillable()
            ->logExcept($except)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at', 'remember_token', 'last_login_at', 'needs_push'])
            ->setDescriptionForEvent(function (string $eventName) {
                $label = property_exists($this, 'activityModelLabel')
                    ? $this->activityModelLabel
                    : class_basename($this);

                $verb = match ($eventName) {
                    'created' => 'creó',
                    'updated' => 'actualizó',
                    'deleted' => 'eliminó',
                    default => $eventName,
                };

                return "Se {$verb} {$label}";
            });
    }
}
