<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::select("SHOW TABLES LIKE 'week_locks'");
        if (empty($exists)) {
            Schema::create('week_locks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->date('week_start');
                $table->date('week_end');
                $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('locked_at')->nullable();
                $table->string('notes', 191)->nullable();
                $table->timestamps();

                $table->index(['company_id', 'week_start', 'week_end'], 'week_locks_company_range_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('week_locks');
    }
};
