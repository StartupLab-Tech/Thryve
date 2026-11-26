<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A conversation remembers which approved model its person picked; an answer remembers the model that was asked for.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('model_choice_id')->nullable()->after('agent_id')->constrained('model_choices')->nullOnDelete();
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->string('model', 100)->nullable()->after('request_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('model');
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('model_choice_id');
        });
    }
};
