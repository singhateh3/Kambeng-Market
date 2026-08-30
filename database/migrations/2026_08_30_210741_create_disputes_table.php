<?php

// database/migrations/2026_08_30_210741_create_disputes_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('reported_by')->constrained('users')->onDelete('cascade');
            $table->enum('reason', [
                'item_not_received',
                'item_not_as_described',
                'quality_issue',
                'wrong_item',
                'farmer_unresponsive',
                'other',
            ]);
            $table->text('description')->nullable();
            $table->enum('status', ['open', 'under_review', 'resolved', 'rejected'])->default('open');
            // Populated only when an admin resolves/rejects — see
            // DisputePolicy/AdminDisputeController::updateStatus(). Null
            // while a dispute is open or under_review.
            $table->text('admin_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('status');
        });

        // Only one active (open/under_review) dispute per order — enforced
        // here as a real DB-level backstop for the application-level
        // duplicate check in OrderController::report(), which is what
        // actually runs on every driver (including MySQL locally and
        // SQLite in tests, neither of which support a filtered/partial
        // unique index the way Postgres does). Production runs Postgres,
        // so that's the driver this needs to hold on.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX disputes_one_active_per_order ON disputes (order_id) WHERE status IN ('open', 'under_review')"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
