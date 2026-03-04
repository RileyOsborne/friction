<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use App\Events\GameStateUpdated;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Game;
use App\Models\Round;
use App\Models\Category;
use App\Services\AnswerMatcher;
use App\Services\ScoringService;
use App\DTOs\GameStateDTO;

class GameStateMachine
{
    protected AnswerMatcher $matcher;
    protected ScoringService $scorer;

    public function __construct(
        protected Game $game
    ) {
        $this->game->load(['players', 'rounds.category.answers', 'rounds.playerAnswers.player', 'rounds.playerAnswers.answer']);
        $this->matcher = new AnswerMatcher();
        $this->scorer = new ScoringService();
    }

    /**
     * Get the current round model.
     */
    public function getCurrentRound(): ?Round
    {
        return $this->game->rounds->firstWhere('round_number', $this->game->current_round);
    }

    /**
     * Start the game - transition from ready to playing.
     */
    public function startGame(): void
    {
        $this->transitionGame(GameStatus::Playing);

        // Set first round to intro if not already
        $round = $this->getCurrentRound();
        if ($round && $round->status === RoundStatus::Pending->value) {
            $round->update(['status' => RoundStatus::Intro->value, 'current_slide' => 0]);
        }

        // Show rules on round 1
        $this->game->update(['show_rules' => $this->game->current_round === 1]);

        $this->broadcast();
    }

    /**
     * Complete the game.
     */
    public function completeGame(): void
    {
        $this->transitionGame(GameStatus::Completed);

        // Mark all categories used in this game as played
        $categoryIds = $this->game->rounds->pluck('category_id');
        Category::whereIn('id', $categoryIds)->whereNull('played_at')->update(['played_at' => now()]);

        $this->broadcast();
    }

    /**
     * Return to setup/lobby state.
     */
    public function returnToSetup(): void
    {
        $this->game->update([
            'status' => GameStatus::Ready->value,
            'show_rules' => true,
            'timer_running' => false,
            'timer_started_at' => null,
        ]);

        // Reset all rounds to initial state
        $this->game->rounds()->update(['status' => RoundStatus::Pending->value, 'current_slide' => 0]);

        $this->game->refresh();
        $this->broadcast();
    }

    /**
     * Dismiss rules and continue.
     */
    public function dismissRules(): void
    {
        $this->game->update(['show_rules' => false]);
        $this->broadcast();
    }

    /**
     * Start collecting answers for the current round.
     * Automatically starts the thinking timer.
     */
    public function startCollecting(): void
    {
        $round = $this->getCurrentRound();
        if (!$round) return;

        $this->transitionRound($round, RoundStatus::Collecting);

        // Automatically start the thinking timer
        $this->game->update([
            'timer_running' => true,
            'timer_started_at' => now(),
        ]);
        $this->game->refresh();

        $this->broadcast();
    }

    /**
     * Start revealing answers.
     */
    public function startRevealing(): void
    {
        $round = $this->getCurrentRound();
        if (!$round) return;

        $this->transitionRound($round, RoundStatus::Revealing);
        $round->update(['current_slide' => 0]);
        $this->stopTimer();
        $this->broadcast();
    }

    /**
     * Reveal the next answer.
     */
    public function revealNext(): int
    {
        $round = $this->getCurrentRound();
        if (!$round) return 0;

        $maxAnswers = $round->category->answers->count();
        $newSlide = min($round->current_slide + 1, $maxAnswers);

        $round->update(['current_slide' => $newSlide]);

        // Update status based on position using game config
        if ($newSlide > $this->game->top_answers_count && $round->status !== RoundStatus::Friction->value) {
            $this->transitionRound($round, RoundStatus::Friction);
        }

        $this->broadcast();

        return $newSlide;
    }

    /**
     * Reveal all answers at once.
     */
    public function revealAll(): void
    {
        $round = $this->getCurrentRound();
        if (!$round) return;

        $maxAnswers = $round->category->answers->count();
        $targetStatus = $maxAnswers > $this->game->top_answers_count ? RoundStatus::Friction : RoundStatus::Revealing;

        $round->update(['current_slide' => $maxAnswers]);
        
        if ($round->status !== $targetStatus->value) {
            $this->transitionRound($round, $targetStatus);
        }

        $this->broadcast();
    }

    /**
     * Show scores for the current round.
     */
    public function showScores(): void
    {
        $round = $this->getCurrentRound();
        if (!$round) return;

        $this->transitionRound($round, RoundStatus::Scoring);
        $this->scorer->recalculateScores($this->game);
        $this->broadcast();
    }

    /**
     * Move to the next round.
     */
    public function nextRound(): bool
    {
        $round = $this->getCurrentRound();
        if ($round) {
            $this->transitionRound($round, RoundStatus::Complete);
        }

        $nextRoundNum = $this->game->current_round + 1;

        if ($nextRoundNum > $this->game->total_rounds) {
            $this->completeGame();
            return false;
        }

        $this->game->update(['current_round' => $nextRoundNum, 'show_rules' => false]);

        $nextRound = $this->game->rounds->firstWhere('round_number', $nextRoundNum);
        if ($nextRound) {
            $nextRound->update(['status' => RoundStatus::Intro->value, 'current_slide' => 0]);
        }

        $this->game->refresh();
        $this->broadcast();

        return true;
    }

    /**
     * Go back to the intro phase from collecting.
     */
    public function goBackToIntro(): void
    {
        $round = $this->getCurrentRound();
        if (!$round) return;

        $this->transitionRound($round, RoundStatus::Intro);
        $this->stopTimer();
        $this->broadcast();
    }

    /**
     * Go back to collecting phase from revealing.
     */
    public function goBackToCollecting(): void
    {
        $round = $this->getCurrentRound();
        if (!$round) return;

        $this->transitionRound($round, RoundStatus::Collecting);
        $round->update(['current_slide' => 0]);

        // Restart the timer when going back to collecting
        $this->game->update([
            'timer_running' => true,
            'timer_started_at' => now(),
        ]);
        $this->game->refresh();

        $this->broadcast();
    }

    /**
     * Go back to revealing phase from scoring or friction.
     */
    public function goBackToRevealing(): void
    {
        $round = $this->getCurrentRound();
        if (!$round) return;

        // Keep the current slide position so we don't lose progress
        $this->transitionRound($round, RoundStatus::Revealing);
        $this->broadcast();
    }

    /**
     * Start the thinking timer.
     */
    public function startTimer(): void
    {
        $this->game->update([
            'timer_running' => true,
            'timer_started_at' => now(),
        ]);
        $this->game->refresh();
    }

    /**
     * Stop the thinking timer.
     */
    public function stopTimer(): void
    {
        $this->game->update([
            'timer_running' => false,
            'timer_started_at' => null,
        ]);
        $this->game->refresh();
    }

    /**
     * Get current turn info derived from who has submitted answers.
     * Returns: [currentPlayer, currentTurnIndex, timerMode, allAnswered]
     */
    public function getCurrentTurnInfo(): array
    {
        $round = $this->getCurrentRound();
        if (!$round || $round->status !== RoundStatus::Collecting->value) {
            return [
                'currentPlayer' => null,
                'currentTurnIndex' => null,
                'timerMode' => null,
                'allAnswered' => false,
            ];
        }

        // Force reload the relationship to ensure we have the latest answers
        $round->load('playerAnswers');

        $turnOrder = collect($this->game->getTurnOrderForRound($this->game->current_round))
            ->filter(fn($p) => $p->isActive())
            ->values();

        $submittedPlayerIds = $round->playerAnswers->pluck('player_id')->toArray();

        // Find first player in turn order who hasn't submitted
        $currentPlayer = null;
        $currentTurnIndex = null;

        foreach ($turnOrder as $index => $player) {
            if (!in_array($player->id, $submittedPlayerIds)) {
                $currentPlayer = $player;
                $currentTurnIndex = $index;
                break;
            }
        }

        // Timer mode: countdown for first player, countup for all others
        $timerMode = $currentTurnIndex === 0 ? 'countdown' : 'countup';
        $allAnswered = $currentPlayer === null;

        return [
            'currentPlayer' => $currentPlayer,
            'currentTurnIndex' => $currentTurnIndex,
            'timerMode' => $allAnswered ? null : $timerMode,
            'allAnswered' => $allAnswered,
        ];
    }

    /**
     * Advance to next turn - called after a player submits.
     * Resets timer_started_at for countup mode.
     */
    public function advanceTurn(): void
    {
        // Refresh the whole game and current round to get latest submissions
        $this->refresh();
        $turnInfo = $this->getCurrentTurnInfo();

        if ($turnInfo['allAnswered']) {
            // All players have answered, stop timer
            $this->stopTimer();
        } elseif ($turnInfo['timerMode'] === 'countup') {
            // Reset timer for countup mode
            $this->game->update([
                'timer_running' => true,
                'timer_started_at' => now(),
            ]);
            $this->game->refresh();
        }
        // For countdown mode (first player), keep existing timer running
    }

    /**
     * Process and save a player's answer submission.
     */
    public function submitPlayerAnswer(string $playerId, string $answerText, bool $useDouble = false): void
    {
        $round = $this->getCurrentRound();
        if (!$round) return;

        $player = \App\Models\Player::find($playerId);
        if (!$player) return;

        // Delete any existing answer for this player in this round
        \App\Models\PlayerAnswer::where('round_id', $round->id)
            ->where('player_id', $playerId)
            ->delete();

        // Find matching answer using the matcher service
        $answer = $this->matcher->match($answerText, $round->category->answers);

        // Calculate points using scoring service
        $points = $this->scorer->calculatePoints($answer?->points, $this->game, $useDouble, $player);

        // Create player answer record
        \App\Models\PlayerAnswer::create([
            'round_id' => $round->id,
            'player_id' => $playerId,
            'answer_id' => $answer?->id,
            'input_text' => $answerText,
            'points_awarded' => $points,
            'was_doubled' => $useDouble && $player->canUseDouble(),
        ]);

        $this->game->refresh();
        $this->scorer->recalculateScores($this->game);
        
        // Final state refresh and turn advancement
        $this->advanceTurn();
    }

    /**
     * Build the current state array for broadcasting.
     */
    public function buildState(): array
    {
        return GameStateDTO::fromModel(
            $this->game,
            $this->getCurrentRound(),
            $this->getCurrentTurnInfo()
        )->toArray();
    }

    /**
     * Broadcast the current state to all views.
     */
    public function broadcast(): array
    {
        $state = $this->buildState();

        // Broadcast to player devices via Reverb/WebSocket
        event(new GameStateUpdated($this->game, $state));

        return $state;
    }

    /**
     * Transition the game to a new status with validation.
     */
    protected function transitionGame(GameStatus $target): void
    {
        $current = GameStatus::tryFrom($this->game->status);

        if ($current && !$current->canTransitionTo($target)) {
            throw new InvalidStateTransitionException($current, $target, 'game');
        }

        $this->game->update(['status' => $target->value]);
    }

    /**
     * Transition a round to a new status with validation.
     */
    protected function transitionRound(Round $round, RoundStatus $target): void
    {
        $current = RoundStatus::tryFrom($round->status);

        if ($current && !$current->canTransitionTo($target)) {
            throw new InvalidStateTransitionException($current, $target, "round {$round->round_number}");
        }

        $round->update(['status' => $target->value]);
    }

    /**
     * Get the game instance.
     */
    public function getGame(): Game
    {
        return $this->game;
    }

    /**
     * Refresh the game from the database.
     */
    public function refresh(): self
    {
        $this->game->refresh();
        $this->game->load(['players', 'rounds.category.answers', 'rounds.playerAnswers.player', 'rounds.playerAnswers.answer']);
        return $this;
    }
}
