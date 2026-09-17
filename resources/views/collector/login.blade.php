<!DOCTYPE html>
<html class="h-full" lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"
      data-kt-theme="true" data-kt-theme-mode="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Generator Manager — {{ __('portal.login_sub') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="{{ asset('metronic/vendors/keenicons/styles.bundle.css') }}">

    @vite(['resources/css/app.css'])

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
<body class="antialiased flex h-full text-base text-foreground bg-muted">
    {{-- `flex h-full` on the body is what lets the inner block centre itself: a
         min-h-full child of an auto-height body collapses, which is how the card
         first ended up pinned to the top of the page. --}}
    <div class="flex grow items-center justify-center p-4">
        <div class="w-full max-w-sm">
            <div class="mb-7 text-center">
                <span class="mx-auto grid size-14 place-items-center rounded-xl bg-primary text-2xl text-primary-foreground">
                    <i class="ki-filled ki-flash-circle"></i>
                </span>
                <h1 class="mt-3.5 text-lg font-semibold text-mono">Generator Manager</h1>
                <p class="mt-1 text-sm text-muted-foreground">{{ __('portal.brand_sub') }}</p>
            </div>

            <div class="kt-card">
                <form method="POST" action="{{ route('collector.login.attempt') }}" class="kt-card-content flex flex-col gap-4 p-6">
                    @csrf

                    <div class="flex items-start justify-between gap-2.5">
                        <div class="flex flex-col gap-1">
                            <h2 class="kt-card-title">{{ __('portal.sign_in') }}</h2>
                            <span class="kt-card-description">{{ __('portal.login_sub') }}</span>
                        </div>
                        <button type="button" class="kt-btn kt-btn-icon kt-btn-ghost" data-kt-theme-switch-toggle
                                aria-label="Theme">
                            <span class="hidden dark:inline-flex"><i class="ki-filled ki-sun"></i></span>
                            <span class="inline-flex dark:hidden"><i class="ki-filled ki-moon"></i></span>
                        </button>
                    </div>

                    @if ($errors->any())
                        <div class="kt-alert kt-alert-light kt-alert-destructive" role="alert">
                            <div class="kt-alert-icon"><i class="ki-filled ki-information text-base"></i></div>
                            <div class="kt-alert-content">
                                <div class="kt-alert-title">{{ $errors->first() }}</div>
                            </div>
                        </div>
                    @endif

                    <div class="flex flex-col gap-1">
                        <label for="identity" class="kt-form-label font-normal text-mono">{{ __('portal.username') }}</label>
                        <div class="kt-input">
                            <i class="ki-filled ki-user"></i>
                            <input id="identity" type="text" name="identity" value="{{ old('identity') }}" required autofocus
                                   autocomplete="username" inputmode="text" autocapitalize="off" autocorrect="off" spellcheck="false">
                        </div>
                        <span class="text-xs text-muted-foreground">{{ __('portal.username_hint') }}</span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="password" class="kt-form-label font-normal text-mono">{{ __('portal.password') }}</label>
                        <div class="kt-input">
                            <i class="ki-filled ki-lock-2"></i>
                            <input id="password" type="password" name="password" required
                                   autocomplete="current-password">
                        </div>
                    </div>

                    <button type="submit" class="kt-btn kt-btn-lg w-full justify-center">
                        <i class="ki-filled ki-exit-right rtl:rotate-180"></i>
                        {{ __('portal.sign_in') }}
                    </button>
                </form>
            </div>

            <div class="mt-5 flex items-center justify-center gap-2">
                <span class="text-xs text-muted-foreground">{{ __('portal.footer_note') }}</span>
            </div>
        </div>
    </div>

    <script src="{{ asset('metronic/vendors/ktui/ktui.min.js') }}"></script>
</body>
</html>
