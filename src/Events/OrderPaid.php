<?php

namespace Iquesters\Commerce\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Iquesters\Commerce\Models\Order;

/**
 * An order became paid. Other modules react without commerce knowing them:
 * event confirms the registration, community activates the membership lines.
 * Fired after the transaction that marked it paid commits.
 */
class OrderPaid
{
    use Dispatchable;

    public function __construct(public Order $order) {}
}
