/*
 * Database structure of the impersonation plugin.
 *
 * The file has to be idempotent: enabling a plugin for the first organization is what installs it,
 * so this also runs on an installation that still carries the table of an earlier enable - the data
 * is an audit trail and is kept.
 *
 * Only PRIMARY KEY and UNIQUE are declared, both inside the table: they are standard SQL and are
 * skipped together with the table, which is what keeps this file repeatable. A standalone
 * CREATE INDEX could not be, because neither engine knows CREATE INDEX IF NOT EXISTS on both sides
 * and Postgres does not accept the MySQL KEY clause that Admidio does not rewrite.
 */

CREATE TABLE IF NOT EXISTS %PREFIX%_plugin_impersonations
(
    imp_id                      integer unsigned    NOT NULL    AUTO_INCREMENT,
    imp_uuid                    varchar(36)         NOT NULL,
    imp_org_id                  integer unsigned    NOT NULL,
    imp_usr_id_admin            integer unsigned    NOT NULL,
    imp_usr_id_target           integer unsigned    NOT NULL,
    imp_admin_name              varchar(255)        NOT NULL,
    imp_target_name             varchar(255)        NOT NULL,
    imp_ip_address              varchar(39)         NULL,
    imp_admin_auth_time         timestamp           NULL        DEFAULT NULL,
    imp_admin_auth_methods      varchar(255)        NULL,
    imp_begin                   timestamp           NOT NULL    DEFAULT CURRENT_TIMESTAMP,
    imp_end                     timestamp           NULL        DEFAULT NULL,
    imp_end_reason              varchar(20)         NULL,
    PRIMARY KEY (imp_id),
    CONSTRAINT %PREFIX%_idx_plugin_imp_uuid UNIQUE (imp_uuid)
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci;
