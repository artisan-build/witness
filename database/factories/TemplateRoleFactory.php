<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Template;
use App\Models\TemplateRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TemplateRole> */
class TemplateRoleFactory extends Factory
{
    protected $model = TemplateRole::class;

    public function definition(): array
    {
        return [
            'template_id' => Template::factory(),
            'label' => fake()->unique()->jobTitle(),
            'position' => 0,
        ];
    }
}
