<?php
/**
 ***********************************************************************************************
 * The history of impersonations in the current organization.
 *
 * Only for administrators. It is linked from the preferences panel of the plugin.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Plugins\Plugin;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\Impersonate\Impersonate;
use AdmidioPlugin\Impersonate\Service\ImpersonationService;

try {
    require_once(__DIR__ . '/../../../system/common.php');
    require(__DIR__ . '/../../../system/login_valid.php');

    if (!$gCurrentUser->isAdministrator()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $plugin = PluginRegistry::get(Impersonate::PLUGIN_ID);

    $page = PagePresenter::withHtmlIDAndHeadline('adm_plg_impersonate_history', $gL10n->get('PLG_IMPERSONATE_HISTORY'));
    $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());

    $page->addTemplateFolder($plugin->getDirectory(Plugin::DIR_TEMPLATES));
    $page->addTemplateFile('plugin.impersonate.history.tpl');
    $page->assignSmartyVariable('impersonations', ImpersonationService::getHistory());
    $page->show();
} catch (Throwable $e) {
    handleException($e);
}
