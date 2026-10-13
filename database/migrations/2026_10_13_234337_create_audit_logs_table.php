<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // No cascade: a message with audit history cannot be deleted.
            $table->foreignId('patient_message_id')->nullable()->constrained();
            $table->string('actor', 8);
            $table->string('action', 64);
            $table->json('details')->nullable();
            // Append-only, so there is no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['patient_message_id', 'created_at']);
        });

        // Enforce append-only in the database too, so even raw SQL can't
        // rewrite history. (The AuditLog model also blocks it in PHP.)
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
                BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only'); END;
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
                BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only'); END;
                SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
