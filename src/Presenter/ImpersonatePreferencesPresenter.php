<?php

namespace AdmidioPlugin\Impersonate\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Language;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Roles\Service\RolesService;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PreferencesPresenter;
use AdmidioPlugin\Impersonate\Impersonate;
use AdmidioPlugin\Impersonate\Service\ImpersonationService;

/**
 * The preferences panel of the plugin.
 *
 * The form could almost be generated from the manifest, but the roles are a list the manifest cannot
 * offer as a select box, and the panel is where an administrator finds the history of impersonations.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */
final class ImpersonatePreferencesPresenter
{
    private const TEMPLATE = 'preferences.plugin.impersonate.tpl';

    /**
     * The class only offers static methods and must not be instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Build the HTML of the panel.
     * @param PreferencesPresenter $page
     * @return string
     * @throws Exception|\Smarty\Exception
     */
    public static function createForm(PreferencesPresenter $page): string
    {
        global $gL10n, $gCurrentSession, $gDb;

        $plugin = PluginRegistry::requireEnabled(Impersonate::PLUGIN_ID);
        $settings = $plugin->settings;
        $values = $plugin->getSettingValues();

        $form = new FormPresenter(
            'adm_preferences_form_impersonate',
            self::TEMPLATE,
            SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/preferences.php', array('mode' => 'save', 'panel' => 'impersonate')),
            null,
            array('class' => 'form-preferences')
        );

        $roles = array(array(ImpersonationService::ALL_ADMINISTRATORS, $gL10n->get('PLG_IMPERSONATE_ALL_ADMINISTRATORS')));
        foreach ((new RolesService($gDb))->findAll() as $role) {
            $roles[] = array($role['rol_id'], Language::translateIfTranslationStrId((string)$role['rol_name']));
        }

        $form->addSelectBox(
            'impersonate_roles',
            Language::translateIfTranslationStrId($settings['impersonate_roles']['label']),
            $roles,
            array(
                'defaultValue' => $values['impersonate_roles'],
                'showContextDependentFirstEntry' => false,
                'helpTextId' => $settings['impersonate_roles']['description'],
                'multiselect' => true,
                'maximumSelectionNumber' => count($roles)
            )
        );
        $form->addCheckbox(
            'impersonate_targets_subset_only',
            Language::translateIfTranslationStrId($settings['impersonate_targets_subset_only']['label']),
            (bool)$values['impersonate_targets_subset_only'],
            array('helpTextId' => $settings['impersonate_targets_subset_only']['description'])
        );
        $form->addInput(
            'impersonate_max_minutes',
            Language::translateIfTranslationStrId($settings['impersonate_max_minutes']['label']),
            (string)$values['impersonate_max_minutes'],
            array(
                'type' => 'number',
                'minNumber' => 1,
                'step' => 1,
                'helpTextId' => $settings['impersonate_max_minutes']['description']
            )
        );
        $form->addCheckbox(
            'impersonate_notify_user',
            Language::translateIfTranslationStrId($settings['impersonate_notify_user']['label']),
            (bool)$values['impersonate_notify_user'],
            array('helpTextId' => $settings['impersonate_notify_user']['description'])
        );
        $form->addSubmitButton(
            'adm_button_save_impersonate',
            $gL10n->get('SYS_SAVE'),
            array('icon' => 'bi-check-lg', 'class' => 'offset-sm-3')
        );

        $form->addToSmarty($page->getSmartyTemplate());
        $gCurrentSession->addFormObject($form);

        return $plugin->renderTemplate($page, self::TEMPLATE, array('impersonateHistoryUrl' => $plugin->getUrl('index.php')));
    }
}
