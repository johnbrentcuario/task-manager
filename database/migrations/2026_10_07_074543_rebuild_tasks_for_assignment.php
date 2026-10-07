<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The old personal-tasks table is replaced entirely.
        Schema::dropIfExists('tasks');

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority')->default('medium');
            $table->date('due_date');
            $table->string('status')->default('pending');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['assigned_to', 'status']);
            $table->index('due_date');
        });

        Schema::create('task_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'created_at']);
        });

        Schema::create('modification_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('status')->default('open');
            $table->text('admin_response')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modification_requests');
        Schema::dropIfExists('task_events');
        Schema::dropIfExists('tasks');

        // Restore the old personal-tasks shape.
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('todo');
            $table->string('priority')->default('medium');
            $table->date('due_date')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }
};