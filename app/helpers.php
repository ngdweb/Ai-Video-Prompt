<?php

use App\Support\SecureMediaHelper;

if (!function_exists('secure_media')) {
    function secure_media(?string $path, ?int $maxWidth = null): string
    {
        return SecureMediaHelper::url($path, $maxWidth);
    }
}
