<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        // Livewire v4 only auto-starts when `window.livewireScriptConfig` is
        // defined; Filament 5.8 + Livewire 4.4 needs it set before livewire.js
        // runs, plus an explicit start. Without this, lazy widgets stay stuck
        // on "Loading..." and wire:submit forms never submit.
        \Filament\Support\Facades\FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::HEAD_START,
            fn (): string => '<script>window.livewireScriptConfig = ' .
                json_encode(['uri' => '/livewire/update', 'csrf' => csrf_token()]) .
                ';</script>' .
                '<script>document.addEventListener("DOMContentLoaded", function () { if (window.Livewire && ! window.__lwStarted) { window.__lwStarted = true; window.Livewire.start(); } });</script>',
        );

        // Custom CSS for report pages & dashboard widgets (see public/css/panel.css).
        \Filament\Support\Facades\FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::HEAD_START,
            fn (): string => '<link rel="stylesheet" href="'.asset('css/panel.css').'">',
        );

        // Language toggle in the top bar: switches the signed-in user's locale.
        \Filament\Support\Facades\FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::TOPBAR_END,
            fn (): string => view('filament.locale-toggle')->render(),
        );
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->navigationGroups([
                NavigationGroup::make()->label(__('nav.network')),
                NavigationGroup::make()->label(__('nav.billing')),
                NavigationGroup::make()->label(__('nav.finance')),
                NavigationGroup::make()->label(__('nav.system')),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                \App\Http\Middleware\SetLocale::class,
            ]);
    }
}
