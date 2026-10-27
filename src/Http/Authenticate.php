<?php

namespace LloydsDigital\TelescopeMcp\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Telescope\Telescope;
use LloydsDigital\TelescopeMcp\Support\Key;
use LloydsDigital\TelescopeMcp\TelescopeMcpServiceProvider;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class Authenticate
{
    /** Where a load balancer or reverse proxy in front of the app connects from. */
    private const PRIVATE_NETWORKS = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '::1', 'fc00::/7'];

    public function handle(Request $request, Closure $next): Response
    {
        Telescope::stopRecording();

        if (! TelescopeMcpServiceProvider::serving()
            || $request->headers->has('Origin')
            || ($this->httpsRequired() && ! $this->isHttps($request))
            || ! Key::matches($request->bearerToken())) {
            throw self::notFound($request);
        }

        if (strlen((string) $request->getContent()) > (int) config('telescope-mcp.max_body_bytes')) {
            abort(413);
        }

        // One key per app, so one budget per app: a leaked key can't keep the database busy.
        $perMinute = (int) config('telescope-mcp.rate_limit', 120);

        if ($perMinute > 0) {
            if (RateLimiter::tooManyAttempts('telescope-mcp', $perMinute)) {
                abort(429, '', ['Retry-After' => RateLimiter::availableIn('telescope-mcp')]);
            }

            RateLimiter::hit('telescope-mcp', 60);
        }

        return $next($request);
    }

    /**
     * Exactly what Laravel's router throws for a path it doesn't know, so the
     * app's 404 page (which may print the message) renders identically.
     */
    public static function notFound(Request $request): NotFoundHttpException
    {
        return new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
    }

    protected function httpsRequired(): bool
    {
        $required = config('telescope-mcp.require_https');

        if ($required === null || $required === '') {
            return app()->environment('production');
        }

        return filter_var($required, FILTER_VALIDATE_BOOL);
    }

    /**
     * HTTPS as Laravel sees it (which honours the app's TrustProxies), or a
     * load balancer's X-Forwarded-Proto when the request comes from a
     * private network address, as an AWS ALB's does. A client on the
     * internet can't claim HTTPS by sending the header itself.
     */
    protected function isHttps(Request $request): bool
    {
        if ($request->secure()) {
            return true;
        }

        return strtolower((string) $request->headers->get('X-Forwarded-Proto')) === 'https'
            && IpUtils::checkIp((string) $request->server('REMOTE_ADDR'), self::PRIVATE_NETWORKS);
    }
}
