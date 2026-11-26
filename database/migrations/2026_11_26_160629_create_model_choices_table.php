<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The AI models a Super Admin has approved for people to choose from. The catalog itself is never stored: it is read live from Hermes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_choices', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->string('model', 100); // the exact model name, which is also the price list key
            $table->string('label', 100);
            $table->string('hint', 200)->nullable();
            $table->boolean('supports_images')->default(false);
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['provider', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_choices');
    }
};
