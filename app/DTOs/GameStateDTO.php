<?php

namespace App\DTOs;

use App\Models\Game;
use App\Models\Round;
use Illuminate\Support\Collection;

class GameStateDTO
{
    public function __construct(
        public string $game_id,
        public bool $show_rules,
        public int $current_round,
        public ?string $round_status,
        public int $current_slide,
        public string $game_status,
        public ?string $join_code,
        public int $player_count,
        public array $revealed_answers,
        public array $collected_answers,
        public array $turn_order,
        public bool $timer_running,
        public ?int $timer_started_at,
        public int $thinking_time,
        public ?string $current_turn_player_id,
        public ?string $current_turn_player_name,
        public ?string $current_turn_player_color,
        public ?int $current_turn_index,
        public ?string $timer_mode,
        public bool $all_answered,
        public ?string $category_title,
        public ?string $category_description,
        public array $players,
        public array $config
    ) {}

    public static function fromModel(Game $game, ?Round $round, array $turnInfo): self
    {
        $revealedAnswersData = [];
        $collectedAnswers = [];
        $categoryTitle = null;
        $categoryDescription = null;

        if ($round) {
            $categoryTitle = $round->category->title;
            $categoryDescription = $round->category->description;

            $answers = $round->category->answers->sortBy('position');
            $revealedCount = $round->current_slide;

            foreach ($answers as $answer) {
                if ($answer->position <= $revealedCount) {
                    $playersWithAnswer = $round->playerAnswers
                        ->where('answer_id', $answer->id)
                        ->map(fn($pa) => [
                            'id' => $pa->player_id,
                            'name' => $pa->player->name,
                            'color' => $pa->player->color,
                            'doubled' => $pa->was_doubled,
                        ])
                        ->values()
                        ->toArray();

                    $revealedAnswersData[] = [
                        'position' => $answer->position,
                        'text' => $answer->display_text,
                        'stat' => $answer->stat,
                        'points' => $answer->points,
                        'is_friction' => $answer->is_friction,
                        'players' => $playersWithAnswer,
                    ];
                }
            }

            foreach ($round->playerAnswers as $pa) {
                $collectedAnswers[] = [
                    'playerId' => $pa->player_id,
                    'playerName' => $pa->player->name,
                    'playerColor' => $pa->player->color,
                    'answerText' => $pa->input_text ?? ($pa->answer?->text ?? 'Not on list'),
                    'isOnList' => $pa->answer_id !== null,
                ];
            }
        }

        $turnOrder = $game->getTurnOrderForRound($game->current_round);
        $turnOrderData = collect($turnOrder)->filter(fn($p) => $p->isActive())->map(fn($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'color' => $p->color,
        ])->values()->toArray();

        return new self(
            game_id: $game->id,
            show_rules: (bool) $game->show_rules,
            current_round: $game->current_round,
            round_status: $round?->status,
            current_slide: $round?->current_slide ?? 0,
            game_status: $game->status,
            join_code: $game->join_code,
            player_count: $game->player_count,
            revealed_answers: $revealedAnswersData,
            collected_answers: $collectedAnswers,
            turn_order: $turnOrderData,
            timer_running: (bool) $game->timer_running,
            timer_started_at: $game->timer_started_at?->timestamp,
            thinking_time: $game->thinking_time,
            current_turn_player_id: $turnInfo['currentPlayer']?->id,
            current_turn_player_name: $turnInfo['currentPlayer']?->name,
            current_turn_player_color: $turnInfo['currentPlayer']?->color,
            current_turn_index: $turnInfo['currentTurnIndex'],
            timer_mode: $turnInfo['timerMode'],
            all_answered: $turnInfo['allAnswered'],
            category_title: $categoryTitle,
            category_description: $categoryDescription,
            players: $game->players->filter(fn($p) => $p->isActive())->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'color' => $p->color,
                'total_score' => $p->total_score,
                'double_used' => $p->double_used,
                'doubles_remaining' => $p->doublesRemaining(),
            ])->values()->toArray(),
            config: [
                'topAnswersCount' => $game->top_answers_count,
                'frictionPenalty' => $game->friction_penalty,
                'notOnListPenalty' => $game->not_on_list_penalty,
                'doubleMultiplier' => $game->double_multiplier,
                'doublesPerPlayer' => $game->doubles_per_player,
                'maxAnswersPerCategory' => $game->max_answers_per_category,
            ]
        );
    }

    public function toArray(): array
    {
        return [
            'gameId' => $this->game_id,
            'showRules' => $this->show_rules,
            'currentRound' => $this->current_round,
            'roundStatus' => $this->round_status,
            'currentSlide' => $this->current_slide,
            'gameStatus' => $this->game_status,
            'joinCode' => $this->join_code,
            'playerCount' => $this->player_count,
            'revealedAnswers' => $this->revealed_answers,
            'collectedAnswers' => $this->collected_answers,
            'turnOrder' => $this->turn_order,
            'timerRunning' => $this->timer_running,
            'timerStartedAt' => $this->timer_started_at,
            'thinkingTime' => $this->thinking_time,
            'currentTurnPlayerId' => $this->current_turn_player_id,
            'currentTurnPlayerName' => $this->current_turn_player_name,
            'currentTurnPlayerColor' => $this->current_turn_player_color,
            'currentTurnIndex' => $this->current_turn_index,
            'timerMode' => $this->timer_mode,
            'allAnswered' => $this->all_answered,
            'categoryTitle' => $this->category_title,
            'categoryDescription' => $this->category_description,
            'players' => $this->players,
            'config' => $this->config,
        ];
    }
}
