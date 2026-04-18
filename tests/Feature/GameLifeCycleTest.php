<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Models\Category;
use App\Events\GameDeleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Volt\Volt;
use Tests\TestCase;

class GameLifeCycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_game_deletion_broadcasts_event_and_removes_data()
    {
        Event::fake();

        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $game = Game::factory()->create(['user_id' => $user->id]);
        $player = Player::factory()->create(['game_id' => $game->id]);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $round = Round::factory()->create([
            'game_id' => $game->id,
            'category_id' => $category->id,
            'round_number' => 1
        ]);

        Volt::test('pages.games.index')
            ->set('gameToDelete', $game->id)
            ->call('deleteGame');

        $this->assertDatabaseMissing('games', ['id' => $game->id]);
        $this->assertDatabaseMissing('players', ['game_id' => $game->id]);
        $this->assertDatabaseMissing('rounds', ['game_id' => $game->id]);

        Event::assertDispatched(GameDeleted::class, function ($event) use ($game) {
            return $event->gameId === $game->id;
        });
    }

    public function test_game_reset_clears_scores_but_keeps_setup()
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $game = Game::factory()->create(['user_id' => $user->id, 'status' => 'completed', 'current_round' => 5]);
        $player = Player::factory()->create(['game_id' => $game->id, 'total_score' => 100, 'double_used' => true]);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $round = Round::factory()->create([
            'game_id' => $game->id,
            'category_id' => $category->id,
            'round_number' => 1,
            'status' => 'scoring'
        ]);

        Volt::test('pages.games.show', ['game' => $game])
            ->call('resetGame');

        $game->refresh();
        $player->refresh();
        $round->refresh();

        $this->assertEquals('ready', $game->status);
        $this->assertEquals(1, $game->current_round);
        $this->assertEquals(0, $player->total_score);
        $this->assertFalse($player->double_used);
        $this->assertEquals('pending', $round->status);
    }
}
