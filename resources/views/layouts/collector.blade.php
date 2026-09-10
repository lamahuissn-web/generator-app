<!DOCTYPE html>
<html class="h-full" lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"
      data-kt-theme="true" data-kt-theme-mode="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Generator Manager')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="{{ asset('metronic/vendors/keenicons/styles.bundle.css') }}">

    @vite(['resources/css/app.css'])
    @livewireStyles

    {{-- Resolve the colour mode before first paint, so a dark-mode collector never
         gets a white flash on every screen change. --}}
    <script>
        (function () {
            var mode = localStorage.getItem('kt-theme')
                || document.documentElement.getAttribute('data-kt-theme-mode')
                || 'light';

            if (mode === 'system') {
                mode = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }

            document.documentElement.classList.add(mode);
        })();
    </script>
</head>
<body class="antialiased text-base text-foreground bg-muted dark:bg-background">
    <div class="flex min-h-full flex-col">
        {{-- App bar: brand, current collector, language, colour mode, sign out --}}
        <header class="sticky top-0 z-10 flex items-stretch shrink-0 border-b border-border bg-background/95 backdrop-blur">
            <div class="kt-container-fluid mx-auto flex max-w-3xl items-center justify-between gap-3 py-2.5">
                <a href="{{ route('collector.dashboard') }}" class="flex items-center gap-2.5">
                    <span class="tile tile-primary size-9 text-lg">
                        <i class="ki-filled ki-flash-circle"></i>
                    </span>
                    <span class="leading-tight">
                        <span class="block text-sm font-semibold text-mono">Generator Manager</span>
                        <span class="block text-2xs font-medium uppercase tracking-widest text-muted-foreground">{{ __('portal.brand_sub') }}</span>
                    </span>
                </a>

                <div class="flex items-center gap-1.5">
                    <span class="hidden max-w-32 truncate text-sm font-medium text-muted-foreground sm:block">{{ auth()->user()->name }}</span>

                    {{-- Two glyphs, one visible: wrap them so Tailwind's hidden/dark:inline-flex
                         lands on the wrapper — keenicons' own `display: inline-flex` is
                         unlayered and would beat a utility applied to the icon itself. --}}
                    <button type="button" class="kt-btn kt-btn-icon kt-btn-ghost" data-kt-theme-switch-toggle
                            aria-label="Theme">
                        <span class="hidden dark:inline-flex"><i class="ki-filled ki-sun"></i></span>
                        <span class="inline-flex dark:hidden"><i class="ki-filled ki-moon"></i></span>
                    </button>

                    <form method="POST" action="{{ route('locale.switch') }}">
                        @csrf
                        <input type="hidden" name="locale" value="{{ app()->isLocale('ar') ? 'en' : 'ar' }}">
                        <button type="submit" class="kt-btn kt-btn-sm kt-btn-outline">
                            {{ app()->isLocale('ar') ? 'EN' : 'عربي' }}
                        </button>
                    </form>

                    <form method="POST" action="{{ route('collector.logout') }}">
                        @csrf
                        <button type="submit" class="kt-btn kt-btn-sm kt-btn-ghost" title="{{ __('portal.logout') }}">
                            <i class="ki-filled ki-exit-right rtl:rotate-180"></i>
                            <span class="hidden sm:inline">{{ __('portal.logout') }}</span>
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main class="grow py-5">
            <div class="kt-container-fluid mx-auto max-w-3xl">
                @yield('content')
            </div>
        </main>
    </div>

    {{-- KTUI: theme switch, modals, selects, toasts and every other kt-* component. --}}
    <script src="{{ asset('metronic/vendors/ktui/ktui.min.js') }}"></script>
    @livewireScripts
</body>
</html>
