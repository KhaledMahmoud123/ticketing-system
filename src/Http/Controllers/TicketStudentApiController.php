<?php

namespace Khaled\Ticketing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Khaled\Ticketing\Contracts\TicketOwnerResolver;
use Khaled\Ticketing\Models\Ticket;
use Khaled\Ticketing\Models\TicketFile;
use Khaled\Ticketing\Models\TicketReply;
use Khaled\Ticketing\Models\TicketType;

class TicketStudentApiController extends Controller
{
    private $ownerResolver;

    public function __construct(TicketOwnerResolver $ownerResolver)
    {
        $this->ownerResolver = $ownerResolver;
    }

    public function types(): JsonResponse
    {
        $types = TicketType::query()
            ->select(['id', 'name', 'description', 'priority', 'access_key'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Ticket types fetched successfully.',
            'data' => ['types' => $types],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $owner = $this->ownerResolver->resolve($request);

        if (!$owner) {
            return response()->json([
                'status' => false,
                'message' => 'Ticket owner could not be resolved for the provided request.',
                'data' => [],
            ], 404);
        }

        $tickets = Ticket::query()
            ->with(['type', 'files', 'replies.sender', 'owner'])
            ->where('owner_type', $owner::class)
            ->where('owner_id', $owner->getKey())
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', (string) $request->input('status'));
            })
            ->latest('id')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Student tickets fetched successfully.',
            'data' => ['tickets' => $tickets->map(fn (Ticket $ticket) => $this->transformTicket($ticket))->values()],
        ]);
    }

    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $owner = $this->ownerResolver->resolve($request);

        if (!$owner || !$this->ownerResolver->ownsTicket($ticket, $owner)) {
            return response()->json([
                'status' => false,
                'message' => 'Ticket not found.',
                'data' => [],
            ], 404);
        }

        $ticket->load(['type', 'files', 'replies.sender', 'owner']);

        return response()->json([
            'status' => true,
            'message' => 'Ticket details fetched successfully.',
            'data' => ['ticket' => $this->transformTicket($ticket)],
        ]);
    }

    public function replies(Request $request, Ticket $ticket): JsonResponse
    {
        $owner = $this->ownerResolver->resolve($request);

        if (!$owner || !$this->ownerResolver->ownsTicket($ticket, $owner)) {
            return response()->json([
                'status' => false,
                'message' => 'Ticket not found.',
                'data' => [],
            ], 404);
        }

        $ticket->load(['replies.sender']);

        return response()->json([
            'status' => true,
            'message' => 'Ticket replies fetched successfully.',
            'data' => [
                'replies' => $ticket->replies->map(function ($reply) {
                    return [
                        'id' => $reply->id,
                        'ticket_id' => $reply->ticket_id,
                        'sender_type' => class_basename($reply->sender_type),
                        'sender_id' => $reply->sender_id,
                        'message' => $reply->message,
                        'is_read' => (bool) $reply->is_read,
                        'created_at' => $reply->created_at,
                    ];
                })->values(),
            ],
        ]);
    }

    public function storeReply(Request $request, Ticket $ticket): JsonResponse
    {
        $owner = $this->ownerResolver->resolve($request);

        if (!$owner || !$this->ownerResolver->ownsTicket($ticket, $owner)) {
            return response()->json([
                'status' => false,
                'message' => 'Ticket not found.',
                'data' => [],
            ], 404);
        }

        $validated = $request->validate([
            'message' => ['required', 'string'],
        ]);

        $reply = TicketReply::query()->create([
            'ticket_id' => $ticket->id,
            'sender_type' => $owner::class,
            'sender_id' => (string) $owner->getKey(),
            'message' => $validated['message'],
            'is_read' => false,
        ]);

        Cache::forget('tickets:unread:admin:' . $ticket->id);

        $reply->load('sender');

        return response()->json([
            'status' => true,
            'message' => 'Reply added successfully.',
            'data' => [
            'reply' => [
                'id' => $reply->id,
                'ticket_id' => $reply->ticket_id,
                'sender_type' => class_basename($reply->sender_type),
                'sender_id' => $reply->sender_id,
                'message' => $reply->message,
                'is_read' => (bool) $reply->is_read,
                'created_at' => $reply->created_at,
            ]
            ],
        ], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $owner = $this->ownerResolver->resolve($request);

        if (!$owner) {
            return response()->json([
                'status' => false,
                'message' => 'Ticket owner could not be resolved for the provided request.',
                'data' => [],
            ], 404);
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

            return response()->json([
                'status' => true,
                'message' => 'Ticket created successfully.',
                'data' => ['ticket' => $this->transformTicket($ticket)],
            ], 201);
        } catch (\Throwable $exception) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to create ticket.',
                'data' => ['error' => $exception->getMessage()],
            ], 500);
        }
    }

    private function transformTicket(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'title' => $ticket->title,
            'body' => $ticket->body,
            'type_id' => $ticket->type_id,
            'type' => $ticket->type,
            'owner_type' => $ticket->owner_type,
            'owner_id' => $ticket->owner_id,
            'owner_label' => $ticket->owner_label,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
            'files' => $ticket->files->map(function (TicketFile $file) {
                $filePath = (string) $file->name;

                return [
                    'id' => $file->id,
                    'ticket_id' => $file->ticket_id,
                    'name' => $filePath,
                    'original_name' => basename($filePath),
                    'type' => $file->type,
                    'url' => $file->url,
                    'created_at' => $file->created_at,
                ];
            })->values(),
            'replies' => $ticket->replies->map(function ($reply) {
                return [
                    'id' => $reply->id,
                    'ticket_id' => $reply->ticket_id,
                    'sender_type' => class_basename($reply->sender_type),
                    'sender_id' => $reply->sender_id,
                    'message' => $reply->message,
                    'is_read' => (bool) $reply->is_read,
                    'created_at' => $reply->created_at,
                ];
            })->values(),
        ];
    }
}
