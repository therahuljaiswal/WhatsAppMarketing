<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Jobs\ProcessMetaWebhook;

class WhatsAppWebhookController extends Controller
{
    /**
     * Handle Meta's webhook verification (hub.challenge).
     *
     * This method responds to the initial GET request from Meta to verify
     * the webhook URL setup. It checks the 'hub_mode' and 'hub_verify_token'
     * and returns the 'hub_challenge' if they match.
     *
     * @param Request $request
     * @return Response
     */
    public function verify(Request $request)
    {
        // Define your verify token here or retrieve it from config/env
        $verifyToken = config('services.whatsapp.verify_token', 'my_custom_verify_token');

        // Extract hub parameters from the request
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        // Check if mode and token are present and match expectations
        if ($mode === 'subscribe' && $token === $verifyToken) {
            // Respond with the challenge token from the request
            return response($challenge, 200);
        }

        // Return a '403 Forbidden' if verify tokens do not match
        return response('Forbidden', 403);
    }

    /**
     * Receive incoming POST requests from Meta.
     *
     * Crucial: This method does NOT do any database queries. It immediately
     * dispatches a Laravel Job with the payload data and returns a 200 OK
     * response so Meta doesn't timeout.
     *
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request)
    {
        // Get the full JSON payload from the request
        $payload = $request->all();

        // Dispatch a queueable job to process the webhook payload asynchronously
        ProcessMetaWebhook::dispatch($payload);

        // Return a 200 OK response immediately to acknowledge receipt to Meta
        return response('EVENT_RECEIVED', 200);
    }
}
