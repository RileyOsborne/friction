<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Models\Category;
use App\Models\Answer;
use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class GMControlTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;
    private Player $player;
    private Round $round;

    protected function setUp(): void
    {
        parent::setUp();

        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $this->game = Game::factory()->create([
            'user_id' => $user->id,
            'status' => GameStatus::Playing->value,
            'current_round' => 1,
            'player_count' => 1,
            'rounds_per_player' => 2
        ]);

        $this->player = Player::factory()->create(['game_id' => $this->game->id]);
        
        $category = Category::factory()->create();
        for ($i = 1; $i <= 15; $i++) {
            Answer::factory()->create([
                'category_id' => $category->id,
                'position' => $i
            ]);
        }

        $this->round = Round::factory()->create([
            'game_id' => $this->game->id,
            'round_number' => 1,
            'category_id' => $category->id,
            'status' => RoundStatus::Intro->value
        ]);
    }

    public function test_gm_can_start_collecting_phase()
    {
        Volt::test('gm.intro-phase', ['game' => $this->game, 'currentRound' => $this->round])
            ->call('startCollecting')
            ->assertDispatched('transition-to-collecting');

        // The actual transition is handled by the parent component (control.blade.php) 
        // which calls GameStateMachine. We'll test the state machine result via Feature test logic.
        $this->game->refresh();
        $stateMachine = new \App\Services\GameStateMachine($this->game);
        $stateMachine->startCollecting();

        $this->assertEquals(RoundStatus::Collecting->value, $this->round->refresh()->status);
    }

    public function test_gm_can_submit_answer_for_player()
    {
        $this->round->update(['status' => RoundStatus::Collecting->value]);

        Volt::test('gm.collecting-phase', ['game' => $this->game, 'currentRound' => $this->round])
            ->set('playerAnswers.' . $this->player->id, 'Test Answer')
            ->call('submitPlayerAnswer', $this->player->id)
            ->assertDispatched('player-answer-updated');

        $this->assertDatabaseHas('player_answers', [
            'player_id' => $this->player->id,
            'round_id' => $this->round->id,
            'input_text' => 'Test Answer'
        ]);
    }

    public function test_gm_can_reveal_answers_one_by_one()
    {
        $this->round->update(['status' => RoundStatus::Revealing->value, 'current_slide' => 0]);

        Volt::test('gm.revealing-phase', ['game' => $this->game, 'currentRound' => $this->round])
            ->call('revealNext')
            ->assertSet('revealedCount', 1)
            ->call('revealNext')
            ->assertSet('revealedCount', 2);

        $this->assertEquals(2, $this->round->refresh()->current_slide);
    }

    public function test_gm_can_transition_to_scoring_after_reveal()
    {
        $this->round->update(['status' => RoundStatus::Revealing->value]);

        Volt::test('gm.revealing-phase', ['game' => $this->game, 'currentRound' => $this->round])
            ->call('showScores')
            ->assertDispatched('transition-to-scoring');
    }

    public function test_gm_can_complete_round_and_move_to_next()
    {
        $this->round->update(['status' => RoundStatus::Scoring->value]);
        Round::factory()->create(['game_id' => $this->game->id, 'round_number' => 2]);

        Volt::test('gm.scoring-phase', ['game' => $this->game, 'currentRound' => $this->round])
            ->call('nextRound')
            ->assertDispatched('transition-to-next-round');
    }
}
