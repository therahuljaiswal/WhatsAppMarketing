<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\WhatsappConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppOnboardingController extends Controller
{
    /**
     * Handle the OAuth callback from Meta's Embedded Signup.
     *
     * This method expects a temporary authorization code from the frontend
     * after the user successfully completes the Facebook popup flow.
     * It exchanges the code for a long-lived access token, fetches the
     * necessary WhatsApp Business Account (WABA) and Phone Number IDs,
     * and securely stores them for the authenticated company.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function callback(Request $request): JsonResponse
    {
        // 1. Validate the incoming request for the temporary code
        $request->validate([
            'code' => 'required|string',
        ]);

        $code = $request->input('code');

        try {
            // Retrieve Meta App credentials from the environment/config
            $appId = config('services.whatsapp.app_id');
            $appSecret = config('services.whatsapp.app_secret');

            // Meta API base URL
            $graphApiVersion = 'v19.0';
            $baseUrl = "https://graph.facebook.com/{$graphApiVersion}";

            // 2. Exchange the temporary code for a System User Access Token
            // According to Meta's Embedded Signup documentation, we hit the oauth/access_token endpoint
            $tokenResponse = Http::asForm()->post("{$baseUrl}/oauth/access_token", [
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'code' => $code,
            ]);

            if ($tokenResponse->failed()) {
                Log::error('Meta API Token Exchange Failed', [
                    'status' => $tokenResponse->status(),
                    // Be careful not to log sensitive details in production, but we need the error message
                    'error' => $tokenResponse->json('error.message'),
                ]);
                return response()->json(['error' => 'Failed to exchange authorization code.'], 400);
            }

            $tokenData = $tokenResponse->json();
            $accessToken = $tokenData['access_token'];

            // 3. Fetch the WABA ID and Phone Number ID
            // For Embedded Signup, a common approach is to query the shared WABAs.
            $wabaResponse = Http::withToken($accessToken)->get("{$baseUrl}/me/client_whatsapp_business_accounts", [
                'fields' => 'id,name,phone_numbers',
            ]);

            if ($wabaResponse->failed()) {
                 Log::error('Meta API WABA Fetch Failed', [
                    'status' => $wabaResponse->status(),
                    'error' => $wabaResponse->json('error.message'),
                ]);
                return response()->json(['error' => 'Failed to retrieve WhatsApp Business Accounts.'], 400);
            }

            $wabaData = $wabaResponse->json();

            // Check if any WABAs were returned
            if (empty($wabaData['data'])) {
                return response()->json(['error' => 'No WhatsApp Business Accounts found.'], 404);
            }

            // For simplicity, we'll take the first WABA and its first phone number.
            $selectedWaba = $wabaData['data'][0];
            $wabaId = $selectedWaba['id'];

            // Ensure phone numbers exist
            if (empty($selectedWaba['phone_numbers']['data'])) {
                 return response()->json(['error' => 'No phone numbers associated with the selected WABA.'], 404);
            }

            $selectedPhoneNumber = $selectedWaba['phone_numbers']['data'][0];
            $phoneNumberId = $selectedPhoneNumber['id'];
            $phoneNumber = $selectedPhoneNumber['display_phone_number'];

            // 4. Store Securely
            // Assume the company is authenticated and we can get their ID.
            // Using $request->user()->id representing the company or a user linked to the company.
            $companyId = $request->user()->id;

            // Use updateOrCreate to handle both initial connection and reconnections
            $whatsappConfig = WhatsappConfig::updateOrCreate(
                ['company_id' => $companyId],
                [
                    'waba_id' => $wabaId,
                    'phone_number_id' => $phoneNumberId,
                    'phone_number' => $phoneNumber,
                    // The access_token is automatically encrypted due to the $casts in the model
                    'access_token' => $accessToken,
                ]
            );

            return response()->json([
                'message' => 'WhatsApp Business Account connected successfully.',
                'waba_id' => $whatsappConfig->waba_id,
            ], 200);

        } catch (\Exception $e) {
            // 5. Error Handling
            // Catch unexpected exceptions and log them securely.
            Log::error('WhatsApp Onboarding Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json(['error' => 'An unexpected error occurred during onboarding.'], 500);
        }
    }
}
