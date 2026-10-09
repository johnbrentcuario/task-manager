<?php

use App\Http\Controllers\Admin\ModificationRequestController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\TaskController as AdminTaskController;
use App\Http\Controllers\Admin\TaskReviewController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AssignedTaskController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // ---- User panel: the tasks assigned to me ----
    Route::get('tasks', [AssignedTaskController::class, 'index'])->name('tasks.index');
    Route::get('tasks/{task}', [AssignedTaskController::class, 'show'])->name('tasks.show');
    Route::post('tasks/{task}/accept', [AssignedTaskController::class, 'accept'])->name('tasks.accept');
    Route::post('tasks/{task}/submit', [AssignedTaskController::class, 'submit'])->name('tasks.submit');
    Route::post('tasks/{task}/modification-requests', [AssignedTaskController::class, 'requestModification'])->name('tasks.modification-requests.store');
    Route::post('tasks/{task}/comments', [AssignedTaskController::class, 'comment'])->name('tasks.comments.store');

    // ---- Admin panel ----
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
        Route::get('tasks/{task}', [TaskReviewController::class, 'show'])
            ->withTrashed()
            ->name('tasks.show');
        Route::put('tasks/{task}', [AdminTaskController::class, 'update'])->name('tasks.update');
        Route::delete('tasks/{task}', [AdminTaskController::class, 'destroy'])->name('tasks.destroy');
        Route::post('tasks/{task}/archive', [AdminTaskController::class, 'archive'])->name('tasks.archive');
        Route::post('tasks/{task}/restore', [AdminTaskController::class, 'restore'])
            ->withTrashed()
            ->name('tasks.restore');

        Route::post('tasks/{task}/approve', [TaskReviewController::class, 'approve'])->name('tasks.approve');
        Route::post('tasks/{task}/request-changes', [TaskReviewController::class, 'requestChanges'])->name('tasks.request-changes');
        Route::post('tasks/{task}/comments', [TaskReviewController::class, 'comment'])->name('tasks.comments.store');

        Route::post('modification-requests/{modificationRequest}/approve', [ModificationRequestController::class, 'approve'])->name('modification-requests.approve');
        Route::post('modification-requests/{modificationRequest}/decline', [ModificationRequestController::class, 'decline'])->name('modification-requests.decline');

        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';