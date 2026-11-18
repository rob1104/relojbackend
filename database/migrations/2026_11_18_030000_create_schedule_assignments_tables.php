<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biotime_pk')->nullable()->index();
            $table->boolean('needs_push')->default(false);
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedBigInteger('biotime_emp_pk')->nullable()->index();
            $table->string('biotime_emp_code', 64)->index();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->unsignedBigInteger('biotime_shift_pk')->nullable()->index();
            $table->string('shift_name', 120)->nullable();
            $table->string('timetable_name', 160)->nullable();
            $table->string('check_in', 16)->nullable();
            $table->string('check_out', 16)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('assigned_by_dept')->default(false);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'start_date', 'end_date']);
        });

        Schema::create('department_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('department_code', 64)->index();
            $table->string('department_name', 160);
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->unsignedBigInteger('biotime_shift_pk')->nullable();
            $table->string('shift_name', 120)->nullable();
            $table->string('timetable_name', 160)->nullable();
            $table->string('check_in', 16)->nullable();
            $table->string('check_out', 16)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('employees_assigned_count')->default(0);
            $table->unsignedInteger('synced_count')->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_schedules');
        Schema::dropIfExists('employee_schedules');
    }
};
