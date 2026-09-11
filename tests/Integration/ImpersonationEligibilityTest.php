<?php
/**
 * Who may act as whom, and the record of an impersonation, against a real database.
 *
 * The rights of a user follow from memberships in organizations and roles, which is why none of this
 * can be described without the database.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\Impersonate\Tests\Integration;

require_once dirname(__DIR__) . '/autoload.php';

use Admidio\Infrastructure\Database;
use Admidio\Tests\Support\AdministratorTestCase;
use Admidio\Tests\Support\AdmidioTestFixture;
use Admidio\Users\Entity\User;
use AdmidioPlugin\Impersonate\Entity\ImpersonationLog;
use AdmidioPlugin\Impersonate\Service\ImpersonationService;

final class ImpersonationEligibilityTest extends AdministratorTestCase
{
    private AdmidioTestFixture $fixture;

    /**
     * The table of the plugin, as its installation creates it. MySQL ends a transaction on every DDL
     * statement, so the table is created once for the class and not inside the transaction of a test.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::runScript('uninstall.sql');
        self::runScript('install.sql');
    }

    public static function tearDownAfterClass(): void
    {
        self::runScript('uninstall.sql');

        parent::tearDownAfterClass();
    }

    private static function runScript(string $file): void
    {
        foreach (Database::getSqlStatementsFromSqlFile(dirname(__DIR__, 2) . '/db_scripts/' . $file) as $sql) {
            self::$gDb->queryPrepared($sql);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = new AdmidioTestFixture($this->getDatabase());
        ImpersonationService::resetCache();
        unset($_SESSION[ImpersonationService::SESSION_KEY]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION[ImpersonationService::SESSION_KEY]);
        ImpersonationService::resetCache();

        parent::tearDown();
    }

    private function administrator(): User
    {
        return $GLOBALS['gCurrentUser'];
    }

    private function loadUser(int $userId): User
    {
        return new User($this->getDatabase(), $GLOBALS['gProfileFields'], $userId);
    }

    private function createUser(): int
    {
        $login = 'imp_' . bin2hex(random_bytes(4));

        return (int)$this->fixture->createAndSaveUser($login, $login . '@example.org')['usr_id'];
    }

    private function createMemberOfThisOrganization(): User
    {
        $userId = $this->createUser();
        $roleId = (int)$this->fixture->createAndSaveRole('Impersonate ' . $userId, (int)$GLOBALS['gCurrentOrgId'])['rol_id'];
        $this->fixture->assignUserToRole($userId, $roleId);

        return $this->loadUser($userId);
    }

    private function createRoleInAnotherOrganization(): int
    {
        $orgId = (int)$this->fixture->createAndSaveOrganization('Impersonate other organization', 'IMPOTHER')['org_id'];

        return (int)$this->fixture->createAndSaveRole('Role of the other organization', $orgId)['rol_id'];
    }

    public function testAMemberOfTheOrganizationCanBeImpersonated(): void
    {
        $this->assertTrue(ImpersonationService::mayImpersonate($this->administrator()));
        $this->assertNull(ImpersonationService::checkTarget($this->administrator(), $this->createMemberOfThisOrganization()));
    }

    public function testNobodyActsAsThemselves(): void
    {
        $administrator = $this->administrator();

        $this->assertSame(
            'PLG_IMPERSONATE_TARGET_SELF',
            ImpersonationService::checkTarget($administrator, $this->loadUser((int)$administrator->getValue('usr_id')))
        );
    }

    public function testAUserWhoIsNoMemberOfThisOrganizationIsRefused(): void
    {
        $userId = $this->createUser();
        $this->fixture->assignUserToRole($userId, $this->createRoleInAnotherOrganization());

        $this->assertSame(
            'PLG_IMPERSONATE_TARGET_NOT_MEMBER',
            ImpersonationService::checkTarget($this->administrator(), $this->loadUser($userId)),
            'nobody can log in to an organization they are not a member of'
        );
    }

    public function testRolesInAnotherOrganizationRefuseTheImpersonation(): void
    {
        $target = $this->createMemberOfThisOrganization();
        $this->fixture->assignUserToRole((int)$target->getValue('usr_id'), $this->createRoleInAnotherOrganization());

        $this->assertSame(
            'PLG_IMPERSONATE_TARGET_MORE_RIGHTS',
            ImpersonationService::checkTarget($this->administrator(), $target)
        );
    }

    public function testSharingTheRoleOfTheOtherOrganizationIsEnough(): void
    {
        $target = $this->createMemberOfThisOrganization();
        $otherRoleId = $this->createRoleInAnotherOrganization();
        $this->fixture->assignUserToRole((int)$target->getValue('usr_id'), $otherRoleId);
        $this->fixture->assignUserToRole((int)$this->administrator()->getValue('usr_id'), $otherRoleId);

        $this->assertNull(ImpersonationService::checkTarget($this->administrator(), $target));
    }

    public function testOnlyAdministratorsMayImpersonate(): void
    {
        $member = $this->createMemberOfThisOrganization();

        $this->assertFalse(ImpersonationService::mayImpersonate($member));
        $this->assertSame('SYS_NO_RIGHTS', ImpersonationService::checkTarget($member, $this->createMemberOfThisOrganization()));
    }

    public function testImpersonationsDoNotNest(): void
    {
        $_SESSION[ImpersonationService::SESSION_KEY] = array('uuid' => 'x', 'admin_id' => 1, 'admin_name' => 'A',
            'target_id' => 2, 'target_name' => 'B', 'org_id' => 1, 'begin' => time());

        $this->assertSame(
            'PLG_IMPERSONATE_ALREADY_ACTIVE',
            ImpersonationService::checkTarget($this->administrator(), $this->createMemberOfThisOrganization())
        );
    }

    public function testTheRecordOfAnImpersonationIsListedInTheHistory(): void
    {
        $target = $this->createMemberOfThisOrganization();

        $record = new ImpersonationLog($this->getDatabase());
        $record->setValue('imp_org_id', (int)$GLOBALS['gCurrentOrgId']);
        $record->setValue('imp_usr_id_admin', (int)$this->administrator()->getValue('usr_id'));
        $record->setValue('imp_usr_id_target', (int)$target->getValue('usr_id'));
        $record->setValue('imp_admin_name', 'Max Admin (max)');
        $record->setValue('imp_target_name', ImpersonationService::describe($target));
        $record->setValue('imp_ip_address', '127.0.0.1');
        $record->setValue('imp_begin', DATETIME_NOW);
        $record->save();

        $uuid = (string)$record->getValue('imp_uuid');
        $this->assertNotSame('', $uuid, 'the record gets its UUID when it is saved');

        $stored = new ImpersonationLog($this->getDatabase());
        $this->assertTrue($stored->readDataByUuid($uuid));
        $this->assertSame('', (string)$stored->getValue('imp_end'), 'a running impersonation has no end');
        $this->assertSame('', (string)$stored->getValue('imp_admin_auth_time'));

        $history = ImpersonationService::getHistory();
        $this->assertNotEmpty($history);
        $this->assertSame('Max Admin (max)', $history[0]['admin']);
        $this->assertSame(ImpersonationService::describe($target), $history[0]['target']);
        $this->assertSame('', $history[0]['end']);
    }
}
