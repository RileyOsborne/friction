<?php

namespace Tests\Browser;

use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Models\Category;
use App\Models\Answer;
use App\Models\PlayerAnswer;
use App\Models\PlayerSession;
use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class DesignGalleryTest extends DuskTestCase
{
    /**
     * Generate a design gallery using the persistent pre-seeded database.
     */
    public function testGenerateDesignGallery(): void
    {
        $joinCode = 'DSGN26';
        
        // Force the connection to the dusk database file manually for the test process
        config(['database.connections.sqlite.database' => base_path('database/dusk.sqlite')]);
        \Illuminate\Support\Facades\DB::purge('sqlite');

        $game = Game::where('join_code', $joinCode)->firstOrFail();
        $round = $game->rounds()->where('round_number', 1)->firstOrFail();

        $this->browse(function (Browser $gm, Browser $present, Browser $playerBrowser) use ($game, $round, $joinCode) {
            
            // 1. Initial Join Flow (Captures authentic Join/Select views)
            $playerBrowser->resize(390, 844)
                ->visit(route('player.join', [], false))
                ->pause(1000)
                ->type('join_code', $joinCode)
                ->press('Join Game')
                ->waitForRoute('player.select', [$joinCode])
                ->pause(1000)
                ->press('RILEY') // Select the first player
                ->waitForRoute('player.play', [$game->id])
                ->pause(500);

            $gmPath = route('games.control', $game, false);
            $presentPath = route('games.present', $game, false);

            $phases = [
                RoundStatus::Intro->value,
                RoundStatus::Collecting->value,
                RoundStatus::Revealing->value,
                RoundStatus::Friction->value,
                RoundStatus::Scoring->value,
                RoundStatus::Complete->value,
            ];

            foreach ($phases as $index => $status) {
                // Update State
                $round->update(['status' => $status]);
                if ($status === RoundStatus::Collecting->value) {
                    $game->update(['status' => 'playing']);
                }

                if ($status === RoundStatus::Revealing->value || $status === RoundStatus::Friction->value) {
                    $round->update(['current_slide' => $status === RoundStatus::Friction->value ? 11 : 5]);
                }

                // --- GM VIEW ---
                $gm->resize(1440, 900)->visit($gmPath)->pause(500)->screenshot("gallery/GM_{$index}_{$status}");

                // --- PRESENTATION VIEW ---
                $present->resize(1920, 1080)->visit($presentPath)->pause(1000)->screenshot("gallery/PRESENT_{$index}_{$status}");

                // --- PLAYER VIEW ---
                $playerBrowser->visit(route('player.play', $game, false))->pause(500)->screenshot("gallery/PLAYER_{$index}_{$status}");
            }

            // --- ADMIN VIEWS ---
            $gm->visit(route('games.index', [], false))->pause(500)->screenshot("gallery/ADMIN_Index");
            $gm->visit(route('games.show', $game, false))->pause(500)->screenshot("gallery/ADMIN_Setup");
        });
    }
}
