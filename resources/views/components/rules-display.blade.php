@props([
    'presentation' => false,
    'game' => null,
])

@php
    // Use game config or defaults
    $topAnswersCount = $game?->top_answers_count ?? 10;
    $frictionPenalty = $game?->friction_penalty ?? -5;
    $notOnListPenalty = $game?->not_on_list_penalty ?? -3;
    $doubleMultiplier = $game?->double_multiplier ?? 2;
    $doublesPerPlayer = $game?->doubles_per_player ?? 1;
    $maxAnswersPerCategory = $game?->max_answers_per_category ?? 15;
@endphp

<div class="{{ $presentation ? 'text-center' : '' }}">
    @if($presentation)
        <h1 class="text-6xl font-title mb-8">
            <span class="inline-flex items-baseline"><span class="text-white">FRIC</span><span class="text-red-500 ml-[0.04em]">TION</span></span>
        </h1>
    @endif

    <div class="grid md:grid-cols-3 gap-{{ $presentation ? '8' : '6' }} {{ $presentation ? 'text-left' : '' }}">
        <div class="{{ $presentation ? 'bg-slate-800/50 rounded-2xl p-6' : 'bg-green-900/30 rounded-lg p-4 border border-green-700' }}">
            <h3 class="{{ $presentation ? 'text-2xl' : 'text-lg' }} font-bold text-green-400 mb-{{ $presentation ? '4' : '2' }}">
                {{ $presentation ? 'Scoring' : 'Top ' . $topAnswersCount . ' Answers' }}
            </h3>
            <p class="{{ $presentation ? 'text-xl' : '' }} text-slate-300">
                #1 = <strong>1 point</strong><br>
                #2 = <strong>2 points</strong><br>
                ...<br>
                #{{ $topAnswersCount }} = <strong>{{ $topAnswersCount }} points</strong>
            </p>
        </div>

        <div class="{{ $presentation ? 'bg-slate-800/50 rounded-2xl p-6' : 'bg-red-900/30 rounded-lg p-4 border border-red-700' }}">
            <h3 class="{{ $presentation ? 'text-2xl' : 'text-lg' }} font-bold text-red-400 mb-{{ $presentation ? '4' : '2' }}">
                {{ $presentation ? 'Friction!' : 'Friction Answers' }}
            </h3>
            <p class="{{ $presentation ? 'text-xl' : '' }} text-slate-300">
                Answers <strong>#{{ $topAnswersCount + 1 }}-{{ $maxAnswersPerCategory }}</strong><br>
                <span class="text-red-400 font-bold">{{ $frictionPenalty }} points each!</span>
            </p>
            <p class="{{ $presentation ? 'text-lg mt-2' : 'mt-2' }} text-slate-400">
                Not on list = <span class="{{ $presentation ? '' : 'text-yellow-400 font-bold' }}">{{ $notOnListPenalty }} pts</span>
            </p>
        </div>

        <div class="{{ $presentation ? 'bg-slate-800/50 rounded-2xl p-6' : 'bg-slate-700/50 rounded-lg p-4 border border-slate-600' }}">
            <h3 class="{{ $presentation ? 'text-2xl' : 'text-lg' }} font-bold text-yellow-400 mb-{{ $presentation ? '4' : '2' }}">{{ $doubleMultiplier }}x Double</h3>
            <p class="{{ $presentation ? 'text-xl' : '' }} text-slate-300">
                Each player can use their <strong>{{ $doubleMultiplier }}x</strong> multiplier on <strong>{{ $doublesPerPlayer === 1 ? 'ONE answer' : $doublesPerPlayer . ' answers' }}</strong> per game
            </p>
        </div>
    </div>

    <p class="{{ $presentation ? 'text-3xl mt-12' : 'text-xl mt-6 text-center' }} text-slate-400">
        Name items closer to <span class="text-green-400 font-bold">#{{ $topAnswersCount }}</span> for more points!
    </p>
</div>
