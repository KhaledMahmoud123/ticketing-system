<?php

namespace Khaled\Ticketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\Instructor;
use App\Models\Parents;
use App\Models\Student;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Khaled\Ticketing\Contracts\TicketAccessResolver;
use Khaled\Ticketing\Models\Ticket;
use Khaled\Ticketing\Models\TicketReply;
use Khaled\Ticketing\Models\TicketType;

class TicketController extends Controller
{
    private TicketAccessResolver $accessResolver;

    public function __construct(TicketAccessResolver $accessResolver)
    {
        $this->accessResolver = $accessResolver;
    }

    public function management(Request $request)
    {
        $filters = [
            'search' => trim((string) $request->input('search', '')) ?: null,
            'status' => trim((string) $request->input('status', '')) ?: null,
            'priority' => trim((string) $request->input('priority', '')) ?: null,
            'type_id' => trim((string) $request->input('type_id', '')) ?: null,
        ];

        $query = Ticket::query()
            ->with(['type', 'user', 'owner'])
            ->when($filters['search'], function ($builder, string $search) {
                $builder->where(function ($searchBuilder) use ($search) {
                    $searchBuilder
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('body', 'like', "%{$search}%")
                        ->orWhere('owner_type', 'like', "%{$search}%")
                        ->orWhere('owner_id', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'], function ($builder, string $status) {
                $builder->where('status', $status);
            })
            ->when($filters['priority'], function ($builder, string $priority) {
                $builder->whereHas('type', function ($typeBuilder) use ($priority) {
                    $typeBuilder->where('priority', $priority);
                });
            })
            ->when($filters['type_id'], function ($builder, string $typeId) {
                $builder->where('type_id', $typeId);
            });

        // Filter by the host application's access resolver
        $currentUser = Auth::user();
        if ($currentUser instanceof Authenticatable && !$this->accessResolver->isSuperAdmin($currentUser)) {
            $visibleTypeIds = $this->accessResolver->visibleTypeIdsFor($currentUser);

            if ($visibleTypeIds !== []) {
                $query->whereIn('type_id', $visibleTypeIds);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $query->orderByDesc('id');

        $paginator = $query->paginate(15)->appends($request->query());
        $pagination = json_decode(json_encode($paginator), true);
        $items = collect($paginator->items())->map(function (Ticket $ticket) {
            $payload = $ticket->toArray();
            $payload['unread_count'] = $this->getUnreadCount((int) $ticket->id);
            return $payload;
        })->values()->all();

        return Inertia::render('Admin/Tickets', [
            'data' => $items,
            'links' => $pagination['links'] ?? [],
            'filters' => $filters,
            'types' => TicketType::query()->select(['id', 'name', 'description', 'access_key', 'priority'])->orderBy('name')->get(),
            'statuses' => ['open', 'processing', 'closed'],
            'priorities' => ['low', 'medium', 'high'],
        ]);
    }

    public function chat(Ticket $ticket): JsonResponse
    {
        $ticket->load([
            'type',
            'user',
            'owner',
            'files',
            'replies' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            'replies.sender',
        ]);

        return response()->json($ticket);
    }

    public function index(): JsonResponse
    {
        $rows = Ticket::query()
            ->with(['type', 'user', 'owner', 'files', 'replies'])
            ->latest('id')
            ->get();

        return response()->json($rows);
    }

    public function show(Ticket $ticket): JsonResponse
    {
        $ticket->load([
            'type',
            'user',
            'owner',
            'files',
            'replies' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            'replies.sender',
        ]);

        return response()->json($ticket);
    }

    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'type_id' => ['required', 'exists:ticket_types,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'owner_type' => ['nullable', 'string'],
            'owner_id' => ['nullable'],
            'status' => ['required', Rule::in(['open', 'processing', 'closed'])],
        ]);

        $ticket->update($validated);
        $ticket->load(['type', 'user', 'files', 'replies.sender']);

        return response()->json([
            'status' => true,
            'message' => 'Ticket updated successfully.',
            'data' => $ticket,
        ]);
    }

    public function destroy(Ticket $ticket): JsonResponse
    {
        $ticket->delete();
        $this->forgetUnreadCount((int) $ticket->id);

        return response()->json([
            'status' => true,
            'message' => 'Ticket deleted successfully.',
        ]);
    }

    private function getUnreadCount(int $ticketId): int
    {
        return (int) Cache::remember("tickets:unread:admin:" . $ticketId,
            now()->addMinutes(5),
            fn () => TicketReply::query()
                ->where('ticket_id', $ticketId)
                ->whereIn('sender_type', [Student::class, Parents::class, Instructor::class, Applicant::class])
                ->where('is_read', false)
                ->count()
        );
    }

    private function forgetUnreadCount(int $ticketId): void
    {
        Cache::forget("tickets:unread:admin:" . $ticketId);
    }
}
