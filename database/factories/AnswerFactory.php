<?php

namespace Database\Factories;

use App\Models\Answer;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Answer>
 */
class AnswerFactory extends Factory
{
    protected $model = Answer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'text' => $this->faker->word,
            'stat' => $this->faker->numberBetween(1, 100),
            'position' => $this->faker->unique()->numberBetween(1, 15),
            'is_friction' => function (array $attributes) {
                return ($attributes['position'] ?? 1) > 10;
            },
            'points' => function (array $attributes) {
                $pos = $attributes['position'] ?? 1;
                return $pos > 10 ? -5 : $pos;
            },
        ];
    }
}
