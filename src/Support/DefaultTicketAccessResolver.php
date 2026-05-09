<?php

namespace Khaled\Ticketing\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Khaled\Ticketing\Contracts\TicketAccessResolver;
use Khaled\Ticketing\Models\TicketType;

class DefaultTicketAccessResolver implements TicketAccessResolver
{
    public function canManageTypes(Authenticatable $user): bool
    {
        $access = config('ticketing.access', []);

        return $this->isSuperAdmin($user) || $this->hasPermission($user, (string) data_get($access, 'manage_permission', 'tickets-management.ticket-types.manage'));
    }

    public function isSuperAdmin(Authenticatable $user): bool
    {
        $access = config('ticketing.access', []);

        return $this->hasPermission($user, (string) data_get($access, 'super_admin_permission', 'ticketing.manage-all'));
    }

    public function visibleTypeIdsFor(Authenticatable $user): array
    {
        $access = config('ticketing.access', []);
        $field = (string) data_get($access, 'field', 'access_key');
        $permissions = $this->userPermissions($user);

        return TicketType::query()
            ->whereNull($field)
            ->orWhereIn($field, $permissions)
            ->pluck('id')
            ->all();
    }

    public function canAccessType(Authenticatable $user, TicketType $type): bool
    {
        $access = config('ticketing.access', []);
        $field = (string) data_get($access, 'field', 'access_key');
        $accessKey = (string) data_get($type, $field, '');

        if ($accessKey === '') {
            return true;
        }

        return $this->hasPermission($user, $accessKey);
    }

    private function hasPermission(Authenticatable $user, string $permission): bool
    {
        if ($permission === '' || !method_exists($user, 'can')) {
            return false;
        }

        return (bool) $user->can($permission);
    }

    private function userPermissions(Authenticatable $user): array
    {
        if (method_exists($user, 'getAllPermissions')) {
            return $user->getAllPermissions()->pluck('name')->all();
        }

        if (method_exists($user, 'permissions')) {
            return $user->permissions()->pluck('name')->all();
        }

        return [];
    }
}