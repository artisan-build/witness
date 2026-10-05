<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\Template;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Template> */
class TemplateFactory extends Factory
{
    protected $model = Template::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'sender_id' => UserFactory::new(),
            'name' => fake()->words(3, true),
            'title' => fake()->sentence(4),
            'message' => fake()->optional()->sentence(),
            'original_name' => 'template.pdf',
            'storage_path' => 'templates/'.fake()->uuid().'.pdf',
            'size_bytes' => 1024,
            'page_count' => 1,
            'original_sha256' => hash('sha256', fake()->uuid()),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Template $template): void {
            $template->team->users()->syncWithoutDetaching([$template->sender_id]);
        });
    }

    public function forOwner(Team $team, User $sender): static
    {
        return $this->state([
            'team_id' => $team->getKey(),
            'sender_id' => $sender->getKey(),
        ]);
    }
}
