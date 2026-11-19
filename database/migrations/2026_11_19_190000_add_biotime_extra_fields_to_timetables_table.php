<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timetables', function (Blueprint $table) {
            $table->boolean('is_24h')->default(false)->after('type');
            $table->unsignedTinyInteger('cross_day')->default(0)->after('check_out');
            $table->string('early_in_mode', 32)->default('ignore')->after('allow_leave_early');
            $table->unsignedInteger('early_in_minutes')->default(60)->after('early_in_mode');
            $table->string('late_out_mode', 32)->default('ignore')->after('early_in_minutes');
            $table->unsignedInteger('late_out_minutes')->default(60)->after('late_out_mode');
            $table->string('overtime_level', 32)->default('ignore')->after('late_out_minutes');
            $table->decimal('overtime_level1_hours', 5, 2)->default(0)->after('overtime_level');
            $table->decimal('overtime_level2_hours', 5, 2)->default(0)->after('overtime_level1_hours');
            $table->decimal('overtime_level3_hours', 5, 2)->default(0)->after('overtime_level2_hours');
        });
    }

    public function down(): void
    {
        Schema::table('timetables', function (Blueprint $table) {
            $table->dropColumn([
                'is_24h',
                'cross_day',
                'early_in_mode',
                'early_in_minutes',
                'late_out_mode',
                'late_out_minutes',
                'overtime_level',
                'overtime_level1_hours',
                'overtime_level2_hours',
                'overtime_level3_hours',
            ]);
        });
    }
};
