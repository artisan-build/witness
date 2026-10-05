<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\Team;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Envelope> */
class EnvelopeFactory extends Factory
{
    protected $model = Envelope::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'sender_id' => UserFactory::new(),
            'title' => fake()->sentence(4),
            'message' => fake()->optional()->sentence(),
            'status' => EnvelopeStatus::Draft,
            'expires_at' => now()->addDays(30),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Envelope $envelope): void {
            $envelope->team->users()->syncWithoutDetaching([$envelope->sender_id]);
        });
    }

    public function sent(): static
    {
        return $this->state([
            'status' => EnvelopeStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    public function forOwner(Team $team, User $sender): static
    {
        return $this->state([
            'team_id' => $team->getKey(),
            'sender_id' => $sender->getKey(),
        ]);
    }
}
