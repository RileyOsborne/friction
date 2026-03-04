<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="bg-slate-950">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700,800,900&display=swap" rel="stylesheet" />
    <link href="https://fonts.bunny.net/css?family=saira-stencil-one:400&display=swap" rel="stylesheet" />

    <!-- Styles & Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    
    <style>
        .font-title { font-family: 'Saira Stencil One', cursive; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="min-h-screen bg-slate-950 bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 text-white antialiased overflow-x-hidden selection:bg-blue-500/30">
    {{ $slot }}
    @livewireScripts
</body>
</html>
