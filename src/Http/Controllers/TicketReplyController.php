<?php

namespace Khaled\Ticketing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Khaled\Ticketing\Models\TicketFile;
use Khaled\Ticketing\Models\Ticket;
use Khaled\Ticketing\Models\TicketReply;

class TicketReplyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = TicketReply::query()
            ->with(['ticket', 'sender'])
            ->when($request->filled('ticket_id'), function ($query) use ($request) {
                $query->where('ticket_id', $request->input('ticket_id'));
            })
            ->latest('id')
            ->get();

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $authUserId = Auth::id();
        if (!$authUserId) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $validated = $request->validate([
            'ticket_id' => ['required', 'exists:tickets,id'],
            'message' => ['nullable', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:10240'],
        ]);

        $ticket = Ticket::query()->findOrFail((int) $validated['ticket_id']);

        return $this->createUserReply(
            $ticket,
            (int) $authUserId,
            (string) ($validated['message'] ?? ''),
            $request->file('files', [])
        );
    }

    public function storeForTicket(Request $request, Ticket $ticket): JsonResponse
    {
        $authUserId = Auth::id();
        if (!$authUserId) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $validated = $request->validate([
            'message' => ['nullable', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:10240'],
        ]);

        return $this->createUserReply(
            $ticket,
            (int) $authUserId,
            (string) ($validated['message'] ?? ''),
            $request->file('files', [])
        );
    }

    private function createUserReply(Ticket $ticket, int $authUserId, string $message, array $uploadedFiles = []): JsonResponse
    {
        $hasFiles = count($uploadedFiles) > 0;
        if (trim($message) === '' && !$hasFiles) {
            return response()->json([
                'status' => false,
                'message' => 'Please enter a message or attach at least one file.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $senderModel = config('ticketing.models.user', \App\Models\User::class);

            $reply = TicketReply::create([
                'ticket_id' => $ticket->id,
                'sender_type' => $senderModel,
                'sender_id' => (string) $authUserId,
                'message' => trim($message) !== '' ? $message : 'Attachment',
                'is_read' => true,
            ]);

            foreach ($uploadedFiles as $file) {
                if (!$file || !$file->isValid()) {
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

            TicketReply::query()
                ->where('ticket_id', $ticket->id)
                ->whereIn('sender_type', array_values(config('ticketing.models', [])))
                ->where('is_read', false)
                ->update(['is_read' => true]);

            Cache::forget('tickets:unread:admin:' . $ticket->id);

            DB::commit();

            $reply->load(['ticket', 'sender']);

            return response()->json([
                'status' => true,
                'message' => 'Reply created successfully.',
                'data' => $reply,
            ]);
        } catch (\Throwable $exception) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Unable to add reply.',
            ], 500);
        }
    }

    public function show(TicketReply $ticketReply): JsonResponse
    {
        $ticketReply->load(['ticket', 'sender']);

        return response()->json($ticketReply);
    }

    public function update(Request $request, TicketReply $ticketReply): JsonResponse
    {
        $validated = $request->validate([
            'ticket_id' => ['required', 'exists:tickets,id'],
            'message' => ['required', 'string'],
        ]);

        $ticketReply->update([
            'ticket_id' => $validated['ticket_id'],
            'message' => $validated['message'],
        ]);

        Cache::forget('tickets:unread:admin:' . $validated['ticket_id']);

        $ticketReply->load(['ticket', 'sender']);

        return response()->json([
            'status' => true,
            'message' => 'Reply updated successfully.',
            'data' => $ticketReply,
        ]);
    }

    public function destroy(TicketReply $ticketReply): JsonResponse
    {
        Cache::forget('tickets:unread:admin:' . $ticketReply->ticket_id);
        TicketReply::query()->whereKey($ticketReply->id)->delete();

        return response()->json([
            'status' => true,
            'message' => 'Reply deleted successfully.',
        ]);
    }
}
