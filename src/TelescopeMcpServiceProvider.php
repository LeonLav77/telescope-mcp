<?php

namespace LloydsDigital\TelescopeMcp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use LloydsDigital\TelescopeMcp\Console\KeyCommand;
use LloydsDigital\TelescopeMcp\Http\Authenticate;
use LloydsDigital\TelescopeMcp\Server\TelescopeServer;
use LloydsDigital\TelescopeMcp\Support\Key;

class TelescopeMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/telescope-mcp.php', 'telescope-mcp');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([KeyCommand::class]);
        }

        if (! static::serving()) {
            return;
        }

        Route::middleware(Authenticate::class)->group(function () {
            Mcp::web(Key::path(), TelescopeServer::class);

            // Without these, Laravel's router answers the path itself (405 for PUT/PATCH,
            // 200 with an Allow header for OPTIONS) before any middleware runs, which
            // tells a scanner the endpoint exists. Through the middleware they 404.
            Route::match(['PUT', 'PATCH', 'OPTIONS'], Key::path(), fn (Request $request) => throw Authenticate::notFound($request));
        });
    }

    /**
     * The endpoint exists only while enabled and the app has an APP_KEY to derive the access key from.
     */
    public static function serving(): bool
    {
        return (bool) config('telescope-mcp.enabled') && Key::expected() !== null;
    }
}
