<?php

namespace Khaled\Ticketing\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Khaled\Ticketing\Models\TicketType;

interface TicketAccessResolver
{
    public function canManageTypes(Authenticatable $user): bool;

    public function isSuperAdmin(Authenticatable $user): bool;

    public function visibleTypeIdsFor(Authenticatable $user): array;

    public function canAccessType(Authenticatable $user, TicketType $type): bool;
}