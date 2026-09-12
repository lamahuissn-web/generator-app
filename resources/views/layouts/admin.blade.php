<!DOCTYPE html>
<html class="h-full" lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"
      data-kt-theme="true" data-kt-theme-mode="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('mdl.dashboard')) — Generator Manager</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="{{ asset('metronic/vendors/keenicons/styles.bundle.css') }}">

    @vite(['resources/css/app.css'])
    @livewireStyles

    {{-- Resolve the colour mode before first paint so a dark-mode operator never
         gets a white flash while moving between screens. --}}
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
<body class="antialiased flex h-full text-base text-foreground bg-muted dark:bg-background demo1 kt-sidebar-fixed kt-header-fixed"
      x-data="{ sidebarOpen: false }">
    {{-- Sidebar. Off-canvas below lg (see .admin-sidebar in app.css) and fixed from lg up;
         demo1 supplies the width via --sidebar-width and the wrapper's inset. --}}
    <aside id="admin-sidebar" class="admin-sidebar kt-sidebar fixed top-0 bottom-0 start-0 z-30 flex shrink-0 flex-col border-e border-border bg-card"
           :class="{ 'is-open': sidebarOpen }">

        <div class="kt-sidebar-header flex shrink-0 items-center border-b border-border">
            <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2.5 px-5 py-4">
                <span class="tile tile-primary size-9 text-lg">
                    <i class="ki-filled ki-flash-circle"></i>
                </span>
                <span class="leading-tight">
                    <span class="block text-sm font-semibold text-mono">Generator Manager</span>
                    <span class="block text-2xs font-medium uppercase tracking-widest text-muted-foreground">{{ __('portal.brand_sub') }}</span>
                </span>
            </a>
        </div>

        <div class="kt-sidebar-wrapper flex grow overflow-hidden">
            <nav class="kt-sidebar-content flex grow flex-col gap-5 overflow-y-auto px-3.5 py-4" aria-label="{{ __('mdl.dashboard') }}">
                @foreach (\App\Admin\AdminNav::forUser(auth()->user()) as $group)
                    <div class="flex flex-col gap-1">
                        @if (($group['label'] ?? '') !== '')
                            <span class="px-2.5 pb-1 text-2xs font-semibold uppercase tracking-widest text-muted-foreground">
                                {{ $group['label'] }}
                            </span>
                        @endif
                        @foreach ($group['items'] as $item)
                            @php
                                $active = \App\Admin\AdminNav::isActive($item);
                                $built = \App\Admin\AdminNav::exists($item);
                            @endphp

                            @if (! $built)
                                {{-- Listed but not converted yet: shown disabled rather than
                                     hidden, so the panel reads as work in progress instead of
                                     hiding screens the operator expects to find. --}}
                                <span class="flex cursor-not-allowed items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm font-medium text-muted-foreground/60"
                                      title="{{ __('t.soon') }}">
                                    <i class="ki-filled {{ $item['icon'] }} text-base"></i>
                                    <span class="truncate">{{ $item['label'] }}</span>
                                    <span class="kt-badge kt-badge-xs kt-badge-outline ms-auto">{{ __('t.soon') }}</span>
                                </span>
                            @else
                                <a href="{{ route($item['route']) }}"
                                   @class([
                                       'flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm transition',
                                       'bg-primary/10 font-semibold text-amber-700 dark:text-amber-400' => $active,
                                       'font-medium text-secondary-foreground hover:bg-muted hover:text-mono' => ! $active,
                                   ])
                                   @if ($active) aria-current="page" @endif>
                                    <i class="ki-filled {{ $item['icon'] }} text-base {{ $active ? '' : 'text-muted-foreground' }}"></i>
                                    <span class="truncate">{{ $item['label'] }}</span>
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endforeach
            </nav>
        </div>

        {{-- Signed-in operator --}}
        <div class="flex shrink-0 items-center gap-2.5 border-t border-border px-3.5 py-3">
            <span class="tile tile-muted size-9 text-xs font-semibold">
                {{ str(auth()->user()->name)->trim()->explode(' ')->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('') ?: '?' }}
            </span>
            <span class="min-w-0 flex-1 leading-tight">
                <span class="block truncate text-sm font-medium text-mono">{{ auth()->user()->name }}</span>
                <span class="block text-2xs uppercase tracking-wide text-muted-foreground">{{ auth()->user()->role }}</span>
            </span>
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button type="submit" class="kt-btn kt-btn-icon kt-btn-ghost kt-btn-sm" title="{{ __('portal.logout') }}">
                    <i class="ki-filled ki-exit-right rtl:rotate-180"></i>
                </button>
            </form>
        </div>
    </aside>

    {{-- Mobile scrim --}}
    <div class="fixed inset-0 z-20 bg-zinc-950/50 lg:hidden" x-show="sidebarOpen" x-cloak
         @click="sidebarOpen = false" aria-hidden="true"></div>

    <div class="kt-wrapper flex grow flex-col">
        <header class="kt-header fixed top-0 z-10 start-0 end-0 flex items-stretch shrink-0 border-b border-border bg-card/95 backdrop-blur">
            <div class="kt-container-fluid flex items-center justify-between gap-3 py-3">
                <div class="flex items-center gap-2">
                    <button type="button" class="kt-btn kt-btn-icon kt-btn-ghost lg:hidden" @click="sidebarOpen = ! sidebarOpen"
                            aria-label="Menu">
                        <i class="ki-filled ki-burger-menu-2"></i>
                    </button>
                    <div class="flex flex-col leading-tight">
                        <span class="text-base font-semibold text-mono">@yield('heading', __('mdl.dashboard'))</span>
                        @hasSection('subheading')
                            <span class="text-xs text-muted-foreground">@yield('subheading')</span>
                        @endif
                    </div>
                </div>

                <div class="flex items-center gap-1.5">
                    {{-- Global search: jumps straight to the subscribers list, which is the
                         lookup an operator starts from ("whose meter is 2202?"). --}}
                    <form method="GET" action="{{ route('admin.subscribers.index') }}" class="hidden items-center sm:flex">
                        {{-- Global lookup: lands on the subscriber list with the term already
                             applied, which is where an operator starts ("whose meter is 2202?").
                             Placeholder stays short — a long Arabic one is clipped inside a
                             compact field. --}}
                        <div class="kt-input kt-input-sm w-44">
                            <i class="ki-filled ki-magnifier"></i>
                            <input type="search" name="q" value="{{ request('q') }}"
                                   placeholder="{{ __('t.search_name_meter_phone') }}" autocomplete="off">
                        </div>
                    </form>

                    <button type="button" class="kt-btn kt-btn-icon kt-btn-ghost" data-kt-theme-switch-toggle aria-label="Theme">
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
                </div>
            </div>
        </header>

        <main class="grow py-5" id="admin-main">
            <div class="kt-container-fluid">
                @if (session('status'))
                    <div class="kt-alert kt-alert-light kt-alert-success mb-5" role="status">
                        <div class="kt-alert-icon"><i class="ki-filled ki-check-circle text-base"></i></div>
                        <div class="kt-alert-content">
                            <div class="kt-alert-title">{{ session('status') }}</div>
                        </div>
                    </div>
                @endif

                @if (session('error'))
                    <div class="kt-alert kt-alert-light kt-alert-destructive mb-5" role="alert">
                        <div class="kt-alert-icon"><i class="ki-filled ki-information text-base"></i></div>
                        <div class="kt-alert-content">
                            <div class="kt-alert-title">{{ session('error') }}</div>
                        </div>
                    </div>
                @endif

                @yield('content')
            </div>
        </main>
    </div>

    <script src="{{ asset('metronic/vendors/ktui/ktui.min.js') }}"></script>
    @livewireScripts
</body>
</html>
