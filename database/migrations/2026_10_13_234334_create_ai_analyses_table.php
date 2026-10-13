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
        // One row per successful, validated AI suggestion. Failed or invalid
        // AI calls are never stored here, so nothing half-filled can be shown
        // as a real suggestion.
        Schema::create('ai_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_message_id')->constrained()->cascadeOnDelete();

            // The exact (redacted) text that was sent to the model.
            $table->text('redacted_text');

            // Validated structured output.
            $table->string('category', 32);
            $table->string('urgency', 16);
            $table->text('summary');
            $table->text('draft_reply');
            $table->decimal('confidence', 4, 3);
            $table->boolean('requires_review')->default(true);
            $table->json('raw_output');

            // Call metadata.
            $table->string('model');
            $table->string('prompt_version', 64);
            $table->string('response_id');
            $table->unsignedInteger('latency_ms');
            $table->unsignedInteger('input_tokens');
            $table->unsignedInteger('output_tokens');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_analyses');
    }
};
