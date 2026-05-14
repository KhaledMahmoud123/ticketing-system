<?php

namespace Khaled\Ticketing\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Khaled\Ticketing\Models\Ticket;
use Khaled\Ticketing\Models\TicketFile;
use Khaled\Ticketing\Models\TicketReply;
use Khaled\Ticketing\Models\TicketType;

class TicketStudentApiController extends Controller
{
    public function types(): JsonResponse
    {
        $types = TicketType::query()
            ->select(['id', 'name', 'description', 'priority', 'role_id'])
            ->with('role:id,name')
            ->orderBy('name')
            ->get();

        return handleResponse(true, 'Ticket types fetched successfully.', ['types' => $types], 200);
    }

    public function index(Request $request): JsonResponse
    {
        $owner = $this->resolveTicketOwner();

        if (!$owner) {
            return handleResponse(false, 'Ticket owner could not be resolved for the provided token.', [], 404);
        }

        $tickets = Ticket::query()
            ->with(['type', 'owner'])
            ->where('owner_type', $owner::class)
            ->where('owner_id', $owner->getKey())
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', (string) $request->input('status'));
            })
            ->latest('id')
            ->get();

        return handleResponse(true, 'Student tickets fetched successfully.', ['tickets' => $tickets->map(fn(Ticket $ticket) => $this->transformTicket($ticket))->values()], 200);
    }

    public function show($id): JsonResponse
    {
        $owner = $this->resolveTicketOwner();
        $ticket = Ticket::query()
            ->where('owner_type', $owner::class)
            ->where('owner_id', $owner->getKey())
            ->whereKey($id)
            ->first();
        if (!$ticket) {
            return handleResponse(false, 'Ticket not found.', [], 404);
        }
        if (!$owner || !$this->ticketBelongsToOwner($ticket, $owner)) {
            return handleResponse(false, 'This ticket does not belong to the authenticated user.', [], 404);
        }

        $ticket->load(['type', 'files', 'owner']);

        $perPage = max(1, min((int) request()->input('per_page', 15), 100));

        $repliesPaginator = $ticket->replies()
            ->with(['sender', 'file'])
            ->orderByDesc('id')
            ->paginate($perPage)
            ->appends(request()->query());

        $ticketData = $this->transformTicket($ticket);
        $ticketData['replies'] = collect($repliesPaginator->items())
            ->map(fn ($reply) => $this->transformReply($reply))
            ->values();

        return handleResponse(true, 'Ticket details fetched successfully.', [
            'ticket' => $ticketData,
            'pagination' => [
                'current_page' => $repliesPaginator->currentPage(),
                'last_page' => $repliesPaginator->lastPage(),
                'per_page' => $repliesPaginator->perPage(),
                'total' => $repliesPaginator->total(),
            ],
        ], 200);
    }
    public function replies(Request $request, $id): JsonResponse
    {
        $ticket = Ticket::query()->find($id);
        if (!$ticket) {
            return handleResponse(false, 'Ticket not found.', [], 404);
        }
        $owner = $this->resolveTicketOwner();

        if (!$owner || !$this->ticketBelongsToOwner($ticket, $owner)) {
            return handleResponse(false, 'his ticket does not belong to the authenticated user.', [], 404);
        }

        $ticket->load(['replies.sender', 'replies.file']);

        return handleResponse(true, 'Ticket replies fetched successfully.', [
            'replies' => $ticket->replies->map(fn (TicketReply $reply) => $this->transformReply($reply))->values(),
        ], 200);
    }
    public function store(Request $request): JsonResponse
    {
        $owner = $this->resolveTicketOwner();
        if (!$owner) {
            return handleResponse(false, 'Ticket owner could not be resolved for the provided token.', [], 404);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'type_id' => ['required', 'exists:ticket_types,id'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', 'mimes:jpg,png,pdf,docx', 'max:51200'],
        ]);

        DB::beginTransaction();

        try {
            $oldOpenTicketsCount = Ticket::query()
                ->where('owner_type', $owner::class)
                ->where('owner_id', $owner->getKey())
                ->where('status', 'open')
                ->count();
                if ($oldOpenTicketsCount > 0) {
                    return handleResponse(false, 'You have an open ticket. Please finish it before opening a new one.', [], 403);
                }
            $ticket = Ticket::query()->create([
                'title' => $validated['title'],
                'body' => $validated['body'],
                'type_id' => (int) $validated['type_id'],
                'user_id' => null,
                'owner_type' => $owner::class,
                'owner_id' => $owner->getKey(),
                'status' => 'open',
            ]);

            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    if (!$file->isValid()) {
                        continue;
                    }

                    $originalName = $file->getClientOriginalName();
                    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
                    $extension = $file->getClientOriginalExtension();
                    $storedFileName = str_replace(' ', '_', $baseName . '_' . now()->timestamp . '_' . uniqid() . '.' . $extension);
                    $path = $file->storeAs('tickets/' . $ticket->id, $storedFileName, 'public');

                    TicketFile::query()->create([
                        'ticket_id' => $ticket->id,
                        'name' => $path,
                        'type' => $file->getMimeType() ?: ($file->getClientMimeType() ?: 'application/octet-stream'),
                    ]);
                }
            }

            DB::commit();
            Cache::forget('tickets:unread:admin:' . $ticket->id);

            $ticket->load(['type', 'files', 'replies.sender']);

            return handleResponse(true, 'Ticket created successfully.', ['ticket' => $this->transformTicket($ticket)], 201);
        } catch (\Throwable $exception) {
            DB::rollBack();

            return handleResponse(false, 'Failed to create ticket.', ['error' => $exception->getMessage()], 500);
        }
    }
    public function storeReply(Request $request, $id): JsonResponse
    {
        $file = null;
        $ticket = Ticket::query()->find($id);
        if (!$ticket) {
            return handleResponse(false, 'Ticket not found.', [], 404);
        }
        $owner = $this->resolveTicketOwner();

        if (!$owner || !$this->ticketBelongsToOwner($ticket, $owner)) {
            return handleResponse(false, 'Ticket not found.', [], 404);
        }

        $validated = $request->validate([
            'message' => ['required', 'string'],
            'files' => ['nullable', 'array', 'max:1'],
            'files.*' => ['file', 'mimes:jpg,png,pdf,docx', 'max:10240'],
        ]);
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                if (!$file->isValid()) {
                    continue;
                }

                $originalName = $file->getClientOriginalName();
                $baseName = pathinfo($originalName, PATHINFO_FILENAME);
                $extension = $file->getClientOriginalExtension();
                $storedFileName = str_replace(' ', '_', $baseName . '_' . now()->timestamp . '_' . uniqid() . '.' . $extension);
                $path = $file->storeAs('tickets/' . $ticket->id, $storedFileName, 'public');

                $file = TicketFile::create([
                    'ticket_id' => $ticket->id,
                    'name' => $path,
                    'type' => $file->getMimeType() ?: ($file->getClientMimeType() ?: 'application/octet-stream'),
                ]);
            }
        }
        $ticket->markRepliesAsRead();
        $reply = TicketReply::create([
            'ticket_id' => $ticket->id,
            'file_id' => $file ? $file->id : null,
            'sender_type' => $owner::class,
            'sender_id' => (string) $owner->getKey(),
            'message' => $validated['message'],
            'is_read' => false,
        ]);

        Cache::forget('tickets:unread:admin:' . $ticket->id);

        $reply->load('sender');

        return handleResponse(true, 'Reply added successfully.', [
            'reply' => [
                'id' => $reply->id,
                'ticket_id' => $reply->ticket_id,
                'sender_type' => class_basename($reply->sender_type),
                'sender_id' => $reply->sender_id,
                'message' => $reply->message,
                'is_read' => (bool) $reply->is_read,
                'created_at' => $reply->created_at,
                'files' => $file ? [
                    'id' => $file->id,
                    'original_name' => basename((string) $file->name),
                    'type' => $file->type,
                    'url' => $file->url,
                    'created_at' => $file->created_at,
                ] : null,
            ]
        ], 201);
    }
    private function resolveTicketOwner(): ?Model
    {
        $typeMap = config('ticketing.owner.type_map', []);
        $requestType = (string) request()->TYPE;
        
        if (!isset($typeMap[$requestType])) {
            return null;
        }
        
        $modelClass = $typeMap[$requestType];
        $requestId = request()->ID;
        
        if (!$requestId || !class_exists($modelClass)) {
            return null;
        }
        
        return $modelClass::query()->find($requestId);
    }

    private function ticketBelongsToOwner(Ticket $ticket, Model $owner): bool
    {
        return (string) $ticket->owner_type === $owner::class
            && (string) $ticket->owner_id === (string) $owner->getKey();
    }

    private function transformReply(TicketReply $reply): array
    {
        return [
            'message' => $reply->message,
            'is_read' => (bool) $reply->is_read,
            'sender_type' => class_basename($reply->sender_type),
            'created_at' => $reply->created_at,
            'files' => $reply->relationLoaded('file') && $reply->file ? [
                'original_name' => basename((string) $reply->file->name),
                'type' => $reply->file->type,
                'url' => $reply->file->url,
                'created_at' => $reply->file->created_at,
            ] : null,
        ];
    }

    private function transformTicket(Ticket $ticket): array
    {
        $repliesFilesId = $ticket->replies->pluck('file.id')->filter()->values()->all();
        $data = [
            'id' => $ticket->id,
            'title' => $ticket->title,
            'body' => $ticket->body,
            'owner_label' => $ticket->owner_label,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
            'owner_type' => class_basename($ticket->owner_type),
        ];

        if ($ticket->relationLoaded('type')) {
            $data['type'] = [
                'name' => $ticket->type->name,
                'description' => $ticket->type->description,
                'priority' => $ticket->type->priority,
            ];
        }
        if ($ticket->relationLoaded('files')) {
            $data['files'] = $ticket->files->filter(function ($file) use ($repliesFilesId) {
                return !in_array($file->id, $repliesFilesId);
            })->map(function ($file) {
                return [
                    'original_name' => basename((string) $file->name),
                    'type' => $file->type,
                    'url' => $file->url,
                    'created_at' => $file->created_at,
                ];
            })->values();
        }
        if ($ticket->relationLoaded('replies')) {
            $data['replies'] = $ticket->replies->map(fn (TicketReply $reply) => $this->transformReply($reply))->values();
        }
        return $data;
    }
}
