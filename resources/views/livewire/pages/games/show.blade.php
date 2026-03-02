<?php

use App\Models\Game;
use App\Models\Player;
use App\Models\Category;
use App\Models\Topic;
use App\Models\Answer;
use App\Models\Round;
use App\Services\GameStateMachine;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

new #[Layout('components.layouts.app')] #[Title('Game Setup')] class extends Component {
    public Game $game;
    protected ?GameStateMachine $stateMachine = null;

    public string $newPlayerName = '';
    public string $newPlayerColor = '#3B82F6';

    public array $roundCategories = [];

    // Category filters
    public ?string $topicFilter = null;
    public string $searchQuery = '';

    // Category modal
    public bool $showCategoryModal = false;
    public string $categoryTitle = '';
    public string $categoryDescription = '';
    public ?string $categoryTopicId = null;
    public string $newTopicName = '';
    public array $categoryAnswers = [];
    public array $categoryAnswerStats = [];
    public string $bulkAnswers = '';

    // Randomize confirmation modal
    public bool $showRandomizeModal = false;

    // Reset game confirmation modal
    public bool $showResetModal = false;

    public function mount(Game $game): void
    {
        $this->game = $game->load(['players', 'rounds.category']);

        // Fix any players with position 0 (from before position tracking was added)
        $this->fixPlayerPositions();

        // Initialize round categories from existing rounds
        foreach ($game->rounds as $round) {
            $this->roundCategories[$round->round_number] = $round->category_id;
        }

        // Initialize empty category answers
        $this->resetCategoryForm();

        // Check ready status on load (in case player count changed)
        $this->checkReady();
    }

    private function fixPlayerPositions(): void
    {
        $playersNeedingPositions = $this->game->players()->where('position', 0)->get();
        if ($playersNeedingPositions->isEmpty()) return;

        $maxPosition = $this->game->players()->max('position') ?? 0;
        foreach ($playersNeedingPositions as $player) {
            $maxPosition++;
            $player->update(['position' => $maxPosition]);
        }
        $this->game->refresh();
    }

    public function with(): array
    {
        // Base query for complete categories (10+ answers)
        $baseQuery = Category::with('topic')
            ->whereHas('answers', function ($q) {
                $q->where('position', '<=', 10);
            }, '>=', 10);

        // Total count (unfiltered)
        $totalCategories = (clone $baseQuery)->count();

        // Filtered categories
        $categories = (clone $baseQuery)
            ->when($this->topicFilter, fn($q) => $q->where('topic_id', $this->topicFilter))
            ->when($this->searchQuery, fn($q) => $q->where('title', 'like', "%{$this->searchQuery}%"))
            ->orderBy('title')
            ->get();

        // Calculate effective player count based on active players
        $activePlayers = $this->game->players->filter(fn($p) => $p->isActive());
        $effectivePlayerCount = $activePlayers->count();
        $effectiveTotalRounds = $effectivePlayerCount * ($this->game->rounds_per_player ?? 2);

        // Get disconnected players (self-registered but not connected, not removed)
        // These are "orphaned" players that exist in DB but won't show in gameplay
        $disconnectedPlayers = $this->game->players
            ->filter(fn($p) => !$p->isRemoved() && !$p->isGmCreated() && !$p->isConnected());

        return [
            'categories' => $categories,
            'totalCategories' => $totalCategories,
            'topics' => Topic::orderBy('name')->get(),
            'effectivePlayerCount' => $effectivePlayerCount,
            'effectiveTotalRounds' => $effectiveTotalRounds,
            'activePlayers' => $activePlayers,
            'disconnectedPlayers' => $disconnectedPlayers,
        ];
    }

    public function clearFilters(): void
    {
        $this->topicFilter = null;
        $this->searchQuery = '';
    }

    protected function getStateMachine(): GameStateMachine
    {
        if (!$this->stateMachine) {
            $this->stateMachine = new GameStateMachine($this->game);
        }
        return $this->stateMachine;
    }

    public function generateJoinCode(): void
    {
        if (!$this->game->join_code) {
            $this->game->update(['join_code' => Game::generateJoinCode()]);
            $this->game->refresh();
        }
        $this->broadcastLobbyState();
    }

    public function broadcastLobbyState(): void
    {
        // Use state machine as single source of truth
        $this->getStateMachine()->refresh();
        $state = $this->getStateMachine()->broadcast();

        // Broadcast to local presentation via BroadcastChannel
        $this->dispatch('game-state-updated', state: $state);
    }

    public function openCategoryModal(): void
    {
        $this->resetCategoryForm();
        $this->showCategoryModal = true;
    }

    public function closeCategoryModal(): void
    {
        $this->showCategoryModal = false;
    }

    public function resetCategoryForm(): void
    {
        $this->categoryTitle = '';
        $this->categoryDescription = '';
        $this->categoryTopicId = null;
        $this->newTopicName = '';
        $this->categoryAnswers = [];
        $this->categoryAnswerStats = [];
        $this->bulkAnswers = '';
        for ($i = 1; $i <= 15; $i++) {
            $this->categoryAnswers[$i] = '';
            $this->categoryAnswerStats[$i] = '';
        }
    }

    public function parseBulkAnswers(): void
    {
        $lines = array_filter(array_map('trim', explode("\n", $this->bulkAnswers)));
        $position = 1;

        foreach ($lines as $line) {
            if ($position > 15) break;
            // Remove common list prefixes like "1.", "1)", "- ", etc.
            $clean = preg_replace('/^[\d]+[\.\)\-]\s*/', '', $line);
            $clean = preg_replace('/^[\-\*\•]\s*/', '', $clean);

            // Parse stat from format "Answer: stat" - uses last colon so "Avengers: Endgame: $2.8B" works
            if (preg_match('/^(.+)\s*[:]\s*([^:]+)$/', trim($clean), $matches)) {
                $this->categoryAnswers[$position] = trim($matches[1]);
                $this->categoryAnswerStats[$position] = trim($matches[2]);
            } else {
                $this->categoryAnswers[$position] = trim($clean);
                $this->categoryAnswerStats[$position] = '';
            }
            $position++;
        }

        $this->bulkAnswers = '';
    }

    public function moveAnswerUp(int $position): void
    {
        if ($position <= 1) return;

        $temp = $this->categoryAnswers[$position - 1];
        $this->categoryAnswers[$position - 1] = $this->categoryAnswers[$position];
        $this->categoryAnswers[$position] = $temp;

        $tempStat = $this->categoryAnswerStats[$position - 1];
        $this->categoryAnswerStats[$position - 1] = $this->categoryAnswerStats[$position];
        $this->categoryAnswerStats[$position] = $tempStat;
    }

    public function moveAnswerDown(int $position): void
    {
        if ($position >= 15) return;

        $temp = $this->categoryAnswers[$position + 1];
        $this->categoryAnswers[$position + 1] = $this->categoryAnswers[$position];
        $this->categoryAnswers[$position] = $temp;

        $tempStat = $this->categoryAnswerStats[$position + 1];
        $this->categoryAnswerStats[$position + 1] = $this->categoryAnswerStats[$position];
        $this->categoryAnswerStats[$position] = $tempStat;
    }

    public function saveCategory(): void
    {
        $this->validate([
            'categoryTitle' => 'required|string|max:255',
            'categoryDescription' => 'nullable|string',
            'categoryAnswers.1' => 'required|string|max:255',
            'categoryAnswers.2' => 'required|string|max:255',
            'categoryAnswers.3' => 'required|string|max:255',
            'categoryAnswers.4' => 'required|string|max:255',
            'categoryAnswers.5' => 'required|string|max:255',
            'categoryAnswers.6' => 'required|string|max:255',
            'categoryAnswers.7' => 'required|string|max:255',
            'categoryAnswers.8' => 'required|string|max:255',
            'categoryAnswers.9' => 'required|string|max:255',
            'categoryAnswers.10' => 'required|string|max:255',
            'categoryAnswers.11' => 'required|string|max:255',
        ], [
            'categoryAnswers.11.required' => 'At least 1 friction answer is required.',
            'categoryAnswers.*.required' => 'Answers 1-10 are required.',
        ]);

        // Handle new topic creation
        $topicId = $this->categoryTopicId;
        if ($this->categoryTopicId === '__new__' && !empty($this->newTopicName)) {
            $topic = Topic::create(['name' => trim($this->newTopicName)]);
            $topicId = $topic->id;
        } elseif ($this->categoryTopicId === '__new__') {
            $topicId = null;
        }

        $category = Category::create([
            'title' => $this->categoryTitle,
            'description' => $this->categoryDescription ?: null,
            'topic_id' => $topicId,
        ]);

        foreach ($this->categoryAnswers as $position => $text) {
            if (!empty(trim($text))) {
                Answer::create([
                    'category_id' => $category->id,
                    'text' => trim($text),
                    'stat' => !empty(trim($this->categoryAnswerStats[$position] ?? '')) ? trim($this->categoryAnswerStats[$position]) : null,
                    'position' => $position,
                ]);
            }
        }

        $this->showCategoryModal = false;
        $this->resetCategoryForm();
    }

    public function addPlayer(): void
    {
        $this->validate([
            'newPlayerName' => 'required|string|max:255',
            'newPlayerColor' => 'required|string',
        ]);

        $nextPosition = $this->game->players()->count() + 1;

        Player::create([
            'game_id' => $this->game->id,
            'name' => $this->newPlayerName,
            'color' => $this->newPlayerColor,
            'position' => $nextPosition,
        ]);

        // Recalculate player count and total rounds
        $this->game->recalculateFromPlayers();

        $this->newPlayerName = '';
        $this->game->refresh();
        $this->checkReady();

        // Broadcast updated state to presentation
        if ($this->game->join_code) {
            $this->broadcastLobbyState();
        }
    }

    public function removePlayer(Player $player): void
    {
        $player->delete();
        $this->game->refresh();

        // Reorder remaining players to ensure sequential positions
        $this->game->players()->orderBy('position')->get()->each(function ($p, $index) {
            $p->update(['position' => $index + 1]);
        });

        // Recalculate player count and total rounds
        $this->game->recalculateFromPlayers();
        $this->game->refresh();
        $this->checkReady();

        // Broadcast updated state to presentation
        if ($this->game->join_code) {
            $this->broadcastLobbyState();
        }
    }

    public function reorderPlayers(array $orderedIds): void
    {
        foreach ($orderedIds as $position => $playerId) {
            Player::where('id', $playerId)->update(['position' => $position + 1]);
        }
        $this->game->refresh();
    }

    public function setRoundCategory(int $roundNumber, ?string $categoryId): void
    {
        // Handle "create new" option
        if ($categoryId === '__new__') {
            $this->openCategoryModal();
            return;
        }

        if (empty($categoryId)) {
            // Remove round if exists
            $this->game->rounds()->where('round_number', $roundNumber)->delete();
            unset($this->roundCategories[$roundNumber]);
        } else {
            // Create or update round
            Round::updateOrCreate(
                ['game_id' => $this->game->id, 'round_number' => $roundNumber],
                ['category_id' => $categoryId]
            );
            $this->roundCategories[$roundNumber] = $categoryId;
        }

        $this->game->refresh();
        $this->checkReady();
    }

    public function randomCategory(int $roundNumber): void
    {
        // Get available categories (filtered by current filters)
        $usedCategoryIds = array_values($this->roundCategories);
        $currentSelection = $this->roundCategories[$roundNumber] ?? null;

        $available = Category::with('topic')
            ->whereHas('answers', function ($q) {
                $q->where('position', '<=', 10);
            }, '>=', 10)
            ->when($this->topicFilter, fn($q) => $q->where('topic_id', $this->topicFilter))
            ->when($this->searchQuery, fn($q) => $q->where('title', 'like', "%{$this->searchQuery}%"))
            ->whereNotIn('id', array_filter($usedCategoryIds, fn($id) => $id !== $currentSelection))
            ->when($currentSelection, fn($q) => $q->where('id', '!=', $currentSelection))
            ->inRandomOrder()
            ->first();

        if ($available) {
            $this->setRoundCategory($roundNumber, $available->id);
        }
    }

    public function tryRandomizeAll(): void
    {
        // If categories are already selected, show confirmation modal
        if (!empty(array_filter($this->roundCategories))) {
            $this->showRandomizeModal = true;
            return;
        }

        // Otherwise, randomize directly
        $this->randomizeAllCategories();
    }

    public function closeRandomizeModal(): void
    {
        $this->showRandomizeModal = false;
    }

    public function confirmReset(): void
    {
        $this->showResetModal = true;
    }

    public function closeResetModal(): void
    {
        $this->showResetModal = false;
    }

    public function randomizeAllCategories(): void
    {
        $this->showRandomizeModal = false;

        // Calculate effective total rounds
        $activePlayers = $this->game->players->filter(fn($p) => $p->isActive());
        $effectiveTotalRounds = $activePlayers->count() * ($this->game->rounds_per_player ?? 2);

        // Get all available categories (filtered by current filters)
        $available = Category::with('topic')
            ->whereHas('answers', function ($q) {
                $q->where('position', '<=', 10);
            }, '>=', 10)
            ->when($this->topicFilter, fn($q) => $q->where('topic_id', $this->topicFilter))
            ->when($this->searchQuery, fn($q) => $q->where('title', 'like', "%{$this->searchQuery}%"))
            ->inRandomOrder()
            ->limit($effectiveTotalRounds)
            ->get();

        // Clear existing selections
        $this->roundCategories = [];

        // Assign categories to rounds
        foreach ($available as $index => $category) {
            $roundNumber = $index + 1;
            if ($roundNumber > $effectiveTotalRounds) break;

            Round::updateOrCreate(
                ['game_id' => $this->game->id, 'round_number' => $roundNumber],
                ['category_id' => $category->id]
            );
            $this->roundCategories[$roundNumber] = $category->id;
        }

        // Remove any rounds beyond the effective total
        $this->game->rounds()
            ->where('round_number', '>', $effectiveTotalRounds)
            ->delete();

        $this->game->refresh();
        $this->checkReady();
    }

    public function refreshPlayerStatus(): void
    {
        // Refresh player data and broadcast to presentation if join code active
        $this->game->load('players');

        if ($this->game->join_code) {
            $this->broadcastLobbyState();
        }

        // Re-check ready status in case player count changed
        $this->checkReady();
    }

    public function checkReady(): void
    {
        // Calculate effective counts based on active players
        $this->game->load('players');
        $activePlayers = $this->game->players->filter(fn($p) => $p->isActive());
        $effectivePlayerCount = $activePlayers->count();
        $effectiveTotalRounds = $effectivePlayerCount * ($this->game->rounds_per_player ?? 2);

        // Update game's stored player_count to match active players
        if ($this->game->player_count !== $effectivePlayerCount) {
            $this->game->recalculateFromPlayers();
            $this->game->refresh();
        }

        $hasPlayers = $effectivePlayerCount > 0;
        $roundsReady = $this->game->rounds()->count() >= $effectiveTotalRounds;

        if ($hasPlayers && $roundsReady && $this->game->status === 'draft') {
            $this->game->update(['status' => 'ready']);
        } elseif ((!$hasPlayers || !$roundsReady) && $this->game->status === 'ready') {
            $this->game->update(['status' => 'draft']);
        }
    }

    public function startGame(): void
    {
        if ($this->game->status !== 'ready') {
            return;
        }

        // Generate join code for multiplayer device support
        $joinCode = Game::generateJoinCode();

        $this->game->update([
            'current_round' => 1,
            'join_code' => $joinCode,
        ]);

        $this->game->refresh();

        // Use state machine to start the game
        $this->getStateMachine()->startGame();

        $this->redirect(route('games.control', $this->game), navigate: true);
    }

    public function resetGame(): void
    {
        $this->showResetModal = false;

        // Reset player scores and double usage
        $this->game->players()->update([
            'total_score' => 0,
            'double_used' => false,
        ]);

        // Delete all player answers
        foreach ($this->game->rounds as $round) {
            $round->playerAnswers()->delete();
        }

        // Reset all rounds to pending
        $this->game->rounds()->update([
            'status' => 'pending',
            'current_slide' => 0,
        ]);

        // Reset game status
        $this->game->update([
            'status' => 'ready',
            'current_round' => 1,
        ]);

        $this->game->refresh();

        // Broadcast reset state to presentation
        $this->dispatch('game-reset', gameId: $this->game->id);

        session()->flash('message', 'Game has been reset! All scores cleared.');
    }
}; ?>

<div wire:poll.3s="refreshPlayerStatus">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-8">
            <a href="{{ route('games.index') }}" class="text-slate-400 hover:text-white transition">
                &larr; Back to Games
            </a>
            <div class="flex justify-between items-center mt-4">
                <h1 class="text-3xl font-bold">{{ $game->name }}</h1>
                <span class="text-sm px-3 py-1 rounded
                    @switch($game->status)
                        @case('draft') bg-slate-600 text-slate-300 @break
                        @case('ready') bg-green-600/20 text-green-400 @break
                        @case('playing') bg-blue-600/20 text-blue-400 @break
                    @endswitch
                ">
                    {{ ucfirst($game->status) }}
                </span>
            </div>
        </div>

        @if (session('message'))
            <div class="bg-green-600/20 border border-green-500 text-green-400 px-4 py-3 rounded-lg mb-6">
                {{ session('message') }}
            </div>
        @endif

        @if (session('error'))
            <div class="bg-red-600/20 border border-red-500 text-red-400 px-4 py-3 rounded-lg mb-6">
                {{ session('error') }}
            </div>
        @endif

        <div class="grid lg:grid-cols-2 gap-8">
            <!-- Players Section -->
            @php
                $activePlayers = $game->players->filter(fn($p) => $p->isActive())->sortBy('position');
            @endphp
            <div class="bg-slate-800 rounded-xl p-6 border border-slate-700">
                <h2 class="text-xl font-semibold mb-4">
                    Players ({{ $activePlayers->count() }})
                </h2>

                <!-- Existing Players (drag to reorder) -->
                <div class="space-y-2 mb-6"
                     x-data
                     x-init="
                        if (typeof Sortable !== 'undefined') {
                            Sortable.create($el, {
                                animation: 150,
                                ghostClass: 'opacity-50',
                                handle: '.drag-handle',
                                onEnd: function(evt) {
                                    const items = Array.from(evt.to.children).map(el => el.dataset.playerId);
                                    $wire.reorderPlayers(items);
                                }
                            });
                        }
                     "
                     wire:key="player-list-{{ $activePlayers->pluck('id')->join('-') }}">
                    @forelse($activePlayers as $player)
                        <div class="flex items-center justify-between bg-slate-900 rounded-lg px-4 py-3 group"
                             data-player-id="{{ $player->id }}">
                            <div class="flex items-center gap-3">
                                <div class="drag-handle cursor-grab active:cursor-grabbing text-slate-600 hover:text-slate-400 transition">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"/>
                                    </svg>
                                </div>
                                <div class="w-4 h-4 rounded-full" style="background-color: {{ $player->color }}"></div>
                                <span class="font-medium">{{ $player->name }}</span>
                            </div>
                            <button wire:click="removePlayer('{{ $player->id }}')"
                                    class="text-red-400 hover:text-red-300 text-sm opacity-0 group-hover:opacity-100 transition">
                                Remove
                            </button>
                        </div>
                    @empty
                        <p class="text-slate-400 text-sm">No players added yet.</p>
                    @endforelse

                    <!-- Disconnected Players (orphaned) -->
                    @if($disconnectedPlayers->count() > 0)
                        <div class="mt-4 pt-4 border-t border-slate-700">
                            <p class="text-yellow-400 text-sm mb-2">⚠ Disconnected players (will rejoin if game starts):</p>
                            @foreach($disconnectedPlayers as $player)
                                <div class="flex items-center justify-between bg-slate-900/50 rounded-lg px-4 py-3 opacity-60">
                                    <div class="flex items-center gap-3">
                                        <div class="w-4 h-4 rounded-full" style="background-color: {{ $player->color }}"></div>
                                        <span class="font-medium">{{ $player->name }}</span>
                                        <span class="text-xs text-yellow-400">(disconnected)</span>
                                    </div>
                                    <button wire:click="removePlayer('{{ $player->id }}')"
                                            class="text-red-400 hover:text-red-300 text-sm">
                                        Remove
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <!-- Add Player Form -->
                <div class="border-t border-slate-700 pt-4">
                    <h3 class="text-sm font-medium text-slate-300 mb-3">Add Player</h3>
                    <div class="flex gap-3 items-center">
                        <input type="text"
                               wire:model="newPlayerName"
                               placeholder="Player name"
                               class="flex-1 bg-slate-900 border border-slate-600 rounded-lg px-4 py-2 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                               wire:keydown.enter="addPlayer">

                        <input type="color"
                               wire:model.live="newPlayerColor"
                               class="w-10 h-10 cursor-pointer rounded-full border-2 border-slate-600 bg-transparent p-0 [&::-webkit-color-swatch-wrapper]:p-0 [&::-webkit-color-swatch]:rounded-full [&::-webkit-color-swatch]:border-0 [&::-moz-color-swatch]:rounded-full [&::-moz-color-swatch]:border-0">

                        <button wire:click="addPlayer"
                                class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg font-medium transition">
                            Add
                        </button>
                    </div>
                </div>
            </div>

            <!-- Rounds Section -->
            <div class="bg-slate-800 rounded-xl p-6 border border-slate-700">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold">
                        Rounds ({{ $game->rounds->count() }}/{{ $effectiveTotalRounds }})
                    </h2>
                    <div class="flex gap-2">
                        <button wire:click="tryRandomizeAll"
                                class="text-sm bg-slate-700 hover:bg-slate-600 px-3 py-1.5 rounded-lg font-medium transition flex items-center gap-1.5"
                                title="Randomly assign categories to all rounds">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            Randomize All
                        </button>
                        <button wire:click="openCategoryModal"
                                class="text-sm bg-blue-600 hover:bg-blue-700 px-3 py-1.5 rounded-lg font-medium transition">
                            + New Category
                        </button>
                    </div>
                </div>

                <!-- Search -->
                <div class="mb-3">
                    <div class="relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <input type="text"
                               wire:model.live.debounce.200ms="searchQuery"
                               placeholder="Search categories..."
                               class="w-full bg-slate-900 border border-slate-600 rounded-lg pl-10 pr-10 py-2 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        @if($searchQuery || $topicFilter)
                            <button wire:click="clearFilters" 
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-white transition"
                                    title="Clear all filters">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        @endif
                    </div>
                </div>

                <!-- Topic Pills -->
                <div class="flex flex-wrap gap-2 mb-4">
                    <button wire:click="$set('topicFilter', null)"
                            class="px-3 py-1 text-sm rounded-full transition {{ $topicFilter === null ? 'bg-blue-600 text-white' : 'bg-slate-700 text-slate-300 hover:bg-slate-600' }}">
                        All Topics
                    </button>
                    @foreach($topics as $topic)
                        <button wire:click="$set('topicFilter', '{{ $topic->id }}')"
                                class="px-3 py-1 text-sm rounded-full transition {{ $topicFilter === $topic->id ? 'bg-blue-600 text-white' : 'bg-slate-700 text-slate-300 hover:bg-slate-600' }}">
                            {{ $topic->name }}
                        </button>
                    @endforeach
                </div>

                @if($categories->isEmpty())
                    <div class="bg-yellow-900/20 border border-yellow-700 text-yellow-400 px-4 py-3 rounded-lg mb-4">
                        @if($topicFilter || $searchQuery)
                            No categories match your filters.
                            <button wire:click="clearFilters" class="underline">Clear filters</button>
                        @else
                            No categories yet. <button wire:click="openCategoryModal" class="underline">Create one</button> to get started.
                        @endif
                    </div>
                @endif

                <div class="space-y-2">
                    @for($i = 1; $i <= $effectiveTotalRounds; $i++)
                        @php
                            $selectedCategoryId = $roundCategories[$i] ?? null;
                            $selectedCategory = $selectedCategoryId ? $categories->firstWhere('id', $selectedCategoryId) : null;
                            $availableCategories = $categories->filter(function($cat) use ($roundCategories, $i, $selectedCategoryId) {
                                if ($cat->id === $selectedCategoryId) return true;
                                return !in_array($cat->id, array_values($roundCategories));
                            });
                        @endphp
                        <div class="flex items-center gap-2 p-2 rounded-lg {{ $selectedCategoryId ? 'bg-slate-900/50' : 'bg-slate-900/30' }}">
                            <div class="w-8 h-8 rounded-lg {{ $selectedCategoryId ? 'bg-green-600/20 text-green-400' : 'bg-slate-700 text-slate-400' }} flex items-center justify-center text-sm font-bold">
                                {{ $i }}
                            </div>
                            <div class="flex-1 relative">
                                <select wire:change="setRoundCategory({{ $i }}, $event.target.value)"
                                        class="w-full bg-slate-800 border {{ $selectedCategoryId ? 'border-slate-600' : 'border-slate-700 border-dashed' }} rounded-lg pl-3 pr-8 py-2 text-sm {{ $selectedCategoryId ? 'text-white' : 'text-slate-400' }} focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent appearance-none cursor-pointer hover:border-slate-500 transition">
                                    <option value="">Select category...</option>
                                    @foreach($availableCategories as $category)
                                        <option value="{{ $category->id }}" {{ $selectedCategoryId === $category->id ? 'selected' : '' }}>
                                            {{ $category->title }}@if($category->topic) · {{ $category->topic->name }}@endif
                                        </option>
                                    @endforeach
                                    <option value="__new__">+ Create new...</option>
                                </select>
                                <div class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none">
                                    <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/>
                                    </svg>
                                </div>
                            </div>
                            <button wire:click="randomCategory({{ $i }})"
                                    class="w-8 h-8 flex items-center justify-center text-slate-500 hover:text-white hover:bg-slate-700 rounded-lg transition group relative"
                                    aria-label="Randomize question">
                                <span class="absolute bottom-full mb-2 left-1/2 -translate-x-1/2 px-2 py-1 text-xs bg-slate-700 text-white rounded whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
                                    Randomize question
                                </span>
                                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="3" width="18" height="18" rx="3"/>
                                    <circle cx="8" cy="8" r="1.5" fill="currentColor"/>
                                    <circle cx="16" cy="8" r="1.5" fill="currentColor"/>
                                    <circle cx="8" cy="16" r="1.5" fill="currentColor"/>
                                    <circle cx="16" cy="16" r="1.5" fill="currentColor"/>
                                </svg>
                            </button>
                        </div>
                    @endfor
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="mt-8 text-center space-y-4">
            <!-- Open Presentation (always available) -->
            <div>
                <a href="{{ route('games.present', $game) }}" target="friction-presentation"
                   wire:click="generateJoinCode"
                   class="inline-block bg-purple-600 hover:bg-purple-700 text-white px-8 py-3 rounded-xl text-lg font-bold transition">
                    Open Presentation
                </a>
                <p class="text-slate-500 text-sm mt-1">Shows join code & QR for players</p>
            </div>

            @if($game->status === 'ready')
                <div>
                    <button wire:click="startGame"
                            class="bg-green-600 hover:bg-green-700 text-white px-12 py-4 rounded-xl text-xl font-bold transition">
                        Start Game
                    </button>
                    <p class="text-slate-400 mt-2">All players and rounds are configured!</p>
                </div>
            @elseif($game->status === 'playing')
                <div class="flex justify-center gap-4">
                    <a href="{{ route('games.control', $game) }}"
                       class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-12 py-4 rounded-xl text-xl font-bold transition">
                        Continue Game
                    </a>
                    <button wire:click="confirmReset"
                            class="bg-red-600/20 hover:bg-red-600/30 text-red-400 border border-red-600/50 px-8 py-4 rounded-xl text-xl font-bold transition">
                        Reset Game
                    </button>
                </div>
            @else
                <div>
                    <button disabled
                            class="bg-slate-600 text-slate-400 px-12 py-4 rounded-xl text-xl font-bold cursor-not-allowed">
                        Start Game
                    </button>
                    <p class="text-slate-400 mt-2">
                        Add players and configure {{ $effectiveTotalRounds }} rounds to start.
                    </p>
                </div>
            @endif
        </div>
    </div>

    <!-- Category Modal -->
    @if($showCategoryModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4">
                <!-- Backdrop -->
                <div class="fixed inset-0 bg-black/70 transition-opacity" wire:click="closeCategoryModal"></div>

                <!-- Modal -->
                <div class="relative bg-slate-800 rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto border border-slate-700">
                    <div class="sticky top-0 bg-slate-800 px-6 py-4 border-b border-slate-700 flex justify-between items-center">
                        <h3 class="text-xl font-bold">Create Category</h3>
                        <button wire:click="closeCategoryModal" class="text-slate-400 hover:text-white text-2xl">&times;</button>
                    </div>

                    <form wire:submit="saveCategory" class="p-6 space-y-6">
                        <!-- Category Info -->
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-2">Title</label>
                                <input type="text"
                                       wire:model="categoryTitle"
                                       placeholder="Top 10 Countries By Population"
                                       class="w-full bg-slate-900 border border-slate-600 rounded-lg px-4 py-2 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                @error('categoryTitle') <span class="text-red-400 text-sm">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-2">Description (optional)</label>
                                <input type="text"
                                       wire:model="categoryDescription"
                                       placeholder="Source: 2024 World Population Review"
                                       class="w-full bg-slate-900 border border-slate-600 rounded-lg px-4 py-2 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-2">Topic (optional)</label>
                                <select wire:model.live="categoryTopicId"
                                        class="w-full bg-slate-900 border border-slate-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="">No topic</option>
                                    @foreach($topics as $topic)
                                        <option value="{{ $topic->id }}">{{ $topic->name }}</option>
                                    @endforeach
                                    <option value="__new__">+ Create new topic...</option>
                                </select>

                                @if($categoryTopicId === '__new__')
                                    <input type="text"
                                           wire:model="newTopicName"
                                           placeholder="New topic name"
                                           class="w-full mt-2 bg-slate-900 border border-slate-600 rounded-lg px-4 py-2 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                @endif
                            </div>
                        </div>

                        <!-- Bulk Paste -->
                        <div class="bg-slate-900/50 rounded-lg p-4 border border-slate-700">
                            <label class="block text-sm font-medium text-slate-300 mb-2">Quick Import</label>
                            <p class="text-xs text-slate-500 mb-2">Paste a list (one per line). Use "Answer: stat" format to include stats.</p>
                            <div class="flex gap-2">
                                <textarea wire:model="bulkAnswers"
                                          rows="3"
                                          placeholder="1. China: 1.4 billion&#10;2. India: 1.4 billion&#10;3. United States: 334 million..."
                                          class="flex-1 bg-slate-900 border border-slate-600 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"></textarea>
                                <button type="button"
                                        wire:click="parseBulkAnswers"
                                        class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-sm font-medium transition self-end">
                                    Import
                                </button>
                            </div>
                        </div>

                        <!-- Top 10 Answers -->
                        <div>
                            <h4 class="text-sm font-medium text-green-400 mb-3">Top 10 Answers (required)</h4>
                            <div class="space-y-2">
                                @for($i = 1; $i <= 10; $i++)
                                    <div class="flex items-center gap-2">
                                        <span class="w-6 text-right text-sm text-green-400 font-medium">{{ $i }}.</span>
                                        <input type="text"
                                               wire:model="categoryAnswers.{{ $i }}"
                                               placeholder="Answer {{ $i }}"
                                               class="flex-1 bg-slate-900 border border-slate-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-green-500">
                                        <input type="text"
                                               wire:model="categoryAnswerStats.{{ $i }}"
                                               placeholder="Stat"
                                               class="w-24 bg-slate-900 border border-slate-600 rounded-lg px-2 py-1.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-green-500">
                                        <button type="button" wire:click="moveAnswerUp({{ $i }})"
                                                class="p-1.5 text-slate-500 hover:text-white hover:bg-slate-700 rounded transition {{ $i === 1 ? 'opacity-30 cursor-not-allowed' : '' }}"
                                                {{ $i === 1 ? 'disabled' : '' }}>
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                        </button>
                                        <button type="button" wire:click="moveAnswerDown({{ $i }})"
                                                class="p-1.5 text-slate-500 hover:text-white hover:bg-slate-700 rounded transition">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </div>
                                @endfor
                            </div>
                            @error('categoryAnswers.*') <span class="text-red-400 text-sm block mt-2">{{ $message }}</span> @enderror
                        </div>

                        <!-- Friction Answers -->
                        <div>
                            <h4 class="text-sm font-medium text-red-400 mb-3">Friction Answers (min 1, -5 pts each)</h4>
                            <div class="space-y-2">
                                @for($i = 11; $i <= 15; $i++)
                                    <div class="flex items-center gap-2">
                                        <span class="w-6 text-right text-sm text-red-400 font-medium">{{ $i }}.</span>
                                        <input type="text"
                                               wire:model="categoryAnswers.{{ $i }}"
                                               placeholder="Friction {{ $i - 10 }}"
                                               class="flex-1 bg-slate-900 border border-slate-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-red-500">
                                        <input type="text"
                                               wire:model="categoryAnswerStats.{{ $i }}"
                                               placeholder="Stat"
                                               class="w-24 bg-slate-900 border border-slate-600 rounded-lg px-2 py-1.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-red-500">
                                        <button type="button" wire:click="moveAnswerUp({{ $i }})"
                                                class="p-1.5 text-slate-500 hover:text-white hover:bg-slate-700 rounded transition">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                        </button>
                                        <button type="button" wire:click="moveAnswerDown({{ $i }})"
                                                class="p-1.5 text-slate-500 hover:text-white hover:bg-slate-700 rounded transition {{ $i === 15 ? 'opacity-30 cursor-not-allowed' : '' }}"
                                                {{ $i === 15 ? 'disabled' : '' }}>
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </div>
                                @endfor
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex justify-end gap-3 pt-4 border-t border-slate-700">
                            <button type="button"
                                    wire:click="closeCategoryModal"
                                    class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg font-medium transition">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg font-medium transition">
                                Create Category
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    <!-- Randomize All Confirmation Modal -->
    @if($showRandomizeModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/70 transition-opacity" wire:click="closeRandomizeModal"></div>

                <div class="relative bg-slate-800 rounded-xl shadow-xl w-full max-w-md border border-slate-700">
                    <div class="px-6 py-4 border-b border-slate-700 flex justify-between items-center">
                        <h3 class="text-lg font-bold">Randomize Categories</h3>
                        <button wire:click="closeRandomizeModal" class="text-slate-400 hover:text-white text-2xl">&times;</button>
                    </div>

                    <div class="p-6">
                        <p class="text-slate-300 mb-6">
                            This will replace all current category selections with random ones. Are you sure you want to continue?
                        </p>

                        <div class="flex justify-end gap-3">
                            <button wire:click="closeRandomizeModal"
                                    class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg font-medium transition">
                                Cancel
                            </button>
                            <button wire:click="randomizeAllCategories"
                                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg font-medium transition">
                                Randomize All
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Reset Game Confirmation Modal -->
    @if($showResetModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/70 transition-opacity" wire:click="closeResetModal"></div>

                <div class="relative bg-slate-800 rounded-xl shadow-xl w-full max-w-md border border-slate-700">
                    <div class="px-6 py-4 border-b border-slate-700 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-red-400">Reset Game</h3>
                        <button wire:click="closeResetModal" class="text-slate-400 hover:text-white text-2xl">&times;</button>
                    </div>

                    <div class="p-6">
                        <p class="text-slate-300 mb-2">
                            Are you sure you want to reset this game?
                        </p>
                        <p class="text-slate-400 text-sm mb-6">
                            All scores and progress will be cleared. This cannot be undone.
                        </p>

                        <div class="flex justify-end gap-3">
                            <button wire:click="closeResetModal"
                                    class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg font-medium transition">
                                Cancel
                            </button>
                            <button wire:click="resetGame"
                                    class="px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg font-medium transition">
                                Reset Game
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

</div>

<script data-navigate-once>
    document.addEventListener('livewire:initialized', function() {
        var gameId = '{{ $game->id }}';
        var channel = new BroadcastChannel('friction-game-' + gameId);

        Livewire.on('game-reset', function(params) {
            console.log('Broadcasting reset');
            channel.postMessage({ type: 'reset' });
        });

        // Listen for state updates from Livewire and forward to presentation
        document.addEventListener('game-state-updated', function(event) {
            var state = event.detail.state || event.detail;
            console.log('[Setup] Broadcasting lobby state:', state);
            channel.postMessage(state);
        });

        // Respond to state requests from presentation
        channel.onmessage = function(event) {
            if (event.data && event.data.type === 'request-state') {
                console.log('[Setup] Presentation requested state');
                var component = Livewire.all()[0];
                if (component && component.$wire.broadcastLobbyState) {
                    component.$wire.broadcastLobbyState();
                }
            }
        };

        // Listen for player joins/leaves via Laravel Echo (from remote devices)
        if (window.Echo) {
            window.Echo.channel('game.' + gameId)
                .listen('.player.joined', function(e) {
                    console.log('[Setup] Player joined via Echo:', e);
                    // Refresh Livewire component to get updated players
                    var component = Livewire.all()[0];
                    if (component && component.$wire) {
                        component.$wire.$refresh().then(function() {
                            // After refresh, broadcast updated state to presentation
                            if (component.$wire.broadcastLobbyState) {
                                component.$wire.broadcastLobbyState();
                            }
                        });
                    }
                })
                .listen('.player.left', function(e) {
                    console.log('[Setup] Player left via Echo:', e);
                    // Refresh Livewire component to get updated players
                    var component = Livewire.all()[0];
                    if (component && component.$wire) {
                        component.$wire.$refresh().then(function() {
                            // After refresh, broadcast updated state to presentation
                            if (component.$wire.broadcastLobbyState) {
                                component.$wire.broadcastLobbyState();
                            }
                        });
                    }
                });
        }
    });
</script>
