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
        Schema::create('patient_messages', function (Blueprint $table) {
            $table->id();
            // Original, unredacted text. Never sent to the AI.
            $table->text('body');
            $table->timestamp('received_at');
            $table->string('status', 32)->default('new')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_messages');
    }
};
