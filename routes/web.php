<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (is_dir(public_path('mobile'))) {
        return redirect('/mobile');
    }

    return view('welcome');
});

Route::get('/mobile/{path?}', function (?string $path = null) {
    $mobileRoot = realpath(public_path('mobile'));

    abort_if($mobileRoot === false, 404, 'Mobile web build is missing. Run: cd frontend && npm run build');

    $requestedPath = ltrim((string) $path, '/');

    if ($requestedPath !== '') {
        $candidate = realpath($mobileRoot.DIRECTORY_SEPARATOR.$requestedPath);

        if (
            $candidate !== false
            && str_starts_with($candidate, $mobileRoot.DIRECTORY_SEPARATOR)
            && is_file($candidate)
        ) {
            if (str_ends_with($candidate, '.html')) {
                return response()->file($candidate, [
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                ]);
            }

            return response()->file($candidate);
        }

        // Do not fallback HTML for missing static assets (e.g., hashed JS/CSS).
        if (preg_match('/\.[a-z0-9]+$/i', $requestedPath)) {
            abort(404, 'Mobile asset not found');
        }
    }

    $fallback = $mobileRoot.DIRECTORY_SEPARATOR.'index.html';

    abort_unless(is_file($fallback), 404, 'Mobile web index is missing. Run: cd frontend && npm run build');

    return response()->file($fallback, [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
})->where('path', '.*');

Route::get('/privacy', function () {
    return view('privacy');
});

Route::get('/support', function () {
    return view('support');
});

Route::get('/account-deletion', function () {
    return view('account-deletion');
});
