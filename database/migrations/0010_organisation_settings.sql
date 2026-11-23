-- An organisation's own settings, one row per setting. Today: whether two-step sign-in is required (see Identity\SignInPolicy).
CREATE TABLE IF NOT EXISTS organisation_settings (
    organisation_id BIGINT UNSIGNED NOT NULL,
    setting_key     VARCHAR(60)  NOT NULL,
    setting_value   VARCHAR(200) NOT NULL,
    PRIMARY KEY (organisation_id, setting_key),
    CONSTRAINT fk_orgset_org FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE
) ENGINE=InnoDB;

