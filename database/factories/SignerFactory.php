<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SignerStatus;
use App\Models\Envelope;
use App\Models\Signer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Signer> */
class SignerFactory extends Factory
{
    protected $model = Signer::class;

    public function definition(): array
    {
        return [
            'envelope_id' => Envelope::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'status' => SignerStatus::Pending,
        ];
    }
}
