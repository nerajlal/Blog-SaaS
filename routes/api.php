<?php

use App\Http\Controllers\Api\BlogApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:120,1')->group(function () {
    Route::get('/projects/redirect', [\App\Http\Controllers\Api\LinkApiController::class, 'resolve']);
    Route::post('/projects/not-found', [\App\Http\Controllers\Api\LinkApiController::class, 'notFound'])->middleware('throttle:60,1');
    Route::get('/projects/sitemap.xml', [BlogApiController::class, 'sitemap']);
    Route::get('/projects/blogs/{slug}', [BlogApiController::class, 'show']);
    Route::get('/projects/blogs', [BlogApiController::class, 'index']);
});
