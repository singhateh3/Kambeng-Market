<?php

// database/migrations/2026_08_31_110047_create_webhook_events_table.php
//
// Deduplication for inbound webhooks. ModemPay's own docs give no
// idempotency guarantee at this layer (unlike their Transfer API, which
// requires an Idempotency-Key) and explicitly retries up to 3 times on a
// non-200 response — so this table is what actually prevents a retried or
// duplicated webhook delivery from being processed twice. A row is written
// (unique on provider+event_id) before any business logic runs; a duplicate
// delivery hits the constraint and is a no-op.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            // ModemPay's docs don't document a single stable top-level
            // event id field name — event_id here is Kambeng's own derived
            // dedup key (the most specific identifier found in the payload,
            // e.g. payload.id for a charge event, or transfer id for a
            // transfer event), not assumed to be a literal field ModemPay
            // sends. See ModemPayWebhookController for exactly how it's derived.
            $table->string('event_id');
            $table->string('event_type');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
