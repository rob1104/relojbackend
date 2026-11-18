<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    protected $fillable = [
        'code',
        'biotime_pk',
        'needs_push',
        'name',
        'parent_position',
        'employee_count',
        'terminated_count',
    ];

    protected function casts(): array
    {
        return ['needs_push' => 'boolean'];
    }
}
