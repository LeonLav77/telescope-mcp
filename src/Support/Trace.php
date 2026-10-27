<?php

namespace LloydsDigital\TelescopeMcp\Support;

/**
 * Stack traces as recorded by Telescope: exceptions store frames as
 * {file, line}, failed jobs as {file, line, class, function, type}.
 *
 * The app's own frames are what explain an error, so they come first and
 * vendor frames are only counted unless the full trace is asked for. Paths
 * are made relative to the project root of the server that recorded them,
 * which may differ from this one (e.g. data copied from production).
 */
final class Trace
{
    /** Entry points every trace ends in; never the interesting frame. */
    private const ENTRY_POINTS = ['artisan', 'public/index.php', 'server.php'];

    private const MAX_APP_FRAMES = 15;

    /**
     * @param  array<string, mixed>  $exception  Content with trace (and file/line for exception entries).
     * @return array<string, mixed>
     */
    public static function present(array $exception, bool $full): array
    {
        $frames = self::frames($exception['trace'] ?? null);
        $root = self::root($frames, $exception['file'] ?? null);

        // Failed jobs record no file; their thrown location is the first frame.
        $thrown = is_string($exception['file'] ?? null) ? $exception['file'] : (string) ($frames[0]['file'] ?? '');

        // The preview of a framework file (e.g. Connection.php) explains nothing.
        if (! $full && self::isVendor($thrown)) {
            unset($exception['line_preview']);
        }

        if (is_string($exception['file'] ?? null)) {
            $exception['file'] = self::relative($exception['file'], $root);
        }

        if ($frames === []) {
            return $exception;
        }

        $appFrames = self::appFrames($frames, $root);
        $vendor = count(array_filter($frames, fn ($frame) => self::isVendor($frame['file'] ?? '')));

        $exception['app_frames'] = $appFrames ?: 'none (every frame is in vendor/)';
        $exception['trace'] = $full
            ? array_map(fn ($frame) => self::format($frame, $root), $frames)
            : count($frames)." frames ({$vendor} in vendor/). Pass include=[\"trace\"] for all of them.";

        return $exception;
    }

    /**
     * "app/Models/User.php:433" for the first frame in the app's own code,
     * or the thrown location when there is none.
     *
     * @param  array<string, mixed>  $exception
     */
    public static function location(array $exception): ?string
    {
        $file = $exception['file'] ?? null;
        $frames = self::frames($exception['trace'] ?? null);

        if (is_string($file) && $file !== '' && ! self::isVendor($file)) {
            return basename($file).':'.($exception['line'] ?? '?');
        }

        foreach ($frames as $frame) {
            if (self::isApp($frame)) {
                return basename($frame['file']).':'.($frame['line'] ?? '?');
            }
        }

        return is_string($file) && $file !== '' ? basename($file).':'.($exception['line'] ?? '?') : null;
    }

    /**
     * @param  list<array<string, mixed>>  $frames
     * @return list<string>
     */
    private static function appFrames(array $frames, ?string $root): array
    {
        $app = array_values(array_filter($frames, fn ($frame) => self::isApp($frame)));
        $lines = array_map(fn ($frame) => self::format($frame, $root), array_slice($app, 0, self::MAX_APP_FRAMES));

        if (count($app) > self::MAX_APP_FRAMES) {
            $lines[] = '… +'.(count($app) - self::MAX_APP_FRAMES).' more app frames';
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private static function format(array $frame, ?string $root): string
    {
        $where = isset($frame['file']) ? self::relative((string) $frame['file'], $root).':'.($frame['line'] ?? '?') : '[internal]';

        if (isset($frame['function'])) {
            $call = (isset($frame['class']) ? class_basename((string) $frame['class']).($frame['type'] ?? '->') : '').$frame['function'].'()';

            return "{$where} → {$call}";
        }

        return $where;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function frames(mixed $trace): array
    {
        if (! is_array($trace)) {
            return [];
        }

        return array_values(array_filter($trace, fn ($frame) => is_array($frame) && $frame !== []));
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private static function isApp(array $frame): bool
    {
        $file = self::normalize((string) ($frame['file'] ?? ''));

        if ($file === '' || self::isVendor($file)) {
            return false;
        }

        foreach (self::ENTRY_POINTS as $entryPoint) {
            if ($file === $entryPoint || str_ends_with($file, '/'.$entryPoint)) {
                return false;
            }
        }

        return true;
    }

    private static function isVendor(string $file): bool
    {
        return str_contains(self::normalize($file), '/vendor/');
    }

    /**
     * The recording server's project root: whatever precedes "/vendor/" in
     * any frame, else this app's base path.
     *
     * @param  list<array<string, mixed>>  $frames
     */
    private static function root(array $frames, mixed $file): ?string
    {
        $files = array_merge([is_string($file) ? $file : ''], array_map(fn ($frame) => (string) ($frame['file'] ?? ''), $frames));

        foreach ($files as $path) {
            $path = self::normalize($path);

            if (($position = strpos($path, '/vendor/')) !== false) {
                return substr($path, 0, $position + 1);
            }
        }

        return function_exists('base_path') ? rtrim(self::normalize(base_path()), '/').'/' : null;
    }

    private static function relative(string $file, ?string $root): string
    {
        $file = self::normalize($file);

        return $root !== null && str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }

    private static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
