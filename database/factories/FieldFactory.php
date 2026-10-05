<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FieldType;
use App\Models\Field;
use App\Models\Signer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Field> */
class FieldFactory extends Factory
{
    protected $model = Field::class;

    public function definition(): array
    {
        return [
            'signer_id' => Signer::factory(),
            'page' => 1,
            'type' => FieldType::Signature,
            'required' => true,
            'position_x' => '0.1000000000',
            'position_y' => '0.2000000000',
            'width' => '0.3000000000',
            'height' => '0.1000000000',
        ];
    }

    public function forSigner(Signer $signer): static
    {
        return $this->state([
            'envelope_id' => $signer->envelope_id,
            'signer_id' => $signer->getKey(),
        ]);
    }
}
