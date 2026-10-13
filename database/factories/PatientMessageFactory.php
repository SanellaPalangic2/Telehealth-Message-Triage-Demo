<?php

namespace Database\Factories;

use App\Enums\MessageStatus;
use App\Models\PatientMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientMessage>
 */
class PatientMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'body' => fake()->paragraph(),
            'received_at' => fake()->dateTimeBetween('-2 days'),
            'status' => MessageStatus::New,
        ];
    }
}
