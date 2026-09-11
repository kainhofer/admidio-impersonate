<?php
/**
 ***********************************************************************************************
 * Return from an impersonation to the administrator.
 *
 * Reached from the banner and, through the logout_target resolver, from the logout. Like the logout
 * it is a plain link without a CSRF token: all a forged request could do is hand the session back to
 * the administrator it belongs to.
 *
 * Parameters:
 *
 * reason : stop   - (default) the administrator returned through the banner
 *          logout - the administrator logged out
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use AdmidioPlugin\Impersonate\Service\ImpersonationService;

try {
    require_once(__DIR__ . '/../../../system/common.php');

    $getReason = admFuncVariableIsValid($_GET, 'reason', 'string', array(
        'defaultValue' => ImpersonationService::END_STOP,
        'validValues' => array(ImpersonationService::END_STOP, ImpersonationService::END_LOGOUT)
    ));

    if (ImpersonationService::getState() === null) {
        admRedirect($gHomepage);
        // => EXIT
    }

    if (ImpersonationService::stop($getReason)) {
        $gMessage->setForwardUrl(ADMIDIO_URL . FOLDER_MODULES . '/contacts/contacts.php', 2000);
        $gMessage->show($gL10n->get('PLG_IMPERSONATE_STOPPED'));
    } else {
        $gMessage->setForwardUrl(ADMIDIO_URL . '/' . $gSettingsManager->getString('homepage_logout'), 3000);
        $gMessage->show($gL10n->get('PLG_IMPERSONATE_LOST'));
    }
    // => EXIT
} catch (Throwable $e) {
    handleException($e);
}
