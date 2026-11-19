<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timetables', function (Blueprint $table) {
            $table->unsignedBigInteger('break_rule_id')->nullable()->after('break_minutes');
            $table->string('break_mode', 24)->default('fixed')->after('break_rule_id');
            $table->boolean('auto_deduct_break')->default(false)->after('break_mode');
        });

        Schema::table('employee_schedules', function (Blueprint $table) {
            $table->unsignedBigInteger('break_rule_id')->nullable()->after('check_out');
            $table->string('break_rule_name', 120)->nullable()->after('break_rule_id');
            $table->string('break_mode', 24)->default('fixed')->after('break_rule_name');
            $table->unsignedInteger('break_minutes')->default(60)->after('break_mode');
            $table->string('break_start_time', 8)->nullable()->after('break_minutes');
            $table->string('break_end_time', 8)->nullable()->after('break_start_time');
            $table->boolean('auto_deduct_break')->default(false)->after('break_end_time');
        });

        Schema::table('department_schedules', function (Blueprint $table) {
            $table->unsignedBigInteger('break_rule_id')->nullable()->after('check_out');
            $table->string('break_rule_name', 120)->nullable()->after('break_rule_id');
            $table->string('break_mode', 24)->default('fixed')->after('break_rule_name');
            $table->unsignedInteger('break_minutes')->default(60)->after('break_mode');
            $table->string('break_start_time', 8)->nullable()->after('break_minutes');
            $table->string('break_end_time', 8)->nullable()->after('break_start_time');
            $table->boolean('auto_deduct_break')->default(false)->after('break_end_time');
        });
    }

    public function down(): void
    {
        Schema::table('timetables', function (Blueprint $table) {
            $table->dropColumn(['break_rule_id', 'break_mode', 'auto_deduct_break']);
        });

        Schema::table('employee_schedules', function (Blueprint $table) {
            $table->dropColumn([
                'break_rule_id',
                'break_rule_name',
                'break_mode',
                'break_minutes',
                'break_start_time',
                'break_end_time',
                'auto_deduct_break',
            ]);
        });

        Schema::table('department_schedules', function (Blueprint $table) {
            $table->dropColumn([
                'break_rule_id',
                'break_rule_name',
                'break_mode',
                'break_minutes',
                'break_start_time',
                'break_end_time',
                'auto_deduct_break',
            ]);
        });
    }
};
