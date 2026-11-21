<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('import_batches', 'is_visible')) {
            Schema::table('import_batches', function (Blueprint $table) {
                $table->boolean('is_visible')->default(true)->after('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('import_batches', 'is_visible')) {
            Schema::table('import_batches', function (Blueprint $table) {
                $table->dropColumn('is_visible');
            });
        }
    }
};
