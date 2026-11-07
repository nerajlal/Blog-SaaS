<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\BlogController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\PasswordController;

// Public Landing / Redirection
Route::get('/', function () {
    return auth()->check() ? redirect()->route('admin.projects') : view('welcome');
})->name('home');

Route::view('/docs', 'welcome')->name('docs');

// Guest Authentication Routes
Route::middleware('guest')->group(function () {
    Route::get('/forgot-password', [PasswordController::class, 'forgot'])->name('password.request');
    Route::post('/forgot-password', [PasswordController::class, 'sendLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordController::class, 'update'])->middleware('throttle:5,1')->name('password.update');

    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.post');

    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:3,1')->name('register.post');
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
        Route::get('/search-console/callback', [\App\Http\Controllers\Admin\SearchConsoleController::class, 'callback'])->name('admin.search.callback');
        Route::get('/projects/{project}/search', [\App\Http\Controllers\Admin\SearchConsoleController::class, 'index'])->name('admin.search.index');
        Route::post('/projects/{project}/search/connect', [\App\Http\Controllers\Admin\SearchConsoleController::class, 'connect'])->middleware('throttle:5,1')->name('admin.search.connect');
        Route::post('/projects/{project}/search/properties', [\App\Http\Controllers\Admin\SearchConsoleController::class, 'properties'])->middleware('throttle:10,1')->name('admin.search.properties');
        Route::post('/projects/{project}/search/property', [\App\Http\Controllers\Admin\SearchConsoleController::class, 'select'])->middleware('throttle:10,1')->name('admin.search.select');
        Route::post('/projects/{project}/search/fetch', [\App\Http\Controllers\Admin\SearchConsoleController::class, 'fetch'])->middleware('throttle:5,1')->name('admin.search.fetch');
        Route::post('/projects/{project}/search/inspect', [\App\Http\Controllers\Admin\SearchConsoleController::class, 'inspect'])->middleware('throttle:10,1')->name('admin.search.inspect');
        Route::post('/projects/{project}/search/disconnect', [\App\Http\Controllers\Admin\SearchConsoleController::class, 'disconnect'])->middleware('throttle:5,1')->name('admin.search.disconnect');
        Route::get('/projects/{project}/search/reports/{report}', [\App\Http\Controllers\Admin\SearchConsoleController::class, 'export'])->name('admin.search.export');
        Route::get('/projects/{project}/metadata', [\App\Http\Controllers\Admin\MetadataController::class, 'index'])->name('admin.metadata.index');
        Route::get('/projects/{project}/metadata/export', [\App\Http\Controllers\Admin\MetadataController::class, 'export'])->name('admin.metadata.export');
        Route::post('/projects/{project}/metadata/preview', [\App\Http\Controllers\Admin\MetadataController::class, 'preview'])->middleware('throttle:10,1')->name('admin.metadata.preview');
        Route::post('/projects/{project}/metadata/{batch}/apply', [\App\Http\Controllers\Admin\MetadataController::class, 'apply'])->name('admin.metadata.apply');
        Route::post('/projects/{project}/metadata/{batch}/undo', [\App\Http\Controllers\Admin\MetadataController::class, 'undo'])->name('admin.metadata.undo');
        Route::get('/projects/{project}/not-found', [\App\Http\Controllers\Admin\TelemetryController::class, 'index'])->name('admin.telemetry.index');
        Route::post('/projects/{project}/not-found/key', [\App\Http\Controllers\Admin\TelemetryController::class, 'rotate'])->middleware('throttle:5,1')->name('admin.telemetry.rotate');
        Route::get('/projects/{project}/redirects', [\App\Http\Controllers\Admin\RedirectController::class, 'index'])->name('admin.redirects.index');
        Route::post('/projects/{project}/redirects/preview', [\App\Http\Controllers\Admin\RedirectController::class, 'preview'])->middleware('throttle:10,1')->name('admin.redirects.preview');
        Route::post('/projects/{project}/redirects/remove', [\App\Http\Controllers\Admin\RedirectController::class, 'remove'])->name('admin.redirects.remove');
        Route::get('/projects/{project}/redirects/export', [\App\Http\Controllers\Admin\RedirectController::class, 'export'])->name('admin.redirects.export');
        Route::post('/projects/{project}/redirects/{batch}/apply', [\App\Http\Controllers\Admin\RedirectController::class, 'apply'])->name('admin.redirects.apply');
        Route::post('/projects/{project}/redirects/{batch}/undo', [\App\Http\Controllers\Admin\RedirectController::class, 'undo'])->name('admin.redirects.undo');
        Route::get('/projects/{project}/links', [\App\Http\Controllers\Admin\LinkController::class, 'index'])->name('admin.links.index');
        Route::post('/projects/{project}/links/rebuild', [\App\Http\Controllers\Admin\LinkController::class, 'rebuild'])->middleware('throttle:5,1')->name('admin.links.rebuild');
        Route::post('/projects/{project}/links/check', [\App\Http\Controllers\Admin\LinkController::class, 'check'])->middleware('throttle:10,1')->name('admin.links.check');
        Route::post('/blogs/{blog}/links/insert', [\App\Http\Controllers\Admin\LinkController::class, 'insert'])->middleware('throttle:10,1')->name('admin.links.insert');
        Route::post('/blogs/{blog}/links/{edit}/undo', [\App\Http\Controllers\Admin\LinkController::class, 'undo'])->name('admin.links.undo');
        Route::get('/blogs/{blog}/links', [\App\Http\Controllers\Admin\LinkController::class, 'suggestions'])->name('admin.links.suggestions');
        Route::get('/projects/{project}/seo/health', [\App\Http\Controllers\Admin\SeoAnalysisController::class, 'health'])->name('admin.seo.health');
        Route::post('/projects/{project}/seo/analyze', [\App\Http\Controllers\Admin\SeoAnalysisController::class, 'preview'])->middleware('throttle:90,1')->name('admin.seo.preview');
        Route::post('/projects/{project}/seo/refresh-page', [\App\Http\Controllers\Admin\SeoAnalysisController::class, 'refreshPage'])->middleware('throttle:10,1')->name('admin.seo.refresh-page');
        Route::get('/blogs/{blog}/seo/report', [\App\Http\Controllers\Admin\SeoAnalysisController::class, 'report'])->name('admin.seo.report');
        Route::post('/blogs/{blog}/seo/refresh', [\App\Http\Controllers\Admin\SeoAnalysisController::class, 'refresh'])->middleware('throttle:30,1')->name('admin.seo.refresh');
        Route::post('/blogs/{blog}/seo/review', [\App\Http\Controllers\Admin\SeoAnalysisController::class, 'review'])->name('admin.seo.review');
        Route::post('/blogs/{blog}/seo/mark-reviewed', [\App\Http\Controllers\Admin\SeoAnalysisController::class, 'markReviewed'])->name('admin.seo.mark-reviewed');

        Route::get('/projects/{project}/seo', [\App\Http\Controllers\Admin\ProjectSeoController::class, 'edit'])->name('admin.projects.seo');
        Route::put('/projects/{project}/seo', [\App\Http\Controllers\Admin\ProjectSeoController::class, 'update'])->name('admin.projects.seo.update');
        Route::post('/projects/{project}/seo/verify', [\App\Http\Controllers\Admin\ProjectSeoController::class, 'verify'])->middleware('throttle:5,1')->name('admin.projects.seo.verify');

        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('admin.projects.destroy');
        Route::get('/projects/{project}/integration', [ProjectController::class, 'show'])->name('admin.projects.integration');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('admin.project-details');

        Route::get('/projects/{project}/blogs/create', [BlogController::class, 'create'])->name('admin.create-blog');
        Route::post('/projects/{project}/blogs', [BlogController::class, 'store'])->name('admin.blogs.store');
        Route::get('/blogs/{blog}/autosave', [\App\Http\Controllers\Admin\BlogAutosaveController::class, 'show'])->name('admin.blogs.autosave.show');
        Route::post('/blogs/{blog}/autosave', [\App\Http\Controllers\Admin\BlogAutosaveController::class, 'store'])->middleware('throttle:40,1')->name('admin.blogs.autosave.store');
        Route::delete('/blogs/{blog}/autosave', [\App\Http\Controllers\Admin\BlogAutosaveController::class, 'destroy'])->middleware('throttle:10,1')->name('admin.blogs.autosave.destroy');
        Route::get('/blogs/{blog}/edit', [BlogController::class, 'edit'])->name('admin.blogs.edit');
        Route::put('/blogs/{blog}', [BlogController::class, 'update'])->name('admin.blogs.update');
        Route::patch('/blogs/{blog}/toggle-status', [BlogController::class, 'toggleStatus'])->name('admin.blogs.toggle-status');
        Route::delete('/blogs/{blog}', [BlogController::class, 'destroy'])->name('admin.blogs.destroy');
        Route::post('/blogs/{blog}/move', [BlogController::class, 'move'])->name('admin.blogs.move');

        Route::post('/projects/{project}/categories', [CategoryController::class, 'store'])->name('admin.categories.store');
        Route::delete('/projects/{project}/categories/{category}', [CategoryController::class, 'destroy'])->name('admin.categories.destroy');
    });
});
