<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('biotime_id', 64)->unique();
            $table->string('name');
            $table->string('department')->nullable();
            $table->string('position')->nullable();
            $table->text('notes')->nullable();
            $table->longText('custom_ref_slots')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->longText('filenames');
            $table->string('source_type', 32)->default('csv');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('inserted_events')->default(0);
            $table->unsignedInteger('duplicate_events')->default(0);
            $table->unsignedInteger('employees_touched')->default(0);
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('biotime_id', 64)->index();
            $table->foreignId('import_batch_id')->nullable()->constrained('import_batches')->cascadeOnDelete();
            $table->date('event_date')->index();
            $table->string('event_time', 8);
            $table->string('attendance_status', 64)->nullable();
            $table->string('temperature', 32)->nullable();
            $table->string('mask', 32)->nullable();
            $table->string('data_source', 64)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'event_date', 'event_time'], 'uniq_emp_date_time');
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('biotime_id', 64)->index();
            $table->date('work_date')->index();
            $table->longText('slots');
            $table->string('reason')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date'], 'uniq_corr_emp_date');
        });

        Schema::create('attendance_correction_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('biotime_id', 64)->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('work_date')->index();
            $table->string('action', 32); // 'save' or 'reset'
            $table->longText('previous_slots')->nullable();
            $table->longText('new_slots')->nullable();
            $table->longText('original_events')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_correction_logs');
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('attendance_events');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('employees');
    }
};
