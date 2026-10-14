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
        // One row per real eval run (dry runs aren't saved). Model + prompt
        // version identify exactly what was tested, so runs can be compared.
        Schema::create('eval_runs', function (Blueprint $table) {
            $table->id();
            $table->string('model');
            $table->string('prompt_version', 64);
            $table->string('case_set', 64);
            $table->unsignedInteger('cases_total');
            $table->unsignedInteger('cases_sent_to_ai');
            $table->decimal('category_accuracy', 5, 4)->nullable();
            $table->decimal('urgency_accuracy', 5, 4)->nullable();
            $table->decimal('red_flag_recall', 5, 4)->nullable();
            $table->unsignedInteger('red_flag_false_positives');
            $table->unsignedInteger('ai_failures');
            $table->unsignedInteger('input_tokens');
            $table->unsignedInteger('output_tokens');
            $table->unsignedInteger('avg_latency_ms')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eval_runs');
    }
};
