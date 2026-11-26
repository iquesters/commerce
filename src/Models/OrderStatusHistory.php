<?php

namespace Iquesters\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Every change of an order's status: who, when, why. */
class OrderStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'order_status_histories';

    protected $fillable = ['order_id', 'from_status', 'to_status', 'note', 'changed_by'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
