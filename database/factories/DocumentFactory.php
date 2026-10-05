<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Document;
use App\Models\Envelope;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'envelope_id' => Envelope::factory(),
            'original_name' => 'agreement.pdf',
            'storage_path' => 'documents/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'page_count' => 1,
            'original_sha256' => hash('sha256', fake()->uuid()),
        ];
    }
}
