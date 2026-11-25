-- Staff documents: contracts, right-to-work evidence, qualifications and the like, kept as private files outside the web
-- root. A document can carry an expiry date (shown in a review list) and can be shared with the person it is about.
-- Care Core keeps the files and a record of who added or removed them; it does not read them.
CREATE TABLE staff_documents (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid               CHAR(36)     NOT NULL,
    organisation_id    BIGINT UNSIGNED NOT NULL,
    person_id          BIGINT UNSIGNED NOT NULL,
    title              VARCHAR(150) NOT NULL,
    category           ENUM('contract','identity','right_to_work','qualification','reference','training','other') NOT NULL DEFAULT 'other',
    file_path          VARCHAR(200) NOT NULL,
    original_name      VARCHAR(200) NOT NULL,
    mime               VARCHAR(100) NOT NULL,
    size_bytes         INT UNSIGNED NOT NULL,
    expires_on         DATE         NULL,
    shared_with_person TINYINT(1)   NOT NULL DEFAULT 0,
    notes              VARCHAR(500) NULL,
    added_by_user_id   BIGINT UNSIGNED NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_doc_uuid (uuid),
    KEY idx_doc_person (person_id, created_at),
    KEY idx_doc_expiry (organisation_id, expires_on),
    CONSTRAINT fk_doc_org    FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE,
    CONSTRAINT fk_doc_person FOREIGN KEY (person_id)       REFERENCES persons (id)       ON DELETE CASCADE
) ENGINE=InnoDB;
