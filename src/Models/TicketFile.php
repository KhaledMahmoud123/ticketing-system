<?php

namespace Khaled\Ticketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketFile extends Model
{
    use HasFactory;

    protected $appends = [
        'url',
    ];

    protected $fillable = [
        'ticket_id',
        'name',
        'type',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/' . ltrim((string) $this->name, '/'));
    }
    public function ticketReply()
    {
        return $this->hasOne(TicketReply::class, 'file_id');
    }
}
