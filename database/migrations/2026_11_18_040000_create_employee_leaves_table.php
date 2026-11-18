<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_leaves', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biotime_pk')->nullable()->index();
            $table->boolean('needs_push')->default(false);
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedBigInteger('biotime_emp_pk')->nullable()->index();
            $table->string('biotime_emp_code', 64)->index();
            $table->string('leave_type', 32)->default('vacaciones'); // vacaciones | incapacidad | permiso
            $table->unsignedInteger('biotime_category_id')->nullable();
            $table->string('category_name', 120)->nullable();
            $table->string('imss_folio', 80)->nullable();
            $table->string('incapacity_type', 80)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days_count', 5, 1)->default(1);
            $table->string('status', 32)->default('Aprobada');
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leaves');
    }
};
