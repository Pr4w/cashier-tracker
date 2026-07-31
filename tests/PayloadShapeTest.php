<?php

namespace Pr4w\CashierTracker\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Pr4w\CashierTracker\Tests\Support\PaymentDataHarness;

/**
 * Stripe moved several invoice fields between API versions, and the package
 * has to read both shapes because it supports Cashier 15 and 16. These are
 * the cases that have silently broken before.
 */
class PayloadShapeTest extends TestCase
{
    private PaymentDataHarness $harness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->harness = new PaymentDataHarness;
    }

    public static function subscriptionShapes(): array
    {
        return [
            'Basil, id only' => [
                ['parent' => ['subscription_details' => ['subscription' => 'sub_1']]],
                'sub_1',
            ],
            'Basil, expanded object' => [
                ['parent' => ['subscription_details' => ['subscription' => ['id' => 'sub_1']]]],
                'sub_1',
            ],
            'pre-Basil, flat id' => [
                ['subscription' => 'sub_1'],
                'sub_1',
            ],
            'pre-Basil, expanded object' => [
                ['subscription' => ['id' => 'sub_1']],
                'sub_1',
            ],
            'both present, Basil wins' => [
                [
                    'subscription' => 'sub_old',
                    'parent'       => ['subscription_details' => ['subscription' => 'sub_new']],
                ],
                'sub_new',
            ],
            'null parent falls back to flat' => [
                ['parent' => ['subscription_details' => null], 'subscription' => 'sub_1'],
                'sub_1',
            ],
            'one-off invoice has none' => [
                ['id' => 'in_1'],
                null,
            ],
        ];
    }

    #[Test]
    #[DataProvider('subscriptionShapes')]
    public function it_resolves_the_subscription_id_across_api_versions(array $invoice, ?string $expected): void
    {
        $this->assertSame($expected, $this->harness->resolveInvoiceSubscriptionId($invoice));
    }

    public static function taxShapes(): array
    {
        return [
            'Basil, single tax'    => [['total_taxes' => [['amount' => 333]]], 333],
            'Basil, several taxes' => [['total_taxes' => [['amount' => 200], ['amount' => 133]]], 333],
            'pre-Basil, flat int'  => [['tax' => 333], 333],
            'pre-Basil, numeric string' => [['tax' => '333'], 333],
            'no tax at all'        => [['id' => 'in_1'], null],
            'explicit null tax'    => [['tax' => null], null],
        ];
    }

    #[Test]
    #[DataProvider('taxShapes')]
    public function it_resolves_tax_across_api_versions(array $invoice, ?int $expected): void
    {
        $this->assertSame($expected, $this->harness->resolveInvoiceTax($invoice));
    }

    public static function paymentIntentShapes(): array
    {
        return [
            'Basil payments collection' => [
                ['payments' => ['data' => [['payment' => ['payment_intent' => 'pi_1']]]]],
                'pi_1',
            ],
            'Basil, expanded payment intent' => [
                ['payments' => ['data' => [['payment' => ['payment_intent' => ['id' => 'pi_1']]]]]],
                'pi_1',
            ],
            'pre-Basil flat field' => [
                ['payment_intent' => 'pi_1'],
                'pi_1',
            ],
            'unsettled invoice' => [
                ['id' => 'in_1'],
                null,
            ],
        ];
    }

    #[Test]
    #[DataProvider('paymentIntentShapes')]
    public function it_resolves_the_payment_intent_id_across_api_versions(array $invoice, ?string $expected): void
    {
        $this->assertSame($expected, $this->harness->resolveInvoicePaymentIntentId($invoice));
    }

    #[Test]
    public function it_persists_the_resolved_subscription_id_into_meta(): void
    {
        $this->harness->storeInvoice($this->invoicePayload([
            'parent' => ['subscription_details' => ['subscription' => 'sub_basil']],
        ]));

        $this->assertSame('sub_basil', $this->payment()->meta['subscription']);
    }
}
