<?php

namespace Khaled\Ticketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Khaled\Ticketing\Models\TicketFile;
use Khaled\Ticketing\Models\TicketReply;
use Khaled\Ticketing\Models\TicketType;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'body',
        'type_id',
        'user_id',
        'owner_type',
        'owner_id',
        'status',
    ];

    protected $appends = [
        'priority',
        'owner_label',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'type_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('ticketing.models.user', \App\Models\User::class), 'user_id');
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class, 'ticket_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(TicketFile::class, 'ticket_id');
    }

    // Get priority from the ticket type
    public function getPriorityAttribute(): string
    {
        return $this->type?->priority ?? 'low';
    }

    public function getOwnerLabelAttribute(): string
    {
        $owner = $this->owner;

        if (!$owner) {
            return 'Unassigned';
        }

        $displayName = data_get($owner, 'full_name')
            ?? data_get($owner, 'name')
            ?? data_get($owner, 'username')
            ?? data_get($owner, 'email');

        if (is_string($displayName) && trim($displayName) !== '') {
            return $displayName;
        }

        return class_basename($owner) . ' #' . $owner->getKey();
    }
}
