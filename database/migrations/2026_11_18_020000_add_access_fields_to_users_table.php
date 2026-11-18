<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $existing = collect(DB::select('SHOW COLUMNS FROM `users`'))
            ->pluck('Field')
            ->all();

        Schema::table('users', function (Blueprint $table) use ($existing) {
            if (! in_array('username', $existing, true)) {
                $table->string('username', 64)->nullable()->after('name');
            }
            if (! in_array('role', $existing, true)) {
                $table->string('role', 64)->default('Administrador')->after('email');
            }
            if (! in_array('position', $existing, true)) {
                $table->string('position', 120)->nullable()->after('role');
            }
            if (! in_array('department', $existing, true)) {
                $table->string('department', 120)->nullable()->after('position');
            }
            if (! in_array('phone', $existing, true)) {
                $table->string('phone', 64)->nullable()->after('department');
            }
            if (! in_array('active', $existing, true)) {
                $table->boolean('active')->default(true)->after('phone');
            }
            if (! in_array('last_login_at', $existing, true)) {
                $table->timestamp('last_login_at')->nullable()->after('active');
            }
            if (! in_array('notes', $existing, true)) {
                $table->text('notes')->nullable()->after('last_login_at');
            }
        });
    }

    public function down(): void
    {
        $existing = collect(DB::select('SHOW COLUMNS FROM `users`'))
            ->pluck('Field')
            ->all();

        Schema::table('users', function (Blueprint $table) use ($existing) {
            $cols = ['username', 'role', 'position', 'department', 'phone', 'active', 'last_login_at', 'notes'];
            foreach ($cols as $col) {
                if (in_array($col, $existing, true)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
