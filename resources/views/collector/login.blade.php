<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Collector Login — Generator Manager</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <div class="flex min-h-screen items-center justify-center bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950 px-4">
        <div class="w-full max-w-sm">
            <div class="mb-8 text-center">
                <span class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-amber-400 text-3xl text-slate-900 shadow-lg shadow-amber-400/20">⚡</span>
                <h1 class="mt-4 text-xl font-bold tracking-tight">Generator Manager</h1>
                <p class="mt-1 text-sm text-slate-400">Collector sign in — تسجيل دخول المحصل</p>
            </div>

            <div class="rounded-2xl bg-white p-6 text-slate-800 shadow-2xl">
                @if ($errors->any())
                    <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('collector.login.attempt') }}" class="space-y-4">
                    @csrf
                    <div>
                        <label for="email" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">Email</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                               class="w-full rounded-xl border-0 bg-slate-100 px-4 py-3 text-base ring-1 ring-transparent transition focus:bg-white focus:ring-2 focus:ring-amber-400 focus:outline-none">
                    </div>
                    <div>
                        <label for="password" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">Password</label>
                        <input id="password" type="password" name="password" required autocomplete="current-password"
                               class="w-full rounded-xl border-0 bg-slate-100 px-4 py-3 text-base ring-1 ring-transparent transition focus:bg-white focus:ring-2 focus:ring-amber-400 focus:outline-none">
                    </div>
                    <button type="submit"
                            class="w-full rounded-xl bg-slate-900 py-3.5 text-base font-bold text-white shadow-sm transition hover:bg-slate-800 active:scale-[0.99]">
                        Sign in
                    </button>
                </form>
            </div>

            <p class="mt-6 text-center text-xs text-slate-500">Field collectors use this mobile portal · Admin panel is at /admin</p>
        </div>
    </div>
</body>
</html>
