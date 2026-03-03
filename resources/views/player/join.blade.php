<x-layouts.app title="Join Game">
    <div class="min-h-screen flex items-center justify-center px-4">
        <div class="w-full max-w-sm">
            <div class="text-center mb-8">
                <h1 class="text-5xl font-title tracking-tight">
                    <span class="inline-flex items-baseline"><span class="text-white">FRIC</span><span class="text-red-500 ml-[0.04em]">TION</span></span>
                </h1>
                <p class="text-slate-400 mt-2">Enter the game code to join</p>
            </div>

            <form method="POST" action="{{ route('player.join.find') }}" class="space-y-6">
                @csrf
                <div>
                    <input type="text"
                           name="join_code"
                           placeholder="XXXXXX"
                           maxlength="6"
                           autocomplete="off"
                           autofocus
                           class="w-full text-center text-4xl font-mono tracking-[0.5em] uppercase
                                  bg-slate-800 border-2 border-slate-600 rounded-xl px-6 py-4
                                  focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50
                                  placeholder-slate-600">
                    @error('join_code')
                        <p class="text-red-400 text-center mt-2">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 py-4 rounded-xl font-bold text-xl transition">
                    Join Game
                </button>
            </form>

            <div class="text-center mt-8">
                <a href="{{ route('games.index') }}" class="text-slate-500 hover:text-slate-300 text-sm">
                    Are you the Game Master? Go to dashboard &rarr;
                </a>
            </div>
        </div>
    </div>
</x-layouts.app>
