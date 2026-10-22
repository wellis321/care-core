-- Table structures of the older team-service system (as of the date this file was made), used to test the exporter.
-- Foreign keys are left out so a test can seed in any order.
SET NAMES utf8mb4;

DROP TABLE IF EXISTS `teams`;
CREATE TABLE `teams` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organisation_id` int NOT NULL,
  `parent_team_id` int DEFAULT NULL COMMENT 'Self-reference for hierarchy (e.g. Region > Area > Team)',
  `team_type_id` int DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_organisation` (`organisation_id`),
  KEY `idx_parent` (`parent_team_id`),
  KEY `idx_type` (`team_type_id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `team_types`;
CREATE TABLE `team_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organisation_id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_staff_only` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'TRUE = this type can only contain staff (e.g. HR, Finance)',
  `display_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_org_type_name` (`organisation_id`,`name`),
  KEY `idx_organisation` (`organisation_id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `team_roles`;
CREATE TABLE `team_roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organisation_id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `applies_to` enum('staff','person','both') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'staff' COMMENT 'Which member type this role applies to',
  `access_level` enum('team','organisation') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'team',
  `display_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_org_role_name` (`organisation_id`,`name`),
  KEY `idx_organisation` (`organisation_id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `team_members`;
CREATE TABLE `team_members` (
  `id` int NOT NULL AUTO_INCREMENT,
  `team_id` int NOT NULL,
  `organisation_id` int NOT NULL,
  `member_type` enum('staff','person','user') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'staff=PMS, person=People Service, user=shared-auth user',
  `external_id` int NOT NULL COMMENT 'Primary key in the source service',
  `display_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Cached full name from source service',
  `display_ref` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Cached reference/ID from source service (employee ref, CHI, etc.)',
  `team_role_id` int DEFAULT NULL COMMENT 'Role within this team (NULL = basic member)',
  `is_primary_team` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether this is their primary/base team',
  `joined_at` date DEFAULT NULL COMMENT 'Date they joined this team',
  `left_at` date DEFAULT NULL COMMENT 'Date they left — NULL means still active',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_team_member` (`team_id`,`member_type`,`external_id`),
  KEY `team_role_id` (`team_role_id`),
  KEY `idx_team` (`team_id`),
  KEY `idx_organisation` (`organisation_id`),
  KEY `idx_member` (`member_type`,`external_id`),
  KEY `idx_active` (`left_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

