<?php

namespace Database\Seeders;

use App\Enums\MessageStatus;
use App\Models\PatientMessage;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PatientMessageSeeder extends Seeder
{
    // Mutes model events, so seeding never auto-dispatches triage jobs. The
    // demo re-seeds on every Render restart; auto-triaging all 30 messages
    // each time would burn API calls. Use "Triage all pending" instead.
    use WithoutModelEvents;

    /**
     * Seed 30 synthetic patient messages, spaced out over the last day.
     */
    public function run(): void
    {
        // Safe to run on every container start: never duplicates the demo data.
        if (PatientMessage::exists()) {
            $this->command?->info('Patient messages already exist; skipping seed.');

            return;
        }

        $messages = self::data();
        $count = count($messages);

        foreach ($messages as $i => $message) {
            PatientMessage::create([
                'body' => $message['body'],
                'received_at' => now()->subMinutes(($count - $i) * 47),
                'status' => MessageStatus::New,
            ]);
        }
    }

    /**
     * @return list<array{kind: string, body: string, pii: list<string>, rules?: list<string>}>
     */
    public static function data(): array
    {
        // __DIR__ rather than database_path(): Pest datasets call this
        // before the Laravel app has booted.
        return require __DIR__.'/data/patient_messages.php';
    }
}
