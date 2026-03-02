<?php

use App\Models\Game;
use App\Events\GameDeleted;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

new #[Layout('components.layouts.app')] #[Title('Games')] class extends Component {
    public bool $showDeleteModal = false;
    public ?string $gameToDelete = null;
    public ?string $gameToDeleteName = null;

    public function with(): array
    {
        return [
            'games' => Game::query()
                ->withCount('players', 'rounds')
                ->latest()
                ->get(),
        ];
    }

    public function confirmDelete(string $gameId, string $gameName): void
    {
        $this->gameToDelete = $gameId;
        $this->gameToDeleteName = $gameName;
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->gameToDelete = null;
        $this->gameToDeleteName = null;
    }

    public function deleteGame(): void
    {
        if (!$this->gameToDelete) return;

        $game = Game::find($this->gameToDelete);
        if (!$game) {
            $this->cancelDelete();
            return;
        }

        // Broadcast deletion event before deleting so connected clients can react
        event(new GameDeleted($game->id));

        $game->delete();
        $this->cancelDelete();
        session()->flash('message', 'Game deleted successfully.');
    }

    public function startGame(Game $game): void
    {
        if ($game->status !== 'ready') {
            return;
        }

        $game->update([
            'status' => 'playing',
            'current_round' => 1,
        ]);

        // Set first round to intro
        $game->rounds()->where('round_number', 1)->update(['status' => 'intro']);

        $this->redirect(route('games.control', $game), navigate: true);
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold">Games</h1>
            <a href="{{ route('games.create') }}"
               class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-semibold transition">
                + New Game
            </a>
        </div>

        @if (session('message'))
            <div class="bg-green-600/20 border border-green-500 text-green-400 px-4 py-3 rounded-lg mb-6">
                {{ session('message') }}
            </div>
        @endif

        @if($games->isEmpty())
            <div class="bg-slate-800 rounded-xl p-12 text-center">
                <p class="text-slate-400 text-lg mb-4">No games yet.</p>
                <a href="{{ route('games.create') }}" class="text-blue-400 hover:text-blue-300">
                    Create your first game
                </a>
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($games as $game)
                    <div class="bg-slate-800 rounded-xl p-6 border border-slate-700 hover:border-slate-600 transition">
                        <div class="flex justify-between items-start mb-4">
                            <h2 class="text-xl font-semibold">{{ $game->name }}</h2>
                            <span class="text-sm px-2 py-1 rounded
                                @switch($game->status)
                                    @case('draft') bg-slate-600 text-slate-300 @break
                                    @case('ready') bg-blue-600/20 text-blue-400 @break
                                    @case('playing') bg-green-600/20 text-green-400 @break
                                    @case('completed') bg-purple-600/20 text-purple-400 @break
                                @endswitch
                            ">
                                {{ ucfirst($game->status) }}
                            </span>
                        </div>

                        <div class="text-slate-400 text-sm space-y-1 mb-4">
                            <p>{{ $game->players_count }} / {{ $game->player_count }} players</p>
                            <p>{{ $game->rounds_count }} / {{ $game->total_rounds }} rounds configured</p>
                            @if($game->status === 'playing')
                                <p>Currently on round {{ $game->current_round }}</p>
                            @endif
                        </div>

                        <div class="flex justify-between items-center pt-4 border-t border-slate-700">
                            <div class="flex gap-4">
                                @if($game->status === 'playing')
                                    <a href="{{ route('games.control', $game) }}"
                                       class="text-green-400 hover:text-green-300 font-medium">
                                        Continue
                                    </a>
                                @elseif($game->status === 'ready')
                                    <button wire:click="startGame('{{ $game->id }}')"
                                            class="text-green-400 hover:text-green-300 font-medium">
                                        Play
                                    </button>
                                    <a href="{{ route('games.show', $game) }}"
                                       class="text-blue-400 hover:text-blue-300 font-medium">
                                        Setup
                                    </a>
                                @elseif($game->status === 'completed')
                                    <a href="{{ route('games.show', $game) }}"
                                       class="text-purple-400 hover:text-purple-300 font-medium">
                                        View Results
                                    </a>
                                @else
                                    <a href="{{ route('games.show', $game) }}"
                                       class="text-blue-400 hover:text-blue-300 font-medium">
                                        Setup
                                    </a>
                                @endif
                            </div>
                            <button wire:click="confirmDelete('{{ $game->id }}', '{{ addslashes($game->name) }}')"
                                    class="text-red-400 hover:text-red-300 font-medium">
                                Delete
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <!-- Delete Game Confirmation Modal -->
    @if($showDeleteModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/70 transition-opacity" wire:click="cancelDelete"></div>

                <div class="relative bg-slate-800 rounded-xl shadow-xl w-full max-w-md border border-slate-700">
                    <div class="px-6 py-4 border-b border-slate-700 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-red-400">Delete Game</h3>
                        <button wire:click="cancelDelete" class="text-slate-400 hover:text-white text-2xl">&times;</button>
                    </div>

                    <div class="p-6">
                        <p class="text-slate-300 mb-2">
                            Are you sure you want to delete <span class="font-semibold text-white">{{ $gameToDeleteName }}</span>?
                        </p>
                        <p class="text-slate-400 text-sm mb-6">
                            This will disconnect any players currently in the game and cannot be undone.
                        </p>

                        <div class="flex justify-end gap-3">
                            <button wire:click="cancelDelete"
                                    class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg font-medium transition">
                                Cancel
                            </button>
                            <button wire:click="deleteGame"
                                    class="px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg font-medium transition">
                                Delete Game
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
