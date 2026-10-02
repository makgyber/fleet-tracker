<?php

use Illuminate\Support\Facades\Route;

// Serve the co-located dashboard SPA (built into public/app by the dashboard's
// Vite build) at the site root. Assets live under /app/ (hashed filenames),
// so this just hands back the SPA entry point. The dashboard is a pure
// client-side app with no router, so no history fallback is required.
Route::get('/', function () {
    $index = public_path('app/index.html');

    if (! file_exists($index)) {
        return response(
            'Dashboard build not found. Run `npm run build` in dashboard/ and deploy public/app.',
            503,
        );
    }

    return response()->file($index);
});
