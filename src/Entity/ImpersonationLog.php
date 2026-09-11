<?php

namespace AdmidioPlugin\Impersonate\Entity;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Entity\Entity;
use Admidio\Infrastructure\Exception;

/**
 * One impersonation: who acted as whom, in which organization, when it started and how it ended.
 *
 * The names of both users are kept as they were at the time, so that the record still says something
 * after either account has been deleted. There are no foreign keys for the same reason - the record
 * of an impersonation must outlive the accounts it names.
 *
 * The record also keeps how the administrator had authenticated, because that is what the single
 * sign-on endpoints rely on and it is taken away from the session while the impersonation lasts.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */
class ImpersonationLog extends Entity
{
    /**
     * @param Database $database
     * @param int $id The ID of the record that should be read, or 0 for a new one.
     * @throws Exception
     */
    public function __construct(Database $database, int $id = 0)
    {
        parent::__construct($database, self::table(), 'imp', $id);
    }

    /**
     * The name of the table, with the table prefix of this installation. Entity::getTableName() answers
     * the same for an object; this one is needed before there is one.
     * @return string
     */
    public static function table(): string
    {
        return TABLE_PREFIX . '_plugin_impersonations';
    }
}
