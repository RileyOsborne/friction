<?php

use App\Models\Game;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

new #[Layout('components.layouts.app')] #[Title('Edit Game')] class extends Component {
    public Game $game;
    public string $name = '';
    public int $playerCount = 2;

    public function mount(Game $game): void
    {
        $this->game = $game;
        $this->name = $game->name;
        $this->playerCount = $game->player_count;
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
        ]);

        $this->game->update([
            'name' => $this->name,
        ]);

        session()->flash('message', 'Game updated successfully.');
        $this->redirect(route('games.show', $this->game), navigate: true);
    }
}; ?>

<div>
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-8">
            <a href="{{ route('games.show', $game) }}" class="text-slate-400 hover:text-white transition">
                &larr; Back to Game
            </a>
            <h1 class="text-3xl font-bold mt-4">Edit Game</h1>
        </div>

        <form wire:submit="save" class="space-y-6">
            <div class="bg-slate-800 rounded-xl p-6 border border-slate-700">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Game Name</label>
                    <input type="text"
                           wire:model="name"
                           class="w-full bg-slate-900 border border-slate-600 rounded-lg px-4 py-3 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    @error('name') <span class="text-red-400 text-sm">{{ $message }}</span> @enderror
                </div>

                <div class="mt-4 text-slate-400 text-sm">
                    <p>Players: {{ $game->player_count }}</p>
                    <p>Total Rounds: {{ $game->total_rounds }}</p>
                    <p class="text-yellow-400 mt-2">Player count cannot be changed after creation.</p>
                </div>
            </div>

            <div class="flex justify-end gap-4">
                <a href="{{ route('games.show', $game) }}"
                   class="px-6 py-3 bg-slate-700 hover:bg-slate-600 rounded-lg font-semibold transition">
                    Cancel
                </a>
                <button type="submit"
                        class="px-8 py-3 bg-blue-600 hover:bg-blue-700 rounded-lg font-semibold transition">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
