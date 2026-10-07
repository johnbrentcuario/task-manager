<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_CHANGES_REQUESTED = 'changes_requested';
    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_SUBMITTED,
        self::STATUS_CHANGES_REQUESTED,
        self::STATUS_COMPLETED,
    ];

    public const PRIORITIES = ['low', 'medium', 'high'];

    protected $fillable = [
        'title',
        'description',
        'priority',
        'due_date',
        'assigned_to',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'accepted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class)->latest();
    }

    public function modificationRequests(): HasMany
    {
        return $this->hasMany(ModificationRequest::class)->latest();
    }

    /**
     * Overdue is calculated, never stored: the deadline has passed
     * and the task is not completed.
     */
    public function isOverdue(): bool
    {
        return $this->status !== self::STATUS_COMPLETED
            && $this->due_date->lt(today());
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', self::STATUS_COMPLETED)
            ->whereDate('due_date', '<', now()->toDateString());
    }
}