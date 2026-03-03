<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

new #[Layout('components.layouts.app')] #[Title('Rules')] class extends Component {
    //
}; ?>

<div>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <h1 class="text-4xl font-bold mb-8 text-center">How to Play <span class="font-title"><span class="inline-flex items-baseline"><span class="text-white">FRIC</span><span class="text-red-500 ml-[0.04em]">TION</span></span></span></h1>

        <!-- The Goal -->
        <div class="bg-slate-800 rounded-xl p-6 border border-slate-700 mb-8">
            <h2 class="text-2xl font-semibold mb-4">The Goal</h2>
            <p class="text-slate-300 text-lg">
                Players try to name items from a <strong>Top 10</strong> list. The twist? You want to name items
                <strong class="text-green-400">closer to #10</strong> than #1, because higher positions score more points!
            </p>
        </div>

        <!-- Scoring Rules -->
        <div class="bg-slate-800 rounded-xl p-6 border border-slate-700 mb-8">
            <h2 class="text-2xl font-semibold mb-4">Scoring</h2>
            <x-rules-display />
        </div>

        <!-- Example -->
        <div class="bg-slate-800 rounded-xl p-6 border border-blue-700/50">
            <h2 class="text-2xl font-semibold mb-4 text-blue-400">Example Round</h2>
            <p class="text-slate-300 mb-4">
                <strong>Category:</strong> "Top 10 Countries by Aerospace Parts Development"
            </p>
            <div class="space-y-2 text-slate-400">
                <p>Player A guesses "United States" &rarr; #1 = <span class="text-green-400">+1 point</span></p>
                <p>Player B guesses "France" &rarr; #5 = <span class="text-green-400">+5 points</span></p>
                <p>Player C guesses "South Korea" &rarr; #10 = <span class="text-green-400">+10 points!</span></p>
                <p>Player D guesses "Brazil" &rarr; #12 (Friction!) = <span class="text-red-400">-5 points</span></p>
            </div>
        </div>

        <!-- Start Playing -->
        <div class="text-center pt-8">
            <a href="{{ route('games.create') }}"
               class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-8 py-4 rounded-xl text-xl font-bold transition">
                Start a Game
            </a>
        </div>
    </div>
</div>
