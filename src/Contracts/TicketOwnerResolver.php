<?php

namespace Khaled\Ticketing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Khaled\Ticketing\Models\Ticket;

interface TicketOwnerResolver
{
    public function resolve(Request $request): ?Model;

    public function ownsTicket(Ticket $ticket, Model $owner): bool;
}