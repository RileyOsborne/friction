<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $name = '';
    public string $username = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function register(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $this->name,
            'username' => $this->username,
            'password' => Hash::make($this->password),
        ]);

        Auth::login($user);

        $this->redirect(route('games.index', absolute: false), navigate: true);
    }
}; ?>

<div class="max-w-md mx-auto mt-20 px-4">
    <div class="bg-slate-800 rounded-2xl border border-white/5 p-8 shadow-2xl">
        <h1 class="text-3xl font-title text-center mb-8 uppercase tracking-wider">Register</h1>

        <form wire:submit="register" class="space-y-6">
            <div>
                <label for="name" class="block text-sm font-bold uppercase tracking-widest text-slate-400 mb-2">Display Name</label>
                <input wire:model="name" type="text" id="name" required autofocus
                    class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-blue-500 transition">
                @error('name') <p class="mt-2 text-sm text-red-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="username" class="block text-sm font-bold uppercase tracking-widest text-slate-400 mb-2">Username</label>
                <input wire:model="username" type="text" id="username" required
                    class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-blue-500 transition">
                @error('username') <p class="mt-2 text-sm text-red-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password" class="block text-sm font-bold uppercase tracking-widest text-slate-400 mb-2">Password</label>
                <input wire:model="password" type="password" id="password" required
                    class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-blue-500 transition">
                @error('password') <p class="mt-2 text-sm text-red-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-bold uppercase tracking-widest text-slate-400 mb-2">Confirm Password</label>
                <input wire:model="password_confirmation" type="password" id="password_confirmation" required
                    class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-blue-500 transition">
            </div>

            <button type="submit"
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-black uppercase tracking-widest py-4 rounded-xl shadow-lg shadow-blue-900/20 transition transform active:scale-[0.98]">
                Create Account
            </button>
        </form>

        <p class="mt-8 text-center text-slate-400 text-sm font-medium">
            Already have an account? 
            <a href="{{ route('login') }}" class="text-blue-400 hover:text-blue-300 font-bold underline decoration-blue-500/30 underline-offset-4">Login here</a>
        </p>
    </div>
</div>
