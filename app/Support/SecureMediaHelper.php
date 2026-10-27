<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;

class SecureMediaHelper
{
    /** In-memory cache for encrypted paths during a single request. */
    private static array $encryptionCache = [];

    /**
     * Encrypt a relative path into a URL-safe token. Width and expiry are baked INSIDE the
     * encrypted payload (not query params), so a token cannot be tampered to fetch the full-res
     * original or to extend its own lifetime.
     */
    public static function encryptPath(string $relativePath, ?int $maxWidth = null, ?int $ttlSeconds = null): string
    {
        $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');

        $payload = $normalized;
        if ($maxWidth !== null && $maxWidth > 0) {
            $payload .= '|w=' . (int) $maxWidth;
        }
        if ($ttlSeconds !== null && $ttlSeconds > 0) {
            $payload .= '|e=' . (time() + (int) $ttlSeconds); // absolute server-side expiry
        }

        if (isset(self::$encryptionCache[$payload])) {
            return self::$encryptionCache[$payload];
        }

        $encrypted = Crypt::encryptString($payload);
        $token = rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');
        self::$encryptionCache[$payload] = $token;

        return $token;
    }

    /** Decrypt + validate. Returns null if invalid, tampered, traversal, or expired. */
    public static function decryptToken(string $token): ?array
    {
        try {
            $base64 = strtr($token, '-_', '+/');
            $pad = strlen($base64) % 4;
            if ($pad > 0) {
                $base64 .= str_repeat('=', 4 - $pad);
            }

            $payload = Crypt::decryptString(base64_decode($base64));

            // Expiry (server time only — no client clock).
            if (str_contains($payload, '|e=')) {
                [$payload, $rawExpires] = explode('|e=', $payload, 2);
                if (time() > (int) $rawExpires) {
                    return null; // expired → controller responds HTTP 403
                }
            }

            // Thumbnail width.
            $width = null;
            if (str_contains($payload, '|w=')) {
                [$payload, $rawWidth] = explode('|w=', $payload, 2);
                $rawWidth = (int) $rawWidth;
                if ($rawWidth >= 16 && $rawWidth <= 1024) {
                    $width = $rawWidth;
                }
            }

            $path = $payload;

            // Reject traversal.
            if (str_contains($path, '..') || str_contains($path, '\\')) {
                return null;
            }
            $path = ltrim(str_replace('\\', '/', $path), '/');

            // CONFIG: media must live under this base folder.
            if (!str_starts_with($path, 'upload/')) {
                return null;
            }

            return ['path' => $path, 'width' => $width];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Full stream URL for a relative path.
     * $ttlSeconds defaults to 30s; pass null for a non-expiring link.
     */
    public static function url(?string $relativePath, ?int $maxWidth = null, ?int $ttlSeconds = 30): string
    {
        if (empty($relativePath)) {
            return '';
        }

        $token = self::encryptPath($relativePath, $maxWidth, $ttlSeconds);

        if (Route::has('admin.secure-media')) {
            return route('admin.secure-media', ['token' => $token]);
        }
        return url('/secure-media?token=' . $token);
    }
}
