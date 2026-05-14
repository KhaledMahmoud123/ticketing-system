<?php

namespace Khaled\Ticketing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Khaled\Ticketing\Models\TicketFile;

class TicketFileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = TicketFile::query()
            ->with('ticket')
            ->when($request->filled('ticket_id'), function ($query) use ($request) {
                $query->where('ticket_id', $request->input('ticket_id'));
            })
            ->latest('id')
            ->get();

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ticket_id' => ['required', 'exists:tickets,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:255'],
        ]);

        $file = TicketFile::create($validated);
        $file->load('ticket');

        return response()->json([
            'status' => true,
            'message' => 'Ticket file created successfully.',
            'data' => $file,
        ]);
    }

    public function show(TicketFile $ticketFile): JsonResponse
    {
        $ticketFile->load('ticket');

        return response()->json($ticketFile);
    }

    public function update(Request $request, TicketFile $ticketFile): JsonResponse
    {
        $validated = $request->validate([
            'ticket_id' => ['required', 'exists:tickets,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:255'],
        ]);

        $ticketFile->update($validated);
        $ticketFile->load('ticket');

        return response()->json([
            'status' => true,
            'message' => 'Ticket file updated successfully.',
            'data' => $ticketFile,
        ]);
    }

    public function destroy(TicketFile $ticketFile): JsonResponse
    {
        $ticketFile->delete();

        return response()->json([
            'status' => true,
            'message' => 'Ticket file deleted successfully.',
        ]);
    }
}
