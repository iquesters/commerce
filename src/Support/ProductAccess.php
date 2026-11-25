<?php

namespace Iquesters\Commerce\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Iquesters\Organisation\Support\OrganisationAccess;

/**
 * Who manages products and discounts (coupon codes included): super-admins,
 * and organisation admins for their organisations (OrganisationAccess: Track B
 * assignments, or the interim "admin" + member rule).
 */
class ProductAccess
{
    public static function canManage(?Authenticatable $user): bool
    {
        return OrganisationAccess::isPlatformAdmin($user) || OrganisationAccess::adminOrganisationIds($user) !== [];
    }

    /** @return int[] */
    public static function organisationIds(?Authenticatable $user): array
    {
        return OrganisationAccess::adminOrganisationIds($user);
    }

    public static function canManageOrganisation(?Authenticatable $user, ?int $organisationId): bool
    {
        return $organisationId !== null && in_array($organisationId, self::organisationIds($user), true);
    }
}
