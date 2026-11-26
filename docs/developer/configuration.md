# Settings

Product settings live in the DB conf (`CommerceConf`, identifier `iquesters/commerce`):

| Setting | Default | Meaning |
|---|---|---|
| `default_currency` | `INR` | Currency of a new product when the form leaves it empty (ISO 4217, 3 letters) |

```php
use Iquesters\Commerce\Enums\Module;
use Iquesters\Foundation\Support\ConfProvider;

ConfProvider::from(Module::COMMERCE)->default_currency;
```
