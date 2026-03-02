<x-layouts.presentation title="Presentation">
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

    <div id="presentation-container" class="min-h-screen flex flex-col items-center justify-center py-16 px-4">
        <!-- Rules Slide -->
        <div id="slide-rules" class="hidden w-full max-w-5xl mx-auto px-8">
            <div class="animate-fade-in">
                <h1 class="text-5xl font-bold mb-8 text-center">How to Play <span class="text-6xl font-title"><span class="text-white">FRIC</span><span class="text-red-500">TION</span></span></h1>

                <!-- The Goal -->
                <div class="bg-slate-800 rounded-xl p-6 border border-slate-700 mb-8">
                    <h2 class="text-2xl font-semibold mb-4">The Goal</h2>
                    <p class="text-slate-300 text-xl">
                        Players try to name items from a <strong>Top {{ $game->top_answers_count }}</strong> list. The twist? You want to name items
                        <strong class="text-green-400">closer to #{{ $game->top_answers_count }}</strong> than #1, because higher positions score more points!
                    </p>
                </div>

                <!-- Scoring Rules -->
                <div class="bg-slate-800 rounded-xl p-6 border border-slate-700 mb-8">
                    <h2 class="text-2xl font-semibold mb-4">Scoring</h2>
                    <x-rules-display :game="$game" />
                </div>

                <!-- Example -->
                <div class="bg-slate-800 rounded-xl p-6 border border-blue-700/50">
                    <h2 class="text-2xl font-semibold mb-4 text-blue-400">Example Round</h2>
                    <p class="text-slate-300 text-lg mb-4">
                        <strong>Category:</strong> "Top {{ $game->top_answers_count }} Countries by Aerospace Parts Development"
                    </p>
                    <div class="space-y-2 text-slate-400 text-lg">
                        <p>Player A guesses "United States" &rarr; #1 = <span class="text-green-400">+1 point</span></p>
                        <p>Player B guesses "France" &rarr; #5 = <span class="text-green-400">+5 points</span></p>
                        <p>Player C guesses "South Korea" &rarr; #{{ $game->top_answers_count }} = <span class="text-green-400">+{{ $game->top_answers_count }} points!</span></p>
                        <p>Player D guesses "Brazil" &rarr; #{{ $game->top_answers_count + 2 }} (Friction!) = <span class="text-red-400">{{ $game->friction_penalty }} points</span></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Intro Slide -->
        <div id="slide-intro" class="hidden w-full max-w-5xl mx-auto px-8 text-center">
            <div class="animate-scale-in">
                <p id="round-number" class="text-5xl font-black text-white/30 uppercase tracking-[0.6em] mb-8">Round 1</p>
                <h1 id="category-title" class="text-8xl md:text-9xl font-black mb-10 leading-tight tracking-tight">Category Title</h1>
                <p id="category-description" class="text-4xl text-slate-400 max-w-3xl mx-auto leading-relaxed italic"></p>
            </div>
        </div>

        <!-- Collecting Slide (players are giving answers) -->
        <div id="slide-collecting" class="hidden w-full max-w-7xl mx-auto px-8">
            <div class="animate-fade-in">
                <div class="text-center mb-12">
                    <p class="text-4xl font-black text-white/40 uppercase tracking-[0.5em] mb-6">Round <span id="collecting-round">1</span></p>
                    <h1 id="collecting-category" class="text-7xl md:text-8xl font-black mb-8 leading-tight">Category Title</h1>

                    <!-- Current Turn Display with Timer -->
                    <div id="current-turn-display" class="hidden my-10">
                        <!-- Countdown Timer (first player - 30 second thinking time) -->
                        <div id="countdown-timer" class="hidden">
                            <div class="inline-flex items-center gap-6 bg-yellow-900/30 border-2 border-yellow-600 rounded-3xl px-12 py-6 shadow-2xl">
                                <span class="text-yellow-400 text-4xl font-bold">⏱️ THINK!</span>
                                <span id="countdown-display" class="text-8xl font-mono font-black text-yellow-400">30</span>
                            </div>
                            <p class="text-3xl text-slate-400 mt-8">
                                <span id="countdown-player-name" class="font-black text-white text-4xl">Player</span>
                                <span class="text-slate-400 uppercase tracking-widest ml-2">answers first</span>
                            </p>
                        </div>

                        <!-- Countup Timer (subsequent players - social pressure) -->
                        <div id="countup-timer" class="hidden">
                            <p class="text-5xl mb-8">
                                <span id="countup-player-name" class="font-black text-6xl" style="color: white">Player</span>
                                <span class="text-slate-400 uppercase tracking-widest ml-4">is up!</span>
                            </p>
                            <div class="inline-flex items-center gap-4 bg-slate-800 border-2 border-slate-600 rounded-2xl px-10 py-5 shadow-xl">
                                <span class="text-slate-400 text-2xl uppercase tracking-tighter">Waiting:</span>
                                <span id="countup-display" class="text-6xl font-mono font-black text-red-500">0s</span>
                            </div>
                        </div>

                        <!-- All Answered -->
                        <div id="all-answered" class="hidden scale-125">
                            <p class="text-6xl text-green-400 font-black tracking-tight drop-shadow-lg">All answers collected!</p>
                        </div>
                    </div>

                    <!-- Turn Order -->
                    <div id="turn-order" class="hidden my-8">
                        <p class="text-slate-500 uppercase tracking-[0.3em] font-bold mb-4">Answer Order</p>
                        <div id="turn-order-players" class="flex flex-wrap justify-center gap-4">
                            <!-- Populated by JS -->
                        </div>
                    </div>

                    <p id="collecting-prompt" class="text-3xl text-blue-400 font-bold animate-pulse">Give your answers now!</p>
                </div>

                <!-- Collected Answers Grid -->
                <div id="collected-answers" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8 mt-12">
                    <!-- Populated by JS -->
                </div>
            </div>
        </div>

        <!-- Reveal Slide (single page, answers appear one by one) -->
        <div id="slide-reveal" class="hidden w-full max-w-[105rem] mx-auto px-12">
            <div class="animate-fade-in">
                <div class="text-center mb-8">
                    <p class="text-4xl font-black text-white/30 uppercase tracking-[0.6em] mb-2">Round <span id="reveal-round">1</span></p>
                    <h2 id="reveal-category" class="text-7xl md:text-8xl font-black leading-none tracking-tight">Category Title</h2>
                </div>

                <!-- Top 10 Grid -->
                <div id="reveal-top10" class="grid grid-cols-2 md:grid-cols-5 gap-8 mb-8">
                    <!-- Populated by JS -->
                </div>

                <!-- Friction Zone -->
                <div id="friction-zone" class="hidden">
                    <div class="flex items-center gap-12 mb-6 justify-center">
                        <div class="h-1 flex-1 bg-gradient-to-r from-transparent to-red-600/30"></div>
                        <h3 class="text-3xl font-black text-red-500 tracking-[0.4em]">FRICTION ZONE</h3>
                        <div class="h-1 flex-1 bg-gradient-to-l from-transparent to-red-600/30"></div>
                    </div>
                    <div id="reveal-friction" class="grid grid-cols-2 md:grid-cols-5 gap-8">
                        <!-- Populated by JS -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Scores Slide -->
        <div id="slide-scores" class="hidden w-full max-w-7xl mx-auto px-8 py-12">
            <div class="animate-fade-in text-center">
                <h2 id="scores-title" class="text-5xl font-black mb-16 text-white/40 uppercase tracking-[0.4em]">Round Complete</h2>
                <div id="scores-container" class="flex flex-wrap justify-center gap-10 items-stretch">
                    <!-- Scores populated by JS -->
                </div>
            </div>
        </div>

        <!-- Game Over Slide -->
        <div id="slide-gameover" class="hidden w-full max-w-7xl mx-auto px-8 py-12 text-center">
            <div class="animate-scale-in">
                <h1 class="text-9xl font-black mb-20 tracking-tight">Final Standings</h1>
                <div id="final-scores-container" class="flex flex-wrap justify-center gap-12 items-stretch">
                    <!-- Final scores populated by JS -->
                </div>
            </div>
        </div>

        <!-- Lobby Slide (players joining before game starts) -->
        <div id="slide-lobby" class="hidden w-full max-w-[95rem] mx-auto px-8">
            <div class="animate-fade-in text-center">
                <!-- Title -->
                <div class="text-center mb-16 text-white">
                    <h1 class="text-[12rem] font-title mb-4 tracking-tighter leading-none">
                        <span class="text-white">FRIC</span><span class="text-red-500">TION</span>
                    </h1>
                    <p class="text-5xl text-white/40 uppercase tracking-[0.5em] font-light">Join the game!</p>
                </div>

                <div class="grid md:grid-cols-2 gap-16 items-stretch text-left">
                    <!-- Join Info -->
                    <div class="text-center">
                        <div class="bg-slate-800/50 rounded-[3rem] p-12 border-2 border-slate-700 shadow-2xl h-full flex flex-col justify-center">
                            @php
                                // Get the actual host from the request (works with both localhost and network IP)
                                $baseUrl = request()->getSchemeAndHttpHost();
                                $joinUrl = $baseUrl . '/join';
                                $fullJoinUrl = $game->join_code ? $baseUrl . '/join/' . $game->join_code : null;
                            @endphp

                            <p class="text-slate-400 text-2xl mb-4 uppercase tracking-[0.2em]">Go to</p>
                            <p class="text-5xl text-blue-400 font-black mb-12" id="lobby-join-url">{{ $joinUrl }}</p>

                            <p class="text-slate-400 text-2xl mb-4 uppercase tracking-[0.2em]">Enter code</p>
                            <p class="text-9xl font-mono font-black tracking-[0.2em] text-white mb-12 drop-shadow-2xl" id="lobby-join-code">{{ $game->join_code ?? '------' }}</p>

                            <p class="text-slate-500 text-xl mb-8 uppercase font-black tracking-tighter opacity-50">- or scan -</p>

                            <div class="flex justify-center">
                                <div class="bg-white p-8 rounded-[2.5rem] shadow-2xl transform transition hover:scale-105 duration-500">
                                    @if($fullJoinUrl)
                                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data={{ urlencode($fullJoinUrl) }}"
                                             alt="QR Code"
                                             class="w-64 h-64"
                                             id="lobby-qr-code">
                                    @else
                                        <div class="w-64 h-64 flex items-center justify-center text-slate-400" id="lobby-qr-placeholder">
                                            QR Code
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Players List -->
                    <div class="h-full">
                        <div class="bg-slate-800/50 rounded-[3rem] p-12 border-2 border-slate-700 shadow-2xl h-full flex flex-col">
                            @php
                                $lobbyActivePlayers = $game->players->filter(fn($p) => $p->isActive());
                                $lobbyConnectedCount = $lobbyActivePlayers->filter(fn($p) => $p->isConnected())->count();
                            @endphp
                            <h2 class="text-5xl font-black mb-10 flex items-center gap-6">
                                <span>Players</span>
                                <span class="text-3xl font-bold text-white/20" id="lobby-player-count">{{ $lobbyConnectedCount }}/{{ $lobbyActivePlayers->count() }}</span>
                            </h2>

                            <div id="lobby-players" class="space-y-6 flex-1 overflow-y-auto pr-2 custom-scrollbar">
                                @php
                                    $activePlayers = $game->players->filter(fn($p) => $p->isActive())->sortBy('position');
                                @endphp
                                @forelse($activePlayers as $player)
                                    @php $contrast = getContrastColor($player->color); @endphp
                                    <div class="flex items-center gap-8 bg-slate-900/80 rounded-[1.5rem] px-8 py-6 border border-white/5 shadow-xl" data-player-id="{{ $player->id }}">
                                        <div class="w-8 h-8 rounded-full shadow-inner border-2 border-white/10" style="background-color: {{ $player->color }}"></div>
                                        <span class="text-4xl font-black tracking-tight" style="color: {{ $player->color }}">{{ $player->name }}</span>
                                        <span class="ml-auto px-5 py-2 rounded-xl text-sm font-black uppercase tracking-[0.2em] border" 
                                              style="background-color: {{ $player->color }}; color: {{ $contrast }}; border-color: white/10">
                                            {{ $player->isGmCreated() ? 'GM' : ($player->isConnected() ? 'Joined' : 'Wait') }}
                                        </span>
                                    </div>
                                @empty
                                    <p class="text-slate-500 text-center py-12 text-3xl italic font-light">Waiting for players to join...</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>

                <p class="text-center text-white/40 mt-24 mb-16 text-3xl font-black italic tracking-tight uppercase">Game Master will start when everyone's ready</p>
            </div>
        </div>
    </div>

    <!-- Scoreboard Overlay (bottom) - hidden during reveal, dynamically populated -->
    <div id="scoreboard-overlay" class="hidden fixed bottom-0 left-0 right-0 bg-slate-900/90 border-t border-slate-700 py-4 px-8">
        <div id="scoreboard-players" class="flex justify-center gap-8">
            <!-- Dynamically populated by JavaScript -->
        </div>
    </div>

    @php
        $categories = [];
        foreach ($game->rounds as $round) {
            $categories[$round->round_number] = [
                'title' => $round->category->title,
                'description' => $round->category->description,
                'answers' => $round->category->answers->sortBy('position')->map(fn($a) => [
                    'position' => $a->position,
                    'text' => $a->display_text, // Use display_text to hide geographic identifiers
                    'stat' => $a->stat,
                    'points' => $a->points,
                    'is_friction' => $a->is_friction,
                ])->values()->toArray(),
            ];
        }

        // Get current round for initial state
        $currentRound = $game->rounds->where('round_number', $game->current_round)->first();

        // Build initial state - use database values for ephemeral state
        $initialState = [
            'gameId' => $game->id,
            'showRules' => (bool) $game->show_rules,
            'currentRound' => $game->current_round,
            'roundStatus' => $currentRound?->status,
            'currentSlide' => $currentRound?->current_slide ?? 0,
            'gameStatus' => $game->status,
            'joinCode' => $game->join_code,
            'playerCount' => $game->player_count,
            'revealedAnswers' => [],
            'collectedAnswers' => [],
            'turnOrder' => [],
            'timerRunning' => (bool) $game->timer_running,
            'timerStartedAt' => $game->timer_started_at?->timestamp,
            'thinkingTime' => $game->thinking_time,
            'currentTurnPlayerId' => null,
            'currentTurnPlayerName' => null,
            'currentTurnPlayerColor' => null,
            'currentTurnIndex' => null,
            'timerMode' => null, // 'countdown' or 'countup'
            'allAnswered' => false,
            'categoryTitle' => $currentRound?->category->title,
            'categoryDescription' => $currentRound?->category->description,
            'players' => $game->players
                ->filter(fn($p) => $p->isActive())
                ->map(fn($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'color' => $p->color,
                    'total_score' => $p->total_score,
                    'double_used' => $p->double_used,
                    'doubles_remaining' => $p->doublesRemaining(),
                    'is_connected' => $p->isConnected(),
                    'is_gm_created' => $p->isGmCreated(),
                    'is_gm_controlled' => $p->isGmControlled(),
                ])->values()->toArray(),
            'config' => [
                'topAnswersCount' => $game->top_answers_count,
                'frictionPenalty' => $game->friction_penalty,
                'notOnListPenalty' => $game->not_on_list_penalty,
                'doubleMultiplier' => $game->double_multiplier,
                'doublesPerPlayer' => $game->doubles_per_player,
                'maxAnswersPerCategory' => $game->max_answers_per_category,
            ],
        ];
    @endphp

    <script>
        const gameId = '{{ $game->id }}';
        const categories = @json($categories);
        const totalRounds = {{ $game->total_rounds }};
        const initialState = @json($initialState);
        const players = @json($game->players->keyBy('id'));

        // Initialize BroadcastChannel
        const channel = new BroadcastChannel('friction-game-' + gameId);

        // Helper: Calculate contrast color for text (black or white)
        function getContrastColor(hexcolor) {
            if (!hexcolor) return 'white';
            // If it's a shorthand hex like #333, expand it to #333333
            if (hexcolor.length === 4) {
                hexcolor = '#' + hexcolor[1] + hexcolor[1] + hexcolor[2] + hexcolor[2] + hexcolor[3] + hexcolor[3];
            }
            var r = parseInt(hexcolor.substr(1, 2), 16);
            var g = parseInt(hexcolor.substr(3, 2), 16);
            var b = parseInt(hexcolor.substr(5, 2), 16);
            var yiq = ((r * 299) + (g * 587) + (b * 114)) / 1000;
            return (yiq >= 128) ? 'black' : 'white';
        }

        // DOM elements
        const slides = {
            lobby: document.getElementById('slide-lobby'),
            rules: document.getElementById('slide-rules'),
            intro: document.getElementById('slide-intro'),
            collecting: document.getElementById('slide-collecting'),
            reveal: document.getElementById('slide-reveal'),
            scores: document.getElementById('slide-scores'),
            gameover: document.getElementById('slide-gameover'),
        };

        const scoreboardOverlay = document.getElementById('scoreboard-overlay');

        function hideAllSlides() {
            Object.values(slides).forEach(function(slide) {
                slide.classList.add('hidden');
            });
        }

        var currentSlideName = null;

        function showSlide(name) {
            // Only change if actually switching slides
            if (currentSlideName === name) return;

            hideAllSlides();
            if (slides[name]) {
                slides[name].classList.remove('hidden');
                // Only trigger animation when actually switching slides
                var animated = slides[name].querySelector('.animate-slide-in, .animate-fade-in, .animate-scale-in');
                if (animated) {
                    animated.style.animation = 'none';
                    animated.offsetHeight; // Trigger reflow
                    animated.style.animation = null;
                }
            }
            currentSlideName = name;
        }

        function updateScoreboard(playerList) {
            var container = document.getElementById('scoreboard-players');
            if (!container) return;

            // Sort players by score descending
            var sorted = playerList.slice().sort(function(a, b) { return b.total_score - a.total_score; });

            // Rebuild the scoreboard with only active players from state
            container.innerHTML = sorted.map(function(player) {
                return '<div class="text-center" data-player-id="' + player.id + '">' +
                    '<div class="text-lg font-bold" style="color: ' + player.color + '">' + player.name + '</div>' +
                    '<div class="text-3xl font-black player-score">' + player.total_score + '</div>' +
                '</div>';
            }).join('');
        }

        // Timer state
        var timerInterval = null;
        var currentTimerMode = null; // 'countdown' or 'countup'

        function renderTurnOrder(turnOrder, currentTurnPlayerId) {
            var container = document.getElementById('turn-order-players');
            if (!container || !turnOrder || turnOrder.length === 0) return;

            var html = turnOrder.map(function(player, index) {
                var isCurrent = player.id === currentTurnPlayerId;
                var classes = 'flex items-center gap-2 px-4 py-2 rounded-full ' +
                    (isCurrent ? 'bg-blue-600 border-2 border-blue-400 ring-2 ring-blue-400/50' : 'bg-slate-800 border border-slate-700');
                return '<div class="' + classes + '">' +
                    '<span class="font-bold ' + (isCurrent ? 'text-white' : 'text-slate-500') + '">' + (index + 1) + '.</span>' +
                    '<span style="color: ' + player.color + '">' + player.name + '</span>' +
                '</div>';
            }).join('');

            container.innerHTML = html;
        }

        function updateTurnDisplay(state) {
            var container = document.getElementById('current-turn-display');
            var countdownEl = document.getElementById('countdown-timer');
            var countupEl = document.getElementById('countup-timer');
            var allAnsweredEl = document.getElementById('all-answered');
            var promptEl = document.getElementById('collecting-prompt');

            if (!container) return;

            // Clear any existing timer interval
            if (timerInterval) {
                clearInterval(timerInterval);
                timerInterval = null;
            }

            container.classList.remove('hidden');
            countdownEl.classList.add('hidden');
            countupEl.classList.add('hidden');
            allAnsweredEl.classList.add('hidden');
            if (promptEl) promptEl.classList.add('hidden');

            if (state.allAnswered) {
                // All players have answered
                allAnsweredEl.classList.remove('hidden');
                currentTimerMode = null;
                return;
            }

            if (state.timerMode === 'countdown' && state.timerRunning && state.timerStartedAt) {
                // First player - show countdown
                countdownEl.classList.remove('hidden');
                currentTimerMode = 'countdown';

                var nameEl = document.getElementById('countdown-player-name');
                var displayEl = document.getElementById('countdown-display');

                if (nameEl) {
                    nameEl.textContent = state.currentTurnPlayerName || 'Player';
                    nameEl.style.color = state.currentTurnPlayerColor || 'white';
                }

                // Calculate remaining time
                var now = Math.floor(Date.now() / 1000);
                var elapsed = now - state.timerStartedAt;
                var thinkingTime = state.thinkingTime || 30;
                var timerEndTime = state.timerStartedAt + thinkingTime;

                function updateCountdown() {
                    var nowSec = Math.floor(Date.now() / 1000);
                    var left = Math.max(0, timerEndTime - nowSec);
                    if (displayEl) {
                        displayEl.textContent = left;
                        if (left <= 5) {
                            displayEl.classList.remove('text-yellow-400');
                            displayEl.classList.add('text-red-400');
                        } else {
                            displayEl.classList.remove('text-red-400');
                            displayEl.classList.add('text-yellow-400');
                        }
                    }

                    if (left <= 0) {
                        clearInterval(timerInterval);
                        timerInterval = null;
                    }
                }

                updateCountdown();
                timerInterval = setInterval(updateCountdown, 1000);

            } else if (state.timerMode === 'countup' && state.timerRunning && state.timerStartedAt) {
                // Subsequent players - show countup (social pressure)
                countupEl.classList.remove('hidden');
                currentTimerMode = 'countup';

                var nameEl = document.getElementById('countup-player-name');
                var displayEl = document.getElementById('countup-display');

                if (nameEl) {
                    nameEl.textContent = state.currentTurnPlayerName || 'Player';
                    nameEl.style.color = state.currentTurnPlayerColor || 'white';
                }

                function updateCountup() {
                    var nowSec = Math.floor(Date.now() / 1000);
                    var elapsed = nowSec - state.timerStartedAt;
                    if (displayEl) {
                        displayEl.textContent = elapsed + 's';
                        // Change color as time increases
                        if (elapsed >= 20) {
                            displayEl.classList.remove('text-yellow-400');
                            displayEl.classList.add('text-red-400');
                        } else if (elapsed >= 10) {
                            displayEl.classList.remove('text-red-400');
                            displayEl.classList.add('text-yellow-400');
                        }
                    }
                }

                updateCountup();
                timerInterval = setInterval(updateCountup, 1000);

            } else if (state.currentTurnPlayerName) {
                // No timer but there's a current player
                countupEl.classList.remove('hidden');
                var nameEl = document.getElementById('countup-player-name');
                var displayEl = document.getElementById('countup-display');
                if (nameEl) {
                    nameEl.textContent = state.currentTurnPlayerName;
                    nameEl.style.color = state.currentTurnPlayerColor || 'white';
                }
                if (displayEl) displayEl.textContent = '0s';
            } else {
                // Fall back to prompt
                container.classList.add('hidden');
                if (promptEl) promptEl.classList.remove('hidden');
            }
        }

        function stopTimer() {
            if (timerInterval) {
                clearInterval(timerInterval);
                timerInterval = null;
            }
            currentTimerMode = null;
            var container = document.getElementById('current-turn-display');
            var promptEl = document.getElementById('collecting-prompt');
            if (container) container.classList.add('hidden');
            if (promptEl) promptEl.classList.remove('hidden');
        }

        function renderCollectedAnswers(collectedAnswers, allPlayers, turnOrder, currentTurnPlayerId) {
            var container = document.getElementById('collected-answers');
            if (!container) return;

            // Create a map of collected answers by player ID
            var answerMap = {};
            collectedAnswers.forEach(function(ca) {
                answerMap[ca.playerId] = ca;
            });

            // Use turn order if available, otherwise use allPlayers
            var orderedPlayers = turnOrder && turnOrder.length > 0 ? turnOrder : allPlayers;

            // Render all players, showing their answer if collected
            var html = orderedPlayers.map(function(player, index) {
                var collected = answerMap[player.id];
                var isCurrent = player.id === currentTurnPlayerId;
                var isGmControlled = player.is_gm_controlled && !player.is_gm_created;
                var gmBadge = isGmControlled ? '<span class="text-xs text-yellow-400 ml-1 opacity-70">(GM)</span>' : '';
                var contrastColor = getContrastColor(player.color);

                if (collected) {
                    return '<div class="bg-slate-800 rounded-2xl p-6 text-center border-b-4 border-green-500 shadow-xl scale-105 transition-all">' +
                        '<div class="text-xs uppercase tracking-widest text-slate-500 mb-2 font-bold">Answer #' + (index + 1) + '</div>' +
                        '<div class="text-2xl font-black mb-3 px-3 py-1 rounded-lg inline-block" style="background-color: ' + player.color + '; color: ' + contrastColor + '">' + 
                            player.name + gmBadge + 
                        '</div>' +
                        '<div class="text-3xl font-black text-white leading-tight break-words">' + collected.answerText + '</div>' +
                        '<div class="mt-4 flex justify-center"><div class="bg-green-500/20 text-green-400 px-3 py-1 rounded-full text-sm font-bold border border-green-500/30">SUBMITTED ✓</div></div>' +
                    '</div>';
                } else {
                    var currentClasses = isCurrent ? 'bg-slate-700 ring-4 ring-blue-500 border-blue-500 scale-110 z-10 animate-pulse-slow' : 'bg-slate-800/40 opacity-40';
                    return '<div class="rounded-2xl p-6 text-center border-b-4 border-slate-600 transition-all ' + currentClasses + '">' +
                        '<div class="text-xs uppercase tracking-widest text-slate-500 mb-2 font-bold">Answer #' + (index + 1) + '</div>' +
                        '<div class="text-2xl font-black mb-3 px-3 py-1 rounded-lg inline-block" style="background-color: ' + player.color + '; color: ' + contrastColor + '">' + 
                            player.name + gmBadge + 
                        '</div>' +
                        '<div class="text-3xl font-black text-slate-500 italic">' + (isCurrent ? 'THINKING...' : 'WAITING...') + '</div>' +
                    '</div>';
                }
            }).join('');

            container.innerHTML = html;
        }

        // Store current config (updated from state)
        var currentConfig = initialState.config || {
            topAnswersCount: 10,
            frictionPenalty: -5,
            notOnListPenalty: -3,
            doubleMultiplier: 2,
            doublesPerPlayer: 1,
            maxAnswersPerCategory: 15
        };

        function renderRevealGrid(category, revealedAnswers, currentSlide) {
            // Build a map of revealed answers with their players
            var revealedMap = {};
            revealedAnswers.forEach(function(ra) {
                revealedMap[ra.position] = ra;
            });

            var topCount = currentConfig.topAnswersCount;
            var maxAnswers = currentConfig.maxAnswersPerCategory;
            var doubleMultiplier = currentConfig.doubleMultiplier;

            // Render top answers (1 to topAnswersCount)
            var top10Html = '';
            for (var i = 1; i <= topCount; i++) {
                var answer = category.answers.find(function(a) { return a.position === i; });
                var revealed = revealedMap[i];
                var isRevealed = i <= currentSlide;

                if (answer) {
                    var playersHtml = '';
                    if (isRevealed && revealed && revealed.players && revealed.players.length > 0) {
                        playersHtml = '<div class="mt-4 flex flex-wrap gap-2 justify-center">';
                        revealed.players.forEach(function(p) {
                            var contrast = getContrastColor(p.color);
                            playersHtml += '<span class="text-sm font-black px-4 py-1.5 rounded-xl shadow-lg border-2 border-white/10" style="background-color: ' + p.color + '; color: ' + contrast + '">' + p.name + (p.doubled ? ' ' + doubleMultiplier + 'x' : '') + '</span>';
                        });
                        playersHtml += '</div>';
                    }

                    var cardClasses = isRevealed ? 'bg-slate-800 border-green-500/50 border-2 shadow-2xl scale-105 z-10' : 'bg-slate-800/40 border-slate-700/50 scale-100';
                    top10Html += '<div class="rounded-3xl p-8 text-center transition-all duration-500 border-b-8 ' + cardClasses + '">';
                    top10Html += '<div class="' + (isRevealed ? 'text-green-400' : 'text-green-400/30') + ' font-black text-2xl mb-1">#' + i + '</div>';
                    if (isRevealed) {
                        top10Html += '<div class="text-3xl font-black text-white leading-tight mb-1">' + answer.text + '</div>';
                        if (answer.stat) {
                            top10Html += '<div class="text-lg text-slate-400 font-bold mb-2">' + answer.stat + '</div>';
                        }
                        top10Html += '<div class="text-2xl font-black text-green-400">+' + answer.points + ' PTS</div>';
                    } else {
                        top10Html += '<div class="text-6xl font-black py-4 text-white/5">?</div>';
                    }
                    top10Html += playersHtml;
                    top10Html += '</div>';
                }
            }
            document.getElementById('reveal-top10').innerHTML = top10Html;

            // Render friction zone if there are friction answers (position > topAnswersCount)
            var hasFriction = category.answers.some(function(a) { return a.position > topCount; });
            var frictionZone = document.getElementById('friction-zone');

            if (hasFriction) {
                frictionZone.classList.remove('hidden');
                var frictionHtml = '';
                for (var i = topCount + 1; i <= maxAnswers; i++) {
                    var answer = category.answers.find(function(a) { return a.position === i; });
                    var revealed = revealedMap[i];
                    var isRevealed = i <= currentSlide;

                    if (answer) {
                        var playersHtml = '';
                        if (isRevealed && revealed && revealed.players && revealed.players.length > 0) {
                            playersHtml = '<div class="mt-4 flex flex-wrap gap-2 justify-center">';
                            revealed.players.forEach(function(p) {
                                var contrast = getContrastColor(p.color);
                                playersHtml += '<span class="text-sm font-black px-4 py-1.5 rounded-xl shadow-lg border-2 border-white/10" style="background-color: ' + p.color + '; color: ' + contrast + '">' + p.name + (p.doubled ? ' ' + doubleMultiplier + 'x' : '') + '</span>';
                            });
                            playersHtml += '</div>';
                        }

                        var cardClasses = isRevealed ? 'bg-red-950/40 border-red-500 border-2 shadow-2xl scale-105 z-10' : 'bg-red-950/20 border-red-900/30 scale-100';
                        frictionHtml += '<div class="rounded-3xl p-8 text-center transition-all duration-500 border-b-8 ' + cardClasses + '">';
                        frictionHtml += '<div class="' + (isRevealed ? 'text-red-500' : 'text-red-500/30') + ' font-black text-2xl mb-1">#' + i + '</div>';
                        if (isRevealed) {
                            frictionHtml += '<div class="text-3xl font-black text-white leading-tight mb-1">' + answer.text + '</div>';
                            if (answer.stat) {
                                frictionHtml += '<div class="text-lg text-slate-400 font-bold mb-2">' + answer.stat + '</div>';
                            }
                            frictionHtml += '<div class="text-2xl font-black text-red-500">' + answer.points + ' PTS</div>';
                        } else {
                            frictionHtml += '<div class="text-6xl font-black py-4 text-white/5">?</div>';
                        }
                        frictionHtml += playersHtml;
                        frictionHtml += '</div>';
                    }
                }
                document.getElementById('reveal-friction').innerHTML = frictionHtml;
            } else {
                frictionZone.classList.add('hidden');
            }
        }

        function renderScores(playerList, title) {
            document.getElementById('scores-title').textContent = title;
            var container = document.getElementById('scores-container');
            var sorted = playerList.slice().sort(function(a, b) { return b.total_score - a.total_score; });
            
            container.innerHTML = sorted.map(function(p, i) {
                var contrast = getContrastColor(p.color);
                var isLeader = i === 0;
                var cardClasses = isLeader ? 'bg-slate-800 ring-4 ring-yellow-500 scale-110 z-10' : 'bg-slate-800/60 scale-100';
                
                return '<div class="rounded-[2.5rem] p-12 flex flex-col items-center justify-center transition-all shadow-2xl border-b-8 border-slate-900 min-w-[320px] ' + cardClasses + '">' +
                    '<div class="text-3xl font-black mb-6 px-8 py-3 rounded-2xl shadow-lg" style="background-color: ' + p.color + '; color: ' + contrast + '">' +
                        (isLeader ? '🏆 ' : '') + p.name +
                    '</div>' +
                    '<div class="text-[10rem] font-black tracking-tighter leading-none mb-4">' + p.total_score + '</div>' +
                    '<div class="text-slate-500 text-xl font-bold uppercase tracking-[0.2em]">Total Points</div>' +
                '</div>';
            }).join('');
        }

        function renderFinalScores(playerList) {
            var container = document.getElementById('final-scores-container');
            var sorted = playerList.slice().sort(function(a, b) { return b.total_score - a.total_score; });
            
            container.innerHTML = sorted.map(function(p, i) {
                var contrast = getContrastColor(p.color);
                var place = i === 0 ? '1st' : i === 1 ? '2nd' : i === 2 ? '3rd' : (i+1)+'th';
                var isFirst = i === 0;
                var cardClasses = isFirst ? 'bg-slate-800 ring-8 ring-yellow-500 scale-110 z-10' : 'bg-slate-800/40';
                
                return '<div class="rounded-[3.5rem] p-16 flex flex-col items-center justify-center transition-all shadow-2xl border-b-8 border-slate-950 min-w-[400px] ' + cardClasses + '">' +
                    '<div class="text-5xl font-black text-white/30 uppercase mb-6 tracking-widest">' + place + '</div>' +
                    '<div class="text-4xl font-black mb-8 px-10 py-4 rounded-[1.5rem] shadow-xl" style="background-color: ' + p.color + '; color: ' + contrast + '">' +
                        p.name +
                    '</div>' +
                    '<div class="text-[12rem] font-black tracking-tighter leading-none mb-4">' + p.total_score + '</div>' +
                    '<div class="text-3xl font-bold text-slate-500 uppercase tracking-[0.3em]">Final Score</div>' +
                '</div>';
            }).join('');
        }

        function renderLobbyPlayers(playerList, playerCount) {
            var container = document.getElementById('lobby-players');
            var countEl = document.getElementById('lobby-player-count');
            if (!container) return;

            // Count connected players
            var connectedCount = playerList.filter(function(p) { return p.is_connected; }).length;

            if (countEl) {
                countEl.textContent = connectedCount + '/' + playerList.length;
            }

            if (playerList.length === 0) {
                container.innerHTML = '<p class="text-slate-500 text-center py-12 text-3xl italic font-light">Waiting for players to join...</p>';
                return;
            }

            container.innerHTML = playerList.map(function(p) {
                var statusText;
                if (p.is_gm_created) {
                    statusText = 'GM';
                } else if (p.is_connected) {
                    statusText = 'Joined';
                } else {
                    statusText = 'Wait';
                }
                var contrast = getContrastColor(p.color);
                var opacityClass = p.is_connected || p.is_gm_created ? '' : 'opacity-40';
                
                return '<div class="flex items-center gap-8 bg-slate-900/80 rounded-[1.5rem] px-8 py-6 border border-white/5 shadow-xl transition-all ' + opacityClass + '">' +
                    '<div class="w-8 h-8 rounded-full shadow-inner border-2 border-white/10" style="background-color: ' + p.color + '"></div>' +
                    '<span class="text-4xl font-black tracking-tight" style="color: ' + p.color + '">' + p.name + '</span>' +
                    '<span class="ml-auto px-5 py-2 rounded-xl text-sm font-black uppercase tracking-[0.2em] border" ' +
                          'style="background-color: ' + p.color + '; color: ' + contrast + '; border-color: white/10">' + 
                        statusText + 
                    '</span>' +
                '</div>';
            }).join('');
        }

        function handleStateUpdate(state) {
            console.log('=== STATE UPDATE ===', state);

            // Update config from state if provided
            if (state.config) {
                currentConfig = state.config;
            }

            // Handle game not started yet (draft or ready status) - show lobby
            if (state.gameStatus === 'draft' || state.gameStatus === 'ready') {
                renderLobbyPlayers(state.players, state.playerCount || 4);
                showSlide('lobby');
                scoreboardOverlay.classList.add('hidden');
                return;
            }

            // Handle game over
            if (state.gameStatus === 'completed') {
                showSlide('gameover');
                renderFinalScores(state.players);
                scoreboardOverlay.classList.add('hidden');
                return;
            }

            // Show rules
            if (state.showRules) {
                showSlide('rules');
                scoreboardOverlay.classList.add('hidden');
                return;
            }

            var category = categories[state.currentRound];
            if (!category) {
                console.log('No category found for round', state.currentRound, '- showing lobby');
                showSlide('lobby');
                scoreboardOverlay.classList.add('hidden');
                return;
            }

            // Handle different phases
            switch (state.roundStatus) {
                case 'intro':
                    document.getElementById('round-number').textContent = 'Round ' + state.currentRound;
                    document.getElementById('category-title').textContent = category.title;
                    document.getElementById('category-description').textContent = category.description || '';
                    showSlide('intro');
                    scoreboardOverlay.classList.add('hidden');
                    break;

                case 'collecting':
                    document.getElementById('collecting-round').textContent = state.currentRound;
                    document.getElementById('collecting-category').textContent = category.title;

                    // Show turn order if available
                    var turnOrderEl = document.getElementById('turn-order');
                    if (state.turnOrder && state.turnOrder.length > 0) {
                        turnOrderEl.classList.remove('hidden');
                        renderTurnOrder(state.turnOrder, state.currentTurnPlayerId);
                    } else {
                        turnOrderEl.classList.add('hidden');
                    }

                    // Handle turn-based timer (countdown for first, countup for others)
                    updateTurnDisplay(state);

                    renderCollectedAnswers(state.collectedAnswers || [], state.players, state.turnOrder, state.currentTurnPlayerId);
                    showSlide('collecting');
                    scoreboardOverlay.classList.add('hidden');
                    break;

                case 'revealing':
                case 'friction':
                    document.getElementById('reveal-round').textContent = state.currentRound;
                    document.getElementById('reveal-category').textContent = category.title;
                    renderRevealGrid(category, state.revealedAnswers || [], state.currentSlide);
                    showSlide('reveal');
                    scoreboardOverlay.classList.add('hidden'); // Hide scores during reveal
                    break;

                case 'scoring':
                    showSlide('scores');
                    updateScoreboard(state.players);
                    renderScores(state.players, 'Round ' + state.currentRound + ' Complete');
                    scoreboardOverlay.classList.remove('hidden');
                    break;

                default:
                    console.log('Unknown status:', state.roundStatus);
            }
        }

        var isGameActive = initialState.gameStatus === 'playing';
        var pollingInterval = null;

        // Listen for updates from control tab
        channel.onmessage = function(event) {
            console.log('[Present] RECEIVED MESSAGE FROM CHANNEL:', event.data);
            if (event.data && event.data.type === 'request-state') return;

            // Handle reset - reload the page to get fresh state
            if (event.data && event.data.type === 'reset') {
                console.log('[Present] GAME RESET - Reloading page');
                window.location.reload();
                return;
            }

            // Handle game deleted via BroadcastChannel
            if (event.data && event.data.type === 'deleted') {
                console.log('[Present] GAME DELETED via BroadcastChannel');
                showGameNotFound();
                return;
            }

            // Stop polling once game becomes active
            if (event.data && event.data.gameStatus === 'playing' && !isGameActive) {
                isGameActive = true;
                if (pollingInterval) {
                    console.log('[Present] Game started, stopping polling');
                    clearInterval(pollingInterval);
                    pollingInterval = null;
                }
            }

            handleStateUpdate(event.data);
        };

        console.log('[Present] BroadcastChannel initialized for game:', gameId);

        // Redirect to game not found page
        function showGameNotFound() {
            // Stop any polling
            if (pollingInterval) {
                clearInterval(pollingInterval);
                pollingInterval = null;
            }
            window.location.href = '/present/' + gameId + '/not-found';
        }

        // Listen for game deletion via WebSocket (Echo/Reverb)
        // Retry until Echo is available (it loads asynchronously)
        function setupEchoListener() {
            if (window.Echo) {
                window.Echo.channel('game.' + gameId)
                    .listen('.game.deleted', function(data) {
                        console.log('[Present] Game deleted event received:', data);
                        showGameNotFound();
                    });
                console.log('[Present] Echo listener initialized for game deletion');
            } else {
                // Echo not ready yet, retry in 100ms
                setTimeout(setupEchoListener, 100);
            }
        }
        setupEchoListener();

        // Always start with lobby view - control panel will push actual state if game is active
        var lobbyState = {
            gameId: initialState.gameId,
            gameStatus: 'draft', // Force lobby view
            joinCode: initialState.joinCode,
            playerCount: initialState.playerCount,
            players: initialState.players
        };
        console.log('[Present] Starting with lobby state:', lobbyState);
        handleStateUpdate(lobbyState);

        // Request current state from control tab (if control panel is open, it will respond)
        channel.postMessage({ type: 'request-state' });

        // Only poll while waiting for game to start
        if (!isGameActive) {
            pollingInterval = setInterval(function() {
                channel.postMessage({ type: 'request-state' });
            }, 2000);
        }
    </script>
</x-layouts.presentation>
