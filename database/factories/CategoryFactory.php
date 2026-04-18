<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->sentence,
            'topic_id' => Topic::factory(),
            'played_at' => null,
            'is_starter' => false,
        ];
    }
}
