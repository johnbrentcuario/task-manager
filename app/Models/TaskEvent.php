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
    public const ARCHIVED = 'archived';
    public const RESTORED = 'restored';

    /**
     * Events an admin causes while setting a task up. They do not count
     * as "someone has acted on this task", so a task that only has these
     * can still be deleted for real.
     */
    public const INERT_TYPES = [
        self::CREATED,
        self::EDITED,
        self::REASSIGNED,
    ];

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