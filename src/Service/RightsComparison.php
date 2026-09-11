<?php

namespace AdmidioPlugin\Impersonate\Service;

/**
 * Decides whether acting as a user could give an administrator more than they already have.
 *
 * Everything a user may do or see in Admidio follows from the roles they are a member of, and from
 * whether they lead them. So the comparison is made on the memberships and not on a list of rights,
 * which would miss the view rights of role lists, profile fields and categories.
 *
 * A membership of the user is covered if the administrator
 *
 * - is a member of the same role, and a leader of it if the user leads it, or
 * - is an administrator of the organization the role belongs to, which grants everything there.
 *
 * A role of a global category grants its rights in every organization, so being the administrator of
 * one organization does not cover it; only the same membership, or an administrator role that is
 * itself global, does.
 *
 * The class knows nothing about the database, so the rule can be tested on its own.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */
final class RightsComparison
{
    /**
     * The class only offers static methods and must not be instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Whether every membership of the target is covered by the memberships of the actor.
     * @param array<int,array{role: int, org: int|null, leader: bool, admin: bool}> $actorMemberships
     * @param array<int,array{role: int, org: int|null, leader: bool, admin: bool}> $targetMemberships
     *        One entry per current membership: the role, the organization of its category (**null**
     *        for a global category), whether the membership is a leadership and whether the role is
     *        an administrator role.
     * @return bool
     */
    public static function covers(array $actorMemberships, array $targetMemberships): bool
    {
        $administratorEverywhere = false;
        $administratedOrganizations = array();
        // role ID => whether the actor leads it
        $actorRoles = array();

        foreach ($actorMemberships as $membership) {
            if ($membership['admin']) {
                if ($membership['org'] === null) {
                    $administratorEverywhere = true;
                } else {
                    $administratedOrganizations[$membership['org']] = true;
                }
            }

            $actorRoles[$membership['role']] = ($actorRoles[$membership['role']] ?? false) || $membership['leader'];
        }

        foreach ($targetMemberships as $membership) {
            if (array_key_exists($membership['role'], $actorRoles)
                && ($actorRoles[$membership['role']] || !$membership['leader'])) {
                continue;
            }

            if ($administratorEverywhere
                || ($membership['org'] !== null && isset($administratedOrganizations[$membership['org']]))) {
                continue;
            }

            return false;
        }

        return true;
    }
}
