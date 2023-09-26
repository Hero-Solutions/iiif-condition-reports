CREATE TABLE IF NOT EXISTS `inventory_numbers` (
  `inventory_number` VARCHAR(100) NOT NULL,
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`inventory_number`),
  KEY(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `datahub_data` (
  `id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `value` TEXT NOT NULL,
  PRIMARY KEY (`id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `reports` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `base_id` INT UNSIGNED DEFAULT NULL,
  `editor` INT UNSIGNED NOT NULL,
  `inventory_id` INT UNSIGNED NOT NULL,
  `timestamp` TIMESTAMP NOT NULL,
  `reason` SMALLINT UNSIGNED DEFAULT NULL,
  `signatures_required` TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `signatures` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `report_id` INT UNSIGNED NOT NULL,
    `timestamp` TIMESTAMP NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `actor_id` SMALLINT UNSIGNED NOT NULL,
    `filename` VARCHAR(255) NOT NULL,
    PRIMARY KEY(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `report_history` (
  `id` INT UNSIGNED NOT NULL,
  `previous_id` INT UNSIGNED NOT NULL,
  `sort_order` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`, `previous_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `report_data` (
  `id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `value` TEXT NOT NULL,
  PRIMARY KEY (`id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `images` (
  `hash` CHAR(64) NOT NULL,
  `image` TEXT NOT NULL,
  `thumbnail` TEXT NOT NULL,
  PRIMARY KEY(`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `annotations` (
  `image` CHAR(64) NOT NULL,
  `report_id` INT UNSIGNED NOT NULL,
  `annotation_id` VARCHAR(255) NOT NULL,
  `annotation` LONGTEXT NOT NULL,
  PRIMARY KEY (`image`, `report_id`, `annotation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `deleted_annotations` (
  `image` CHAR(64) NOT NULL,
  `report_id` INT UNSIGNED NOT NULL,
  `annotation_id` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`image`, `report_id`, `annotation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `organisations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `alias` VARCHAR(255) DEFAULT NULL,
  `name` VARCHAR(255) NOT NULL,
  `logo` VARCHAR(255) DEFAULT NULL,
  `vat` VARCHAR(255) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `postal` VARCHAR(255) DEFAULT NULL,
  `city` VARCHAR(255) DEFAULT NULL,
  `state_province` VARCHAR(255) DEFAULT NULL,
  `country` VARCHAR(255) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `website` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(255) DEFAULT NULL,
  `mobile` VARCHAR(255) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `representatives` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation` INT UNSIGNED DEFAULT NULL,
  `organisation_name` VARCHAR(255) DEFAULT NULL,
  `alias` VARCHAR(255) DEFAULT NULL,
  `name` VARCHAR(255) NOT NULL,
  `role` VARCHAR(255) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(255) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `loan_projects` (
`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
`alias` VARCHAR(255) DEFAULT NULL,
`title` VARCHAR(255) DEFAULT NULL,
`organisation` INT UNSIGNED DEFAULT NULL,
`organisation_name` VARCHAR(255) DEFAULT NULL,
`address` VARCHAR(255) DEFAULT NULL,
`postal` VARCHAR(255) DEFAULT NULL,
`city` VARCHAR(255) DEFAULT NULL,
`state_province` VARCHAR(255) DEFAULT NULL,
`country` VARCHAR(255) DEFAULT NULL,
`url` VARCHAR(255) DEFAULT NULL,
`start_date` TIMESTAMP NULL DEFAULT NULL,
`end_date` TIMESTAMP NULL DEFAULT NULL,
`start_date_insured` TIMESTAMP NULL DEFAULT NULL,
`end_date_insured` TIMESTAMP NULL DEFAULT NULL,
`loan_number` VARCHAR(255) DEFAULT NULL,
`notes` TEXT DEFAULT NULL,
`representative` INT UNSIGNED DEFAULT NULL,
`representative_name` VARCHAR(255) DEFAULT NULL,
`representative_role` VARCHAR(255) DEFAULT NULL,
`representative_email` VARCHAR(255) DEFAULT NULL,
`representative_phone` VARCHAR(255) DEFAULT NULL,
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `iiif_manifests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `manifest_id` VARCHAR(255) NOT NULL,
  `data` LONGTEXT NOT NULL,
  PRIMARY KEY (`id`)
);

CREATE TABLE IF NOT EXISTS user (
    `id` INT AUTO_INCREMENT NOT NULL,
    `email` VARCHAR(180) NOT NULL,
    `full_name` VARCHAR(255) NOT NULL,
    `roles` JSON NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    UNIQUE INDEX UNIQ_8D93D649E7927C74 (email),
    PRIMARY KEY(id)
);

CREATE TABLE IF NOT EXISTS reset_password_request (
    `id` INT AUTO_INCREMENT NOT NULL,
    `user_id` INT NOT NULL,
    `selector` VARCHAR(20) NOT NULL,
    `hashed_token` VARCHAR(100) NOT NULL,
    `requested_at` DATETIME NOT NULL,
    `expires_at` DATETIME NOT NULL,
    INDEX IDX_7CE748AA76ED395 (`user_id`),
    PRIMARY KEY(`id`)
);

INSERT INTO images VALUES('f0cb750841386053f9101da5fee15f164eec5e3a72a2d87df53cd625534cd4fc', '/annotation_images/rectangle_portrait_front.svg', '/annotation_images/rectangle_portrait_front.svg') ON DUPLICATE KEY UPDATE image=VALUES(image), thumbnail=VALUES(thumbnail);
INSERT INTO images VALUES('cb11c44f66df0e56914c7bc1fea2c673170aa365d563a22b33ed8a98608afb5b', '/annotation_images/rectangle_landscape_front.svg', '/annotation_images/rectangle_landscape_front.svg') ON DUPLICATE KEY UPDATE image=VALUES(image), thumbnail=VALUES(thumbnail);
INSERT INTO images VALUES('a3063387f9101dd3e989348ee1feee6aefcc1b4558c4231a4ae0005e9c4a08e2', '/annotation_images/square_front.svg', '/annotation_images/square_front.svg') ON DUPLICATE KEY UPDATE image=VALUES(image), thumbnail=VALUES(thumbnail);
INSERT INTO images VALUES('abcb5b054a46a8d7998c451dd5ad7b6573c837feafa55c7a492d50143ea05e7b', '/annotation_images/oval_portrait_front.svg', '/annotation_images/oval_portrait_front.svg') ON DUPLICATE KEY UPDATE image=VALUES(image), thumbnail=VALUES(thumbnail);
INSERT INTO images VALUES('81dd7d912f3d1440b53eea877d06e275f002d50cf0e2b2fa6750883b8498af0d', '/annotation_images/oval_landscape_front.svg', '/annotation_images/oval_landscape_front.svg') ON DUPLICATE KEY UPDATE image=VALUES(image), thumbnail=VALUES(thumbnail);
INSERT INTO images VALUES('674af98ba91fe1f17260eab65fd6a97a8f7aa82aaad46d4bf4eed1393880f737', '/annotation_images/circle_front.svg', '/annotation_images/circle_front.svg') ON DUPLICATE KEY UPDATE image=VALUES(image), thumbnail=VALUES(thumbnail);
INSERT INTO images VALUES('c3d10b7efed2d15868918fdb39ad678920e01d6d4f8d7fa6c0dfcc8a41f875cb', '/annotation_images/diamond_front.svg', '/annotation_images/diamond_front.svg') ON DUPLICATE KEY UPDATE image=VALUES(image), thumbnail=VALUES(thumbnail);
INSERT INTO images VALUES('555e70ba359a7aad18c44d0fa04a6406407b305ec2923d145aeec153deb071ad', '/annotation_images/rectangle_portrait_back.svg', '/annotation_images/rectangle_portrait_back.svg') ON DUPLICATE KEY UPDATE image=VALUES(image), thumbnail=VALUES(thumbnail);
INSERT INTO images VALUES('304f4ff0d2e5f12768845a86658b70aeff545aaf43899537ade0026717e48743', '/annotation_images/rectangle_landscape_back.svg', '/annotation_images/rectangle_landscape_back.svg') ON DUPLICATE KEY UPDATE image=VALUES(image), thumbnail=VALUES(thumbnail);
INSERT INTO images VALUES('29ec20e9cbeac21f12eef2b4a06cc242e89f7c007c449bee832f74a3332519af', '/annotation_images/square_back.svg', '/annotation_images/square_back.svg') ON DUPLICATE KEY UPDATE image=VALUES(image), thumbnail=VALUES(thumbnail);
INSERT INTO images VALUES('aa259545834915a6f05f7529007a5cc73c27f3c650637377748870da37809cf6', '/annotation_images/oval_portrait_back.svg', '/annotation_images/oval_portrait_back.svg') ON DUPLICATE KEY UPDATE image=VALUES(image), thumbnail=VALUES(thumbnail);
INSERT INTO images VALUES('67fc3c45e2f32de14bdb11eeb0231cad89d6d16673e513081bb58592240e9e9e', '/annotation_images/oval_landscape_back.svg', '/annotation_images/oval_landscape_back.svg') ON DUPLICATE KEY UPDATE image=VALUES(image), thumbnail=VALUES(thumbnail);
INSERT INTO images VALUES('856a5313c2d266a1f78b93080f183fc4ee4a12299591c0b8a07ecad95534de75', '/annotation_images/circle_back.svg', '/annotation_images/circle_back.svg') ON DUPLICATE KEY UPDATE image=VALUES(image), thumbnail=VALUES(thumbnail);
INSERT INTO images VALUES('f1b77336762102a17e092e17697e85bd8d760afc12c8ebc217cc70d8ac5ceddf', '/annotation_images/diamond_back.svg', '/annotation_images/diamond_back.svg') ON DUPLICATE KEY UPDATE image=VALUES(image), thumbnail=VALUES(thumbnail);
