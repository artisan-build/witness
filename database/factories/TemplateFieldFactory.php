<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FieldType;
use App\Models\TemplateField;
use App\Models\TemplateRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TemplateField> */
class TemplateFieldFactory extends Factory
{
    protected $model = TemplateField::class;

    public function definition(): array
    {
        return [
            'template_role_id' => TemplateRole::factory(),
            'page' => 1,
            'type' => FieldType::Signature,
            'required' => true,
            'position_x' => '0.1000000000',
            'position_y' => '0.2000000000',
            'width' => '0.3000000000',
            'height' => '0.1000000000',
        ];
    }

    public function forRole(TemplateRole $role): static
    {
        return $this->state([
            'template_id' => $role->template_id,
            'template_role_id' => $role->getKey(),
        ]);
    }
}
