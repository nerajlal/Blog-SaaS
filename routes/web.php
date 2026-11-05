<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\BlogController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Api\BlogApiController;

// Public Landing / Redirection
Route::get('/', function () {
    return auth()->check() ? redirect()->route('admin.projects') : view('welcome');
})->name('home');

// Guest Authentication Routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.post');

    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.post');
});

// Authenticated Routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Workspaces
    Route::post('/workspaces/{workspace}/switch', [WorkspaceController::class, 'switchWorkspace'])->name('workspaces.switch');
    Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');

    // Dashboard & Admin Management
    Route::prefix('admin')->group(function () {
        Route::get('/login', fn() => redirect()->route('admin.projects'))->name('admin.login');
        Route::post('/logout', [AuthController::class, 'logout'])->name('admin.logout');

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

        Route::post('/projects/{project}/categories', [CategoryController::class, 'store'])->name('admin.categories.store');
        Route::delete('/projects/{project}/categories/{category}', [CategoryController::class, 'destroy'])->name('admin.categories.destroy');
    });
});

// Public Headless API v1
Route::prefix('api/v1')->group(function () {
    Route::get('/projects/blogs', [BlogApiController::class, 'index']);
});
