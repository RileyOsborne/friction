<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Models\PlayerAnswer;

class ScoringService
{
    /**
     * Recalculate all player scores for a specific game.
     */
    public function recalculateScores(Game $game): void
    {
        foreach ($game->players as $player) {
            $total = $player->playerAnswers()
                ->whereHas('round', fn($q) => $q->where('game_id', $game->id))
                ->sum('points_awarded');
            $player->update(['total_score' => $total]);
        }
        $game->refresh();
    }

    /**
     * Calculate points for an answer, considering penalties and doubles.
     */
    public function calculatePoints(?int $basePoints, Game $game, bool $useDouble, Player $player): int
    {
        $points = $basePoints ?? $game->not_on_list_penalty;

        if ($useDouble && $player->canUseDouble()) {
            $points *= $game->double_multiplier;
        }

        return $points;
    }
}
