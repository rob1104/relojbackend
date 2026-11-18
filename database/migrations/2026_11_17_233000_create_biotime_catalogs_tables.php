<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('device_privilege', 100)->nullable()->after('department');
            $table->text('area')->nullable()->after('device_privilege');
            $table->string('app_status', 64)->nullable()->after('area');
            $table->string('fingerprint', 64)->nullable()->after('app_status');
            $table->string('face', 64)->nullable()->after('fingerprint');
            $table->string('palm', 64)->nullable()->after('face');
            $table->string('vl_face', 64)->nullable()->after('palm');
        });

        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('parent_area')->nullable();
            $table->integer('device_count')->default(0);
            $table->integer('employee_count')->default(0);
            $table->integer('resigned_count')->default(0);
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('parent_position')->nullable();
            $table->integer('employee_count')->default(0);
            $table->integer('terminated_count')->default(0);
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('parent_department')->nullable();
            $table->integer('employee_count')->default(0);
            $table->integer('resigned_count')->default(0);
            $table->timestamps();
        });

        Schema::create('break_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('start_time', 32);
            $table->string('end_time', 32);
            $table->integer('duration_minutes')->default(0);
            $table->string('calculation_type', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number', 100)->unique();
            $table->string('name');
            $table->string('area')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('last_activity', 64)->nullable();
            $table->integer('users_count')->default(0);
            $table->integer('fingerprints_count')->default(0);
            $table->integer('faces_count')->default(0);
            $table->integer('palms_count')->default(0);
            $table->integer('events_count')->default(0);
            $table->integer('cmd_count')->default(0);
            $table->timestamps();
        });

        Schema::create('timetables', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('type', 64)->nullable();
            $table->string('check_in', 32)->nullable();
            $table->string('check_out', 32)->nullable();
            $table->integer('work_time_minutes')->default(0);
            $table->integer('break_minutes')->default(0);
            $table->decimal('work_day', 6, 2)->default(1.0);
            $table->string('work_type', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->text('timetable')->nullable();
            $table->string('unit', 64)->nullable();
            $table->integer('cycle')->default(1);
            $table->string('auto_shift', 32)->default('False');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('timetables');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('break_rules');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('areas');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'device_privilege',
                'area',
                'app_status',
                'fingerprint',
                'face',
                'palm',
                'vl_face',
            ]);
        });
    }
};
