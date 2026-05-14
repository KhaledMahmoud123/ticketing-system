<?php

use Illuminate\Support\Facades\Route;
use Khaled\Ticketing\Http\Controllers\TicketStudentApiController;
$middleware = config('ticketing.api_middleware',[]);

Route::middleware($middleware)->group(function () {

    Route::get('ticket-types', [TicketStudentApiController::class, 'types']);
    Route::get('tickets', [TicketStudentApiController::class, 'index']);
    Route::get('tickets/{ticket}', [TicketStudentApiController::class, 'show']);
    Route::get('tickets/{ticket}/replies', [TicketStudentApiController::class, 'replies']);
    Route::post('tickets', [TicketStudentApiController::class, 'store']);
    Route::post('tickets/{ticket}/replies/student', [TicketStudentApiController::class, 'storeReply']);
    Route::post('tickets/replies/{ticket}', [TicketStudentApiController::class, 'storeReply']);
});