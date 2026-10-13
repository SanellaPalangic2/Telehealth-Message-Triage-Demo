<?php

namespace Database\Seeders;

use App\Enums\MessageStatus;
use App\Models\PatientMessage;
use Illuminate\Database\Seeder;

class PatientMessageSeeder extends Seeder
{
    /**
     * Seed 30 synthetic patient messages, spaced out over the last day.
     */
    public function run(): void
    {
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
     * @return list<array{kind: string, body: string, pii: list<string>}>
     */
    public static function data(): array
    {
        return require database_path('seeders/data/patient_messages.php');
    }
}
