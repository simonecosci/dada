<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credentials', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->string('username')->nullable();
            $table->text('secret');
            $table->text('passphrase')->nullable();
            $table->string('registry_url')->nullable();
            $table->timestamps();
        });

        Schema::create('hosts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('hostname');
            $table->unsignedInteger('port')->default(22);
            $table->string('username');
            $table->foreignId('credential_id')->constrained('credentials');
            $table->foreignId('jump_host_id')->nullable()->constrained('hosts')->nullOnDelete();
            $table->string('sudo')->default('');
            $table->text('host_key')->nullable();
            $table->boolean('reachable')->nullable();
            $table->json('facts')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hosts');
        Schema::dropIfExists('credentials');
    }
};
