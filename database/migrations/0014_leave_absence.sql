-- Leave and absence. A person asks for annual, other paid or unpaid leave and a manager decides; sickness is recorded
-- (by the person or a manager) and may be open-ended until they are back. Days are working days Monday to Friday and
-- are NOT reduced for bank holidays (the approver can correct the figure). Care Core does not do payroll.
-- The allowance is per person per leave year; the leave year's first month is an organisation setting (leave_year_start_month, default January).
CREATE TABLE leave_allowances (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organisation_id BIGINT UNSIGNED NOT NULL,
    person_id       BIGINT UNSIGNED NOT NULL,
    year_start      DATE         NOT NULL,
    days            DECIMAL(5,1) NOT NULL,
    note            VARCHAR(200) NULL,
    UNIQUE KEY uq_allow (person_id, year_start),
    KEY idx_allow_org (organisation_id, year_start),
    CONSTRAINT fk_allow_org    FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE,
    CONSTRAINT fk_allow_person FOREIGN KEY (person_id)       REFERENCES persons (id)       ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE absences (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid               CHAR(36)     NOT NULL,
    organisation_id    BIGINT UNSIGNED NOT NULL,
    person_id          BIGINT UNSIGNED NOT NULL,
    kind               ENUM('annual','sickness','other_paid','unpaid') NOT NULL,
    starts_on          DATE         NOT NULL,
    ends_on            DATE         NULL,
    start_half         TINYINT(1)   NOT NULL DEFAULT 0,
    end_half           TINYINT(1)   NOT NULL DEFAULT 0,
    days               DECIMAL(5,1) NULL,
    status             ENUM('requested','approved','declined','cancelled') NOT NULL DEFAULT 'requested',
    reason             VARCHAR(500) NULL,
    decision_note      VARCHAR(500) NULL,
    decided_by_user_id BIGINT UNSIGNED NULL,
    decided_at         DATETIME     NULL,
    requested_by_user_id BIGINT UNSIGNED NULL,
    rtw_done_on        DATE         NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_absence_uuid (uuid),
    KEY idx_absence_person (person_id, starts_on),
    KEY idx_absence_org (organisation_id, status, starts_on),
    CONSTRAINT fk_abs_org    FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE,
    CONSTRAINT fk_abs_person FOREIGN KEY (person_id)       REFERENCES persons (id)       ON DELETE CASCADE
) ENGINE=InnoDB;
