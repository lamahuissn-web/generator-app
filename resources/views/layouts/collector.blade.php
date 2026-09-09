<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Generator Manager') — Field Collections</title>
    @vite(['resources/css/app.css'])
    @livewireStyles
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 antialiased">
    <header class="sticky top-0 z-30 border-b border-slate-800 bg-slate-900/95 text-white backdrop-blur">
        <div class="mx-auto flex max-w-xl items-center justify-between px-4 py-2.5">
            <a href="{{ route('collector.dashboard') }}" class="flex items-center gap-2.5">
                <span class="grid h-9 w-9 place-items-center rounded-xl bg-amber-400 text-lg text-slate-900 shadow-sm">⚡</span>
                <span class="leading-tight">
                    <span class="block text-sm font-bold">Generator Manager</span>
                    <span class="block text-[10px] font-medium uppercase tracking-widest text-slate-400">Field Collections</span>
                </span>
            </a>
            <div class="flex items-center gap-2">
                <span class="hidden max-w-32 truncate text-xs text-slate-300 sm:block">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('collector.logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-semibold text-slate-300 transition hover:bg-slate-700 hover:text-white">
                        Logout
                    </button>
                </form>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-xl px-4 pb-20 pt-5">
        @yield('content')
    </main>

    <script>window.livewireScriptConfig = { uri: '/livewire/update', csrf: '{{ csrf_token() }}' };</script>
    @livewireScripts
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (window.Livewire && !window.__lwStarted) {
                window.__lwStarted = true;
                window.Livewire.start();
            }
        });
    </script>
</body>
</html>
