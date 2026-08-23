<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\KnowledgeType;
use App\Models\KnowledgeBase;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KnowledgeBase> */
class KnowledgeBaseFactory extends Factory
{
    protected $model = KnowledgeBase::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'title' => fake()->sentence(),
            'type' => KnowledgeType::Document,
            'is_active' => true,
        ];
    }
}
