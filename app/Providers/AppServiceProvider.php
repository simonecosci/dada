<?php

namespace App\Providers;

use App\Models\User;
use App\Ssh\OpenSsh\OpenSshShellFactory;
use App\Ssh\RemoteShellFactory;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(RemoteShellFactory::class, OpenSshShellFactory::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureApi();

        Gate::define('admin', fn (User $user): bool => $user->isAdmin());
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

    /**
     * Rate limit and OpenAPI documentation of the REST API (/api/v1).
     */
    protected function configureApi(): void
    {
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));

        // Any authenticated user may read the docs (the `auth` middleware runs first, see config/scramble.php).
        Gate::define('viewApiDocs', fn (User $user): bool => true);

        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
            $openApi->secure(
                SecurityScheme::http('bearer')->setDescription(
                    'Personal access token created in Settings → API tokens, sent as `Authorization: Bearer <token>`.',
                ),
            );
        });
    }
}
