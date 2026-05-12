<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('NEXUM')
            ->brandLogo(asset('images/eggon_logo_43542.png'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('favicon.ico'))
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->defaultThemeMode(ThemeMode::Light)
            ->darkMode(false)
            ->colors([
                'primary' => Color::hex('#6fa6cf'),
                'info' => Color::hex('#2f678f'),
                'warning' => Color::hex('#f28a52'),
                'success' => Color::hex('#276b4a'),
                'danger' => Color::hex('#b54d45'),
                'gray' => Color::Slate,
            ])
            ->navigation(false)
            ->maxContentWidth(MaxWidth::FiveExtraLarge)
            ->pages([
                Dashboard::class,
            ])
            ->unsavedChangesAlerts()
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
            ]);
    }
}
