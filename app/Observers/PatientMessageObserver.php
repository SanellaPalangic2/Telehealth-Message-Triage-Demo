<?php

namespace App\Observers;

use App\Enums\MessageStatus;
use App\Jobs\TriageMessage;
use App\Models\PatientMessage;

class PatientMessageObserver
{
    /**
     * Every newly received message is triaged automatically in the
     * background. (Seeders mute model events, so demo data is not.)
     */
    public function created(PatientMessage $message): void
    {
        if ($message->status === MessageStatus::New) {
            TriageMessage::dispatch($message);
        }
    }
}
