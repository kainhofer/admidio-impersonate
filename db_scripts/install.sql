CREATE TABLE %PREFIX%_plugin_impersonations
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
    PRIMARY KEY (imp_id)
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

CREATE UNIQUE INDEX %PREFIX%_idx_plugin_imp_uuid ON %PREFIX%_plugin_impersonations (imp_uuid);

CREATE INDEX %PREFIX%_idx_plugin_imp_org_begin ON %PREFIX%_plugin_impersonations (imp_org_id, imp_begin);
