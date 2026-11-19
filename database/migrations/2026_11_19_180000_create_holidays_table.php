<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biotime_pk')->nullable()->index();
            $table->boolean('needs_push')->default(false);
            $table->string('name', 191);
            $table->date('start_date')->index();
            $table->unsignedSmallInteger('duration_days')->default(1);
            $table->string('department_name', 191)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
