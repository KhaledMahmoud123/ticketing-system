<?php
use Illuminate\Support\Facades\Route;
use Khaled\Ticketing\Http\Controllers\TicketController;
use Khaled\Ticketing\Http\Controllers\TicketFileController;
use Khaled\Ticketing\Http\Controllers\TicketReplyController;
use Khaled\Ticketing\Http\Controllers\TicketTypeController;

Route::group(['prefix' => 'tickets-management', 'as' => 'tickets-management.'], function () {
        Route::get('/', [TicketController::class, 'management'])->name('index');
        Route::apiResource('ticket-types', TicketTypeController::class);
        Route::get('tickets/{ticket}/chat', [TicketController::class, 'chat'])->name('tickets.chat');
        Route::post('tickets/{ticket}/replies/user', [TicketReplyController::class, 'storeForTicket'])->name('tickets.replies.user');
        Route::apiResource('tickets', TicketController::class)->except(['store']);
        Route::apiResource('ticket-replies', TicketReplyController::class);
        Route::apiResource('ticket-files', TicketFileController::class);
    });