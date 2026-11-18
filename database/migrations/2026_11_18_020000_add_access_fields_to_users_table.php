<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'username')) {
                $table->string('username', 64)->nullable()->after('name');
            }
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role', 64)->default('Administrador')->after('email');
            }
            if (! Schema::hasColumn('users', 'position')) {
                $table->string('position', 120)->nullable()->after('role');
            }
            if (! Schema::hasColumn('users', 'department')) {
                $table->string('department', 120)->nullable()->after('position');
            }
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 64)->nullable()->after('department');
            }
            if (! Schema::hasColumn('users', 'active')) {
                $table->boolean('active')->default(true)->after('phone');
            }
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('active');
            }
            if (! Schema::hasColumn('users', 'notes')) {
                $table->text('notes')->nullable()->after('last_login_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $cols = ['username', 'role', 'position', 'department', 'phone', 'active', 'last_login_at', 'notes'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
