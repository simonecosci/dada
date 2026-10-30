<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_host_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deploy_run_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('installations');
            $table->longText('host_variables');
            $table->longText('files');
            $table->timestamp('created_at')->nullable();

            $table->index(['deployment_host_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_snapshots');
    }
};
