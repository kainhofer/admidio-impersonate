<?php

namespace AdmidioPlugin\Impersonate;

use Admidio\Hooks\Hooks;
use Admidio\Infrastructure\Plugins\PluginPanel;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\PagePresenter;
use Admidio\Users\Entity\User;
use AdmidioPlugin\Impersonate\Presenter\ImpersonatePreferencesPresenter;
use AdmidioPlugin\Impersonate\Service\ImpersonationService;

/**
 * What the plugin contributes to Admidio, as hook callbacks.
 *
 * Two contributions are always there: the preferences panel and the link in the contacts list. The
 * others only exist while the current session is an impersonation, so that an installation in which
 * nobody impersonates anybody pays nothing for them - the **translation_text** filter in particular
 * runs for every text of every page once somebody listens to it.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */
final class Impersonate
{
    /**
     * The directory of the plugin, which is its ID and its only identity.
     */
    public const PLUGIN_ID = 'impersonate';

    /**
     * The texts of the profile change notifications. Their placeholder **#VAR4#** is the user who made
     * the change, which during an impersonation is the impersonated user.
     */
    private const NOTIFICATION_TEXTS = array(
        'SYS_EMAIL_CHANGE_NOTIFICATION_MESSAGE',
        'SYS_EMAIL_CREATE_NOTIFICATION_MESSAGE',
        'SYS_EMAIL_DELETE_NOTIFICATION_MESSAGE'
    );

    /**
     * The class only offers static methods and must not be instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Register the hooks of the plugin. This is what plugin.php calls.
     * @return void
     * @throws \Admidio\Infrastructure\Exception
     */
    public static function register(): void
    {
        $plugin = PluginRegistry::get(self::PLUGIN_ID);
        if ($plugin === null) {
            return;
        }

        Hooks::addFilter(
            PluginPanel::HOOK,
            static function (array $panels) use ($plugin): array {
                global $gL10n;

                $panels[] = array(
                    'id' => PluginPanel::normalizeId($plugin->id),
                    'title' => $gL10n->get($plugin->name),
                    'icon' => $plugin->icon,
                    'create' => array(ImpersonatePreferencesPresenter::class, 'createForm')
                );

                return $panels;
            },
            PluginPanel::DEFAULT_SEQUENCE,
            1,
            $plugin->id
        );

        Hooks::addFilter('list_row_actions', array(self::class, 'addContactsAction'), Hooks::DEFAULT_PRIORITY, 3, self::PLUGIN_ID);

        // An impersonation that has run out, or whose session is gone, ends here - before anything
        // else of this request runs in the name of the impersonated user.
        ImpersonationService::enforce();

        if (ImpersonationService::isActive()) {
            Hooks::addAction('page_before_render', array(self::class, 'decoratePage'), Hooks::DEFAULT_PRIORITY, 1, self::PLUGIN_ID);
            Hooks::addResolver('logout_target', array(self::class, 'getLogoutTarget'), Hooks::DEFAULT_PRIORITY, 1, self::PLUGIN_ID);
            Hooks::addFilter('translation_text', array(self::class, 'annotateNotification'), Hooks::DEFAULT_PRIORITY, 2, self::PLUGIN_ID);
        }
    }

    /**
     * Callback of the **list_row_actions** filter: add the impersonation link to a row of the contacts
     * list, if the current user may act as the contact of that row.
     *
     * The link is only a convenience. start.php checks everything again, because the list is not the
     * only way a request can reach it.
     * @param string $actions The HTML of the actions the row has so far.
     * @param string $listId The list the row belongs to.
     * @param array<string,mixed> $row The data of the row.
     * @return string
     * @throws \Admidio\Infrastructure\Exception
     */
    public static function addContactsAction(string $actions, string $listId, array $row): string
    {
        global $gCurrentUser, $gCurrentUserUUID, $gCurrentSession, $gL10n;

        $targetUuid = (string)($row['usr_uuid'] ?? '');

        if ($listId !== 'contacts' || $targetUuid === '' || $targetUuid === (string)$gCurrentUserUUID
            || empty($row['member_this_orga'])
            || ImpersonationService::getState() !== null
            || !ImpersonationService::mayImpersonate($gCurrentUser)
            || !ImpersonationService::isCoveredTarget((string)$gCurrentUserUUID, $targetUuid)) {
            return $actions;
        }

        $plugin = PluginRegistry::get(self::PLUGIN_ID);
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

        // messageBox() puts the message into the dialog as HTML, so the name is encoded once for that
        // and the whole message once more for the attribute it travels in.
        $message = $gL10n->get('PLG_IMPERSONATE_CONFIRM', array(SecurityUtils::encodeHTML($name)));
        $onConfirm = 'redirectPost(\'' . $plugin->getUrl('start.php') . '\', {adm_csrf_token: \''
            . $gCurrentSession->getCsrfToken() . '\', user_uuid: \'' . $targetUuid . '\'})';

        return $actions . '
            <a class="admidio-icon-link admidio-messagebox" href="javascript:void(0);" data-buttons="yes-no"
                data-message="' . SecurityUtils::encodeHTML($message) . '"
                data-href="' . SecurityUtils::encodeHTML($onConfirm) . '">
                <i class="bi bi-incognito" data-bs-toggle="tooltip" title="' . SecurityUtils::encodeHTML($gL10n->get('PLG_IMPERSONATE_ACTION', array($name))) . '"></i></a>';
    }

    /**
     * Callback of the **page_before_render** action: mark every page of an impersonated session, with
     * a banner that offers the way back and with colors that cannot be overlooked.
     *
     * Content that is loaded into a dialog or into a reduced page is left alone; it is shown inside a
     * page that already carries the banner.
     * @param PagePresenter $page
     * @return void
     * @throws \Admidio\Infrastructure\Exception|\Smarty\Exception
     */
    public static function decoratePage(PagePresenter $page): void
    {
        global $gLayoutReduced, $gL10n, $gSettingsManager;

        $state = ImpersonationService::getState();
        if ($state === null || !ImpersonationService::isActive() || !empty($gLayoutReduced)
            || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
            return;
        }

        $plugin = PluginRegistry::get(self::PLUGIN_ID);
        $endsAt = (int)$state['begin'] + ImpersonationService::getSettings()['maxMinutes'] * 60;

        $page->addCssFile($plugin->getAssetUrl('impersonate.css'));
        $page->addHtml($plugin->renderTemplate($page, 'plugin.impersonate.banner.tpl', array(
            'impersonateText' => $gL10n->get('PLG_IMPERSONATE_BANNER', array(
                '<strong>' . SecurityUtils::encodeHTML((string)$state['target_name']) . '</strong>',
                SecurityUtils::encodeHTML((string)$state['admin_name']),
                date($gSettingsManager->getString('system_time'), $endsAt)
            )),
            'impersonateStopUrl' => $plugin->getUrl('stop.php')
        )));

        // The page content is placed below the headline, the banner belongs above it.
        $page->addJavascript('$("#adm_plg_impersonate_banner").prependTo("#adm_content");', true);
    }

    /**
     * Resolver of **logout_target**: a logout during an impersonation returns to the administrator
     * instead of ending the session.
     * @param User|null $user The user who is logging out, i.e. the impersonated one.
     * @return string|null
     */
    public static function getLogoutTarget(?User $user = null): ?string
    {
        if (!ImpersonationService::isActive()) {
            return null;
        }

        return SecurityUtils::encodeUrl(
            PluginRegistry::get(self::PLUGIN_ID)->getUrl('stop.php'),
            array('reason' => ImpersonationService::END_LOGOUT)
        );
    }

    /**
     * Callback of the **translation_text** filter: a profile change notification that is sent during an
     * impersonation names the administrator next to the user who seemingly made the change.
     *
     * The text is changed before its placeholders are filled, so the note follows **#VAR4#** in every
     * language and in both the plain text and the HTML mail.
     * @param string $text The text as the language file has it.
     * @param string $textId The ID of the text.
     * @return string
     * @throws \Admidio\Infrastructure\Exception
     */
    public static function annotateNotification(string $text, string $textId): string
    {
        global $gL10n;

        if (!in_array($textId, self::NOTIFICATION_TEXTS, true) || !ImpersonationService::isActive()) {
            return $text;
        }

        $state = ImpersonationService::getState();
        $note = $gL10n->get('PLG_IMPERSONATE_NOTIFICATION_NOTE', array((string)$state['admin_name']));

        return str_replace('#VAR4#', '#VAR4# ' . $note, $text);
    }
}
