<?php

namespace Database\Factories;

use App\Enums\DocumentKind;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'kind' => DocumentKind::Runbook,
            'body' => "## Steps\n\n1. ".fake()->sentence(),
        ];
    }
}
