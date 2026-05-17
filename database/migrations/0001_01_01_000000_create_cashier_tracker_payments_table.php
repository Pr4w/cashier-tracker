<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('cashier-tracker.table', 'cashier_tracker_payments'), function (Blueprint $table) {
            $table->id();

            $table->string('type')->index();              // invoice | payment_intent
            $table->string('stripe_id')->unique();         // idempotency key
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('customer_email')->nullable();

            // Montants, toujours en plus petite unité (centimes).
            $table->unsignedBigInteger('amount');          // amount_paid (TTC encaissé)
            $table->unsignedBigInteger('subtotal')->nullable();  // HT
            $table->unsignedBigInteger('tax')->nullable();       // TVA
            $table->unsignedBigInteger('fee')->nullable();       // frais Stripe
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->string('currency', 3);

            $table->string('status')->default('succeeded');
            $table->string('billing_reason')->nullable();

            $table->boolean('livemode')->default(true)->index();

            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            $table->nullableMorphs('billable');
            $table->json('meta')->nullable();

            $table->timestamp('paid_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('cashier-tracker.table', 'cashier_tracker_payments'));
    }
};