<?php

// app/Services/UserDeactivationService.php
//
// Phase 3B — replaces AdminUserController::destroy()'s old hard-delete
// with the approved deactivation/anonymization lifecycle:
//
//   deactivate -> revoke tokens -> delist active listings -> anonymize PII
//
// Deliberately does NOT touch: orders, payment_transactions, disputes,
// reviews, or any financial/ledger data — the whole point of this service
// is that none of that is ever deleted or modified. The user row itself
// is never deleted either (its id is what every historical record still
// references); only specific columns on it (and on farmer_profiles) are
// overwritten.
//
// Per the approved outstanding-obligations decision, deactivation is never
// blocked by an existing order/dispute/payout in any state — the one
// exception is the farmer's settlement details specifically: those are
// read fresh at payout-release time (see PayoutReleaseService /
// FarmerProfile::hasSettlementDetails()), so anonymizing them out from
// under an order whose payout hasn't reached a final, no-longer-retriable
// state would turn a real payout into a missing_settlement_info failure —
// a data-corruption case created by this feature's own anonymization
// step, which the approved spec's own carve-out ("unless required to
// prevent data corruption... created by deactivation") covers. Everything
// else about the account is anonymized immediately regardless; only the
// four settlement columns are deferred while any order is in one of the
// three payout_status values where PayoutReleaseService::release() could
// still run (now, or via a future retry) and need them:
//   - pending_release — release() hasn't run yet.
//   - released — the transfer was dispatched but not yet confirmed by
//     ModemPay's webhook; a failure report moves it to 'failed' below.
//   - failed — AdminOrderController::retryPayout() can reset this back to
//     pending_release and call release() again, which re-reads these same
//     fields at that later moment.
// 'paid' (confirmed success), 'voided', and 'not_applicable' are all
// terminal — nothing ever calls release() again for those, so no
// retention is needed once an order reaches one of them.

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserDeactivationService
{
    /**
     * @return array{success: bool, reason: ?string, settlement_deferred: bool}
     */
    public function deactivate(User $user): array
    {
        if ($user->deactivated_at !== null) {
            return ['success' => false, 'reason' => 'already_deactivated', 'settlement_deferred' => false];
        }

        $settlementDeferred = false;

        DB::transaction(function () use ($user, &$settlementDeferred) {
            $user->update(['deactivated_at' => now()]);

            // Revoke every existing Sanctum token — same call already used
            // by AuthController::login()/refreshToken() and
            // SocialAuthController::handle(), just triggered here instead
            // of at login time.
            $user->tokens()->delete();

            // Hide (never delete) this farmer's listings from public
            // marketplace discovery — Product::scopeActive() excludes
            // anything with delisted_at set. Harmless no-op for a buyer
            // (zero products). Products with historical orders keep
            // resolving those orders exactly as before; only their
            // visibility in the active/browse scope changes.
            Product::where('farmer_id', $user->id)
                ->whereNull('delisted_at')
                ->update(['delisted_at' => now()]);

            $user->update([
                'name' => 'Deactivated User',
                // Deterministic on the immutable primary key — collision-free
                // (ids are unique), always available (no dependency on the
                // original value), and .invalid is the IANA-reserved TLD for
                // addresses that must never resolve (RFC 2606) — exactly
                // this use case. Frees the real address so a future Google/
                // Apple sign-in with it creates a distinct new account
                // rather than ever matching this row by email again.
                'email' => sprintf('deleted-user-%d@deleted.kambeng.invalid', $user->id),
                'phone' => null,
                'location' => null,
                'avatar' => null,
                'avatar_public_id' => null,
                // Nullable since the social-auth migration (social-only
                // accounts already prove null is safe — Hash::check()
                // against a null hash fails cleanly, never crashes).
                'password' => null,
                // provider / provider_id deliberately left untouched — see
                // the approved decision: retaining them lets a returning
                // Google/Apple identity resolve to this same deactivated
                // row (and then be rejected by EnsureAccountIsActive /
                // the login-time checks) instead of silently creating a
                // disconnected duplicate account.
            ]);

            if ($profile = $user->farmerProfile) {
                // farm_name / farm_location are NOT NULL columns (see
                // create_farmer_profiles_table) — anonymized to a fixed
                // placeholder rather than null, which the schema wouldn't
                // accept anyway.
                $profile->update([
                    'farm_name' => 'Deactivated Farmer',
                    'farm_location' => 'Not disclosed',
                    'bio' => null,
                ]);

                // Not in $fillable by design (verification documents are
                // deliberately excluded from mass assignment elsewhere in
                // the app) — forceFill() is used here specifically because
                // this is trusted internal code, not user input.
                $profile->forceFill([
                    'verification_document' => null,
                    'business_license' => null,
                    'id_document' => null,
                ])->save();

                $hasPendingPayout = Order::whereHas('product', function ($q) use ($user) {
                    $q->where('farmer_id', $user->id);
                })->whereIn('payout_status', ['pending_release', 'released', 'failed'])->exists();

                if ($hasPendingPayout) {
                    $settlementDeferred = true;
                } else {
                    $profile->update([
                        'settlement_network' => null,
                        'settlement_account_number' => null,
                        'settlement_beneficiary_name' => null,
                        'settlement_verified_at' => null,
                    ]);
                }
            }
        });

        return ['success' => true, 'reason' => null, 'settlement_deferred' => $settlementDeferred];
    }
}
