<?php

namespace Khaled\Ticketing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Khaled\Ticketing\Contracts\TicketOwnerResolver;
use Khaled\Ticketing\Models\Ticket;

class DefaultTicketOwnerResolver implements TicketOwnerResolver
{
    public function resolve(Request $request): ?Model
    {
        $ownerConfig = (array) config('ticketing.owner', []);
        $typeKey = (string) data_get($ownerConfig, 'request_keys.type', 'TYPE');
        $idKey = (string) data_get($ownerConfig, 'request_keys.id', 'ID');

        $typeValue = strtolower(trim((string) $request->input($typeKey, '')));
        $ownerId = $request->input($idKey);

        if ($typeValue === '' || $ownerId === null || $ownerId === '') {
            return null;
        }

        $modelClass = $this->resolveModelClass($typeValue, $ownerConfig);

        if ($modelClass === null || !class_exists($modelClass)) {
            return null;
        }

        if (!is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        return $modelClass::query()->find($ownerId);
    }

    public function ownsTicket(Ticket $ticket, Model $owner): bool
    {
        return (string) $ticket->owner_type === $owner::class
            && (string) $ticket->owner_id === (string) $owner->getKey();
    }

    private function resolveModelClass(string $typeValue, array $ownerConfig): ?string
    {
        $typeMap = (array) data_get($ownerConfig, 'type_map', []);

        if (isset($typeMap[$typeValue]) && is_string($typeMap[$typeValue]) && $typeMap[$typeValue] !== '') {
            return $typeMap[$typeValue];
        }

        foreach ($typeMap as $alias => $modelClass) {
            if (!is_string($alias) || !is_string($modelClass)) {
                continue;
            }

            if (strtolower($alias) === $typeValue || strtolower(class_basename($modelClass)) === $typeValue) {
                return $modelClass;
            }
        }

        return class_exists($typeValue) ? $typeValue : null;
    }
}