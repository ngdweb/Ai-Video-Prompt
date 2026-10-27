<?php

namespace App\Http\Middleware;

use App\Support\SecureMediaHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProtectAdminMediaUrls
{
    // CONFIG: match your base folder ("upload") and the extensions you serve.
    // The path group allows spaces (file/folder names like "Old Dance") — it is lazy and
    // bounded by quotes/angle-brackets, so it stops at the first real media extension.
    private const MEDIA_PATTERN =
        '/(?:https?:\/\/[^\/\"\'\s<>]+)?\/?upload\/([^\"\'<>]+?\.(png|jpe?g|webp|gif|svg|mp4|mov|avi|wmv|ttf|otf|zip))/i';

    // Click-to-open links (video / downloads) are opened long after page render, so they get a
    // longer token lifetime than immediately-loaded images.
    private const LONG_LIVED_EXTENSIONS = ['mp4', 'mov', 'avi', 'wmv', 'zip'];
    private const LONG_TTL_SECONDS  = 3600; // 1 hour
    private const SHORT_TTL_SECONDS = 30;   // images/fonts (loaded on render)

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Never rewrite the stream route itself.
        if ($request->routeIs('admin.secure-media')) {
            return $response;
        }
        if (!($response instanceof Response)) {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'text/html') && !str_contains($contentType, 'application/json')) {
            return $response;
        }

        $content = $response->getContent();
        if (!is_string($content) || !str_contains($content, 'upload/')) {
            return $response;
        }

        $transformed = preg_replace_callback(self::MEDIA_PATTERN, function ($m) {
            $extension = strtolower($m[2]);
            $ttl = in_array($extension, self::LONG_LIVED_EXTENSIONS, true)
                ? self::LONG_TTL_SECONDS
                : self::SHORT_TTL_SECONDS;

            return SecureMediaHelper::url('upload/' . ltrim(rawurldecode($m[1]), '/'), null, $ttl);
        }, $content);

        if ($transformed !== null) {
            $response->setContent($transformed);
        }
        return $response;
    }
}
