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
        Schema::create('eval_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eval_run_id')->constrained()->cascadeOnDelete();
            $table->string('case_id', 16);

            $table->boolean('expected_red_flag');
            $table->boolean('red_flag');
            $table->json('rules_fired');

            $table->string('expected_category', 32)->nullable();
            $table->string('category', 32)->nullable();
            $table->string('expected_urgency', 16);
            $table->string('urgency', 16)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();

            // Set when the AI call failed or its output was rejected.
            $table->text('error')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->string('response_id')->nullable();

            $table->timestamps();
            $table->unique(['eval_run_id', 'case_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eval_results');
    }
};
