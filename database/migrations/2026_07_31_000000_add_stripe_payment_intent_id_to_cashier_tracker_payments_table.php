<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('cashier-tracker.table', 'cashier_tracker_payments'), function (Blueprint $table) {
            // Stable join key for refunds. A refund arrives on a charge, but
            // rows are keyed by invoice id or payment intent id. Charge.invoice
            // was removed in the Basil API (Cashier 16) while
            // Charge.payment_intent survives across versions, so the payment
            // intent is the only join back to a tracked row that holds on both.
            $table->string('stripe_payment_intent_id')
                ->nullable()
                ->index()
                ->after('stripe_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table(config('cashier-tracker.table', 'cashier_tracker_payments'), function (Blueprint $table) {
            // The index has to go first: SQLite errors on dropping a column
            // that a surviving index still references.
            $table->dropIndex(['stripe_payment_intent_id']);
            $table->dropColumn('stripe_payment_intent_id');
        });
    }
};
