<x-layouts.presentation title="Game Not Found">
    <div class="min-h-screen flex items-center justify-center">
        <div class="text-center max-w-2xl mx-auto px-8 animate-fade-in">
            <!-- Friction Logo -->
            <h1 class="text-8xl font-title mb-8">
                <span class="inline-flex items-baseline"><span class="text-white">FRIC</span><span class="text-red-500 ml-[0.04em]">TION</span></span>
            </h1>

            <!-- Icon -->
            <div class="w-32 h-32 mx-auto mb-8 rounded-full bg-slate-800/50 border border-slate-700 flex items-center justify-center">
                <svg class="w-16 h-16 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>

            <!-- Message -->
            <h2 class="text-4xl font-bold mb-4 text-slate-300">Game Not Found</h2>
            <p class="text-xl text-slate-500 mb-12">
                This game has been deleted or no longer exists.
            </p>

            <!-- Suggestion -->
            <div class="bg-slate-800/50 rounded-xl p-6 border border-slate-700">
                <p class="text-slate-400">
                    The Game Master can close this window.
                </p>
            </div>

            <!-- Subtle animation hint -->
            <p class="text-slate-600 text-sm mt-8 animate-pulse-slow">
                You can safely close this tab
            </p>
        </div>
    </div>
</x-layouts.presentation>
