<?php

namespace Pr4w\CashierTracker\Tests\Support;

use Stripe\Collection;

/**
 * Stands in for a Stripe list endpoint (invoices, paymentIntents). Serves
 * pre-canned rows in pages of `limit`, honouring `starting_after`, so the
 * command's pagination is genuinely exercised rather than stubbed out.
 */
class FakeListService
{
    public int $calls = 0;

    /** @var array<int, array> the params each call was made with */
    public array $seenParams = [];

    public function __construct(private array $rows = [])
    {
    }

    public function all($params = null, $opts = null): Collection
    {
        $this->calls++;
        $this->seenParams[] = $params ?? [];

        $rows  = $this->rows;
        $limit = $params['limit'] ?? 10;

        if ($after = $params['starting_after'] ?? null) {
            $ids   = array_column($rows, 'id');
            $index = array_search($after, $ids, true);
            $rows  = $index === false ? [] : array_slice($rows, $index + 1);
        }

        if (isset($params['created']['gte'])) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $r) => ($r['created'] ?? 0) >= $params['created']['gte']
            ));
        }

        $page = array_slice($rows, 0, $limit);

        return Collection::constructFrom([
            'object'   => 'list',
            'data'     => $page,
            'has_more' => count($rows) > count($page),
        ]);
    }
}
