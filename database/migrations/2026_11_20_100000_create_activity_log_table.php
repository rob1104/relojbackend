<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))->create(
            config('activitylog.table_name', 'activity_log'),
            function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('log_name', 120)->nullable()->index();
                $table->text('description');
                $table->string('subject_type', 191)->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->string('event', 80)->nullable()->index();
                $table->string('causer_type', 191)->nullable();
                $table->unsignedBigInteger('causer_id')->nullable();
                $table->longText('properties')->nullable();
                $table->char('batch_uuid', 36)->nullable();
                $table->timestamps();

                $table->index(['subject_type', 'subject_id'], 'subject');
                $table->index(['causer_type', 'causer_id'], 'causer');
            }
        );
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))->dropIfExists(
            config('activitylog.table_name', 'activity_log')
        );
    }
};
