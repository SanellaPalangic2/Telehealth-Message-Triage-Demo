<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('review_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_analysis_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 16);
            // The reply the human signed off on (null when rejected).
            $table->text('final_reply')->nullable();
            $table->string('reviewer');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('review_decisions');
    }
};
