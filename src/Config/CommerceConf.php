<?php

namespace Iquesters\Commerce\Config;

use Iquesters\Commerce\Enums\Module;
use Iquesters\Foundation\Support\BaseConf;

/** Commerce settings, stored in the DB conf (see docs/developer/configuration.md). */
class CommerceConf extends BaseConf
{
    protected ?string $identifier = Module::COMMERCE;

    /** Currency for new products (ISO 4217). */
    protected string $default_currency;

    /** Order references: {prefix}-0001, -0002… (numbered per organisation and prefix). */
    protected string $order_reference_prefix;

    protected function prepareDefault(BaseConf $default_values)
    {
        $default_values->default_currency = 'INR';
        $default_values->order_reference_prefix = 'ORD';
    }

    protected function rules(): array
    {
        return [
            'default_currency' => 'string|size:3',
            'order_reference_prefix' => 'string|max:24|regex:/^[A-Za-z0-9-]+$/',
        ];
    }
}
