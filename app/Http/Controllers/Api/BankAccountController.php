<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bank\StoreBankAccountRequest;
use App\Models\StripeConnectAccount;
use App\Models\UserBankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BankAccountController extends Controller
{
    /**
     * Add bank account + create Stripe Custom Connect account silently.
     */
    public function store(StoreBankAccountRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            DB::beginTransaction();

            try {
                \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

                // Parse DOB
                $dob = \Carbon\Carbon::parse($request->dob);

                // Check if user already has a Connect account
                $connectAccount = StripeConnectAccount::where('user_id', $user->id)->first();

                // Verify existing Connect account is still accessible with current Stripe key
                if ($connectAccount) {
                    try {
                        \Stripe\Account::retrieve($connectAccount->connect_account_id);
                    } catch (\Stripe\Exception\PermissionException|\Stripe\Exception\InvalidRequestException $e) {
                        Log::warning('Stale Connect account detected, recreating', [
                            'user_id' => $user->id,
                            'old_account' => substr($connectAccount->connect_account_id, -6),
                            'error' => $e->getMessage(),
                        ]);
                        // Clean up stale records
                        UserBankAccount::where('user_id', $user->id)->delete();
                        $connectAccount->delete();
                        $connectAccount = null;
                    }
                }

                if (! $connectAccount) {
                    // First bank — create Stripe Custom Connect account
                    $nameParts = explode(' ', $user->full_name ?? 'User', 2);
                    $firstName = $nameParts[0];
                    $lastName = $nameParts[1] ?? $firstName;

                    $accountParams = [
                        'type' => 'custom',
                        'country' => strtoupper($request->country),
                        'email' => $user->email,
                        'capabilities' => [
                            'transfers' => ['requested' => true],
                        ],
                        'business_type' => 'individual',
                        'business_profile' => [
                            'url' => config('services.stripe.platform_url', 'https://timevault.app'),
                        ],
                        'individual' => array_filter([
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'dob' => [
                                'day' => $dob->day,
                                'month' => $dob->month,
                                'year' => $dob->year,
                            ],
                            'email' => $user->email,
                            'phone' => $request->phone,
                            'address' => [
                                'line1' => $request->address_line1,
                                'city' => $request->city,
                                'state' => $request->state,
                                'postal_code' => $request->postal_code,
                                'country' => strtoupper($request->country),
                            ],
                            'ssn_last_4' => strtoupper($request->country) === 'US' ? $request->ssn_last_4 : null,
                            'id_number' => strtoupper($request->country) !== 'US' ? $request->id_number : null,
                        ]),
                        'tos_acceptance' => [
                            'date' => time(),
                            'ip' => $request->ip(),
                        ],
                        'metadata' => [
                            'user_id' => (string) $user->id,
                            'platform' => 'time_vault',
                        ],
                    ];

                    $stripeAccount = \Stripe\Account::create($accountParams);

                    Log::info('Stripe Custom Connect account created', [
                        'user_id' => $user->id,
                        'account_id_suffix' => substr($stripeAccount->id, -6),
                    ]);

                    $accountStatus = $stripeAccount->details_submitted ? 'verified' : 'pending';
                    $connectAccount = StripeConnectAccount::create([
                        'user_id' => $user->id,
                        'connect_account_id' => $stripeAccount->id,
                        'status' => $accountStatus,
                        'payouts_enabled' => $stripeAccount->payouts_enabled ?? false,
                        'stripe_data' => $stripeAccount->toArray(),
                        'verified_at' => $stripeAccount->details_submitted ? now() : null,
                    ]);
                }

                // Add external bank account to Stripe
                $externalAccountParams = [
                    'external_account' => [
                        'object' => 'bank_account',
                        'country' => strtoupper($request->country),
                        'currency' => strtolower($request->currency),
                        'account_number' => $request->account_number,
                    ],
                ];

                if ($request->routing_number) {
                    $externalAccountParams['external_account']['routing_number'] = $request->routing_number;
                }

                $stripeBankAccount = \Stripe\Account::createExternalAccount(
                    $connectAccount->connect_account_id,
                    $externalAccountParams
                );

                Log::info('Stripe external bank account added', [
                    'user_id' => $user->id,
                    'bank_account_id_suffix' => substr($stripeBankAccount->id, -6),
                ]);

                // If this is the first bank account, make it primary
                $isFirst = ! UserBankAccount::where('user_id', $user->id)->exists();

                $bankAccount = UserBankAccount::create([
                    'user_id' => $user->id,
                    'account_holder_name' => $user->full_name ?? 'Account Holder',
                    'bank_name' => $request->bank_name,
                    'account_number' => $request->account_number,
                    'routing_number' => $request->routing_number,
                    'iban' => $request->iban,
                    'account_type' => $request->account_type,
                    'country' => strtoupper($request->country),
                    'currency' => strtolower($request->currency),
                    'stripe_bank_account_id' => $stripeBankAccount->id,
                    'dob' => $request->dob,
                    'is_primary' => $isFirst,
                ]);

                DB::commit();

                Log::info('Bank account saved successfully', [
                    'user_id' => $user->id,
                    'bank_account_id' => $bankAccount->id,
                    'is_primary' => $isFirst,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Bank account added successfully.',
                    'data' => [
                        'id' => $bankAccount->id,
                        'account_holder_name' => $bankAccount->account_holder_name,
                        'bank_name' => $bankAccount->bank_name,
                        'account_number' => $bankAccount->masked_account_number,
                        'account_type' => $bankAccount->account_type,
                        'country' => $bankAccount->country,
                        'currency' => strtoupper($bankAccount->currency),
                        'is_primary' => $bankAccount->is_primary,
                        'created_at' => $bankAccount->created_at->toIso8601String(),
                    ],
                ], 201);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Add bank account failed: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to setup bank account. Please try again.',
            ], 500);
        }
    }

    /**
     * Get all user's bank accounts (masked).
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $bankAccounts = UserBankAccount::where('user_id', $user->id)
            ->orderByDesc('is_primary')
            ->orderByDesc('created_at')
            ->get();

        if ($bankAccounts->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'has_bank_account' => false,
                    'bank_accounts' => [],
                ],
            ]);
        }

        $data = $bankAccounts->map(function ($bankAccount) {
            return [
                'id' => $bankAccount->id,
                'account_holder_name' => $bankAccount->account_holder_name,
                'bank_name' => $bankAccount->bank_name,
                'account_number' => $bankAccount->masked_account_number,
                'account_type' => $bankAccount->account_type,
                'country' => $bankAccount->country,
                'currency' => strtoupper($bankAccount->currency),
                'is_primary' => $bankAccount->is_primary,
                'created_at' => $bankAccount->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'has_bank_account' => true,
                'total' => $bankAccounts->count(),
                'bank_accounts' => $data,
            ],
        ]);
    }

    /**
     * Set a bank account as primary (used for withdrawals).
     */
    public function setPrimary(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $bankAccount = UserBankAccount::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $bankAccount) {
            return response()->json([
                'success' => false,
                'message' => 'Bank account not found.',
            ], 404);
        }

        if ($bankAccount->is_primary) {
            return response()->json([
                'success' => true,
                'message' => 'This bank account is already primary.',
            ]);
        }

        DB::transaction(function () use ($user, $bankAccount) {
            // Lock all user's bank accounts to prevent concurrent setPrimary race
            UserBankAccount::where('user_id', $user->id)->lockForUpdate()->get();

            // Remove primary from all user's bank accounts
            UserBankAccount::where('user_id', $user->id)
                ->update(['is_primary' => false]);

            // Set this one as primary
            $bankAccount->refresh();
            $bankAccount->update(['is_primary' => true]);

            // Sync default bank on Stripe Connect account
            $connectAccount = StripeConnectAccount::where('user_id', $user->id)->first();
            if ($connectAccount && $bankAccount->stripe_bank_account_id) {
                \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
                \Stripe\Account::updateExternalAccount(
                    $connectAccount->connect_account_id,
                    $bankAccount->stripe_bank_account_id,
                    ['default_for_currency' => true]
                );
            }
        });

        Log::info('Primary bank account changed', [
            'user_id' => $user->id,
            'bank_account_id' => $bankAccount->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Bank account set as primary.',
            'data' => [
                'id' => $bankAccount->id,
                'bank_name' => $bankAccount->bank_name,
                'account_number' => $bankAccount->masked_account_number,
                'is_primary' => true,
            ],
        ]);
    }

    /**
     * Delete a specific bank account from Stripe + DB.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();
            $bankAccount = UserBankAccount::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (! $bankAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bank account not found.',
                ], 404);
            }

            // Check no pending withdrawals
            $pendingTransfer = \App\Models\Transfer::where('user_id', $user->id)
                ->where('status', 'pending')
                ->exists();

            if ($pendingTransfer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete bank while a withdrawal is pending.',
                ], 400);
            }

            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            // Delete external bank from Stripe first (fail if Stripe rejects)
            $connectAccount = StripeConnectAccount::where('user_id', $user->id)->first();

            if ($connectAccount && $bankAccount->stripe_bank_account_id) {
                try {
                    \Stripe\Account::deleteExternalAccount(
                        $connectAccount->connect_account_id,
                        $bankAccount->stripe_bank_account_id
                    );
                } catch (\Stripe\Exception\InvalidRequestException $e) {
                    // Bank already deleted on Stripe (resource_missing) — safe to proceed
                    if (strpos($e->getMessage(), 'No such external account') === false &&
                        strpos($e->getMessage(), 'resource_missing') === false) {
                        throw $e;
                    }
                    Log::info('Bank already removed from Stripe, proceeding with DB deletion', [
                        'bank_account_id' => $id,
                    ]);
                }
            }

            // Wrap DB deletion + primary reassignment in a transaction
            DB::transaction(function () use ($user, $bankAccount, $connectAccount) {
                $wasPrimary = $bankAccount->is_primary;
                $bankAccount->delete();

                // If deleted bank was primary, make the next one primary + sync Stripe
                if ($wasPrimary) {
                    $nextBank = UserBankAccount::where('user_id', $user->id)
                        ->orderBy('created_at', 'asc')
                        ->first();
                    if ($nextBank) {
                        $nextBank->update(['is_primary' => true]);

                        if ($connectAccount && $nextBank->stripe_bank_account_id) {
                            \Stripe\Account::updateExternalAccount(
                                $connectAccount->connect_account_id,
                                $nextBank->stripe_bank_account_id,
                                ['default_for_currency' => true]
                            );
                        }
                    }
                }
            });

            Log::info('Bank account deleted', [
                'user_id' => $user->id,
                'bank_account_id' => $id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Bank account removed successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Delete bank account failed: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove bank account.',
            ], 500);
        }
    }
}
