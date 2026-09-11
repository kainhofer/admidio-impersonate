<?php
/**
 ***********************************************************************************************
 * Start acting as another user.
 *
 * Reached from the link in the contacts list, as a POST with the CSRF token of the session, because
 * it changes whose session this is. Every check of the link is repeated here.
 *
 * Parameters:
 *
 * user_uuid : UUID of the user the administrator wants to act as
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Users\Entity\User;
use AdmidioPlugin\Impersonate\Service\ImpersonationService;

try {
    require_once(__DIR__ . '/../../../system/common.php');
    require(__DIR__ . '/../../../system/login_valid.php');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('SYS_INVALID_PAGE_VIEW');
    }
    SecurityUtils::validateCsrfToken((string)($_POST['adm_csrf_token'] ?? ''));

    $postUserUuid = admFuncVariableIsValid($_POST, 'user_uuid', 'uuid', array('requireValue' => true));

    $target = new User($gDb, $gProfileFields);
    if (!$target->readDataByUuid($postUserUuid)) {
        throw new Exception('SYS_INVALID_PAGE_VIEW');
    }

    ImpersonationService::start($target);

    admRedirect(ADMIDIO_URL . '/' . $gSettingsManager->getString('homepage_login'));
    // => EXIT
} catch (Throwable $e) {
    handleException($e);
}
