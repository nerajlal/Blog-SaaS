<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\BlogController;

Route::get('/', function () {
    return redirect()->route('admin.login');
});

Route::prefix('admin')->group(function () {
    Route::get('/login', [AdminAuthController::class, 'login'])->name('admin.login');
    Route::post('/login', [AdminAuthController::class, 'authenticate'])->name('admin.login.post');
    Route::post('/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

    Route::middleware('auth')->group(function () {
        Route::get('/projects', [ProjectController::class, 'index'])->name('admin.projects');
        Route::post('/projects', [ProjectController::class, 'store'])->name('admin.projects.store');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('admin.project-details');

        Route::get('/projects/{project}/blogs/create', [BlogController::class, 'create'])->name('admin.create-blog');
        Route::post('/projects/{project}/blogs', [BlogController::class, 'store'])->name('admin.blogs.store');
        Route::get('/blogs/{blog}/edit', [BlogController::class, 'edit'])->name('admin.blogs.edit');
        Route::put('/blogs/{blog}', [BlogController::class, 'update'])->name('admin.blogs.update');
        Route::patch('/blogs/{blog}/toggle-status', [BlogController::class, 'toggleStatus'])->name('admin.blogs.toggle-status');
        Route::delete('/blogs/{blog}', [BlogController::class, 'destroy'])->name('admin.blogs.destroy');
        Route::post('/blogs/{blog}/move', [BlogController::class, 'move'])->name('admin.blogs.move');
        Route::post('/projects/{project}/categories', [\App\Http\Controllers\Admin\CategoryController::class, 'store'])->name('admin.categories.store');
        Route::delete('/projects/{project}/categories/{category}', [\App\Http\Controllers\Admin\CategoryController::class, 'destroy'])->name('admin.categories.destroy');
    });
});

Route::prefix('api/v1')->group(function () {
    Route::get('/projects/blogs', [\App\Http\Controllers\Api\BlogApiController::class, 'index']);
});
