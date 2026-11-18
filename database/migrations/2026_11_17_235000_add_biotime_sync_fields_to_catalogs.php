<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'employees',
        'areas',
        'positions',
        'departments',
        'break_rules',
        'devices',
        'timetables',
        'shifts',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('biotime_pk')->nullable()->index();
                $table->boolean('needs_push')->default(false);
            });
        }

        Schema::create('deleted_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('catalog_type', 64)->index();
            $table->unsignedBigInteger('biotime_pk')->nullable();
            $table->string('unique_key', 150)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_catalog_items');

        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['biotime_pk', 'needs_push']);
            });
        }
    }
};
