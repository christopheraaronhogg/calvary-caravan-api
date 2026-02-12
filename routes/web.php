<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/mobile/{path?}', function (?string $path = null) {
    $mobileRoot = realpath(public_path('mobile'));

    abort_if($mobileRoot === false, 404, 'Mobile web build is missing. Run: cd frontend && npm run build');

    if ($path !== null && $path !== '') {
        $candidate = realpath($mobileRoot.DIRECTORY_SEPARATOR.ltrim($path, '/'));

        if (
            $candidate !== false
            && str_starts_with($candidate, $mobileRoot.DIRECTORY_SEPARATOR)
            && is_file($candidate)
        ) {
            return response()->file($candidate);
        }
    }

    $fallback = $mobileRoot.DIRECTORY_SEPARATOR.'index.html';

    abort_unless(is_file($fallback), 404, 'Mobile web index is missing. Run: cd frontend && npm run build');

    return response()->file($fallback);
})->where('path', '.*');

Route::get('/privacy', function () {
    return view('privacy');
});

Route::get('/support', function () {
    return view('support');
});
