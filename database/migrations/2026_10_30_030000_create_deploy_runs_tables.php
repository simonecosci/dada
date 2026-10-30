<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deploy_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('type');
            $table->string('status');
            $table->json('options');
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['deployment_id', 'status']);
        });

        Schema::create('deploy_run_hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deploy_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deployment_host_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
        });

        Schema::create('deploy_run_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deploy_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deployment_host_id')->nullable()->constrained()->nullOnDelete();
            $table->string('level');
            $table->text('message');
            $table->timestamp('created_at')->nullable();

            $table->index(['deploy_run_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_run_logs');
        Schema::dropIfExists('deploy_run_hosts');
        Schema::dropIfExists('deploy_runs');
    }
};
