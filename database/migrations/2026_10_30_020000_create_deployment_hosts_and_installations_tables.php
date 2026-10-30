<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('host_id')->constrained()->restrictOnDelete();
            $table->string('path');
            $table->string('orchestrator')->default('compose');
            $table->string('stack_name');
            $table->json('compose_template');
            $table->timestamps();

            $table->unique(['host_id', 'path']);
            $table->unique(['host_id', 'stack_name']);
        });

        Schema::create('deployment_host_registry', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_host_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credential_id')->constrained()->restrictOnDelete();

            $table->unique(['deployment_host_id', 'credential_id']);
        });

        Schema::create('installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_host_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_version_id')->constrained()->restrictOnDelete();
            $table->string('slug');
            $table->unsignedInteger('order')->default(0);
            $table->json('compose');
            $table->json('commands');
            $table->timestamps();

            $table->unique(['deployment_host_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installations');
        Schema::dropIfExists('deployment_host_registry');
        Schema::dropIfExists('deployment_hosts');
    }
};
