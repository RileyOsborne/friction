<?php

use App\Models\Game;
use App\Models\Player;
use App\Models\PlayerAnswer;
use App\Services\PlayerConnectionService;
use App\Services\GameStateMachine;
use App\Events\PlayerAnswerSubmitted;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;

new #[Layout('components.layouts.player')] class extends Component {
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

    // Track the active round to prevent clearing input during polls
    public ?string $activeRoundId = null;

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

        if ($currentRound = $this->game->currentRoundModel()) {
            $this->activeRoundId = $currentRound->id;
        }

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
                $this->submittedAnswer = $existingAnswer->input_text ?? ($existingAnswer->answer?->text ?? 'Not on list');
                $this->activeRoundId = $currentRound->id;
            } else {
                $this->hasSubmitted = false;
                $this->submittedAnswer = null;
                
                // Only clear the input if we just moved to a new round
                if ($this->activeRoundId !== $currentRound->id) {
                    $this->answerText = '';
                    $this->activeRoundId = $currentRound->id;
                }
            }
        } else {
            $this->hasSubmitted = false;
            $this->submittedAnswer = null;
            $this->activeRoundId = null;
        }
    }

    public function submitAnswer(): void
    {
        if ($this->hasSubmitted || empty(trim($this->answerText))) {
            return;
        }

        \Illuminate\Support\Facades\Log::info('Player submitting answer', [
            'player_id' => $this->player->id,
            'answer' => $this->answerText,
            'game_id' => $this->game->id
        ]);

        // Save directly to DB via state machine
        $this->getStateMachine()->submitPlayerAnswer(
            $this->player->id,
            trim($this->answerText),
            $this->useDouble && $this->player->canUseDouble()
        );

        // Broadcast the answer submission to GM (now as a signal to refresh)
        event(new PlayerAnswerSubmitted(
            $this->game,
            $this->player,
            trim($this->answerText),
            $this->useDouble && $this->player->canUseDouble()
        ));

        $this->submittedAnswer = trim($this->answerText);
        $this->hasSubmitted = true;
        $this->answerText = '';
        
        // Refresh local state immediately
        $this->loadCurrentState();
    }

    private function getStateMachine(): GameStateMachine
    {
        return new GameStateMachine($this->game);
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
<div>
    @php
        /**
         * Helper: Calculate contrast color for text (black or white)
         * PHP implementation for use inside Blade @php blocks
         */
        if (!function_exists('getContrastColor')) {
            function getContrastColor($hexcolor) {
                if (!$hexcolor) return 'white';
                $hexcolor = str_replace('#', '', $hexcolor);
                if (strlen($hexcolor) === 3) {
                    $hexcolor = $hexcolor[0] . $hexcolor[0] . $hexcolor[1] . $hexcolor[1] . $hexcolor[2] . $hexcolor[2];
                }
                $r = hexdec(substr($hexcolor, 0, 2));
                $g = hexdec(substr($hexcolor, 2, 2));
                $b = hexdec(substr($hexcolor, 4, 2));
                $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
                return ($yiq >= 128) ? 'black' : 'white';
            }
        }
    @endphp

<div class="min-h-screen flex flex-col text-white"

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
     }">
    <!-- Top Persistent Header -->
    <header class="bg-slate-900 border-b border-white/5 px-6 py-4 sticky top-0 z-50 shadow-2xl">
        <div class="max-w-4xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-4 w-1/3">
                <div class="w-5 h-5 rounded-full shadow-inner border-2 border-white/10" style="background-color: {{ $player->color }}"></div>
                <span class="text-xl font-black tracking-tight truncate">{{ $player->name }}</span>
            </div>
            
            <!-- Logo centered -->
            <div class="flex justify-center flex-1">
                <span class="text-2xl font-title tracking-tighter">
                    <span class="inline-flex items-baseline"><span class="text-white">FRIC</span><span class="text-red-500 ml-[0.04em]">TION</span></span>
                </span>
            </div>

            <div class="flex items-center justify-end gap-6 w-1/3">
                <div class="text-right">
                    <div class="text-[10px] uppercase font-black text-slate-500 tracking-widest leading-none mb-1">Score</div>
                    <div class="text-xl font-black leading-none">{{ $player->total_score }}</div>
                </div>
                @if($player->canUseDouble())
                    <div class="bg-yellow-500 text-black px-2.5 py-1 rounded-lg text-xs font-black uppercase tracking-tighter shadow-lg shadow-yellow-500/20">
                        2x
                    </div>
                @endif
            </div>
        </div>
    </header>

    <main class="flex-1 w-full max-w-4xl mx-auto p-6 md:p-12 flex flex-col">
        @if($player->isRemoved())
            <!-- Player was removed from the game -->
            <div class="flex-1 flex flex-col items-center justify-center text-center py-12">
                <div class="w-32 h-32 mb-8 rounded-full bg-red-900/20 flex items-center justify-center border-4 border-red-500/20">
                    <svg class="w-16 h-16 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </div>

                <h2 class="text-4xl font-black mb-4 text-red-500 uppercase tracking-tight">You've Been Removed</h2>
                <p class="text-xl text-slate-400 mb-12 max-w-md mx-auto">The Game Master removed you from this session.</p>

                <a href="{{ route('player.join') }}"
                   class="w-full max-w-xs px-8 py-5 bg-slate-800 hover:bg-slate-700 rounded-2xl font-black text-xl uppercase tracking-wider transition shadow-xl border-b-4 border-slate-900">
                    Join Another Game
                </a>
            </div>

        @elseif($gameDeleted)
            <!-- Game was deleted -->
            <div class="flex-1 flex flex-col items-center justify-center text-center py-12">
                <div class="w-32 h-32 mb-8 rounded-full bg-slate-800 flex items-center justify-center border-4 border-white/5">
                    <svg class="w-16 h-16 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </div>

                <h2 class="text-4xl font-black mb-4 uppercase tracking-tight">Game Deleted</h2>
                <p class="text-xl text-slate-400 mb-12 max-w-md mx-auto">This game has been deleted by the host.</p>

                <a href="{{ route('player.join') }}"
                   class="w-full max-w-xs px-8 py-5 bg-blue-600 hover:bg-blue-700 rounded-2xl font-black text-xl uppercase tracking-wider transition shadow-xl border-b-4 border-blue-800">
                    Join Another Game
                </a>
            </div>

        @elseif(($gameStatus === 'draft' || $gameStatus === 'ready') && $wasInActiveGame)
            <!-- Game was reset or ended early -->
            <div class="flex-1 flex flex-col items-center justify-center text-center py-12">
                <div class="w-32 h-32 mb-8 rounded-full bg-yellow-900/20 flex items-center justify-center border-4 border-yellow-500/20">
                    <svg class="w-16 h-16 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>

                <h2 class="text-4xl font-black mb-4 uppercase tracking-tight">Game Ended</h2>
                <p class="text-xl text-slate-400 mb-12 max-w-md mx-auto">The Game Master has ended or reset this game.</p>

                <div class="w-full max-w-xs space-y-4">
                    <a href="{{ route('player.join') }}"
                       class="block px-8 py-5 bg-blue-600 hover:bg-blue-700 rounded-2xl font-black text-xl uppercase tracking-wider transition shadow-xl border-b-4 border-blue-800 text-center">
                        Join Another Game
                    </a>
                    <button wire:click="$refresh"
                            class="block w-full px-8 py-5 bg-slate-800 hover:bg-slate-700 rounded-2xl font-black text-xl uppercase tracking-wider transition shadow-xl border-b-4 border-slate-900">
                        Try Reconnect
                    </button>
                </div>
            </div>

        @elseif($gameStatus === 'draft' || $gameStatus === 'ready')
            <!-- Lobby - Waiting for game to start -->
            <div class="flex-1 flex flex-col items-center justify-center text-center py-12">
                <div class="mb-12">
                    <h1 class="text-6xl font-title mb-4 tracking-tighter">
                        <span class="inline-flex items-baseline"><span class="text-white">FRIC</span><span class="text-red-500 ml-[0.04em]">TION</span></span>
                    </h1>
                    <p class="text-2xl text-slate-500 uppercase tracking-[0.3em] font-light">You're in!</p>
                </div>

                <div class="w-32 h-32 mb-10 rounded-full bg-green-500/10 border-4 border-green-500/20 flex items-center justify-center animate-pulse">
                    <svg class="w-16 h-16 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>

                <h2 class="text-3xl font-black mb-2 uppercase tracking-tight">Waiting for Host</h2>
                <p class="text-xl text-slate-400">The Game Master will begin shortly</p>

                <!-- Show other players -->
                <div class="mt-16 w-full max-w-2xl bg-slate-900/50 rounded-[2rem] p-8 border border-white/5 shadow-2xl">
                    <p class="text-slate-500 text-sm font-black uppercase tracking-widest mb-6">Players joined</p>
                    <div class="flex flex-wrap justify-center gap-3">
                        @foreach($activePlayers as $p)
                            <div class="flex items-center gap-3 px-5 py-2.5 rounded-xl text-lg font-bold transition
                                        {{ $p->id === $player->id ? 'bg-blue-600 shadow-lg shadow-blue-500/20' : 'bg-slate-800' }}">
                                <div class="w-3 h-3 rounded-full shadow-inner border border-white/10" style="background-color: {{ $p->color }}"></div>
                                <span>{{ $p->name }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

        @elseif($gameStatus === 'playing' && $currentRound)
            @if($showRules)
                <!-- Rules display -->
                <div class="flex-1 flex flex-col justify-center py-6">
                    <h1 class="text-4xl font-black mb-10 text-center uppercase tracking-tight">How to Play <span class="inline-flex items-baseline"><span class="text-white">FRIC</span><span class="text-red-500 ml-[0.04em]">TION</span></span></h1>

                    <div class="space-y-6">
                        <div class="bg-slate-900/50 border border-white/5 rounded-3xl p-8 shadow-xl">
                            <h3 class="text-xl font-black uppercase tracking-widest text-blue-400 mb-3">The Goal</h3>
                            <p class="text-xl text-slate-300 leading-relaxed">Name items from a Top 10 list. Items closer to #10 score more points!</p>
                        </div>

                        <div class="bg-slate-900/50 border border-white/5 rounded-3xl p-8 shadow-xl">
                            <h3 class="text-xl font-black uppercase tracking-widest text-green-400 mb-3">Scoring</h3>
                            <div class="text-xl text-slate-300 space-y-3">
                                <div class="flex items-center gap-4">
                                    <span class="bg-green-500/20 text-green-400 px-3 py-1 rounded-lg font-black tracking-tighter">#1-10</span>
                                    <span>Points equal to position</span>
                                </div>
                                <div class="flex items-center gap-4">
                                    <span class="bg-red-500/20 text-red-400 px-3 py-1 rounded-lg font-black tracking-tighter">#11-15</span>
                                    <span>FRICTION! Lose 5 points</span>
                                </div>
                            </div>
                        </div>

                        <div class="bg-slate-900/50 border border-white/5 rounded-3xl p-8 shadow-xl">
                            <h3 class="text-xl font-black uppercase tracking-widest text-yellow-500 mb-3">2x Double</h3>
                            <p class="text-xl text-slate-300 leading-relaxed">Use once per game to double your points (or penalty!)</p>
                        </div>
                    </div>

                    <p class="text-center text-slate-500 text-xl font-black italic mt-12 animate-pulse uppercase tracking-widest">Get ready!</p>
                </div>

            @elseif($roundStatus === 'intro')
                <!-- Round intro - show category -->
                <div class="flex-1 flex flex-col items-center justify-center text-center py-12">
                    <div class="mb-12">
                        <div class="inline-block bg-slate-800 px-6 py-2 rounded-2xl text-xl font-black text-slate-400 uppercase tracking-[0.3em] mb-6">
                            Round {{ $currentRoundNumber }} <span class="mx-2 text-slate-600">/</span> {{ $game->total_rounds }}
                        </div>
                        <h1 class="text-5xl md:text-6xl font-black leading-tight tracking-tight mb-6">{{ $currentRound->category->title }}</h1>
                        @if($currentRound->category->description)
                            <p class="text-2xl text-slate-400 italic max-w-md mx-auto">{{ $currentRound->category->description }}</p>
                        @endif
                    </div>
                    
                    <div class="w-full h-2 bg-slate-800 rounded-full overflow-hidden max-w-sm mb-12">
                        <div class="h-full bg-blue-500 animate-pulse" style="width: 100%"></div>
                    </div>

                    <p class="text-3xl text-blue-400 font-black uppercase tracking-[0.2em] animate-pulse">Get ready to answer!</p>
                </div>

            @elseif($roundStatus === 'collecting')
                <!-- Collecting answers -->
                <div class="flex-1 flex flex-col gap-4 md:gap-6 h-full">
                    <div class="text-center">
                        <div class="inline-block bg-slate-900/50 border border-white/5 px-3 py-1 md:px-4 md:py-1.5 rounded-xl text-[10px] md:text-sm font-black text-slate-500 uppercase tracking-widest mb-1 md:mb-4">
                            Round {{ $currentRoundNumber }} <span class="mx-1 opacity-30">/</span> {{ $game->total_rounds }}
                        </div>
                        <h1 class="text-2xl md:text-4xl font-black leading-tight tracking-tight">{{ $currentRound->category->title }}</h1>
                    </div>

                    <!-- Turn-based Timer Display -->
                    <template x-if="!allAnswered">
                        <div class="w-full">
                            <!-- Countdown Timer (first player) -->
                            <div x-show="timerMode === 'countdown' && timerRunning" x-cloak 
                                 class="bg-yellow-900/20 border-2 border-yellow-600 rounded-2xl md:rounded-3xl p-4 md:p-8 text-center shadow-2xl">
                                <p class="text-yellow-500 text-xs md:text-lg font-black uppercase tracking-widest mb-1 md:mb-4">Thinking Time!</p>
                                <div class="flex items-center justify-center gap-2 md:gap-4">
                                    <span class="text-5xl md:text-8xl font-mono font-black" :class="timerSeconds <= 5 ? 'text-red-500 animate-pulse' : 'text-yellow-400'" x-text="timerSeconds"></span>
                                    <span class="text-2xl md:text-4xl text-yellow-600 font-black">s</span>
                                </div>
                            </div>

                            <!-- Countup Timer - It's My Turn -->
                            <div x-show="timerMode === 'countup' && isMyTurn" x-cloak
                                 class="bg-blue-900/30 border-2 md:border-4 border-blue-500 rounded-2xl md:rounded-[2.5rem] p-4 md:p-10 text-center animate-pulse shadow-2xl">
                                <p class="text-blue-400 text-lg md:text-3xl font-black uppercase tracking-widest mb-2 md:mb-6">Your Turn!</p>
                                <div class="flex items-center justify-center gap-2 md:gap-4">
                                    <span class="text-6xl md:text-9xl font-mono font-black" :class="timerSeconds >= 20 ? 'text-red-500' : timerSeconds >= 10 ? 'text-yellow-400' : 'text-white'" x-text="timerSeconds"></span>
                                    <span class="text-2xl md:text-4xl text-slate-500 font-black">s</span>
                                </div>
                            </div>

                            <!-- Countup Timer - Waiting for another player -->
                            <div x-show="timerMode === 'countup' && !isMyTurn" x-cloak
                                 class="bg-slate-900/50 border border-white/5 rounded-2xl md:rounded-3xl p-4 md:p-8 text-center">
                                <p class="text-slate-500 text-[10px] md:text-sm font-black uppercase tracking-widest mb-1">Waiting for</p>
                                <p class="text-xl md:text-3xl font-black text-white uppercase tracking-tight">{{ $currentTurnPlayerName ?? 'Next Player' }}</p>
                            </div>
                        </div>
                    </template>

                    <!-- Turn order -->
                    <div class="bg-slate-900/50 border border-white/5 rounded-2xl md:rounded-[2.5rem] p-4 md:p-10 shadow-xl">
                        <p class="text-slate-500 text-[10px] md:text-sm font-black uppercase tracking-widest mb-3 md:mb-10 text-center">Answer Order</p>
                        <div class="flex flex-wrap justify-center gap-2 md:gap-4">
                            @foreach($turnOrder as $index => $p)
                                @php $isCurrent = $p->id === $currentTurnPlayerId; @endphp
                                <div class="flex items-center gap-2 md:gap-4 px-3 py-1.5 md:px-6 md:py-3.5 rounded-xl md:rounded-2xl text-sm md:text-2xl font-black transition-all duration-500
                                            {{ $p->id === $player->id ? 'bg-blue-600 shadow-lg md:shadow-xl shadow-blue-500/20 ring-2 md:ring-4 ring-white/20' : ($isCurrent ? 'bg-green-600 ring-2 md:ring-[6px] ring-green-400 shadow-lg md:shadow-2xl shadow-green-500/30' : 'bg-slate-800 opacity-40 grayscale-[0.5]') }}">
                                    <span class="opacity-30">{{ $index + 1 }}.</span>
                                    <span>{{ $p->name }}</span>
                                    @if($isCurrent)
                                        <span class="text-xs md:text-3xl ml-1 md:ml-2">⬅</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        @if($myTurnPosition)
                            <p class="text-slate-400 text-xs md:text-lg font-black mt-4 md:mt-12 text-center uppercase tracking-widest">
                                You are <span class="text-white">#{{ $myTurnPosition }}</span> in the rotation
                            </p>
                        @endif
                    </div>

                    <!-- Answer input or submitted status -->
                    <div class="mt-4 md:mt-6">
                        @if($hasSubmitted)
                            <div class="bg-green-500/10 border-2 border-green-500/30 rounded-2xl md:rounded-[2.5rem] p-6 md:p-10 text-center shadow-2xl">
                                <div class="w-12 h-12 md:w-20 md:h-20 mx-auto mb-3 md:mb-6 rounded-full bg-green-500 flex items-center justify-center shadow-lg shadow-green-500/20">
                                    <svg class="w-6 h-6 md:w-10 md:h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </div>
                                <p class="text-green-400 text-[10px] md:text-sm font-black uppercase tracking-widest mb-1 md:mb-2">Answer Submitted!</p>
                                <p class="text-2xl md:text-4xl font-black tracking-tight text-white leading-tight">"{{ $submittedAnswer }}"</p>
                            </div>
                        @else
                            <div class="space-y-3 md:space-y-6">
                                <div class="relative group">
                                    <input type="text"
                                           wire:model="answerText"
                                           wire:keydown.enter="submitAnswer"
                                           placeholder="TYPE YOUR ANSWER..."
                                           autofocus
                                           class="w-full bg-slate-900 border-2 md:border-4 border-slate-800 rounded-2xl md:rounded-[2rem] px-4 py-5 md:px-8 md:py-10 text-xl md:text-4xl font-black text-center uppercase tracking-tight
                                                  focus:border-blue-600 focus:outline-none focus:ring-0 transition-all shadow-inner
                                                  placeholder-slate-700">
                                </div>

                                @if($player->canUseDouble())
                                    <label class="flex items-center justify-center gap-3 md:gap-4 cursor-pointer group bg-slate-900/50 p-3 md:p-6 rounded-xl md:rounded-[1.5rem] border-2 border-transparent hover:border-yellow-500/30 transition">
                                        <input type="checkbox"
                                               wire:model="useDouble"
                                               class="w-6 h-6 md:w-8 md:h-8 rounded bg-slate-800 border-slate-700 text-yellow-500 focus:ring-offset-0 focus:ring-yellow-500 transition cursor-pointer">
                                        <div class="flex flex-col">
                                            <span class="text-yellow-500 font-black text-sm md:text-xl uppercase tracking-tighter leading-none">Use 2x Double</span>
                                            <span class="text-slate-500 text-[10px] md:text-sm font-bold uppercase tracking-widest">Single Use Only</span>
                                        </div>
                                    </label>
                                @endif

                                <button wire:click="submitAnswer"
                                        @disabled(empty(trim($answerText)))
                                        class="w-full bg-blue-600 hover:bg-blue-500 disabled:opacity-30 disabled:grayscale disabled:cursor-not-allowed
                                               py-5 md:py-8 rounded-2xl md:rounded-[2rem] font-black text-xl md:text-3xl uppercase tracking-widest transition-all shadow-xl border-b-4 md:border-b-8 border-blue-800 active:translate-y-1 active:border-b-0">
                                    Submit Answer
                                </button>
                            </div>
                        @endif
                    </div>
                </div>

            @elseif(in_array($roundStatus, ['revealing', 'friction']))
                <!-- Revealing answers -->
                <div class="flex-1 flex flex-col items-center justify-center text-center py-12">
                    <div class="mb-12">
                        <div class="inline-block bg-slate-900/50 border border-white/5 px-4 py-1.5 rounded-xl text-sm font-black text-slate-500 uppercase tracking-widest mb-4">
                            Round {{ $currentRoundNumber }}
                        </div>
                        <h1 class="text-4xl font-black leading-tight tracking-tight mb-4">{{ $currentRound->category->title }}</h1>
                    </div>

                    <div class="w-full max-w-2xl bg-slate-900/50 border border-white/5 rounded-[3rem] p-12 shadow-2xl relative overflow-hidden">
                        <div class="absolute inset-0 bg-gradient-to-br from-blue-500/5 to-transparent pointer-events-none"></div>
                        <p class="text-slate-500 text-lg font-black uppercase tracking-widest mb-8">Answers being revealed</p>
                        
                        @if($submittedAnswer)
                            <div class="text-sm font-black text-slate-500 uppercase tracking-widest mb-2">Your answer</div>
                            <div class="text-5xl font-black text-white leading-tight tracking-tight break-words">"{{ $submittedAnswer }}"</div>
                        @else
                            <p class="text-2xl text-slate-600 italic">No answer submitted</p>
                        @endif
                    </div>

                    <p class="text-2xl text-white/20 font-black uppercase tracking-[0.3em] mt-16 animate-pulse">Watch the presentation!</p>
                </div>

            @elseif($roundStatus === 'scoring')
                <!-- Scoring -->
                <div class="flex-1 flex flex-col items-center justify-center text-center py-12">
                    <div class="inline-block bg-slate-900/50 border border-white/5 px-6 py-2 rounded-2xl text-xl font-black text-slate-400 uppercase tracking-[0.3em] mb-12">
                        Round Complete
                    </div>

                    <div class="bg-slate-800 border-b-8 border-slate-900 rounded-[3rem] p-12 shadow-2xl w-full max-w-sm flex flex-col items-center">
                        <div class="text-xl font-black mb-6 px-6 py-2 rounded-xl shadow-lg" style="background-color: {{ $player->color }}; color: {{ getContrastColor($player->color) }}">
                            {{ $player->name }}
                        </div>
                        <div class="text-[8rem] md:text-[10rem] font-black tracking-tighter leading-none mb-4">{{ $player->total_score }}</div>
                        <div class="text-slate-500 text-xl font-bold uppercase tracking-[0.2em]">Current Score</div>
                    </div>

                    <p class="text-xl text-slate-500 font-black italic mt-16 animate-pulse uppercase tracking-widest">Next round starting soon...</p>
                </div>
            @endif

        @elseif($gameStatus === 'completed')
            <!-- Game over -->
            <div class="flex-1 flex flex-col items-center justify-center text-center py-12">
                <h2 class="text-5xl font-black mb-12 text-white/30 uppercase tracking-[0.4em]">Game Over</h2>
                
                <div class="bg-slate-800 border-b-8 border-slate-900 rounded-[4rem] p-16 shadow-2xl w-full max-w-md relative overflow-hidden flex flex-col items-center mb-16">
                    <div class="absolute top-0 right-0 p-6 text-6xl opacity-20">🏆</div>
                    <div class="text-2xl font-black mb-8 px-8 py-3 rounded-2xl shadow-xl" style="background-color: {{ $player->color }}; color: {{ getContrastColor($player->color) }}">
                        {{ $player->name }}
                    </div>
                    <div class="text-[10rem] font-black tracking-tighter leading-none mb-4">{{ $player->total_score }}</div>
                    <div class="text-2xl font-black text-slate-500 uppercase tracking-[0.2em]">Final Standing</div>
                </div>

                <div class="w-full max-w-2xl space-y-4">
                    <p class="text-slate-500 text-sm font-black uppercase tracking-widest mb-4">Leaderboard</p>
                    @foreach($game->players->sortByDesc('total_score')->values() as $index => $p)
                        @php $contrast = getContrastColor($p->color); @endphp
                        <div class="flex items-center justify-between bg-slate-900/80 rounded-2xl px-8 py-5 border border-white/5 shadow-lg
                                    {{ $p->id === $player->id ? 'ring-4 ring-blue-500 shadow-blue-500/20 scale-105 z-10' : 'opacity-60' }}">
                            <div class="flex items-center gap-6">
                                <span class="font-black text-3xl text-slate-500">{{ $index + 1 }}.</span>
                                <div class="w-6 h-6 rounded-full shadow-inner border-2 border-white/10" style="background-color: {{ $p->color }}"></div>
                                <span class="text-2xl font-black tracking-tight" style="color: {{ $p->color }}">{{ $p->name }}</span>
                            </div>
                            <span class="text-4xl font-black">{{ $p->total_score }}</span>
                        </div>
                    @endforeach
                </div>

                <a href="{{ route('player.join') }}"
                   class="mt-20 w-full max-w-xs px-8 py-6 bg-blue-600 hover:bg-blue-500 rounded-2xl font-black text-2xl uppercase tracking-widest transition-all shadow-xl border-b-8 border-blue-800 active:translate-y-1 active:border-b-0 text-center">
                    Play Again
                </a>
            </div>
        @endif
    </main>
</div>
