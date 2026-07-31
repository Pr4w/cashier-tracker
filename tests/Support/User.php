<?php

namespace Pr4w\CashierTracker\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Billable;
use Pr4w\CashierTracker\Concerns\HasPayments;

class User extends Model
{
    use Billable;
    use HasPayments;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
