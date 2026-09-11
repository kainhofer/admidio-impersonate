<?php
/**
 * Starting and ending an impersonation, against a real database and a real session.
 *
 * The session is built the way UserAuthenticationTest builds one, with an explicit ID, and it keeps
 * the user object the way system/common.php makes it keep it: the global is a reference to the object
 * in the session. That reference is what the switch relies on.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\Impersonate\Tests\Integration;

require_once dirname(__DIR__) . '/autoload.php';

use Admidio\Application\Navigation;
use Admidio\Changelog\Entity\LogChanges;
use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Exception;
use Admidio\Session\Entity\Session;
use Admidio\Tests\Support\AdministratorTestCase;
use Admidio\Tests\Support\AdmidioTestFixture;
use Admidio\Users\Entity\User;
use AdmidioPlugin\Impersonate\Entity\ImpersonationLog;
use AdmidioPlugin\Impersonate\Service\ImpersonationService;

final class ImpersonationLifecycleTest extends AdministratorTestCase
{
    /**
     * How the administrator authenticated, as a login with a second factor leaves it in the session.
     */
    private const AUTHENTICATION_TIME = '2026-09-11 10:00:00';
    private const AUTHENTICATION_METHODS = 'pwd otp';

    private AdmidioTestFixture $fixture;
    private Session $session;

    /**
     * The request globals this test replaces, as name => value before the test.
     * @var array<string,mixed>
     */
    private array $previousGlobals = array();

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

        foreach (array('gCurrentSession', 'gMenu', 'gNavigation', 'gCurrentUserUUID') as $name) {
            $this->previousGlobals[$name] = $GLOBALS[$name] ?? null;
        }

        $administrator = $GLOBALS['gCurrentUser'];
        $GLOBALS['gCurrentUserUUID'] = (string)$administrator->getValue('usr_uuid');

        $_COOKIE[COOKIE_PREFIX . '_SESSION_ID'] = 'impersonate-' . bin2hex(random_bytes(8));
        $this->session = new Session($this->getDatabase(), COOKIE_PREFIX);
        $this->session->setValue('ses_usr_id', (int)$administrator->getValue('usr_id'));
        $this->session->setValue('ses_authentication_time', self::AUTHENTICATION_TIME);
        $this->session->setValue('ses_authentication_methods', self::AUTHENTICATION_METHODS);
        $this->session->save();
        $this->session->addObject('gCurrentUser', $GLOBALS['gCurrentUser']);

        $GLOBALS['gCurrentSession'] = $this->session;
        // the switch only asks the menu to forget itself
        $GLOBALS['gMenu'] = new class {
            public function initialize(): void
            {
            }
        };
        $GLOBALS['gNavigation'] = new Navigation();
    }

    protected function tearDown(): void
    {
        unset($_SESSION[ImpersonationService::SESSION_KEY], $_COOKIE[COOKIE_PREFIX . '_SESSION_ID']);
        LogChanges::setOriginComment('');
        ImpersonationService::resetCache();

        foreach ($this->previousGlobals as $name => $value) {
            $GLOBALS[$name] = $value;
        }

        parent::tearDown();
    }

    private function loadUser(int $userId): User
    {
        return new User($this->getDatabase(), $GLOBALS['gProfileFields'], $userId);
    }

    private function createMemberOfThisOrganization(): User
    {
        $login = 'imp_' . bin2hex(random_bytes(4));
        $userId = (int)$this->fixture->createAndSaveUser($login, $login . '@example.org')['usr_id'];
        $roleId = (int)$this->fixture->createAndSaveRole('Impersonate ' . $userId, (int)$GLOBALS['gCurrentOrgId'])['rol_id'];
        $this->fixture->assignUserToRole($userId, $roleId);

        return $this->loadUser($userId);
    }

    private function record(string $uuid): ImpersonationLog
    {
        $record = new ImpersonationLog($this->getDatabase());
        $this->assertTrue($record->readDataByUuid($uuid), 'the impersonation is recorded');

        return $record;
    }

    public function testStartingHandsTheSessionToTheUserAndStoppingHandsItBack(): void
    {
        $administratorId = (int)$GLOBALS['gCurrentUser']->getValue('usr_id');
        $target = $this->createMemberOfThisOrganization();
        $targetId = (int)$target->getValue('usr_id');
        $loginsBefore = (int)$target->getValue('usr_number_login');

        ImpersonationService::start($target);

        $this->assertSame($targetId, (int)$this->session->getValue('ses_usr_id'));
        $this->assertSame($targetId, (int)$this->session->getObject('gCurrentUser')->getValue('usr_id'), 'the user object the session keeps is replaced');
        $this->assertSame($targetId, (int)$GLOBALS['gCurrentUserId']);
        $this->assertSame('', (string)$this->session->getValue('ses_authentication_time', 'Y-m-d H:i:s'), 'no single sign-on assertion can be issued from the session');
        $this->assertSame('', (string)$this->session->getValue('ses_authentication_methods', 'database'));
        $this->assertTrue(ImpersonationService::isActive());
        $this->assertNotSame('', ImpersonationService::getChangelogComment());
        $this->assertSame($loginsBefore, (int)$this->loadUser($targetId)->getValue('usr_number_login'), 'acting as the user is no login of the user');

        $uuid = (string)ImpersonationService::getState()['uuid'];
        $this->assertTrue(ImpersonationService::stop(ImpersonationService::END_STOP));

        $this->assertNull(ImpersonationService::getState());
        $this->assertSame($administratorId, (int)$this->session->getValue('ses_usr_id'));
        $this->assertSame($administratorId, (int)$this->session->getObject('gCurrentUser')->getValue('usr_id'));
        $this->assertSame(self::AUTHENTICATION_TIME, (string)$this->session->getValue('ses_authentication_time', 'Y-m-d H:i:s'), 'the authentication of the administrator is back');
        $this->assertSame(self::AUTHENTICATION_METHODS, (string)$this->session->getValue('ses_authentication_methods', 'database'));

        $record = $this->record($uuid);
        $this->assertSame($administratorId, (int)$record->getValue('imp_usr_id_admin'));
        $this->assertSame($targetId, (int)$record->getValue('imp_usr_id_target'));
        $this->assertSame(ImpersonationService::END_STOP, (string)$record->getValue('imp_end_reason', 'database'));
        $this->assertNotSame('', (string)$record->getValue('imp_end', 'Y-m-d H:i:s'));
    }

    public function testAnImpersonationWhoseSessionChangedUserIsRecordedAsLost(): void
    {
        $administratorId = (int)$GLOBALS['gCurrentUser']->getValue('usr_id');
        ImpersonationService::start($this->createMemberOfThisOrganization());
        $uuid = (string)ImpersonationService::getState()['uuid'];

        // the session expired and the auto login of the administrator took it over again
        $this->session->setValue('ses_usr_id', $administratorId);
        ImpersonationService::enforce();

        $this->assertNull(ImpersonationService::getState());
        $this->assertSame($administratorId, (int)$this->session->getValue('ses_usr_id'), 'nothing is switched');
        $this->assertSame(ImpersonationService::END_LOST, (string)$this->record($uuid)->getValue('imp_end_reason', 'database'));
    }

    public function testStoppingWithoutAnImpersonationChangesNothing(): void
    {
        $administratorId = (int)$GLOBALS['gCurrentUser']->getValue('usr_id');

        $this->assertFalse(ImpersonationService::stop(ImpersonationService::END_STOP));
        $this->assertSame($administratorId, (int)$this->session->getValue('ses_usr_id'));
    }

    public function testARefusedImpersonationLeavesTheSessionAlone(): void
    {
        $administratorId = (int)$GLOBALS['gCurrentUser']->getValue('usr_id');

        try {
            ImpersonationService::start($this->loadUser($administratorId));
            $this->fail('acting as oneself is refused');
        } catch (Exception $exception) {
            $this->assertNull(ImpersonationService::getState());
            $this->assertSame($administratorId, (int)$this->session->getValue('ses_usr_id'));
            $this->assertSame(self::AUTHENTICATION_METHODS, (string)$this->session->getValue('ses_authentication_methods', 'database'));
        }
    }
}
