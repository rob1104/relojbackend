<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::select("SHOW COLUMNS FROM `import_batches` LIKE 'is_visible'");
        if (empty($exists)) {
            Schema::table('import_batches', function (Blueprint $table) {
                $table->boolean('is_visible')->default(true)->after('notes');
            });
        }
    }

    public function down(): void
    {
        $exists = DB::select("SHOW COLUMNS FROM `import_batches` LIKE 'is_visible'");
        if (! empty($exists)) {
            Schema::table('import_batches', function (Blueprint $table) {
                $table->dropColumn('is_visible');
            });
        }
    }
};
