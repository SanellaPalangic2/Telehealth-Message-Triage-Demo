<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Defense in depth for "the AI can only suggest": even raw SQL can't
        // mark a message approved or rejected unless a human review decision
        // exists for it. (PatientMessage enforces the same rule in PHP.)
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER patient_messages_require_human_decision
                BEFORE UPDATE OF status ON patient_messages
                WHEN NEW.status IN ('approved', 'rejected')
                    AND NOT EXISTS (SELECT 1 FROM review_decisions WHERE patient_message_id = NEW.id)
                BEGIN SELECT RAISE(ABORT, 'approve/reject requires a human review decision'); END;
                SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS patient_messages_require_human_decision');
        }
    }
};
