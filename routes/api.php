<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WhatsAppWebhookController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Meta WhatsApp Cloud API Webhook Routes
Route::prefix('webhook/whatsapp')->group(function () {
    // GET route for Meta's webhook verification (hub.challenge)
    Route::get('/', [WhatsAppWebhookController::class, 'verify']);

    // POST route to receive the actual webhook payloads
    Route::post('/', [WhatsAppWebhookController::class, 'handle']);
});
