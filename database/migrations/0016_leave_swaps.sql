-- Swapping approved annual leave between two colleagues. Each keeps the number of days they were allowed; only the dates change places.
-- The colleague agrees first, then a manager (never either of them) decides. Nothing is deleted.
CREATE TABLE leave_swaps (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid               CHAR(36)     NOT NULL,
    organisation_id    BIGINT UNSIGNED NOT NULL,
    from_absence_id    BIGINT UNSIGNED NOT NULL,
    to_absence_id      BIGINT UNSIGNED NOT NULL,
    from_person_id     BIGINT UNSIGNED NOT NULL,
    to_person_id       BIGINT UNSIGNED NOT NULL,
    status             ENUM('requested','accepted','declined','approved','refused','cancelled') NOT NULL DEFAULT 'requested',
    note               VARCHAR(300) NULL,
    decision_note      VARCHAR(500) NULL,
    decided_by_user_id BIGINT UNSIGNED NULL,
    decided_at         DATETIME     NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_leave_swap_uuid (uuid),
    KEY idx_leave_swap_org (organisation_id, status),
    KEY idx_leave_swap_from (from_person_id), KEY idx_leave_swap_to (to_person_id),
    CONSTRAINT fk_lsw_org FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE,
    CONSTRAINT fk_lsw_fa  FOREIGN KEY (from_absence_id) REFERENCES absences (id) ON DELETE CASCADE,
    CONSTRAINT fk_lsw_ta  FOREIGN KEY (to_absence_id)   REFERENCES absences (id) ON DELETE CASCADE
) ENGINE=InnoDB;
