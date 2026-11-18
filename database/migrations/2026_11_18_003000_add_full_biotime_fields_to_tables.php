<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('nickname', 100)->nullable()->after('last_name');
            $table->string('card_no', 64)->nullable()->after('nickname');
            $table->string('device_password', 64)->nullable()->after('card_no');
            $table->date('hire_date')->nullable()->after('position');
            $table->string('gender', 16)->nullable()->after('hire_date');
            $table->date('birthday')->nullable()->after('gender');
            $table->integer('emp_type')->nullable()->default(1)->after('birthday');
            $table->integer('verify_mode')->nullable()->default(0)->after('emp_type');
            $table->string('ssn', 64)->nullable()->after('verify_mode');
            $table->string('mobile', 64)->nullable()->after('ssn');
            $table->string('contact_tel', 64)->nullable()->after('mobile');
            $table->string('email', 150)->nullable()->after('contact_tel');
            $table->string('city', 100)->nullable()->after('email');
            $table->string('address', 255)->nullable()->after('city');
            $table->boolean('enable_att')->default(true)->after('address');
            $table->boolean('enable_overtime')->default(false)->after('enable_att');
            $table->boolean('enable_holiday')->default(true)->after('enable_overtime');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->string('terminal_name', 100)->nullable()->after('name');
            $table->string('real_ip', 64)->nullable()->after('ip_address');
            $table->integer('state')->default(1)->after('real_ip');
            $table->integer('terminal_tz')->default(-6)->after('state');
            $table->string('fw_ver', 100)->nullable()->after('terminal_tz');
            $table->string('push_ver', 100)->nullable()->after('fw_ver');
            $table->string('transfer_time', 64)->nullable()->after('push_ver');
            $table->integer('transfer_interval')->default(1)->after('transfer_time');
        });

        Schema::table('timetables', function (Blueprint $table) {
            $table->integer('in_ahead_margin')->default(0)->after('check_out');
            $table->integer('in_above_margin')->default(120)->after('in_ahead_margin');
            $table->integer('out_ahead_margin')->default(0)->after('in_above_margin');
            $table->integer('out_above_margin')->default(120)->after('out_ahead_margin');
            $table->string('in_required', 16)->default('Si')->after('work_type');
            $table->string('out_required', 16)->default('Si')->after('in_required');
            $table->integer('allow_late')->default(0)->after('out_required');
            $table->integer('allow_leave_early')->default(0)->after('allow_late');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->boolean('work_weekend')->default(true)->after('auto_shift');
        });

        Schema::table('break_rules', function (Blueprint $table) {
            $table->integer('end_margin')->default(60)->after('duration_minutes');
            $table->integer('minimum_duration')->default(60)->after('end_margin');
            $table->integer('multiple_punch')->default(1)->after('minimum_duration');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'first_name', 'last_name', 'nickname', 'card_no', 'device_password',
                'hire_date', 'gender', 'birthday', 'emp_type', 'verify_mode',
                'ssn', 'mobile', 'contact_tel', 'email', 'city', 'address',
                'enable_att', 'enable_overtime', 'enable_holiday',
            ]);
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'terminal_name', 'real_ip', 'state', 'terminal_tz',
                'fw_ver', 'push_ver', 'transfer_time', 'transfer_interval',
            ]);
        });

        Schema::table('timetables', function (Blueprint $table) {
            $table->dropColumn([
                'in_ahead_margin', 'in_above_margin', 'out_ahead_margin', 'out_above_margin',
                'in_required', 'out_required', 'allow_late', 'allow_leave_early',
            ]);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['work_weekend']);
        });

        Schema::table('break_rules', function (Blueprint $table) {
            $table->dropColumn(['end_margin', 'minimum_duration', 'multiple_punch']);
        });
    }
};
