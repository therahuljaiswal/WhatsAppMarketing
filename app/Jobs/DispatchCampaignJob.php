<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class DispatchCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The campaign instance.
     *
     * @var Campaign
     */
    protected $campaign;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 5;

    /**
     * Create a new job instance.
     *
     * @param Campaign $campaign
     * @return void
     */
    public function __construct(Campaign $campaign)
    {
        $this->campaign = $campaign;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // 1. Retrieve the company's WhatsApp configuration
        // We load the company and its whatsappConfig relationship.
        $company = $this->campaign->company()->with('whatsappConfig')->first();

        if (!$company || !$company->whatsappConfig) {
            Log::error("Campaign {$this->campaign->id} failed: No WhatsApp configuration found for company.");
            $this->campaign->update(['status' => 'failed']);
            return;
        }

        $config = $company->whatsappConfig;

        // The access_token is automatically decrypted by the Eloquent model cast
        $accessToken = $config->access_token;
        $phoneNumberId = $config->phone_number_id;

        // Retrieve the approved template information
        $template = $this->campaign->template;
        if (!$template || $template->status !== 'approved') {
            Log::error("Campaign {$this->campaign->id} failed: Template is missing or not approved.");
            $this->campaign->update(['status' => 'failed']);
            return;
        }

        $templateName = $template->name;
        $templateLanguage = $template->language;
        // The components structure stored in the DB (JSON cast to array)
        // If there are dynamic variables, they should be mapped here, but for now we assume
        // the generic structure or a static template.
        $templateComponents = $template->components ?? [];

        // Meta Cloud API URL (v18.0 as requested)
        $apiUrl = "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages";

        // Update campaign status to processing
        $this->campaign->update(['status' => 'processing']);

        // 2. Chunking the Audience
        // We retrieve contacts that belong to the company and are not opted out.
        // Crucially, we use a left join or whereDoesntHave to ensure idempotency.
        // If this job is released (due to rate limits) and retried, we only want to message
        // contacts who haven't already received a message for THIS campaign.
        $company->contacts()
            ->where('is_opted_out', false)
            ->whereDoesntHave('messages', function ($query) {
                $query->where('campaign_id', $this->campaign->id);
            })
            ->chunkById(500, function ($contacts) use ($apiUrl, $accessToken, $templateName, $templateLanguage, $templateComponents) {

                foreach ($contacts as $contact) {
                    // 3. Meta API Call
                    // Format the payload according to Meta's Template Message documentation
                    $payload = [
                        'messaging_product' => 'whatsapp',
                        'recipient_type' => 'individual',
                        'to' => $contact->phone_number,
                        'type' => 'template',
                        'template' => [
                            'name' => $templateName,
                            'language' => [
                                'code' => $templateLanguage
                            ],
                            // If components exist (e.g., body parameters, buttons), include them.
                            // Otherwise, omit or send empty array based on Meta's strict requirements.
                            // We use array_values to ensure it's a list if it's not empty.
                        ]
                    ];

                    if (!empty($templateComponents)) {
                        $payload['template']['components'] = array_values($templateComponents);
                    }

                    try {
                        // Send the POST request to Meta
                        $response = Http::withToken($accessToken)
                            ->post($apiUrl, $payload);

                        // 4. Error & Rate Limit Handling
                        if ($response->status() === 429) {
                            Log::warning("Rate limit hit for Campaign {$this->campaign->id}. Releasing job for 60 seconds.");
                            // Release the job back onto the queue with a 60-second delay.
                            // The whereDoesntHave clause above ensures we don't re-send to already processed contacts.
                            $this->release(60);
                            return false; // Stop processing the current chunk
                        }

                        if ($response->successful()) {
                            $responseData = $response->json();

                            // 5. Storing the Message ID
                            // Extract the Meta message ID from the response
                            $metaMessageId = $responseData['messages'][0]['id'] ?? null;

                            if ($metaMessageId) {
                                // Create a tracking record with 'pending' status
                                Message::create([
                                    'campaign_id' => $this->campaign->id,
                                    'contact_id' => $contact->id,
                                    'meta_message_id' => $metaMessageId,
                                    'status' => 'pending',
                                ]);
                            } else {
                                Log::error("Campaign {$this->campaign->id}: Meta API returned success but no message ID for contact {$contact->id}.", ['response' => $responseData]);
                            }
                        } else {
                            // Handle other API errors (e.g., 400 Bad Request)
                            Log::error("Campaign {$this->campaign->id}: Failed to send message to contact {$contact->id}.", [
                                'status' => $response->status(),
                                'error' => $response->json('error'),
                                'payload' => $payload,
                            ]);

                            // Even on failure, we should track the attempt so we don't infinitely retry
                            // this specific bad number/payload on subsequent job attempts.
                            Message::create([
                                'campaign_id' => $this->campaign->id,
                                'contact_id' => $contact->id,
                                'meta_message_id' => null, // No ID generated
                                'status' => 'failed',
                                'error_message' => json_encode($response->json('error')),
                            ]);
                        }

                    } catch (Exception $e) {
                        Log::error("Campaign {$this->campaign->id}: Exception while sending message to contact {$contact->id}.", [
                            'message' => $e->getMessage(),
                        ]);

                        // If it's a severe network issue, you might choose to release the entire job.
                        // However, standard practice is to log and continue to the next contact,
                        // or release with exponential backoff depending on the exception type.
                        // For this implementation, we log the failure and mark this specific message as failed.
                        Message::create([
                            'campaign_id' => $this->campaign->id,
                            'contact_id' => $contact->id,
                            'status' => 'failed',
                            'error_message' => substr($e->getMessage(), 0, 255), // Truncate if too long
                        ]);
                    }
                }
            });

        // Once all chunks have completed without releasing the job, mark the campaign as completed.
        // Note: The actual status of individual messages will be updated asynchronously by the Webhook Engine.
        if (! $this->job || ! $this->job->isReleased()) {
            $this->campaign->update(['status' => 'completed']);
        }
    }
}
