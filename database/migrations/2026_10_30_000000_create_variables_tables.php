<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variables', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('key');
            $table->text('default')->nullable();
            $table->text('value')->nullable();
            $table->boolean('use_default')->default(true);
            $table->text('description')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('secret')->default(false);
            $table->string('scope');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'key']);
        });

        Schema::create('secret_reveals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variable_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secret_reveals');
        Schema::dropIfExists('variables');
    }
};
