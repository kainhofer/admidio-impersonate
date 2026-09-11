<?php
/**
 * The parts of the service that need no database: which scripts are refused, what the session
 * remembers and the settings of a plugin nobody configured.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\Impersonate\Tests\Unit;

require_once dirname(__DIR__) . '/autoload.php';

use Admidio\Tests\Unit\Plugins\Support\PluginTestCase;
use AdmidioPlugin\Impersonate\Service\ImpersonationService;

final class ImpersonationServiceTest extends PluginTestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION[ImpersonationService::SESSION_KEY]);

        parent::tearDown();
    }

    public function testTheSingleSignOnEndpointsAreRefused(): void
    {
        $this->assertSame(
            'PLG_IMPERSONATE_BLOCKED_SSO',
            ImpersonationService::getBlockedMessage(ADMIDIO_PATH . FOLDER_MODULES . '/sso/index.php')
        );
    }

    public function testSendingMessagesAndECardsIsRefused(): void
    {
        foreach (array('/messages/messages_send.php', '/photos/ecard_send.php') as $script) {
            $this->assertSame(
                'PLG_IMPERSONATE_BLOCKED_MESSAGES',
                ImpersonationService::getBlockedMessage(ADMIDIO_PATH . FOLDER_MODULES . $script),
                $script . ' is refused'
            );
        }
    }

    public function testEverythingElseIsAllowed(): void
    {
        $allowed = array(
            ADMIDIO_PATH . FOLDER_MODULES . '/messages/messages_write.php' => 'writing a message is only refused when it is sent',
            ADMIDIO_PATH . FOLDER_MODULES . '/sso/clients.php' => 'the client administration is no endpoint',
            ADMIDIO_PATH . FOLDER_MODULES . '/profile/password.php' => 'the password can be changed, as an administrator could anyway',
            ADMIDIO_PATH . '/system/logout.php' => 'the logout returns to the administrator',
            ADMIDIO_PATH . '/does/not/exist.php' => 'a path that does not exist',
            '' => 'a request without a script'
        );

        foreach ($allowed as $script => $why) {
            $this->assertNull(ImpersonationService::getBlockedMessage($script), $why);
        }
    }

    public function testASessionWithoutAnImpersonationHasNoState(): void
    {
        unset($_SESSION[ImpersonationService::SESSION_KEY]);

        $this->assertNull(ImpersonationService::getState());
        $this->assertFalse(ImpersonationService::isActive());
    }

    public function testARememberedImpersonationIsNotActiveWithoutALogin(): void
    {
        $_SESSION[ImpersonationService::SESSION_KEY] = array('uuid' => 'x', 'admin_id' => 1, 'admin_name' => 'A',
            'target_id' => 2, 'target_name' => 'B', 'org_id' => 1, 'begin' => time());
        $GLOBALS['gValidLogin'] = false;

        $this->assertSame(2, ImpersonationService::getState()['target_id']);
        $this->assertFalse(ImpersonationService::isActive(), 'a session that expired is no impersonation any more');
    }

    public function testThePluginWithoutPreferencesUsesTheDefaultsOfItsManifest(): void
    {
        // the test plugins path does not contain the plugin, so nothing was ever configured
        $this->assertSame(
            array('roles' => array('All'), 'subsetOnly' => true, 'maxMinutes' => 30, 'notifyUser' => false),
            ImpersonationService::getSettings()
        );
    }
}
