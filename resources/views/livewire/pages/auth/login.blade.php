<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $username = '';
    public string $password = '';
    public bool $remember = false;

    public function login(): void
    {
        $this->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt(['username' => $this->username, 'password' => $this->password], $this->remember)) {
            throw ValidationException::withMessages([
                'username' => __('auth.failed'),
            ]);
        }

        session()->regenerate();

        $this->redirectIntended(default: route('games.index', absolute: false), navigate: true);
    }
}; ?>

<div class="max-w-md mx-auto mt-20 px-4">
    <div class="bg-slate-800 rounded-2xl border border-white/5 p-8 shadow-2xl">
        <h1 class="text-3xl font-title text-center mb-8 uppercase tracking-wider">Login</h1>

        <form wire:submit="login" class="space-y-6">
            <div>
                <label for="username" class="block text-sm font-bold uppercase tracking-widest text-slate-400 mb-2">Username</label>
                <input wire:model="username" type="text" id="username" required autofocus
                    class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-blue-500 transition">
                @error('username') <p class="mt-2 text-sm text-red-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password" class="block text-sm font-bold uppercase tracking-widest text-slate-400 mb-2">Password</label>
                <input wire:model="password" type="password" id="password" required
                    class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-blue-500 transition">
                @error('password') <p class="mt-2 text-sm text-red-500">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center">
                <input wire:model="remember" type="checkbox" id="remember"
                    class="rounded bg-slate-900 border-white/10 text-blue-600 focus:ring-blue-500">
                <label for="remember" class="ml-2 text-sm text-slate-400">Remember me</label>
            </div>

            <button type="submit"
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-black uppercase tracking-widest py-4 rounded-xl shadow-lg shadow-blue-900/20 transition transform active:scale-[0.98]">
                Log In
            </button>
        </form>

        <p class="mt-8 text-center text-slate-400 text-sm font-medium">
            Don't have an account? 
            <a href="{{ route('register') }}" class="text-blue-400 hover:text-blue-300 font-bold underline decoration-blue-500/30 underline-offset-4">Register here</a>
        </p>
    </div>
</div>
