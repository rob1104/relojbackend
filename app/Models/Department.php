<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $fillable = [
        'code',
        'biotime_pk',
        'needs_push',
        'name',
        'parent_department',
        'employee_count',
        'resigned_count',
    ];

    protected function casts(): array
    {
        return ['needs_push' => 'boolean'];
    }
}
