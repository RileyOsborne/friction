<?php

use App\Models\Game;
use App\Events\GameDeleted;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

new #[Layout('components.layouts.app')] #[Title('Games')] class extends Component {
    use WithPagination;

    public bool $showDeleteModal = false;
    public ?string $gameToDelete = null;
    public ?string $gameToDeleteName = null;
    public int $perPage = 10;

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'games' => Game::query()
                ->withCount('players', 'rounds')
                ->latest()
                ->paginate($this->perPage),
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

    public function viewGame(Game $game): void
    {
        if ($game->status === 'playing') {
            $this->redirect(route('games.control', $game), navigate: true);
        } else {
            $this->redirect(route('games.show', $game), navigate: true);
        }
    }

    public function createGame(): void
    {
        $game = Game::create([
            'name' => 'New Game ' . now()->format('M j, Y'),
            'status' => 'draft',
        ]);

        $this->redirect(route('games.show', $game), navigate: true);
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold">Games</h1>
            <button wire:click="createGame"
               class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-semibold transition">
                + New Game
            </button>
        </div>

        @if (session('message'))
            <div class="bg-green-600/20 border border-green-500 text-green-400 px-4 py-3 rounded-lg mb-6">
                {{ session('message') }}
            </div>
        @endif

        @if($games->isEmpty())
            <div class="bg-slate-800 rounded-xl p-12 text-center">
                <p class="text-slate-400 text-lg mb-4">No games yet.</p>
                <button wire:click="createGame" class="text-blue-400 hover:text-blue-300 font-medium">
                    Create your first game
                </button>
            </div>
        @else
            <div class="bg-slate-800 rounded-xl border border-slate-700 overflow-hidden shadow-sm">
                <table class="w-full">
                    <thead class="bg-slate-900/50 border-b border-slate-700">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-bold text-slate-400 uppercase tracking-widest">Game Name</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-slate-400 uppercase tracking-widest">Status</th>
                            <th class="px-6 py-4 text-center text-xs font-bold text-slate-400 uppercase tracking-widest">Players</th>
                            <th class="px-6 py-4 text-center text-xs font-bold text-slate-400 uppercase tracking-widest">Rounds</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-slate-400 uppercase tracking-widest">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700">
                        @foreach($games as $game)
                            <tr wire:click="viewGame('{{ $game->id }}')" 
                                class="hover:bg-slate-700/30 transition group cursor-pointer">
                                <td class="px-6 py-4">
                                    <div class="font-bold text-white text-lg group-hover:text-blue-400 transition">{{ $game->name }}</div>
                                    <div class="text-xs text-slate-500 font-mono">{{ $game->join_code }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-black uppercase tracking-wider
                                        @switch($game->status)
                                            @case('draft') bg-slate-700 text-slate-400 @break
                                            @case('ready') bg-blue-900/40 text-blue-400 ring-1 ring-blue-500/30 @break
                                            @case('playing') bg-green-900/40 text-green-400 ring-1 ring-green-500/30 @break
                                            @case('completed') bg-purple-900/40 text-purple-400 ring-1 ring-purple-500/30 @break
                                        @endswitch
                                    ">
                                        {{ $game->status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <div class="text-white font-bold">{{ $game->players_count }} / {{ $game->player_count }}</div>
                                    <div class="text-[10px] text-slate-500 uppercase font-black">Joined</div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <div class="text-white font-bold">
                                        @if($game->status === 'playing')
                                            {{ $game->current_round }} / {{ $game->total_rounds }}
                                        @else
                                            {{ $game->rounds_count }} / {{ $game->total_rounds }}
                                        @endif
                                    </div>
                                    <div class="text-[10px] text-slate-500 uppercase font-black">
                                        {{ $game->status === 'playing' ? 'Current' : 'Configured' }}
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-right" wire:click.stop>
                                    <div class="flex justify-end gap-4 items-center">
                                        @if($game->status === 'playing')
                                            <a href="{{ route('games.control', $game) }}"
                                               class="text-green-400 hover:text-green-300 font-bold text-sm uppercase tracking-wider">
                                                Resume
                                            </a>
                                        @elseif($game->status === 'ready')
                                            <button wire:click="startGame('{{ $game->id }}')"
                                                    class="text-green-400 hover:text-green-300 font-bold text-sm uppercase tracking-wider">
                                                Start
                                            </button>
                                            <a href="{{ route('games.show', $game) }}"
                                               class="text-blue-400 hover:text-blue-300 font-bold text-sm uppercase tracking-wider">
                                                Setup
                                            </a>
                                        @elseif($game->status === 'completed')
                                            <a href="{{ route('games.show', $game) }}"
                                               class="text-purple-400 hover:text-purple-300 font-bold text-sm uppercase tracking-wider">
                                                Results
                                            </a>
                                        @else
                                            <a href="{{ route('games.show', $game) }}"
                                               class="text-blue-400 hover:text-blue-300 font-bold text-sm uppercase tracking-wider">
                                                Setup
                                            </a>
                                        @endif
                                        
                                        <button wire:click="confirmDelete('{{ $game->id }}', '{{ addslashes($game->name) }}')"
                                                class="text-slate-500 hover:text-red-400 transition">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <span class="text-sm text-slate-400">
                        Showing {{ $games->firstItem() ?? 0 }} to {{ $games->lastItem() ?? 0 }} of {{ $games->total() }}
                    </span>
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-slate-500">Per page:</span>
                        @foreach([10, 20, 50] as $size)
                            <button wire:click="$set('perPage', {{ $size }})"
                                    class="px-2 py-1 text-sm rounded transition {{ $perPage === $size ? 'bg-blue-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700' }}">
                                {{ $size }}
                            </button>
                        @endforeach
                    </div>
                </div>
                <div>
                    {{ $games->links('vendor.livewire.simple') }}
                </div>
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
