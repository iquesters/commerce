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

    protected function prepareDefault(BaseConf $default_values)
    {
        $default_values->default_currency = 'INR';
    }

    protected function rules(): array
    {
        return [
            'default_currency' => 'string|size:3',
        ];
    }
}
