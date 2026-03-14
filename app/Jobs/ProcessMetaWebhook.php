<?php

namespace App\Jobs;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMetaWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The payload received from Meta's webhook.
     *
     * @var array
     */
    protected $payload;

    /**
     * Create a new job instance.
     *
     * @param array $payload
     * @return void
     */
    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Meta webhook payloads can contain multiple entries; iterate over each.
        if (isset($this->payload['entry']) && is_array($this->payload['entry'])) {
            foreach ($this->payload['entry'] as $entry) {
                // Ensure the entry has changes
                if (isset($entry['changes']) && is_array($entry['changes'])) {
                    foreach ($entry['changes'] as $change) {
                        // We are only interested in 'messages' value updates
                        if (isset($change['value'])) {
                            $value = $change['value'];

                            // Extract the WhatsApp Business Account ID (waba_id)
                            $wabaId = $value['metadata']['display_phone_number'] ?? null;

                            // Check if there are statuses to update
                            if (isset($value['statuses']) && is_array($value['statuses'])) {
                                foreach ($value['statuses'] as $statusUpdate) {
                                    $this->processStatusUpdate($statusUpdate);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Process an individual status update from the payload.
     *
     * @param array $statusUpdate
     * @return void
     */
    protected function processStatusUpdate(array $statusUpdate)
    {
        // Extract the Meta message ID and the new status
        $metaMessageId = $statusUpdate['id'] ?? null;
        $status = $statusUpdate['status'] ?? null;

        if (!$metaMessageId || !$status) {
            // Cannot process without message ID or status
            return;
        }

        // Look up the corresponding Message model using the Meta message ID
        $message = Message::where('meta_message_id', $metaMessageId)->first();

        if ($message) {
            // Check if status is one of the accepted values
            if (in_array($status, ['sent', 'delivered', 'read', 'failed'])) {
                $message->status = $status;

                // If the message failed, extract the error details
                if ($status === 'failed' && isset($statusUpdate['errors']) && is_array($statusUpdate['errors'])) {
                    // Extract the first error message as a representation
                    $error = $statusUpdate['errors'][0];
                    $errorCode = $error['code'] ?? 'Unknown';
                    $errorMessage = $error['title'] ?? $error['message'] ?? 'No error message provided.';

                    $message->error_message = sprintf('Code %s: %s', $errorCode, $errorMessage);
                }

                // Save the updated status and/or error back to the database
                $message->save();
            } else {
                Log::warning("Received unknown status '$status' for Meta message ID: $metaMessageId");
            }
        } else {
            // If the message is not found, log a warning for debugging purposes
            Log::warning("Message with Meta ID $metaMessageId not found in the database during webhook processing.");
        }
    }
}
