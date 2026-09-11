<?php

namespace AdmidioPlugin\Impersonate\Service;

use Admidio\Changelog\Entity\LogChanges;
use Admidio\Infrastructure\Email;
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Users\Entity\User;
use AdmidioPlugin\Impersonate\Entity\ImpersonationLog;
use AdmidioPlugin\Impersonate\Impersonate;
use Throwable;

/**
 * Starting, ending and checking an impersonation.
 *
 * Admidio knows who is logged in from one value, **ses_usr_id** of the session. Acting as another user
 * therefore means writing that user into the session and replacing the user object the session keeps,
 * exactly as the login does - and nothing of what a real login does besides: the login counters and
 * the last login of the user stay untouched, because the user did not log in.
 *
 * What the session was before is kept in two places. The PHP session holds it for the requests of this
 * browser, the table of the plugin holds it as the record. Returning to the administrator requires both
 * to agree; if they do not, the session is ended instead, because staying in the name of somebody else
 * is the one outcome that must not happen.
 *
 * While the impersonation lasts, the session has no authentication time and methods. They are what the
 * single sign-on endpoints issue their assertions from, and the endpoints are refused as well, so no
 * other application can be logged in with the identity of the user - not even if the plugin is
 * disabled in the middle of an impersonation. Neither the PHP session ID nor the external session ID
 * changes, so the single sign-on sessions of the administrator are still there afterwards.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */
final class ImpersonationService
{
    /**
     * The key of the PHP session under which a running impersonation is kept.
     */
    public const SESSION_KEY = 'plg_impersonate';

    /**
     * The value of the role setting that means "every administrator", like the sentinel of the
     * calendar plugin.
     */
    public const ALL_ADMINISTRATORS = 'All';

    /**
     * How an impersonation ended, as stored in **imp_end_reason**.
     */
    public const END_STOP = 'stop';
    public const END_LOGOUT = 'logout';
    public const END_TIMEOUT = 'timeout';
    public const END_LOST = 'lost';

    /**
     * The current memberships of the users asked about in this request, as UUID => memberships. The
     * contacts list asks once per row, always about the same administrator.
     * @var array<string,array<int,array{role: int, org: int|null, leader: bool, admin: bool}>>
     */
    private static array $memberships = array();

    /**
     * The class only offers static methods and must not be instantiated.
     */
    private function __construct()
    {
    }

    /**
     * The settings of the plugin, in the shape the service works with.
     * @return array{roles: array<int,string>, subsetOnly: bool, maxMinutes: int, notifyUser: bool}
     * @throws Exception
     */
    public static function getSettings(): array
    {
        $plugin = PluginRegistry::get(Impersonate::PLUGIN_ID);
        $values = $plugin === null ? array() : $plugin->getSettingValues();

        return array(
            'roles' => array_map('strval', (array)($values['impersonate_roles'] ?? array(self::ALL_ADMINISTRATORS))),
            'subsetOnly' => (bool)($values['impersonate_targets_subset_only'] ?? true),
            'maxMinutes' => max(1, (int)($values['impersonate_max_minutes'] ?? 30)),
            'notifyUser' => (bool)($values['impersonate_notify_user'] ?? false)
        );
    }

    /**
     * The impersonation this PHP session is in, or **null**.
     * @return array{uuid: string, admin_id: int, admin_name: string, target_id: int, target_name: string, org_id: int, begin: int}|null
     */
    public static function getState(): ?array
    {
        $state = $_SESSION[self::SESSION_KEY] ?? null;

        return is_array($state) ? $state : null;
    }

    /**
     * Whether the current request runs as an impersonated user. The session has to be logged in as the
     * user the impersonation was started for; a session that expired or was logged in anew in between
     * is not an impersonation any more, whatever the PHP session still remembers.
     * @return bool
     */
    public static function isActive(): bool
    {
        global $gCurrentSession, $gValidLogin;

        $state = self::getState();

        return $state !== null && !empty($gValidLogin) && isset($gCurrentSession)
            && (int)$gCurrentSession->getValue('ses_usr_id') === (int)$state['target_id'];
    }

    /**
     * Whether a user may act as other users at all: an administrator, and a member of one of the roles
     * of the setting unless that names every administrator.
     * @param User $actor
     * @return bool
     * @throws Exception
     */
    public static function mayImpersonate(User $actor): bool
    {
        if ((int)$actor->getValue('usr_id') === 0 || !$actor->isAdministrator()) {
            return false;
        }

        $roles = self::getSettings()['roles'];
        if ($roles === array() || in_array(self::ALL_ADMINISTRATORS, $roles, true)) {
            return true;
        }

        // isAdministrator() has read the memberships, which isMemberOfRole() only looks up
        foreach ($roles as $roleId) {
            if ((int)$roleId > 0 && $actor->isMemberOfRole((int)$roleId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why the actor must not act as the target, or **null** if they may.
     * @param User $actor
     * @param User $target
     * @return string|null The ID of the text that explains the refusal.
     * @throws Exception
     */
    public static function checkTarget(User $actor, User $target): ?string
    {
        if (self::getState() !== null) {
            return 'PLG_IMPERSONATE_ALREADY_ACTIVE';
        }

        if (!self::mayImpersonate($actor)) {
            return 'SYS_NO_RIGHTS';
        }

        $targetId = (int)$target->getValue('usr_id');
        if ($targetId === 0 || !(bool)$target->getValue('usr_valid')) {
            return 'PLG_IMPERSONATE_TARGET_INVALID';
        }

        if ($targetId === (int)$actor->getValue('usr_id')) {
            return 'PLG_IMPERSONATE_TARGET_SELF';
        }

        // Nobody can log in to an organization they are not a member of, so acting as them there would
        // not be "as if they had logged in".
        if (!$target->isMemberOfOrganization()) {
            return 'PLG_IMPERSONATE_TARGET_NOT_MEMBER';
        }

        if (!self::isCoveredTarget((string)$actor->getValue('usr_uuid'), (string)$target->getValue('usr_uuid'))) {
            return 'PLG_IMPERSONATE_TARGET_MORE_RIGHTS';
        }

        return null;
    }

    /**
     * Whether acting as the target stays within what the actor may do anyway. Always **true** if the
     * administrator has switched the check off.
     * @param string $actorUuid
     * @param string $targetUuid
     * @return bool
     * @throws Exception
     */
    public static function isCoveredTarget(string $actorUuid, string $targetUuid): bool
    {
        if (!self::getSettings()['subsetOnly']) {
            return true;
        }

        return RightsComparison::covers(self::readMemberships($actorUuid), self::readMemberships($targetUuid));
    }

    /**
     * The current memberships of a user in every organization.
     * @param string $userUuid
     * @return array<int,array{role: int, org: int|null, leader: bool, admin: bool}>
     * @throws Exception
     */
    public static function readMemberships(string $userUuid): array
    {
        global $gDb;

        if (isset(self::$memberships[$userUuid])) {
            return self::$memberships[$userUuid];
        }

        $sql = 'SELECT rol_id, cat_org_id, mem_leader, rol_administrator
                  FROM ' . TBL_MEMBERS . '
            INNER JOIN ' . TBL_USERS . '
                    ON usr_id = mem_usr_id
            INNER JOIN ' . TBL_ROLES . '
                    ON rol_id = mem_rol_id
            INNER JOIN ' . TBL_CATEGORIES . '
                    ON cat_id = rol_cat_id
                 WHERE usr_uuid  = ? -- $userUuid
                   AND rol_valid = true
                   AND mem_begin <= ? -- DATE_NOW
                   AND mem_end    > ? -- DATE_NOW';
        $statement = $gDb->queryPrepared($sql, array($userUuid, DATE_NOW, DATE_NOW));

        $memberships = array();
        while ($row = $statement->fetch()) {
            $memberships[] = array(
                'role' => (int)$row['rol_id'],
                'org' => $row['cat_org_id'] === null ? null : (int)$row['cat_org_id'],
                'leader' => (bool)$row['mem_leader'],
                'admin' => (bool)$row['rol_administrator']
            );
        }

        return self::$memberships[$userUuid] = $memberships;
    }

    /**
     * Forget the memberships read so far. For tests, which change memberships between two questions.
     * @return void
     */
    public static function resetCache(): void
    {
        self::$memberships = array();
    }

    /**
     * Start acting as the target. The current user is the administrator.
     * @param User $target
     * @return void
     * @throws Exception
     */
    public static function start(User $target): void
    {
        global $gDb, $gCurrentUser, $gCurrentSession, $gCurrentOrgId;

        $admin = $gCurrentUser;
        $refusal = self::checkTarget($admin, $target);
        if ($refusal !== null) {
            throw new Exception($refusal);
        }

        $adminName = self::describe($admin);
        $targetName = self::describe($target);
        $authenticationTime = (string)$gCurrentSession->getValue('ses_authentication_time', 'Y-m-d H:i:s');

        $record = new ImpersonationLog($gDb);
        $record->setValue('imp_org_id', $gCurrentOrgId);
        $record->setValue('imp_usr_id_admin', (int)$admin->getValue('usr_id'));
        $record->setValue('imp_usr_id_target', (int)$target->getValue('usr_id'));
        $record->setValue('imp_admin_name', $adminName);
        $record->setValue('imp_target_name', $targetName);
        $record->setValue('imp_ip_address', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $record->setValue('imp_admin_auth_time', $authenticationTime === '' ? null : $authenticationTime);
        $record->setValue('imp_admin_auth_methods', (string)$gCurrentSession->getValue('ses_authentication_methods', 'database'));
        $record->setValue('imp_begin', DATETIME_NOW);
        $record->save();

        // still in the name of the administrator, who is the sender of the notification
        if (self::getSettings()['notifyUser']) {
            self::notifyTarget($target, $adminName);
        }

        $_SESSION[self::SESSION_KEY] = array(
            'uuid' => (string)$record->getValue('imp_uuid'),
            'admin_id' => (int)$admin->getValue('usr_id'),
            'admin_name' => $adminName,
            'target_id' => (int)$target->getValue('usr_id'),
            'target_name' => $targetName,
            'org_id' => (int)$gCurrentOrgId,
            'begin' => time()
        );

        self::switchTo((int)$target->getValue('usr_id'), null, null);
        LogChanges::setOriginComment(self::getChangelogComment());
    }

    /**
     * Return to the administrator.
     * @param string $reason One of the END_* constants.
     * @return bool **true** if the session belongs to the administrator again, **false** if there was
     *              nothing trustworthy to return to and the session was ended instead.
     * @throws Exception
     */
    public static function stop(string $reason): bool
    {
        global $gDb;

        $state = self::getState();
        if ($state === null) {
            return false;
        }

        unset($_SESSION[self::SESSION_KEY]);
        LogChanges::setOriginComment('');

        $record = new ImpersonationLog($gDb);
        $trusted = $record->readDataByUuid((string)$state['uuid'])
            && (int)$record->getValue('imp_usr_id_admin') === (int)$state['admin_id']
            && (int)$record->getValue('imp_usr_id_target') === (int)$state['target_id']
            && (string)$record->getValue('imp_end') === '';

        if (!$trusted) {
            self::endSession();
            return false;
        }

        $record->setValue('imp_end', DATETIME_NOW);
        $record->setValue('imp_end_reason', $reason);
        $record->save();

        $authenticationTime = (string)$record->getValue('imp_admin_auth_time', 'Y-m-d H:i:s');
        $authenticationMethods = (string)$record->getValue('imp_admin_auth_methods', 'database');
        self::switchTo(
            (int)$state['admin_id'],
            $authenticationTime === '' ? null : $authenticationTime,
            $authenticationMethods === '' ? null : $authenticationMethods
        );

        return true;
    }

    /**
     * Run at the start of every request of a session that remembers an impersonation: end it if its
     * session is gone or its time is up, otherwise mark the changes of the request and refuse what must
     * not be done in the name of somebody else.
     * @return void
     * @throws Exception
     */
    public static function enforce(): void
    {
        global $gMessage, $gL10n;

        $state = self::getState();
        if ($state === null) {
            return;
        }

        if (!self::isActive()) {
            // The session ended underneath the impersonation: it expired, or it was logged in anew.
            // Nothing is switched back, the session is no longer the impersonated user's anyway.
            unset($_SESSION[self::SESSION_KEY]);
            self::close((string)$state['uuid'], self::END_LOST);
            return;
        }

        if (time() >= (int)$state['begin'] + self::getSettings()['maxMinutes'] * 60) {
            $restored = self::stop(self::END_TIMEOUT);
            $gMessage->setForwardUrl(ADMIDIO_URL . FOLDER_MODULES . '/contacts/contacts.php', 3000);
            $gMessage->show($gL10n->get($restored ? 'PLG_IMPERSONATE_TIMEOUT' : 'PLG_IMPERSONATE_LOST'));
            // => EXIT
        }

        LogChanges::setOriginComment(self::getChangelogComment());
        self::guardScript();
    }

    /**
     * The comment every change history entry of an impersonated request gets.
     * @return string
     * @throws Exception
     */
    public static function getChangelogComment(): string
    {
        global $gL10n;

        $state = self::getState();

        return $state === null ? '' : $gL10n->get('PLG_IMPERSONATE_CHANGELOG_COMMENT', array((string)$state['admin_name']));
    }

    /**
     * The scripts that are refused during an impersonation, as path below the Admidio directory =>
     * ID of the text that explains why.
     *
     * The single sign-on endpoints would log other applications in with the identity of the user.
     * Messages, emails and e-cards would go out in the name of the user.
     * @return array<string,string>
     */
    public static function getBlockedScripts(): array
    {
        return array(
            FOLDER_MODULES . '/sso/index.php' => 'PLG_IMPERSONATE_BLOCKED_SSO',
            FOLDER_MODULES . '/messages/messages_send.php' => 'PLG_IMPERSONATE_BLOCKED_MESSAGES',
            FOLDER_MODULES . '/photos/ecard_send.php' => 'PLG_IMPERSONATE_BLOCKED_MESSAGES'
        );
    }

    /**
     * Why a script is refused during an impersonation, or **null** if it is not.
     * @param string $scriptFile The file system path of the script, as in SCRIPT_FILENAME.
     * @return string|null
     */
    public static function getBlockedMessage(string $scriptFile): ?string
    {
        $script = $scriptFile === '' ? false : realpath($scriptFile);
        if ($script === false) {
            return null;
        }

        foreach (self::getBlockedScripts() as $path => $messageId) {
            if (realpath(ADMIDIO_PATH . $path) === $script) {
                return $messageId;
            }
        }

        return null;
    }

    /**
     * The impersonations of the current organization, newest first, prepared for the history page.
     * @param int $limit
     * @return array<int,array{begin: string, end: string, admin: string, target: string, status: string, ip: string}>
     * @throws Exception
     */
    public static function getHistory(int $limit = 500): array
    {
        global $gDb, $gCurrentOrgId, $gSettingsManager, $gL10n;

        $format = $gSettingsManager->getString('system_date') . ' ' . $gSettingsManager->getString('system_time');
        $maxSeconds = self::getSettings()['maxMinutes'] * 60;
        $current = (string)(self::getState()['uuid'] ?? '');
        $endTexts = array(
            self::END_STOP => 'PLG_IMPERSONATE_END_STOP',
            self::END_LOGOUT => 'PLG_IMPERSONATE_END_LOGOUT',
            self::END_TIMEOUT => 'PLG_IMPERSONATE_END_TIMEOUT',
            self::END_LOST => 'PLG_IMPERSONATE_END_LOST'
        );

        $sql = 'SELECT imp_uuid, imp_admin_name, imp_target_name, imp_ip_address, imp_begin, imp_end, imp_end_reason
                  FROM ' . ImpersonationLog::table() . '
                 WHERE imp_org_id = ? -- $gCurrentOrgId
              ORDER BY imp_begin DESC, imp_id DESC
                 LIMIT ' . max(1, $limit);
        $statement = $gDb->queryPrepared($sql, array($gCurrentOrgId));

        $history = array();
        while ($row = $statement->fetch()) {
            $begin = (int)strtotime((string)$row['imp_begin']);
            $end = '';

            if ($row['imp_end'] !== null) {
                $end = date($format, (int)strtotime((string)$row['imp_end']));
                $status = $gL10n->get($endTexts[(string)$row['imp_end_reason']] ?? 'PLG_IMPERSONATE_END_LOST');
            } elseif ($row['imp_uuid'] === $current || time() < $begin + $maxSeconds) {
                $status = $gL10n->get('PLG_IMPERSONATE_ACTIVE');
            } else {
                // the browser was closed or the session expired before anybody returned
                $status = $gL10n->get('PLG_IMPERSONATE_STATUS_UNFINISHED');
            }

            $history[] = array(
                'begin' => date($format, $begin),
                'end' => $end,
                'admin' => SecurityUtils::encodeHTML((string)$row['imp_admin_name']),
                'target' => SecurityUtils::encodeHTML((string)$row['imp_target_name']),
                'status' => SecurityUtils::encodeHTML($status),
                'ip' => SecurityUtils::encodeHTML((string)$row['imp_ip_address'])
            );
        }

        return $history;
    }

    /**
     * How a user is named in the record, the banner and the change history: the name and, if there is
     * one, the username.
     * @param User $user
     * @return string
     * @throws Exception
     */
    public static function describe(User $user): string
    {
        // read unencoded: the name is stored and put into texts, and encoded where it becomes HTML
        $name = trim($user->getValue('FIRST_NAME', 'database') . ' ' . $user->getValue('LAST_NAME', 'database'));
        $login = (string)$user->getValue('usr_login_name', 'database');

        if ($name === '' || $login === '') {
            return $name . $login;
        }

        return $name . ' (' . $login . ')';
    }

    /**
     * Make the session belong to another user, the way ModuleLogin::authenticate() does it: the
     * user object the session keeps is replaced, so the next requests find the new user as well.
     * The navigation of the previous user is forgotten, its back links would lead into their pages.
     * @param int $userId
     * @param string|null $authenticationTime
     * @param string|null $authenticationMethods
     * @return void
     * @throws Exception
     */
    private static function switchTo(int $userId, ?string $authenticationTime, ?string $authenticationMethods): void
    {
        global $gDb, $gCurrentSession, $gCurrentUser, $gCurrentUserId, $gCurrentUserUUID, $gProfileFields, $gMenu, $gNavigation;

        $gCurrentSession->setValue('ses_usr_id', $userId);
        $gCurrentSession->setValue('ses_authentication_time', $authenticationTime);
        $gCurrentSession->setValue('ses_authentication_methods', $authenticationMethods);
        $gCurrentSession->save();

        $gMenu->initialize();
        $gNavigation->clear();

        // $gCurrentUser is a reference to the object the session keeps, so assigning replaces that one
        $gCurrentUser = new User($gDb, $gProfileFields, $userId);
        $gCurrentUserId = (int)$gCurrentUser->getValue('usr_id');
        $gCurrentUserUUID = (string)$gCurrentUser->getValue('usr_uuid');
    }

    /**
     * End the session entirely. The last resort when there is nothing trustworthy to return to.
     * @return void
     * @throws Exception
     */
    private static function endSession(): void
    {
        global $gCurrentSession, $gCurrentUser, $gValidLogin;

        $gValidLogin = false;
        $gCurrentSession->logout();
        $gCurrentUser->clear();
    }

    /**
     * Record the end of an impersonation that is not recorded as ended yet.
     * @param string $uuid
     * @param string $reason
     * @return void
     * @throws Exception
     */
    private static function close(string $uuid, string $reason): void
    {
        global $gDb;

        $record = new ImpersonationLog($gDb);
        if ($uuid === '' || !$record->readDataByUuid($uuid) || (string)$record->getValue('imp_end') !== '') {
            return;
        }

        $record->setValue('imp_end', DATETIME_NOW);
        $record->setValue('imp_end_reason', $reason);
        $record->save();
    }

    /**
     * Refuse the current script if it must not run in the name of the impersonated user.
     *
     * This runs while the plugin is being loaded, where an exception would only make the loader skip
     * the plugin, so the refusal is answered and the request ended here. A form sends its data with an
     * AJAX request and expects JSON.
     * @return void
     * @throws Exception
     */
    private static function guardScript(): void
    {
        global $gMessage, $gL10n;

        $messageId = self::getBlockedMessage((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        if ($messageId === null) {
            return;
        }

        $message = $gL10n->get($messageId);

        if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
            echo json_encode(array('status' => 'error', 'message' => $message));
            exit();
        }

        $gMessage->show($message);
        // => EXIT
    }

    /**
     * Tell the user that an administrator has started acting as them. A mail that cannot be sent is
     * logged and does not stop the impersonation: the record of it exists either way.
     * @param User $target
     * @param string $adminName
     * @return void
     */
    private static function notifyTarget(User $target, string $adminName): void
    {
        global $gSettingsManager, $gL10n, $gCurrentOrganization, $gLogger;

        try {
            $email = new Email();
            if ($email->addRecipientsByUser((string)$target->getValue('usr_uuid')) === 0) {
                return;
            }

            $organization = (string)$gCurrentOrganization->getValue('org_longname');
            $email->setSender($gSettingsManager->getString('mail_sender_email'), $gSettingsManager->getString('mail_sender_name'));
            $email->setSubject($gL10n->get('PLG_IMPERSONATE_NOTIFY_SUBJECT', array($organization)));
            $email->setText($gL10n->get('PLG_IMPERSONATE_NOTIFY_TEXT', array(
                (string)$target->getValue('FIRST_NAME'),
                $adminName,
                $organization,
                date($gSettingsManager->getString('system_date') . ' ' . $gSettingsManager->getString('system_time'))
            )));
            $email->sendEmail();
        } catch (Throwable $exception) {
            $gLogger->warning(
                'IMPERSONATE: The user could not be notified that an administrator acts as them.',
                array('user' => (string)$target->getValue('usr_uuid'), 'error' => $exception->getMessage())
            );
        }
    }
}
