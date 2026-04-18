<?php

namespace Database\Factories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Game>
 */
class GameFactory extends Factory
{
    protected $model = Game::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'name' => $this->faker->sentence(3),
            'player_count' => 0,
            'status' => 'ready',
            'join_code' => strtoupper($this->faker->bothify('?????')),
            'current_round' => 1,
            'show_rules' => true,
            'top_answers_count' => 10,
            'friction_penalty' => -5,
            'not_on_list_penalty' => -3,
            'rounds_per_player' => 2,
            'double_multiplier' => 2,
            'doubles_per_player' => 1,
            'max_answers_per_category' => 15,
            'thinking_time' => 30,
        ];
    }
}
