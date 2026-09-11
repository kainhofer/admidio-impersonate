<?php
/**
 * The rule that decides whether acting as a user stays within what the administrator may do anyway.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\Impersonate\Tests\Unit;

require_once dirname(__DIR__) . '/autoload.php';

use Admidio\Tests\Support\AdmidioTestCase;
use AdmidioPlugin\Impersonate\Service\RightsComparison;

final class RightsComparisonTest extends AdmidioTestCase
{
    private const CURRENT_ORG = 1;
    private const OTHER_ORG = 2;

    /**
     * @return array{role: int, org: int|null, leader: bool, admin: bool}
     */
    private static function membership(int $role, ?int $org, bool $leader = false, bool $admin = false): array
    {
        return array('role' => $role, 'org' => $org, 'leader' => $leader, 'admin' => $admin);
    }

    /**
     * The administrator of the current organization, and nothing else.
     * @return array<int,array{role: int, org: int|null, leader: bool, admin: bool}>
     */
    private static function administrator(): array
    {
        return array(self::membership(1, self::CURRENT_ORG, false, true));
    }

    public function testAUserWithoutMembershipsIsCovered(): void
    {
        $this->assertTrue(RightsComparison::covers(self::administrator(), array()));
    }

    public function testEveryRoleOfTheAdministratedOrganizationIsCovered(): void
    {
        $target = array(
            self::membership(10, self::CURRENT_ORG),
            self::membership(11, self::CURRENT_ORG, true),
            self::membership(12, self::CURRENT_ORG, false, true)
        );

        $this->assertTrue(RightsComparison::covers(self::administrator(), $target), 'leaders and other administrators included');
    }

    public function testARoleOfAnotherOrganizationIsNotCovered(): void
    {
        $target = array(self::membership(10, self::CURRENT_ORG), self::membership(20, self::OTHER_ORG));

        $this->assertFalse(RightsComparison::covers(self::administrator(), $target));
    }

    public function testSharingTheRoleOfTheOtherOrganizationCoversIt(): void
    {
        $actor = array_merge(self::administrator(), array(self::membership(20, self::OTHER_ORG)));
        $target = array(self::membership(20, self::OTHER_ORG));

        $this->assertTrue(RightsComparison::covers($actor, $target));
    }

    public function testLeadingARoleIsOnlyCoveredByLeadingItToo(): void
    {
        $target = array(self::membership(20, self::OTHER_ORG, true));

        $member = array_merge(self::administrator(), array(self::membership(20, self::OTHER_ORG)));
        $this->assertFalse(RightsComparison::covers($member, $target), 'a plain member lacks the rights of a leader');

        $leader = array_merge(self::administrator(), array(self::membership(20, self::OTHER_ORG, true)));
        $this->assertTrue(RightsComparison::covers($leader, $target));
    }

    public function testAdministeringTheOtherOrganizationCoversItsRoles(): void
    {
        $actor = array_merge(self::administrator(), array(self::membership(2, self::OTHER_ORG, false, true)));
        $target = array(self::membership(20, self::OTHER_ORG, true), self::membership(21, self::OTHER_ORG, false, true));

        $this->assertTrue(RightsComparison::covers($actor, $target));
    }

    public function testAGlobalRoleIsNotCoveredByAdministeringOneOrganization(): void
    {
        // a role of a global category grants its rights in every organization
        $target = array(self::membership(30, null));

        $this->assertFalse(RightsComparison::covers(self::administrator(), $target));
        $this->assertTrue(RightsComparison::covers(array_merge(self::administrator(), array(self::membership(30, null))), $target), 'but by sharing it');
        $this->assertTrue(RightsComparison::covers(array(self::membership(1, null, false, true)), $target), 'or by a global administrator role');
    }
}
