<?php

use App\Http\Controllers\Admin\TaskController as AdminTaskController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('users/{user}/status', [UserController::class, 'status'])->name('users.status');

        Route::get('tasks', [AdminTaskController::class, 'index'])->name('tasks.index');
        Route::get('tasks/create', [AdminTaskController::class, 'create'])->name('tasks.create');
        Route::post('tasks', [AdminTaskController::class, 'store'])->name('tasks.store');
        Route::get('tasks/{task}/edit', [AdminTaskController::class, 'edit'])->name('tasks.edit');
        Route::put('tasks/{task}', [AdminTaskController::class, 'update'])->name('tasks.update');
        Route::delete('tasks/{task}', [AdminTaskController::class, 'destroy'])->name('tasks.destroy');
        Route::post('tasks/{task}/archive', [AdminTaskController::class, 'archive'])->name('tasks.archive');
        Route::post('tasks/{task}/restore', [AdminTaskController::class, 'restore'])
            ->withTrashed()
            ->name('tasks.restore');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';