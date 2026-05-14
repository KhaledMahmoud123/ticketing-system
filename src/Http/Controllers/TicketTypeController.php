<?php

namespace Khaled\Ticketing\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Khaled\Ticketing\Models\TicketType;

class TicketTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = TicketType::query()->latest('id')->get();

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:ticket_types,name'],
            'description' => ['nullable', 'string'],
            'access_key' => ['nullable', 'string', 'max:255'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high'])],
        ]);

        $type = TicketType::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Ticket type created successfully.',
            'data' => $type,
        ]);
    }

    public function show(TicketType $ticketType): JsonResponse
    {
        return response()->json($ticketType);
    }

    public function update(Request $request, TicketType $ticketType): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('ticket_types', 'name')->ignore($ticketType->id)],
            'description' => ['nullable', 'string'],
            'access_key' => ['nullable', 'string', 'max:255'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high'])],
        ]);

        $ticketType->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Ticket type updated successfully.',
            'data' => $ticketType,
        ]);
    }

    public function destroy(TicketType $ticketType): JsonResponse
    {
        if ($ticketType->tickets()->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'This ticket type cannot be deleted because it is already used by one or more tickets.',
            ], 422);
        }

        try {
            $ticketType->delete();
        } catch (QueryException $exception) {
            return response()->json([
                'status' => false,
                'message' => 'This ticket type cannot be deleted because it is linked to existing records.',
            ], 422);
        }

        return response()->json([
            'status' => true,
            'message' => 'Ticket type deleted successfully.',
        ]);
    }
}
