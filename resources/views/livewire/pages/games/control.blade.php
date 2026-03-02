<?php

use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Models\Category;
use App\Models\Answer;
use App\Models\PlayerAnswer;
use App\Services\GameStateMachine;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;

new #[Layout('components.layouts.app')] #[Title('Game Master Control')] class extends Component {
    public Game $game;
    public ?Round $currentRound = null;
    protected ?GameStateMachine $stateMachine = null;

    // Player answer collection
    public array $playerAnswers = []; // player_id => answer text
    public array $playerDoubles = []; // player_id => bool (using double)

    // Reveal tracking (local UI state, synced from database)
    public int $revealedCount = 0; // How many answers have been revealed (1-15)

    // Player removal confirmation
    public ?string $confirmingRemovePlayerId = null;

    protected function getStateMachine(): GameStateMachine
    {
        if (!$this->stateMachine) {
            $this->stateMachine = new GameStateMachine($this->game);
        }
        return $this->stateMachine;
    }

    #[On('broadcast-state')]
    public function handleBroadcastState(): void
    {
        $state = $this->getStateMachine()->broadcast();
        $this->dispatch('game-state-updated', state: $state);
    }

    #[On('echo:game.{game.id},player.answer.submitted')]
    public function handlePlayerAnswer(array $data): void
    {
        // Player submitted an answer from their device
        $playerId = $data['playerId'];
        $answerText = $data['answerText'];
        $useDouble = $data['useDouble'] ?? false;

        // Store in our local state
        $this->playerAnswers[$playerId] = $answerText;
        $this->playerDoubles[$playerId] = $useDouble;

        // Auto-submit the answer
        $this->submitPlayerAnswer($playerId);
    }

    #[On('echo:game.{game.id},player.joined')]
    public function handlePlayerJoined(array $data): void
    {
        // Player joined or reconnected - refresh and broadcast
        $this->game->load('players'); // Force reload players relationship
        $this->getStateMachine()->refresh();
        $this->loadCurrentState();
        $this->broadcastState();
    }

    #[On('echo:game.{game.id},player.left')]
    public function handlePlayerLeft(array $data): void
    {
        // Player disconnected - refresh and broadcast
        $this->game->load('players'); // Force reload players relationship
        $this->getStateMachine()->refresh();
        $this->broadcastState();
    }

    /**
     * Periodically refresh player connection status.
     * This catches disconnections (which are passive - heartbeats just stop)
     * and reconnections even if Echo events are missed.
     */
    public function refreshPlayerStatus(): void
    {
        // Force fresh data from database
        $this->game->refresh();
        $this->game->load('players');

        // Refresh state machine and broadcast to presentation view
        $this->getStateMachine()->refresh();
        $this->broadcastState();
    }

    public function startThinkingTimer(): void
    {
        $this->getStateMachine()->startTimer();
        $this->game = $this->getStateMachine()->getGame();
        $this->broadcastState();
    }

    public function stopThinkingTimer(): void
    {
        $this->getStateMachine()->stopTimer();
        $this->game = $this->getStateMachine()->getGame();
        $this->broadcastState();
    }

    public function mount(Game $game): void
    {
        $this->game = $game->load(['players', 'rounds.category.answers']);
        $this->loadCurrentState();
    }

    public function loadCurrentState(): void
    {
        $this->currentRound = $this->game->rounds()
            ->where('round_number', $this->game->current_round)
            ->with(['category.answers', 'playerAnswers.player', 'playerAnswers.answer'])
            ->first();

        // Load existing player answers for this round
        if ($this->currentRound) {
            $this->revealedCount = $this->currentRound->current_slide;

            // Initialize player answers array
            foreach ($this->game->players as $player) {
                $existing = $this->currentRound->playerAnswers->where('player_id', $player->id)->first();
                if ($existing && $existing->answer) {
                    $this->playerAnswers[$player->id] = $existing->answer->text;
                } elseif ($existing && !$existing->answer_id) {
                    $this->playerAnswers[$player->id] = '__not_on_list__';
                } else {
                    $this->playerAnswers[$player->id] = '';
                }
                $this->playerDoubles[$player->id] = false;
            }
        }
    }

    public function with(): array
    {
        $answers = $this->currentRound?->category->answers->sortBy('position') ?? collect();

        // Get revealed answers (positions 1 to revealedCount)
        $revealedAnswers = $answers->filter(fn($a) => $a->position <= $this->revealedCount);

        // Build player answers map for display
        $playerAnswerMap = [];
        if ($this->currentRound) {
            foreach ($this->currentRound->playerAnswers as $pa) {
                $playerAnswerMap[$pa->player_id] = [
                    'answer' => $pa->answer,
                    'answer_text' => $pa->answer?->text ?? 'Not on list',
                    'points' => $pa->points_awarded,
                    'was_doubled' => $pa->was_doubled,
                ];
            }
        }

        // Only show active players during gameplay
        $activePlayers = $this->game->players->filter(fn($p) => $p->isActive());

        // Get removed players
        $removedPlayers = $this->game->players->filter(fn($p) => $p->isRemoved());

        // Get turn order for current round (filtered to active players only)
        $turnOrder = collect($this->game->getTurnOrderForRound($this->game->current_round))
            ->filter(fn($p) => $p->isActive());

        // Get current turn info
        $turnInfo = $this->getStateMachine()->getCurrentTurnInfo();

        return [
            'players' => $activePlayers,
            'removedPlayers' => $removedPlayers,
            'answers' => $answers,
            'revealedAnswers' => $revealedAnswers,
            'playerAnswerMap' => $playerAnswerMap,
            'allAnswersCollected' => $this->allAnswersCollected(),
            'answerOptions' => $answers->pluck('text', 'id')->toArray(),
            'turnOrder' => $turnOrder,
            'currentTurnPlayer' => $turnInfo['currentPlayer'],
            'currentTurnIndex' => $turnInfo['currentTurnIndex'],
            'timerMode' => $turnInfo['timerMode'],
        ];
    }

    public function allAnswersCollected(): bool
    {
        if (!$this->currentRound) return false;

        // Only check active players (connected self-registered or GM-created)
        $activePlayers = $this->game->players->filter(fn($p) => $p->isActive());

        foreach ($activePlayers as $player) {
            if (empty($this->playerAnswers[$player->id] ?? '')) {
                return false;
            }
        }
        return true;
    }

    /**
     * Find a matching answer using fuzzy matching.
     * Handles cases like "El Fuego" matching "Fuego" or "The Beatles" matching "Beatles".
     */
    public function findMatchingAnswer(string $input): ?Answer
    {
        if (!$this->currentRound) return null;

        $input = trim($input);
        $inputLower = strtolower($input);
        $inputNormalized = $this->normalizeForMatching($input);

        // First try exact match against full text (case-insensitive)
        foreach ($this->currentRound->category->answers as $answer) {
            if (strtolower($answer->text) === $inputLower) {
                return $answer;
            }
        }

        // Try exact match against display_text (for geo answers)
        foreach ($this->currentRound->category->answers as $answer) {
            if (strtolower($answer->display_text) === $inputLower) {
                return $answer;
            }
        }

        // Try normalized match against both text and display_text
        foreach ($this->currentRound->category->answers as $answer) {
            $answerNormalized = $this->normalizeForMatching($answer->text);
            $displayNormalized = $this->normalizeForMatching($answer->display_text);

            if ($answerNormalized === $inputNormalized || $displayNormalized === $inputNormalized) {
                return $answer;
            }
        }

        // Try containment match (input contains answer or answer contains input)
        foreach ($this->currentRound->category->answers as $answer) {
            $answerNormalized = $this->normalizeForMatching($answer->text);
            $displayNormalized = $this->normalizeForMatching($answer->display_text);

            // Check if one is contained in the other (for "El Fuego" vs "Fuego")
            foreach ([$answerNormalized, $displayNormalized] as $targetNormalized) {
                if (str_contains($inputNormalized, $targetNormalized) || str_contains($targetNormalized, $inputNormalized)) {
                    // Only match if the shorter string is at least 4 chars to avoid false positives
                    $shorter = strlen($inputNormalized) < strlen($targetNormalized) ? $inputNormalized : $targetNormalized;
                    if (strlen($shorter) >= 4) {
                        return $answer;
                    }
                }
            }
        }

        // Try similarity match using Levenshtein distance
        $bestMatch = null;
        $bestScore = PHP_INT_MAX;

        foreach ($this->currentRound->category->answers as $answer) {
            foreach ([$answer->text, $answer->display_text] as $target) {
                $targetNormalized = $this->normalizeForMatching($target);

                // Calculate Levenshtein distance
                $distance = levenshtein($inputNormalized, $targetNormalized);

                // Allow max 2 character difference for strings > 5 chars, or 1 for shorter
                $maxDistance = strlen($targetNormalized) > 5 ? 2 : 1;

                if ($distance <= $maxDistance && $distance < $bestScore) {
                    $bestScore = $distance;
                    $bestMatch = $answer;
                }
            }
        }

        return $bestMatch;
    }

    /**
     * Normalize a string for matching by removing articles, punctuation, and extra spaces.
     */
    private function normalizeForMatching(string $text): string
    {
        $text = strtolower(trim($text));

        // Remove common articles/prefixes in various languages
        $articles = ['the ', 'a ', 'an ', 'el ', 'la ', 'los ', 'las ', 'le ', 'les ', 'der ', 'die ', 'das '];
        foreach ($articles as $article) {
            if (str_starts_with($text, $article)) {
                $text = substr($text, strlen($article));
                break;
            }
        }

        // Remove punctuation and extra spaces
        $text = preg_replace('/[^\w\s]/', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    public function dismissRules(): void
    {
        $this->getStateMachine()->dismissRules();
        $this->game = $this->getStateMachine()->getGame();
        $this->broadcastState();
    }

    public function startCollecting(): void
    {
        if (!$this->currentRound) return;

        $this->getStateMachine()->startCollecting();
        $this->currentRound->refresh();
        $this->broadcastState();
    }

    public function goBackToIntro(): void
    {
        if (!$this->currentRound) return;

        $this->getStateMachine()->goBackToIntro();
        $this->currentRound->refresh();
        $this->game = $this->getStateMachine()->getGame();
        $this->broadcastState();
    }

    public function goBackToCollecting(): void
    {
        if (!$this->currentRound) return;

        $this->getStateMachine()->goBackToCollecting();
        $this->currentRound->refresh();
        $this->revealedCount = 0;
        $this->game = $this->getStateMachine()->getGame();
        $this->broadcastState();
    }

    public function goBackToRevealing(): void
    {
        if (!$this->currentRound) return;

        $this->getStateMachine()->goBackToRevealing();
        $this->currentRound->refresh();
        $this->revealedCount = $this->currentRound->current_slide;
        $this->broadcastState();
    }

    public function submitPlayerAnswer(string $playerId): void
    {
        if (!$this->currentRound) return;

        $answerText = trim($this->playerAnswers[$playerId] ?? '');
        if (empty($answerText)) return;

        $player = Player::find($playerId);
        if (!$player) return;

        // Delete any existing answer for this player in this round
        PlayerAnswer::where('round_id', $this->currentRound->id)
            ->where('player_id', $playerId)
            ->delete();

        // Find matching answer using fuzzy matching
        $answer = $this->findMatchingAnswer($answerText);

        // If match found, use its points; otherwise it's "not on list"
        $points = $answer ? $answer->points : $this->game->not_on_list_penalty;

        // Apply double if selected
        $wasDoubled = $this->playerDoubles[$playerId] ?? false;
        if ($wasDoubled && $player->canUseDouble()) {
            $points *= $this->game->double_multiplier;
        }

        // Create player answer record
        PlayerAnswer::create([
            'round_id' => $this->currentRound->id,
            'player_id' => $playerId,
            'answer_id' => $answer?->id,
            'input_text' => $answerText,
            'points_awarded' => $points,
            'was_doubled' => $wasDoubled,
        ]);

        // Reset double for this player
        $this->playerDoubles[$playerId] = false;

        $this->currentRound->refresh();
        $this->game->refresh();

        // Advance turn - this resets timer for countup mode or stops it if all answered
        $this->getStateMachine()->refresh();
        $this->getStateMachine()->advanceTurn();
        $this->game = $this->getStateMachine()->getGame();

        $this->broadcastState();
    }

    public function startRevealing(): void
    {
        if (!$this->currentRound || !$this->allAnswersCollected()) return;

        $this->getStateMachine()->startRevealing();
        $this->currentRound->refresh();
        $this->revealedCount = 0;
        $this->broadcastState();
    }

    public function revealNext(): void
    {
        if (!$this->currentRound) return;

        $this->revealedCount = $this->getStateMachine()->revealNext();
        $this->currentRound->refresh();
        $this->broadcastState();
    }

    public function revealAll(): void
    {
        if (!$this->currentRound) return;

        $this->getStateMachine()->revealAll();
        $this->currentRound->refresh();
        $this->revealedCount = $this->currentRound->current_slide;
        $this->broadcastState();
    }

    public function showScores(): void
    {
        if (!$this->currentRound) return;

        $this->getStateMachine()->showScores();
        $this->currentRound->refresh();
        $this->game->refresh();
        $this->broadcastState();
    }

    public function correctAnswer(string $playerId, string $answerId): void
    {
        if (!$this->currentRound) return;

        $playerAnswer = PlayerAnswer::where('round_id', $this->currentRound->id)
            ->where('player_id', $playerId)
            ->first();

        if (!$playerAnswer) return;

        $newAnswer = $this->currentRound->category->answers->find($answerId);
        if (!$newAnswer) return;

        $oldPoints = $playerAnswer->points_awarded;
        $newPoints = $newAnswer->points;

        // Apply double if it was doubled
        if ($playerAnswer->was_doubled) {
            $newPoints *= $this->game->double_multiplier;
        }

        $playerAnswer->update([
            'answer_id' => $newAnswer->id,
            'points_awarded' => $newPoints,
        ]);

        // Recalculate player's total score
        $player = Player::find($playerId);
        if ($player) {
            $total = PlayerAnswer::where('player_id', $player->id)
                ->whereHas('round', fn($q) => $q->where('game_id', $this->game->id))
                ->sum('points_awarded');
            $player->update(['total_score' => $total]);
        }

        $this->currentRound->refresh();
        $this->game->refresh();
        $this->broadcastState();
    }

    public function nextRound(): void
    {
        if (!$this->currentRound) return;

        $hasMoreRounds = $this->getStateMachine()->nextRound();
        $this->game = $this->getStateMachine()->getGame();

        if ($hasMoreRounds) {
            $this->loadCurrentState();
        }

        $this->broadcastState();
    }

    public function returnToSetup(): void
    {
        $this->getStateMachine()->returnToSetup();
        $this->game = $this->getStateMachine()->getGame();

        // Broadcast to local presentation via BroadcastChannel
        $state = $this->getStateMachine()->buildState();
        $this->dispatch('game-state-updated', state: $state);

        // Redirect to setup page
        $this->redirect(route('games.show', $this->game));
    }

    public function broadcastState(): void
    {
        // Use state machine as single source of truth
        $this->getStateMachine()->refresh();
        $state = $this->getStateMachine()->broadcast();

        // Broadcast to local presentation via BroadcastChannel
        $this->dispatch('game-state-updated', state: $state);
    }

    // Alias for compatibility with show.blade.php's Echo listener that persists after navigation
    public function broadcastLobbyState(): void
    {
        $this->broadcastState();
    }

    public function confirmRemovePlayer(string $playerId): void
    {
        $this->confirmingRemovePlayerId = $playerId;
    }

    public function cancelRemovePlayer(): void
    {
        $this->confirmingRemovePlayerId = null;
    }

    public function removePlayer(?string $playerId = null): void
    {
        $playerId = $playerId ?? $this->confirmingRemovePlayerId;
        $this->confirmingRemovePlayerId = null;

        $player = Player::find($playerId);
        if (!$player || $player->game_id !== $this->game->id) {
            return;
        }

        $player->update(['removed_at' => now()]);

        // Refresh state and broadcast
        $this->game->refresh();
        $this->getStateMachine()->refresh();
        $this->loadCurrentState();
        $this->broadcastState();
    }

    public function restorePlayer(string $playerId): void
    {
        $player = Player::find($playerId);
        if (!$player || $player->game_id !== $this->game->id) {
            return;
        }

        $player->update(['removed_at' => null]);

        // Refresh state and broadcast
        $this->game->refresh();
        $this->getStateMachine()->refresh();
        $this->loadCurrentState();
        $this->broadcastState();
    }
}; ?>

<div wire:poll.10s="refreshPlayerStatus">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold">{{ $game->name }}</h1>
                <p class="text-slate-400">
                    Round {{ $game->current_round }} of {{ $game->total_rounds }}
                    @if($currentRound)
                        - {{ $currentRound->category->title }}
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-4">
                @if($game->join_code)
                    <div class="bg-slate-800 rounded-lg px-4 py-2 border border-slate-600">
                        <span class="text-slate-400 text-sm">Join Code:</span>
                        <span class="font-mono text-2xl font-bold tracking-wider ml-2">{{ $game->join_code }}</span>
                    </div>
                @endif
                <div class="flex gap-3">
                    <a href="{{ route('games.present', $game) }}" target="friction-presentation"
                       class="bg-purple-600 hover:bg-purple-700 px-4 py-2 rounded-lg font-medium transition">
                        Open Presentation
                    </a>
                    <button wire:click="returnToSetup"
                            class="bg-slate-700 hover:bg-slate-600 px-4 py-2 rounded-lg font-medium transition">
                        Back to Setup
                    </button>
                </div>
            </div>
        </div>

        @if($game->status === 'completed')
            <!-- Game Complete -->
            <div class="bg-slate-800 rounded-xl p-8 text-center">
                <h2 class="text-4xl font-bold mb-4">Game Complete!</h2>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 max-w-3xl mx-auto">
                    @foreach($players->sortByDesc('total_score') as $index => $player)
                        <div class="bg-slate-900 rounded-xl p-4 {{ $index === 0 ? 'ring-2 ring-yellow-500' : '' }}">
                            <div class="text-lg font-bold" style="color: {{ $player->color }}">
                                @if($index === 0) 👑 @endif
                                {{ $player->name }}
                            </div>
                            <div class="text-3xl font-bold mt-2">{{ $player->total_score }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            <div class="grid lg:grid-cols-3 gap-6">
                <!-- Main Control Panel -->
                <div class="lg:col-span-2 space-y-6">

                    {{-- RULES PHASE --}}
                    @if($game->show_rules)
                        <div class="bg-slate-800 rounded-xl p-6 border border-slate-700">
                            <div class="text-center">
                                <h2 class="text-2xl font-bold mb-4">Showing Game Rules</h2>
                                <p class="text-slate-400 mb-6">Players can see the rules on the presentation screen</p>
                                <button wire:click="dismissRules"
                                        class="bg-green-600 hover:bg-green-700 px-8 py-3 rounded-lg font-bold transition">
                                    Continue to Round 1 →
                                </button>
                            </div>
                        </div>

                    {{-- INTRO PHASE --}}
                    @elseif($currentRound?->status === 'intro')
                        <div class="bg-slate-800 rounded-xl p-6 border border-slate-700">
                            <div class="text-center">
                                <p class="text-slate-400 mb-2">Round {{ $game->current_round }}</p>
                                <h2 class="text-3xl font-bold mb-2">{{ $currentRound->category->title }}</h2>
                                @if($currentRound->category->description)
                                    <p class="text-slate-400 mb-6">{{ $currentRound->category->description }}</p>
                                @endif
                                <button wire:click="startCollecting"
                                        class="bg-blue-600 hover:bg-blue-700 px-8 py-3 rounded-lg font-bold transition">
                                    Start Collecting Answers →
                                </button>
                            </div>
                        </div>

                    {{-- COLLECTING PHASE --}}
                    @elseif($currentRound?->status === 'collecting')
                        <div class="bg-slate-800 rounded-xl p-6 border border-slate-700">
                            <div class="flex justify-between items-center mb-4">
                                <h2 class="text-xl font-bold">Collect Player Answers</h2>
                                <span class="text-sm px-3 py-1 rounded bg-blue-600/20 text-blue-400">
                                    {{ $currentRound->category->title }}
                                </span>
                            </div>

                            <!-- Turn Order Display -->
                            <div class="mb-4 p-3 bg-slate-900 rounded-lg">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-slate-400 text-sm">Answer Order (Round {{ $game->current_round }})</span>
                                    @if($game->timer_running && $timerMode)
                                        <span class="text-sm font-medium {{ $timerMode === 'countdown' ? 'text-yellow-400' : 'text-red-400' }}">
                                            {{ $timerMode === 'countdown' ? 'Thinking Time' : 'Waiting...' }}
                                        </span>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    @foreach($turnOrder as $index => $p)
                                        @php
                                            $isCurrent = $currentTurnPlayer && $p->id === $currentTurnPlayer->id;
                                            $isDisconnected = $p->isGmControlled() && !$p->isGmCreated();
                                        @endphp
                                        <div class="flex items-center gap-2 px-3 py-1.5 rounded-full text-sm
                                                    {{ $isCurrent ? 'bg-blue-600 ring-2 ring-blue-400' : 'bg-slate-800 border border-slate-700' }}
                                                    {{ $isDisconnected ? 'border-yellow-500/50' : '' }}">
                                            <span class="font-bold {{ $isCurrent ? 'text-white' : 'text-slate-500' }}">{{ $index + 1 }}.</span>
                                            <div class="w-2 h-2 rounded-full" style="background-color: {{ $p->color }}"></div>
                                            <span style="color: {{ $p->color }}">{{ $p->name }}</span>
                                            @if($isDisconnected)
                                                <span class="text-xs text-yellow-400">⚠</span>
                                            @endif
                                            @if($isCurrent)
                                                <span class="text-xs text-white">⬅</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                                @if($currentTurnPlayer)
                                    <p class="text-slate-400 text-sm mt-2">
                                        Waiting for: <span class="font-bold" style="color: {{ $currentTurnPlayer->color }}">{{ $currentTurnPlayer->name }}</span>
                                    </p>
                                @endif
                            </div>

                            <div class="space-y-4">
                                @foreach($turnOrder as $player)
                                    @php
                                        $hasAnswer = !empty($playerAnswers[$player->id] ?? '');
                                        $existingAnswer = $playerAnswerMap[$player->id] ?? null;
                                        $isDisconnected = $player->isGmControlled() && !$player->isGmCreated();
                                    @endphp
                                    <div class="bg-slate-900 rounded-lg p-4 {{ $hasAnswer ? 'ring-1 ring-green-500/50' : '' }} {{ $isDisconnected ? 'ring-1 ring-yellow-500/50' : '' }}">
                                        <div class="flex items-center gap-4">
                                            <div class="w-4 h-4 rounded-full flex-shrink-0" style="background-color: {{ $player->color }}"></div>
                                            <div class="font-bold text-lg flex-shrink-0 w-32" style="color: {{ $player->color }}">
                                                {{ $player->name }}
                                                @if($isDisconnected)
                                                    <span class="text-xs text-yellow-400 font-normal">(disconnected)</span>
                                                @endif
                                            </div>

                                            <div class="flex-1 flex items-center gap-2" x-data="{ open: false }">
                                                <div class="relative flex-1">
                                                    <input type="text"
                                                           wire:model="playerAnswers.{{ $player->id }}"
                                                           @focus="open = true"
                                                           @blur="setTimeout(() => open = false, 200)"
                                                           @keydown.enter="open = false; $wire.submitPlayerAnswer('{{ $player->id }}')"
                                                           placeholder="Type answer..."
                                                           class="w-full bg-slate-800 border border-slate-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">

                                                    <!-- Autocomplete dropdown -->
                                                    <div x-show="open"
                                                         x-cloak
                                                         class="absolute z-10 w-full mt-1 bg-slate-800 border border-slate-600 rounded-lg shadow-lg max-h-48 overflow-auto"
                                                         x-data="{ input: '' }"
                                                         x-effect="input = ($wire.playerAnswers['{{ $player->id }}'] || '').toLowerCase()">
                                                        @foreach($answers as $answer)
                                                            <button type="button"
                                                                    x-show="input.length > 0 && '{{ strtolower(addslashes($answer->display_text)) }}'.includes(input)"
                                                                    @click="$wire.set('playerAnswers.{{ $player->id }}', '{{ addslashes($answer->display_text) }}'); open = false; $wire.submitPlayerAnswer('{{ $player->id }}')"
                                                                    class="w-full text-left px-4 py-2 hover:bg-slate-700 transition {{ $answer->is_friction ? 'text-red-400' : '' }}">
                                                                <span class="text-slate-500 mr-2">#{{ $answer->position }}</span>
                                                                {{ $answer->display_text }}
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                </div>

                                                @if($player->canUseDouble())
                                                    <label class="flex items-center gap-1 cursor-pointer flex-shrink-0">
                                                        <input type="checkbox" wire:model.live="playerDoubles.{{ $player->id }}" class="w-4 h-4 rounded">
                                                        <span class="text-yellow-400 text-sm">{{ $game->double_multiplier }}x</span>
                                                    </label>
                                                @endif

                                                <button wire:click="submitPlayerAnswer('{{ $player->id }}')"
                                                        class="bg-slate-700 hover:bg-slate-600 px-3 py-2 rounded transition flex-shrink-0">
                                                    Set
                                                </button>
                                            </div>

                                            @if($existingAnswer)
                                                <div class="flex-shrink-0 text-right">
                                                    <span class="text-green-400">✓</span>
                                                    <span class="text-slate-400 text-sm ml-1">{{ Str::limit($existingAnswer['answer_text'], 20) }}</span>
                                                </div>
                                            @endif

                                            <!-- Remove button -->
                                            <button wire:click="confirmRemovePlayer('{{ $player->id }}')"
                                                    class="flex-shrink-0 text-red-400 hover:text-red-300 hover:bg-red-900/30 text-xs px-2 py-1 rounded transition"
                                                    title="Remove player">
                                                ✕
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="mt-6 pt-4 border-t border-slate-700 flex justify-between items-center">
                                <div class="flex items-center gap-4">
                                    <button wire:click="goBackToIntro"
                                            class="text-slate-400 hover:text-white transition">
                                        ← Back to Intro
                                    </button>
                                    <span class="text-slate-400">
                                        {{ count(array_filter($playerAnswers)) }} / {{ count($players) }} answers collected
                                    </span>
                                </div>
                                <button wire:click="startRevealing"
                                        @disabled(!$allAnswersCollected)
                                        class="bg-green-600 hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed px-8 py-3 rounded-lg font-bold transition">
                                    Start Reveal →
                                </button>
                            </div>
                        </div>

                    {{-- REVEALING / FRICTION PHASE --}}
                    @elseif(in_array($currentRound?->status, ['revealing', 'friction']))
                        <div class="bg-slate-800 rounded-xl p-6 border border-slate-700">
                            <div class="flex justify-between items-center mb-6">
                                <h2 class="text-xl font-bold">
                                    @if($revealedCount <= $game->top_answers_count)
                                        Revealing Top {{ $game->top_answers_count }}
                                    @else
                                        Revealing FRICTION Answers
                                    @endif
                                </h2>
                                <span class="text-sm px-3 py-1 rounded {{ $revealedCount > $game->top_answers_count ? 'bg-red-600/20 text-red-400' : 'bg-green-600/20 text-green-400' }}">
                                    {{ $revealedCount }} / {{ $answers->count() }} revealed
                                </span>
                            </div>

                            <!-- Answers Grid -->
                            <div class="grid grid-cols-2 gap-3 mb-6">
                                @foreach($answers->take($game->top_answers_count) as $answer)
                                    @php
                                        $isRevealed = $answer->position <= $revealedCount;
                                        $playersWithThis = collect($playerAnswerMap)->filter(fn($pa) => ($pa['answer']?->id ?? null) === $answer->id);
                                    @endphp
                                    <div class="bg-slate-900 rounded-lg p-3 {{ $isRevealed ? '' : 'opacity-40' }}">
                                        <div class="flex justify-between items-start">
                                            <div>
                                                <span class="text-green-400 font-bold">#{{ $answer->position }}</span>
                                                @if($isRevealed)
                                                    <span class="ml-2">{{ $answer->text }}</span>
                                                @else
                                                    <span class="ml-2 text-slate-500">???</span>
                                                @endif
                                            </div>
                                            <span class="text-green-400 font-bold">+{{ $answer->points }}</span>
                                        </div>
                                        @if($isRevealed && $playersWithThis->count() > 0)
                                            <div class="mt-2 flex flex-wrap gap-1">
                                                @foreach($playersWithThis as $playerId => $pa)
                                                    <span class="text-xs px-2 py-0.5 rounded-full" style="background-color: {{ $players->find($playerId)?->color }}30; color: {{ $players->find($playerId)?->color }}">
                                                        {{ $players->find($playerId)?->name }}
                                                        @if($pa['was_doubled']) 2x @endif
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            @if($answers->count() > $game->top_answers_count)
                                <h3 class="text-red-400 font-bold mb-3">FRICTION ZONE</h3>
                                <div class="grid grid-cols-2 gap-3 mb-6">
                                    @foreach($answers->skip($game->top_answers_count) as $answer)
                                        @php
                                            $isRevealed = $answer->position <= $revealedCount;
                                            $playersWithThis = collect($playerAnswerMap)->filter(fn($pa) => ($pa['answer']?->id ?? null) === $answer->id);
                                        @endphp
                                        <div class="bg-red-900/30 border border-red-500/30 rounded-lg p-3 {{ $isRevealed ? '' : 'opacity-40' }}">
                                            <div class="flex justify-between items-start">
                                                <div>
                                                    <span class="text-red-400 font-bold">#{{ $answer->position }}</span>
                                                    @if($isRevealed)
                                                        <span class="ml-2">{{ $answer->text }}</span>
                                                    @else
                                                        <span class="ml-2 text-slate-500">???</span>
                                                    @endif
                                                </div>
                                                <span class="text-red-400 font-bold">{{ $answer->points }}</span>
                                            </div>
                                            @if($isRevealed && $playersWithThis->count() > 0)
                                                <div class="mt-2 flex flex-wrap gap-1">
                                                    @foreach($playersWithThis as $playerId => $pa)
                                                        <span class="text-xs px-2 py-0.5 rounded-full" style="background-color: {{ $players->find($playerId)?->color }}30; color: {{ $players->find($playerId)?->color }}">
                                                            {{ $players->find($playerId)?->name }}
                                                            @if($pa['was_doubled']) 2x @endif
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            <div class="flex justify-between items-center pt-4 border-t border-slate-700">
                                <div class="flex items-center gap-4">
                                    <button wire:click="goBackToCollecting"
                                            class="text-slate-400 hover:text-white transition">
                                        ← Back to Collecting
                                    </button>
                                    <button wire:click="revealAll"
                                            class="text-slate-400 hover:text-white transition">
                                        Reveal All
                                    </button>
                                </div>
                                <div class="flex gap-3">
                                    @if($revealedCount < $answers->count())
                                        <button wire:click="revealNext"
                                                class="bg-blue-600 hover:bg-blue-700 px-8 py-3 rounded-lg font-bold transition">
                                            Reveal #{{ $revealedCount + 1 }} →
                                        </button>
                                    @else
                                        <button wire:click="showScores"
                                                class="bg-green-600 hover:bg-green-700 px-8 py-3 rounded-lg font-bold transition">
                                            Show Scores →
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>

                    {{-- SCORING PHASE --}}
                    @elseif($currentRound?->status === 'scoring')
                        <div class="bg-slate-800 rounded-xl p-6 border border-slate-700">
                            <h2 class="text-2xl font-bold text-center mb-6">Round {{ $game->current_round }} Complete!</h2>

                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                                @foreach($players->sortByDesc('total_score') as $index => $player)
                                    @php $pa = $playerAnswerMap[$player->id] ?? null; @endphp
                                    <div class="bg-slate-900 rounded-xl p-4 {{ $index === 0 ? 'ring-2 ring-yellow-500' : '' }}">
                                        <div class="text-lg font-bold" style="color: {{ $player->color }}">
                                            {{ $player->name }}
                                        </div>
                                        <div class="text-3xl font-bold mt-2">{{ $player->total_score }}</div>
                                        @if($pa)
                                            <div class="text-sm text-slate-400 mt-2">
                                                {{ $pa['answer_text'] }}
                                                <span class="{{ $pa['points'] >= 0 ? 'text-green-400' : 'text-red-400' }}">
                                                    ({{ $pa['points'] > 0 ? '+' : '' }}{{ $pa['points'] }}{{ $pa['was_doubled'] ? ' 2x' : '' }})
                                                </span>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            <div class="flex justify-between items-center">
                                <button wire:click="goBackToRevealing"
                                        class="text-slate-400 hover:text-white transition">
                                    ← Back to Revealing
                                </button>
                                <button wire:click="nextRound"
                                        class="bg-green-600 hover:bg-green-700 px-8 py-3 rounded-lg font-bold transition">
                                    @if($game->current_round >= $game->total_rounds)
                                        Finish Game
                                    @else
                                        Next Round →
                                    @endif
                                </button>
                            </div>
                        </div>
                    @endif

                    <!-- Player Answers This Round -->
                    @if($currentRound && count($playerAnswerMap) > 0 && !in_array($currentRound->status, ['intro', 'collecting']))
                        <div class="bg-slate-800 rounded-xl p-6 border border-slate-700">
                            <h3 class="text-lg font-semibold mb-4">Player Answers This Round</h3>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                @foreach($players as $player)
                                    @php $pa = $playerAnswerMap[$player->id] ?? null; @endphp
                                    <div class="bg-slate-900 rounded-lg p-3" x-data="{ editing: false }">
                                        <div class="font-bold text-sm" style="color: {{ $player->color }}">{{ $player->name }}</div>
                                        @if($pa)
                                            <div x-show="!editing">
                                                <div class="text-slate-300 mt-1">{{ $pa['answer_text'] }}</div>
                                                <div class="flex items-center justify-between mt-1">
                                                    <span class="text-xs {{ $pa['points'] >= 0 ? 'text-green-400' : 'text-red-400' }}">
                                                        {{ $pa['points'] > 0 ? '+' : '' }}{{ $pa['points'] }} pts
                                                        @if($pa['was_doubled']) (2x) @endif
                                                    </span>
                                                    <button @click="editing = true" class="text-xs text-blue-400 hover:text-blue-300">Edit</button>
                                                </div>
                                            </div>
                                            <div x-show="editing" x-cloak class="mt-1">
                                                <select @change="$wire.correctAnswer('{{ $player->id }}', $event.target.value); editing = false"
                                                        class="w-full text-xs bg-slate-800 border border-slate-600 rounded px-2 py-1 text-white">
                                                    <option value="">Select correct answer...</option>
                                                    @foreach($answers as $answer)
                                                        <option value="{{ $answer->id }}" {{ $pa['answer']?->id === $answer->id ? 'selected' : '' }}>
                                                            #{{ $answer->position }} {{ $answer->display_text }} ({{ $answer->points > 0 ? '+' : '' }}{{ $answer->points }})
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <button @click="editing = false" class="text-xs text-slate-400 hover:text-slate-300 mt-1">Cancel</button>
                                            </div>
                                        @else
                                            <div class="text-slate-500 mt-1">No answer</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- GM Scoreboard (always visible to GM) -->
                    <div class="bg-slate-800 rounded-xl p-6 border border-slate-700">
                        <h2 class="text-lg font-semibold mb-4">Players (GM View)</h2>
                        <div class="space-y-3">
                            @foreach($players->sortByDesc('total_score') as $index => $player)
                                <div class="flex items-center justify-between bg-slate-900 rounded-lg px-4 py-3 group">
                                    <div class="flex items-center gap-3">
                                        <span class="text-slate-500 font-bold w-6">{{ $index + 1 }}</span>
                                        <div class="w-3 h-3 rounded-full" style="background-color: {{ $player->color }}"></div>
                                        <span class="font-medium">{{ $player->name }}</span>
                                        @if($player->isGmControlled() && !$player->isGmCreated())
                                            <span class="text-xs text-yellow-400">⚠</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xl font-bold">{{ $player->total_score }}</span>
                                        @if($player->doublesRemaining() > 0)
                                            <span class="text-xs bg-yellow-500/20 text-yellow-400 px-1.5 py-0.5 rounded">{{ $game->double_multiplier }}x{{ $player->doublesRemaining() > 1 ? ' ×' . $player->doublesRemaining() : '' }}</span>
                                        @endif
                                        <!-- Remove button (hidden until hover) -->
                                        <button wire:click="confirmRemovePlayer('{{ $player->id }}')"
                                                class="opacity-0 group-hover:opacity-100 text-red-400 hover:text-red-300 text-xs ml-2 transition">
                                            ✕
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <!-- Removed Players -->
                        @if($removedPlayers->count() > 0)
                            <div class="mt-4 pt-4 border-t border-slate-700">
                                <h3 class="text-sm text-slate-400 mb-2">Removed Players</h3>
                                <div class="space-y-2">
                                    @foreach($removedPlayers as $player)
                                        <div class="flex items-center justify-between bg-slate-900/50 rounded-lg px-4 py-2 opacity-60">
                                            <div class="flex items-center gap-3">
                                                <div class="w-3 h-3 rounded-full" style="background-color: {{ $player->color }}"></div>
                                                <span class="font-medium line-through">{{ $player->name }}</span>
                                            </div>
                                            <button wire:click="restorePlayer('{{ $player->id }}')"
                                                    class="text-xs text-green-400 hover:text-green-300">
                                                Restore
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>

                    <!-- Round Progress -->
                    <div class="bg-slate-800 rounded-xl p-6 border border-slate-700">
                        <h3 class="text-sm text-slate-400 mb-2">Round Progress</h3>
                        <div class="flex gap-1">
                            @for($i = 1; $i <= $game->total_rounds; $i++)
                                <div class="flex-1 h-2 rounded-full
                                    @if($i < $game->current_round) bg-green-500
                                    @elseif($i === $game->current_round) bg-blue-500
                                    @else bg-slate-700
                                    @endif
                                "></div>
                            @endfor
                        </div>
                    </div>

                    <!-- Category Reference (GM only - shows full text) -->
                    @if($currentRound && in_array($currentRound->status, ['collecting', 'revealing', 'friction']))
                        <div class="bg-slate-800 rounded-xl p-6 border border-slate-700">
                            <h3 class="text-sm text-slate-400 mb-2">Answer Reference</h3>
                            <div class="space-y-1 text-sm max-h-64 overflow-auto">
                                @foreach($answers as $answer)
                                    <div class="flex justify-between {{ $answer->is_friction ? 'text-red-400' : 'text-slate-300' }}">
                                        <span>#{{ $answer->position }} {{ $answer->text }}</span>
                                        <span>{{ $answer->points > 0 ? '+' : '' }}{{ $answer->points }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <!-- Remove Player Confirmation Modal -->
    @if($confirmingRemovePlayerId)
        @php $playerToRemove = $players->firstWhere('id', $confirmingRemovePlayerId) ?? $removedPlayers->firstWhere('id', $confirmingRemovePlayerId); @endphp
        @if($playerToRemove)
            <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
                <div class="flex min-h-screen items-center justify-center p-4">
                    <div class="fixed inset-0 bg-black/70 transition-opacity" wire:click="cancelRemovePlayer"></div>

                    <div class="relative bg-slate-800 rounded-xl shadow-xl w-full max-w-sm border border-slate-700">
                        <div class="px-6 py-4 border-b border-slate-700 flex justify-between items-center">
                            <h3 class="text-lg font-bold text-red-400">Remove Player</h3>
                            <button wire:click="cancelRemovePlayer" class="text-slate-400 hover:text-white text-2xl">&times;</button>
                        </div>

                        <div class="p-6">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-4 h-4 rounded-full" style="background-color: {{ $playerToRemove->color }}"></div>
                                <span class="font-bold text-lg" style="color: {{ $playerToRemove->color }}">{{ $playerToRemove->name }}</span>
                            </div>
                            <p class="text-slate-300 mb-2">
                                Remove this player from the game?
                            </p>
                            <p class="text-slate-400 text-sm mb-6">
                                They will no longer appear in the turn order or scoring. You can restore them later if needed.
                            </p>

                            <div class="flex justify-end gap-3">
                                <button wire:click="cancelRemovePlayer"
                                        class="px-4 py-2 text-slate-400 hover:text-white transition">
                                    Cancel
                                </button>
                                <button wire:click="removePlayer"
                                        class="px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg font-medium transition">
                                    Remove Player
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endif

    <script data-navigate-track>
        (function() {
            const gameId = '{{ $game->id }}';
            const channelName = 'friction-game-' + gameId;

            // Close any existing channel for this game
            if (window.frictionChannel) {
                window.frictionChannel.close();
            }

            const channel = new BroadcastChannel(channelName);
            window.frictionChannel = channel;
            console.log('[Control] BroadcastChannel initialized for game:', gameId);

            // Listen for Livewire 3 dispatched events
            document.addEventListener('game-state-updated', function(event) {
                const state = event.detail.state || event.detail;
                console.log('[Control] Broadcasting state:', state);
                channel.postMessage(state);
            });

            channel.onmessage = function(event) {
                console.log('[Control] Received message:', event.data);
                if (event.data && event.data.type === 'request-state') {
                    console.log('[Control] Presentation requested state, triggering refresh...');
                    // Find the Livewire component and call broadcastState
                    const component = Livewire.all()[0];
                    if (component) {
                        component.$wire.handleBroadcastState();
                    }
                }
            };

            // Broadcast initial state after a short delay
            setTimeout(function() {
                console.log('[Control] Sending initial broadcast');
                const component = Livewire.all()[0];
                if (component) {
                    component.$wire.handleBroadcastState();
                }
            }, 500);
        })();
    </script>
</div>
