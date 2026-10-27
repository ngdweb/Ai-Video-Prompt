<?php

namespace App\Http\Controllers;

use App\Support\SecureMediaHelper;
use Illuminate\Http\Request;

class SecureMediaController extends Controller
{
    public function stream(Request $request)
    {
        $token = $request->query('token');
        if (empty($token) || !is_string($token)) {
            abort(404, 'Media token missing.');
        }

        $decoded = SecureMediaHelper::decryptToken($token);
        if (!$decoded) {
            abort(403, 'Invalid or expired media token.'); // also fires on expiry
        }

        $relativePath = $decoded['path'];
        $thumbWidth   = $decoded['width'] ?? null;

        $realUploadRoot = realpath(public_path('upload')); // CONFIG: base folder
        $realTarget     = realpath(public_path($relativePath));

        if (!$realTarget || !$realUploadRoot || !str_starts_with($realTarget, $realUploadRoot) || !is_file($realTarget)) {
            abort(404, 'Media file not found.');
        }

        $extension = strtolower(pathinfo($realTarget, PATHINFO_EXTENSION));

        // --- Thumbnail (optional: only runs if intervention/image v3 is installed). ---
        $rasterThumbable = in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true);
        $hasIntervention = class_exists(\Intervention\Image\ImageManager::class)
            && class_exists(\Intervention\Image\Drivers\Gd\Driver::class);

        if ($thumbWidth && $rasterThumbable && $hasIntervention) {
            $cacheDir = storage_path('app/secure-thumbs');
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0755, true);
            }
            $cacheFile = $cacheDir . DIRECTORY_SEPARATOR
                . sha1($realTarget . '|' . filemtime($realTarget) . '|w' . $thumbWidth) . '.webp';

            if (!is_file($cacheFile)) {
                try {
                    $managerClass = \Intervention\Image\ImageManager::class;
                    $driverClass  = \Intervention\Image\Drivers\Gd\Driver::class;
                    $encoded = (new $managerClass(new $driverClass()))
                        ->read($realTarget)->scaleDown(width: $thumbWidth)->toWebp(70);
                    file_put_contents($cacheFile, (string) $encoded);
                } catch (\Throwable $e) {
                    $cacheFile = null;
                }
            }
            if ($cacheFile && is_file($cacheFile)) {
                return response()->file($cacheFile, [
                    'Content-Type'           => 'image/webp',
                    'Cache-Control'          => 'private, no-cache, no-store, must-revalidate',
                    'Pragma'                 => 'no-cache',
                    'Expires'                => '0',
                    'X-Content-Type-Options' => 'nosniff',
                    'Content-Disposition'    => 'inline; filename="thumb.webp"',
                ]);
            }
        }
        // --- end thumbnail block ---

        $mimeMap = [
            'webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo',
            'wmv' => 'video/x-ms-wmv', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'zip' => 'application/zip',
        ];
        $mimeType = $mimeMap[$extension] ?? (mime_content_type($realTarget) ?: 'application/octet-stream');

        return response()->file($realTarget, [
            'Content-Type'           => $mimeType,
            'Cache-Control'          => 'private, no-cache, no-store, must-revalidate',
            'Pragma'                 => 'no-cache',
            'Expires'                => '0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => (($extension === 'zip') ? 'attachment' : 'inline') . '; filename="' . basename($realTarget) . '"',
        ]);
    }
}
