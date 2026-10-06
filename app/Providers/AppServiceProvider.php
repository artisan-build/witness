<?php

namespace App\Providers;

use App\Http\Middleware\EnsureSelectedTeam;
use App\Support\SelectedTeam;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUiAuthority;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUserIsAuthenticated;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(SelectedTeam::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::addPersistentMiddleware([
            EnsureUiAuthority::class,
            EnsureUserIsAuthenticated::class,
            EnsureSelectedTeam::class,
        ]);

        RateLimiter::for('signing-public', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by(hash('sha256', $request->ip() ?? 'unknown')));
        RateLimiter::for('verification-public', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by(hash('sha256', $request->ip() ?? 'unknown')));

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
