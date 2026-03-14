<?php

namespace App\Services;

use App\Models\Company;
use App\Models\WalletTransaction;
use App\Exceptions\InsufficientBalanceException;
use Illuminate\Support\Facades\DB;

class WalletService
{
    /**
     * Charge a company's wallet for a bulk campaign.
     *
     * This method calculates the total cost, checks the wallet balance using a pessimistic
     * database lock (lockForUpdate) to prevent race conditions during concurrent high-volume
     * sending, deducts the amount, and creates an audit trail in the ledger.
     *
     * @param Company $company
     * @param int $totalMessages
     * @param float $costPerMessage
     * @return WalletTransaction The resulting audit trail transaction
     * @throws InsufficientBalanceException If the wallet balance is lower than the calculated cost
     */
    public function chargeForCampaign(Company $company, int $totalMessages, float $costPerMessage): WalletTransaction
    {
        // Ensure total cost is calculated accurately.
        // Using bcmul (if available) or standard float multiplication.
        // For simplicity and speed in standard scenarios, float multiplication rounded to 2 decimals.
        $totalCost = round($totalMessages * $costPerMessage, 2);

        if ($totalCost <= 0) {
            throw new \InvalidArgumentException("Total cost must be greater than zero.");
        }

        // The entire process must be wrapped in a transaction to guarantee atomicity.
        return DB::transaction(function () use ($company, $totalCost, $totalMessages) {
            // Retrieve the wallet using a pessimistic lock to serialize concurrent access.
            // This prevents race conditions where multiple requests might read the same balance
            // simultaneously and attempt to deduct below zero.
            $wallet = $company->wallet()->lockForUpdate()->firstOrFail();

            // Check if there is enough balance
            if ($wallet->balance < $totalCost) {
                throw new InsufficientBalanceException(
                    "Insufficient wallet balance. Required: {$totalCost}, Available: {$wallet->balance}."
                );
            }

            // Deduct the total cost from the wallet balance
            $wallet->balance -= $totalCost;
            $wallet->save();

            // Create an audit trail record in the ledger immediately
            $transaction = $wallet->transactions()->create([
                'type' => 'debit',
                'amount' => $totalCost,
                'balance_after_transaction' => $wallet->balance,
                'description' => "Charged for Bulk Campaign ({$totalMessages} messages)",
                // reference_id could be added here if you pass a campaign_id to link it directly.
            ]);

            return $transaction;
        });
    }
}
