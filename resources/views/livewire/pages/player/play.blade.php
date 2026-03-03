<?php

use App\Models\Game;
use App\Models\Player;
use App\Models\PlayerAnswer;
use App\Services\PlayerConnectionService;
use App\Events\PlayerAnswerSubmitted;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;

new #[Layout('components.layouts.app')] class extends Component {
    public Game $game;
    public ?Player $player = null;
    public ?string $sessionToken = null;

    public string $answerText = '';
    public bool $useDouble = false;
    public bool $hasSubmitted = false;
    public ?string $submittedAnswer = null;

    // State driven by broadcasts (single source of truth)
    public ?string $gameStatus = null;
    public ?int $currentRoundNumber = null;
    public ?string $roundStatus = null;
    public bool $timerRunning = false;
    public ?int $timerStartedAt = null;
    public int $thinkingTime = 30;
    public bool $showRules = false;

    // Turn tracking
    public ?string $currentTurnPlayerId = null;
    public ?string $currentTurnPlayerName = null;
    public ?int $currentTurnIndex = null;
    public ?string $timerMode = null; // 'countdown' or 'countup'
    public bool $allAnswered = false;

    // Track if we were ever in an active game (to detect resets)
    public bool $wasInActiveGame = false;

    // Track if the game has been deleted
    public bool $gameDeleted = false;

    public function mount(Game $game): void
    {
        $this->game = $game;
        $this->sessionToken = session('player_token');
        $playerId = session('player_id');

        if ($playerId) {
            $this->player = Player::find($playerId);
        }

        // Verify session is valid for this game
        if (!$this->player || $this->player->game_id !== $game->id) {
            session()->forget(['player_token', 'player_id']);
            $this->redirect(route('player.join'));
            return;
        }

        // Send initial heartbeat (only if not removed)
        if (!$this->player->isRemoved()) {
            app(PlayerConnectionService::class)->heartbeat($this->sessionToken);
        }

        $this->loadCurrentState();

        // Check if the game was ever active (has rounds with answers or current_round > 0)
        $hasPlayedBefore = $this->game->current_round > 0 ||
            $this->game->rounds()->whereHas('playerAnswers')->exists();
        $this->wasInActiveGame = $hasPlayedBefore;
    }

    #[On('echo:game.{game.id},game.deleted')]
    public function handleGameDeleted(array $data): void
    {
        $this->gameDeleted = true;
    }

    #[On('echo:game.{game.id},state.updated')]
    public function handleStateUpdate(array $data): void
    {
        // Update local state from broadcast (single source of truth)
        $this->gameStatus = $data['gameStatus'] ?? $this->gameStatus;
        $this->currentRoundNumber = $data['currentRound'] ?? $this->currentRoundNumber;
        $this->roundStatus = $data['roundStatus'] ?? $this->roundStatus;
        $this->timerRunning = $data['timerRunning'] ?? false;
        $this->timerStartedAt = $data['timerStartedAt'] ?? null;
        $this->thinkingTime = $data['thinkingTime'] ?? 30;
        $this->showRules = $data['showRules'] ?? false;

        // Turn tracking
        $this->currentTurnPlayerId = $data['currentTurnPlayerId'] ?? null;
        $this->currentTurnPlayerName = $data['currentTurnPlayerName'] ?? null;
        $this->currentTurnIndex = $data['currentTurnIndex'] ?? null;
        $this->timerMode = $data['timerMode'] ?? null;
        $this->allAnswered = $data['allAnswered'] ?? false;

        // Track if we've ever been in an active game
        if ($this->gameStatus === 'playing') {
            $this->wasInActiveGame = true;
        }

        // Refresh game and player data to get latest scores and round info
        $this->game->refresh();
        $this->player->refresh();

        // Check if we need to reset submission state for new round
        $this->checkSubmissionState();
    }

    public function loadCurrentState(): void
    {
        $this->game->refresh();
        $this->player->refresh();

        // Initialize state from database
        $this->gameStatus = $this->game->status;
        $this->currentRoundNumber = $this->game->current_round;
        $this->showRules = (bool) $this->game->show_rules;
        $this->timerRunning = (bool) $this->game->timer_running;
        $this->timerStartedAt = $this->game->timer_started_at?->timestamp;
        $this->thinkingTime = $this->game->thinking_time;

        // Track if we've ever been in an active game
        if ($this->gameStatus === 'playing') {
            $this->wasInActiveGame = true;
        }

        $currentRound = $this->game->currentRoundModel();
        if ($currentRound) {
            $this->roundStatus = $currentRound->status;
        }

        // Only check submission state if player is active
        if (!$this->player->isRemoved()) {
            $this->checkSubmissionState();
        }
    }

    private function checkSubmissionState(): void
    {
        $currentRound = $this->game->currentRoundModel();

        if ($currentRound) {
            $existingAnswer = PlayerAnswer::where('round_id', $currentRound->id)
                ->where('player_id', $this->player->id)
                ->first();

            if ($existingAnswer) {
                $this->hasSubmitted = true;
                $this->submittedAnswer = $existingAnswer->answer?->text ?? 'Not on list';
            } else {
                $this->hasSubmitted = false;
                $this->submittedAnswer = null;
                $this->answerText = '';
            }
        } else {
            $this->hasSubmitted = false;
            $this->submittedAnswer = null;
        }
    }

    public function submitAnswer(): void
    {
        if ($this->hasSubmitted || empty(trim($this->answerText))) {
            return;
        }

        // Broadcast the answer submission to GM
        event(new PlayerAnswerSubmitted(
            $this->game,
            $this->player,
            trim($this->answerText),
            $this->useDouble && $this->player->canUseDouble()
        ));

        $this->submittedAnswer = trim($this->answerText);
        $this->hasSubmitted = true;
        $this->answerText = '';
    }

    public function getTitle(): string
    {
        return $this->game->name;
    }

    public function with(): array
    {
        $this->game->load(['players' => fn($q) => $q->orderBy('position')]);
        $currentRound = $this->game->currentRoundModel();
        $turnOrder = $this->game->getTurnOrderForRound($this->game->current_round);

        $myTurnPosition = null;
        foreach ($turnOrder as $index => $p) {
            if ($p->id === $this->player->id) {
                $myTurnPosition = $index + 1;
                break;
            }
        }

        // Use service for active players
        $connectionService = app(PlayerConnectionService::class);
        $activePlayers = $connectionService->getActivePlayers($this->game);

        return [
            'currentRound' => $currentRound,
            'turnOrder' => $turnOrder,
            'myTurnPosition' => $myTurnPosition,
            'activePlayers' => $activePlayers,
        ];
    }
}; ?>

<div class="min-h-screen bg-slate-900 text-white"
     @if(!$player->isRemoved())
     wire:poll.2s="loadCurrentState"
     @endif
     x-data="{
        timerRunning: @entangle('timerRunning'),
        timerStartedAt: @entangle('timerStartedAt'),
        thinkingTime: @entangle('thinkingTime'),
        timerMode: @entangle('timerMode'),
        currentTurnPlayerId: @entangle('currentTurnPlayerId'),
        allAnswered: @entangle('allAnswered'),
        timerSeconds: 0,
        heartbeatInterval: null,
        timerInterval: null,
        playerId: '{{ $player->id }}',
        isRemoved: {{ $player->isRemoved() ? 'true' : 'false' }},

        get isMyTurn() {
            return this.currentTurnPlayerId === this.playerId;
        },

        init() {
            // Don't run heartbeats if player is removed
            if (this.isRemoved) return;

            const sessionToken = '{{ $sessionToken }}';

            // Heartbeat function - keeps player connected
            const sendHeartbeat = () => {
                fetch('/api/player/heartbeat', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: sessionToken })
                }).catch(() => {});
            };

            // Send heartbeat every 5 seconds (timeout is 15 seconds)
            sendHeartbeat();
            this.heartbeatInterval = setInterval(sendHeartbeat, 5000);

            // Disconnect function
            const sendDisconnect = () => {
                fetch('/api/player/disconnect', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: sessionToken }),
                    keepalive: true
                }).catch(() => {});
            };

            window.addEventListener('beforeunload', () => {
                clearInterval(this.heartbeatInterval);
                sendDisconnect();
            });

            window.addEventListener('pagehide', () => {
                clearInterval(this.heartbeatInterval);
                sendDisconnect();
            });

            // Handle visibility change
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') {
                    clearInterval(this.heartbeatInterval);
                    this.heartbeatInterval = null;
                    sendDisconnect();
                } else if (document.visibilityState === 'visible') {
                    sendHeartbeat();
                    this.heartbeatInterval = setInterval(sendHeartbeat, 5000);
                    $wire.loadCurrentState();
                }
            });

            // Watch for timer state changes
            this.$watch('timerRunning', (running) => {
                this.updateTimerLogic();
            });
            this.$watch('timerMode', () => {
                this.updateTimerLogic();
            });
            this.$watch('timerStartedAt', () => {
                this.updateTimerLogic();
            });
        },

        updateTimerLogic() {
            clearInterval(this.timerInterval);

            if (!this.timerRunning || !this.timerStartedAt) {
                this.timerSeconds = 0;
                return;
            }

            if (this.timerMode === 'countdown') {
                // Countdown mode - show seconds remaining
                this.updateCountdown();
                this.timerInterval = setInterval(() => this.updateCountdown(), 1000);
            } else if (this.timerMode === 'countup') {
                // Countup mode - show seconds elapsed
                this.updateCountup();
                this.timerInterval = setInterval(() => this.updateCountup(), 1000);
            }
        },

        updateCountdown() {
            if (this.timerStartedAt) {
                const now = Math.floor(Date.now() / 1000);
                const elapsed = now - this.timerStartedAt;
                this.timerSeconds = Math.max(0, this.thinkingTime - elapsed);
            }
        },

        updateCountup() {
            if (this.timerStartedAt) {
                const now = Math.floor(Date.now() / 1000);
                this.timerSeconds = now - this.timerStartedAt;
            }
        }
     }"
    <!-- Header -->
    <header class="bg-slate-800 border-b border-slate-700 px-4 py-3">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-4 h-4 rounded-full" style="background-color: {{ $player->color }}"></div>
                <span class="font-bold">{{ $player->name }}</span>
            </div>
            <div class="flex items-center gap-4">
                <span class="text-slate-400">{{ $player->total_score }} pts</span>
                @if($player->canUseDouble())
                    <span class="text-yellow-400 text-sm font-medium">2x</span>
                @endif
            </div>
        </div>
    </header>

    <main class="p-4 max-w-lg mx-auto">
        @if($player->isRemoved())
            <!-- Player was removed from the game -->
            <div class="text-center py-12">
                <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-red-900/30 flex items-center justify-center">
                    <svg class="w-10 h-10 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </div>

                <h2 class="text-2xl font-bold mb-2 text-red-400">You've Been Removed</h2>
                <p class="text-slate-400 mb-8">The Game Master removed you from this game.</p>

                <a href="{{ route('player.join') }}"
                   class="inline-block px-6 py-3 bg-slate-700 hover:bg-slate-600 rounded-lg font-semibold transition">
                    Join Another Game
                </a>
            </div>

        @elseif($gameDeleted)
            <!-- Game was deleted -->
            <div class="text-center py-12">
                <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-slate-800 flex items-center justify-center">
                    <svg class="w-10 h-10 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </div>

                <h2 class="text-2xl font-bold mb-2">Game Deleted</h2>
                <p class="text-slate-400 mb-8">This game has been deleted by the Game Master.</p>

                <a href="{{ route('player.join') }}"
                   class="inline-block px-6 py-3 bg-blue-600 hover:bg-blue-700 rounded-lg font-semibold transition">
                    Join Another Game
                </a>
            </div>

        @elseif(($gameStatus === 'draft' || $gameStatus === 'ready') && $wasInActiveGame)
            <!-- Game was reset or ended early -->
            <div class="text-center py-12">
                <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-yellow-900/30 flex items-center justify-center">
                    <svg class="w-10 h-10 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>

                <h2 class="text-2xl font-bold mb-2">Game Has Ended</h2>
                <p class="text-slate-400 mb-8">The Game Master has ended or reset this game.</p>

                <div class="space-y-3">
                    <a href="{{ route('player.join') }}"
                       class="block px-6 py-3 bg-blue-600 hover:bg-blue-700 rounded-lg font-semibold transition">
                        Join Another Game
                    </a>
                    <button wire:click="$refresh"
                            class="block w-full px-6 py-3 bg-slate-700 hover:bg-slate-600 rounded-lg font-semibold transition">
                        Check If Game Restarted
                    </button>
                </div>
            </div>

        @elseif($gameStatus === 'draft' || $gameStatus === 'ready')
            <!-- Lobby - Waiting for game to start -->
            <div class="text-center py-12">
                <h1 class="text-4xl font-title mb-2">
                    <span class="inline-flex items-baseline"><span class="text-white">FRIC</span><span class="text-red-500 ml-[0.04em]">TION</span></span>
                </h1>
                <p class="text-slate-400 mb-8">You're in!</p>

                <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-slate-800 flex items-center justify-center">
                    <svg class="w-10 h-10 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>

                <h2 class="text-xl font-bold mb-2">Waiting for game to start...</h2>
                <p class="text-slate-400">The Game Master will begin shortly</p>

                <!-- Show other players -->
                <div class="mt-8 bg-slate-800 rounded-xl p-4">
                    <p class="text-slate-400 text-sm mb-3">Players joined:</p>
                    <div class="flex flex-wrap justify-center gap-2">
                        @foreach($activePlayers as $p)
                            <div class="flex items-center gap-2 px-3 py-1.5 rounded-full text-sm
                                        {{ $p->id === $player->id ? 'bg-blue-600' : 'bg-slate-700' }}">
                                <div class="w-2 h-2 rounded-full" style="background-color: {{ $p->color }}"></div>
                                <span>{{ $p->name }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

        @elseif($gameStatus === 'playing' && $currentRound)
            @if($showRules)
                <!-- Rules display -->
                <div class="py-6">
                    <h1 class="text-2xl font-bold mb-6 text-center">How to Play <span class="text-white">TEN</span><span class="text-red-500">SION</span></h1>

                    <div class="space-y-4 text-sm">
                        <div class="bg-slate-800 rounded-xl p-4">
                            <h3 class="font-semibold mb-2">The Goal</h3>
                            <p class="text-slate-300">Name items from a Top 10 list. Items closer to #10 score more points!</p>
                        </div>

                        <div class="bg-slate-800 rounded-xl p-4">
                            <h3 class="font-semibold mb-2">Scoring</h3>
                            <div class="text-slate-300 space-y-1">
                                <p><span class="text-green-400">#1-10:</span> Earn points equal to position</p>
                                <p><span class="text-red-400">#11-15:</span> FRICTION! Lose 5 points</p>
                            </div>
                        </div>

                        <div class="bg-slate-800 rounded-xl p-4">
                            <h3 class="font-semibold mb-2">2x Double</h3>
                            <p class="text-slate-300">Use once per game to double your points (or penalty!)</p>
                        </div>
                    </div>

                    <p class="text-center text-slate-500 mt-6">Game starting soon...</p>
                </div>

            @elseif($roundStatus === 'intro')
                <!-- Round intro - show category -->
                <div class="text-center py-12">
                    <p class="text-slate-400 text-sm mb-4">Round {{ $currentRoundNumber }} of {{ $game->total_rounds }}</p>
                    <h1 class="text-3xl font-bold mb-4">{{ $currentRound->category->title }}</h1>
                    @if($currentRound->category->description)
                        <p class="text-slate-400 mt-2">{{ $currentRound->category->description }}</p>
                    @endif
                    <p class="text-blue-400 mt-8 animate-pulse">Get ready...</p>
                </div>

            @elseif($roundStatus === 'collecting')
                <!-- Collecting answers -->
                <div class="space-y-6">
                    <div class="text-center">
                        <p class="text-slate-400 text-sm">Round {{ $currentRoundNumber }} of {{ $game->total_rounds }}</p>
                        <h1 class="text-2xl font-bold mt-1">{{ $currentRound->category->title }}</h1>
                    </div>

                    <!-- Turn-based Timer Display -->
                    <template x-if="!allAnswered">
                        <!-- Countdown Timer (first player) -->
                        <div x-show="timerMode === 'countdown' && timerRunning" x-cloak>
                            <div class="bg-yellow-900/30 border border-yellow-600 rounded-xl p-4 text-center">
                                <p class="text-yellow-400 text-sm mb-2">Think about your answer!</p>
                                <p class="text-5xl font-mono font-black" :class="timerSeconds <= 5 ? 'text-red-400' : 'text-yellow-400'" x-text="timerSeconds"></p>
                            </div>
                        </div>
                    </template>

                    <template x-if="!allAnswered">
                        <!-- Countup Timer - It's My Turn -->
                        <div x-show="timerMode === 'countup' && isMyTurn" x-cloak>
                            <div class="bg-blue-900/50 border-2 border-blue-500 rounded-xl p-4 text-center animate-pulse">
                                <p class="text-blue-400 text-xl font-bold mb-2">YOUR TURN!</p>
                                <p class="text-3xl font-mono font-bold" :class="timerSeconds >= 20 ? 'text-red-400' : timerSeconds >= 10 ? 'text-yellow-400' : 'text-white'" x-text="timerSeconds + 's'"></p>
                            </div>
                        </div>
                    </template>

                    <template x-if="!allAnswered">
                        <!-- Countup Timer - Waiting for another player -->
                        <div x-show="timerMode === 'countup' && !isMyTurn" x-cloak>
                            <div class="bg-slate-800 border border-slate-600 rounded-xl p-4 text-center">
                                <p class="text-slate-400 text-sm mb-1">Waiting for</p>
                                <p class="text-xl font-bold text-white">{{ $currentTurnPlayerName ?? 'Next Player' }}</p>
                            </div>
                        </div>
                    </template>

                    <!-- Turn order -->
                    <div class="bg-slate-800 rounded-xl p-4">
                        <p class="text-slate-400 text-sm mb-3">Answer Order</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach($turnOrder as $index => $p)
                                @php $isCurrent = $p->id === $currentTurnPlayerId; @endphp
                                <div class="flex items-center gap-2 px-3 py-1.5 rounded-full text-sm
                                            {{ $p->id === $player->id ? 'bg-blue-600' : ($isCurrent ? 'bg-green-600 ring-2 ring-green-400' : 'bg-slate-700') }}">
                                    <span class="font-medium">{{ $index + 1 }}.</span>
                                    <span>{{ $p->name }}</span>
                                    @if($isCurrent)
                                        <span class="text-xs">⬅</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        @if($myTurnPosition)
                            <p class="text-slate-400 text-sm mt-3">
                                You are <span class="text-white font-bold">#{{ $myTurnPosition }}</span> in the order
                            </p>
                        @endif
                    </div>

                    <!-- Answer input or submitted status -->
                    @if($hasSubmitted)
                        <div class="bg-green-900/30 border border-green-600 rounded-xl p-6 text-center">
                            <svg class="w-12 h-12 mx-auto mb-3 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            <p class="text-green-400 font-medium mb-1">Answer Submitted!</p>
                            <p class="text-xl font-bold">{{ $submittedAnswer }}</p>
                        </div>
                    @else
                        <div class="space-y-4">
                            <input type="text"
                                   wire:model="answerText"
                                   wire:keydown.enter="submitAnswer"
                                   placeholder="Type your answer..."
                                   autofocus
                                   class="w-full bg-slate-800 border-2 border-slate-600 rounded-xl px-4 py-4 text-xl text-center
                                          focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50
                                          placeholder-slate-500">

                            @if($player->canUseDouble())
                                <label class="flex items-center justify-center gap-3 cursor-pointer">
                                    <input type="checkbox"
                                           wire:model="useDouble"
                                           class="w-5 h-5 rounded bg-slate-700 border-slate-600 text-yellow-500 focus:ring-yellow-500">
                                    <span class="text-yellow-400 font-medium">Use 2x Double (one-time use)</span>
                                </label>
                            @endif

                            <button wire:click="submitAnswer"
                                    @disabled(empty(trim($answerText)))
                                    class="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed
                                           py-4 rounded-xl font-bold text-xl transition">
                                Submit Answer
                            </button>
                        </div>
                    @endif
                </div>

            @elseif(in_array($roundStatus, ['revealing', 'friction']))
                <!-- Revealing answers -->
                <div class="text-center py-12">
                    <p class="text-slate-400 text-sm mb-4">Round {{ $currentRoundNumber }}</p>
                    <h1 class="text-2xl font-bold mb-6">{{ $currentRound->category->title }}</h1>

                    <div class="bg-slate-800 rounded-xl p-6">
                        <p class="text-slate-400 mb-2">Answers are being revealed...</p>
                        @if($submittedAnswer)
                            <p class="mt-4">Your answer: <span class="font-bold text-white">{{ $submittedAnswer }}</span></p>
                        @else
                            <p class="mt-4 text-slate-500">You didn't submit an answer</p>
                        @endif
                    </div>

                    <p class="text-slate-500 mt-6">Watch the presentation screen!</p>
                </div>

            @elseif($roundStatus === 'scoring')
                <!-- Scoring -->
                <div class="text-center py-12">
                    <p class="text-slate-400 text-sm mb-4">Round {{ $currentRoundNumber }} Complete</p>
                    <h2 class="text-2xl font-bold mb-6">Scores</h2>

                    <div class="bg-slate-800 rounded-xl p-4">
                        <p class="text-4xl font-black text-blue-400 mb-2">{{ $player->total_score }}</p>
                        <p class="text-slate-400">Your score</p>
                    </div>

                    <p class="text-slate-500 mt-6">Next round starting soon...</p>
                </div>
            @endif

        @elseif($gameStatus === 'completed')
            <!-- Game over -->
            <div class="text-center py-12">
                <h2 class="text-3xl font-bold mb-4">Game Over!</h2>
                <p class="text-xl mb-2">Your final score:</p>
                <p class="text-5xl font-black text-blue-400">{{ $player->total_score }}</p>

                <div class="mt-8 space-y-2">
                    <p class="text-slate-400">Final Standings</p>
                    @foreach($game->players->sortByDesc('total_score')->values() as $index => $p)
                        <div class="flex items-center justify-between bg-slate-800 rounded-lg px-4 py-2
                                    {{ $p->id === $player->id ? 'ring-2 ring-blue-500' : '' }}">
                            <div class="flex items-center gap-3">
                                <span class="font-bold text-lg">{{ $index + 1 }}.</span>
                                <div class="w-3 h-3 rounded-full" style="background-color: {{ $p->color }}"></div>
                                <span>{{ $p->name }}</span>
                            </div>
                            <span class="font-bold">{{ $p->total_score }}</span>
                        </div>
                    @endforeach
                </div>

                <a href="{{ route('player.join') }}"
                   class="inline-block mt-8 text-blue-400 hover:text-blue-300">
                    Join another game &rarr;
                </a>
            </div>
        @endif
    </main>
</div>
