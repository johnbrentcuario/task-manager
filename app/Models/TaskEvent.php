<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskEvent extends Model
{
    public const CREATED = 'created';
    public const EDITED = 'edited';
    public const REASSIGNED = 'reassigned';
    public const ACCEPTED = 'accepted';
    public const SUBMITTED = 'submitted';
    public const APPROVED = 'approved';
    public const CHANGES_REQUESTED = 'changes_requested';
    public const MODIFICATION_REQUESTED = 'modification_requested';
    public const MODIFICATION_APPROVED = 'modification_approved';
    public const MODIFICATION_DECLINED = 'modification_declined';
    public const COMMENTED = 'commented';

    protected $fillable = [
        'task_id',
        'user_id',
        'type',
        'note',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}