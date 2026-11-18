<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $fillable = [
        'serial_number',
        'biotime_pk',
        'needs_push',
        'name',
        'terminal_name',
        'area',
        'ip_address',
        'real_ip',
        'state',
        'terminal_tz',
        'fw_ver',
        'push_ver',
        'transfer_time',
        'transfer_interval',
        'last_activity',
        'users_count',
        'fingerprints_count',
        'faces_count',
        'palms_count',
        'events_count',
        'cmd_count',
    ];

    protected function casts(): array
    {
        return ['needs_push' => 'boolean'];
    }
}
