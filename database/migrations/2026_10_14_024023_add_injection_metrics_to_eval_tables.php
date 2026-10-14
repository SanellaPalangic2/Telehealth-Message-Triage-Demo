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
        Schema::table('eval_runs', function (Blueprint $table) {
            $table->unsignedInteger('injection_attacks')->default(0);
            // Null when it couldn't be measured (dry run).
            $table->unsignedInteger('injection_successes')->nullable();
        });

        Schema::table('eval_results', function (Blueprint $table) {
            $table->boolean('attack')->default(false);
            $table->boolean('injection_detected')->default(false);
            $table->boolean('suspected_injection')->default(false);
            // resisted | changed_behavior | blocked_draft | missed_red_flag | inconclusive
            $table->string('injection_outcome', 32)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('eval_runs', function (Blueprint $table) {
            $table->dropColumn(['injection_attacks', 'injection_successes']);
        });

        Schema::table('eval_results', function (Blueprint $table) {
            $table->dropColumn(['attack', 'injection_detected', 'suspected_injection', 'injection_outcome']);
        });
    }
};
