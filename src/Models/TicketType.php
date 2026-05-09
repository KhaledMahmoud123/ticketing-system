<?php

namespace Khaled\Ticketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Khaled\Ticketing\Models\Ticket;

class TicketType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'access_key',
        'priority',
    ];

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'type_id');
    }
}
