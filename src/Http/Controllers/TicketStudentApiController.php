<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use App\Models\Student;
use App\Models\Ticket;
use App\Models\TicketFile;
use App\Models\TicketReply;
use App\Models\TicketType;
use App\Models\Instructor;
use App\Models\Parents;
use App\Models\Token;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
            ->with(['type', 'files', 'replies.sender', 'owner'])
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
        $ticket = Ticket::find($id);
        if (!$ticket) {
            return handleResponse(false, 'Ticket not found.', [], 404);
        }
        $owner = $this->resolveTicketOwner();
        if (!$owner || !$this->ticketBelongsToOwner($ticket, $owner)) {
            return handleResponse(false, 'This ticket does not belong to the authenticated user.', [], 404);
        }

        $ticket->load(['type', 'files', 'replies.sender', 'owner']);

        return handleResponse(true, 'Ticket details fetched successfully.', ['ticket' => $this->transformTicket($ticket)], 200);
    }

    public function replies($id): JsonResponse
    {
        $ticket = Ticket::find($id);
        if (!$ticket) {
            return handleResponse(false, 'Ticket not found.', [], 404);
        }
        $owner = $this->resolveTicketOwner();

        if (!$owner || !$this->ticketBelongsToOwner($ticket, $owner)) {
            return handleResponse(false, 'his ticket does not belong to the authenticated user.', [], 404);
        }

        $ticket->load(['replies.sender']);

        return handleResponse(true, 'Ticket replies fetched successfully.', [
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
            })->values()
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
        $ticket = Ticket::find($id);
        if (!$ticket) {
            return handleResponse(false, 'Ticket not found.', [], 404);
        }
        $owner = $this->resolveTicketOwner();

        if (!$owner || !$this->ticketBelongsToOwner($ticket, $owner)) {
            return handleResponse(false, 'Ticket not found.', [], 404);
        }

        $validated = $request->validate([
            'message' => ['required', 'string'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', 'mimes:jpg,png,pdf,docx', 'max:51200'],
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
        $reply = TicketReply::create([
            'ticket_id' => $ticket->id,
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
                    'ticket_id' => $file->ticket_id,
                    'name' => (string) $file->name,
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
        return match ((string) request()->TYPE) {
            Token::TYPE_STUDENT => Student::query()->find(request()->ID),
            Token::TYPE_PARENT => Parents::query()->find(request()->ID),
            Token::TYPE_INSTRUCTOR => Instructor::query()->find(request()->ID),
            Token::TYPE_APPLICANT => Applicant::query()->find(request()->ID),
            default => Student::query()->find(request()->ID),
        };
    }

    private function ticketBelongsToOwner(Ticket $ticket, Model $owner): bool
    {
        return (string) $ticket->owner_type === $owner::class
            && (string) $ticket->owner_id === (string) $owner->getKey();
    }

    private function transformTicket(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'title' => $ticket->title,
            'body' => $ticket->body,
            'type_id' => $ticket->type_id,
            'type' => $ticket->type,
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
                    'message' => $reply->message,
                    'is_read' => (bool) $reply->is_read,
                    'created_at' => $reply->created_at,
                ];
            })->values(),
        ];
    }
}
