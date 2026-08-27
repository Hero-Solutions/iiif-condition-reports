<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260818115345 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema for the rebuilt IIIF Condition Reports application.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            "Migration can only be executed safely on a MySQL-compatible database."
        );

        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');

        $this->addSql(<<<'SQL'
            CREATE TABLE actors (
              id INT AUTO_INCREMENT NOT NULL,
              name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              type VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              alias VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              logo VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              vat VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              address VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              postal VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              city VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              state_province VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              country VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              email VARCHAR(180) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              website VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              phone VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              mobile VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              notes LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              UNIQUE INDEX uniq_actor_name (name),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            "Migration can only be executed safely on a MySQL-compatible database."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE annotations (
              id INT AUTO_INCREMENT NOT NULL,
              client_id VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              geometry JSON NOT NULL,
              body JSON NOT NULL,
              damage_type VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              label VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              color VARCHAR(30) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              notes LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              sort_order INT NOT NULL,
              deleted TINYINT NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              report_id INT NOT NULL,
              report_image_id INT NOT NULL,
              source_annotation_id INT DEFAULT NULL,
              INDEX IDX_4893180525192D36 (source_annotation_id),
              INDEX IDX_489318054BD2A4C0 (report_id),
              INDEX IDX_489318053DC5BC10 (report_image_id),
              UNIQUE INDEX uniq_annotation_client_id (report_image_id, client_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              annotations
            ADD
              CONSTRAINT `FK_4893180525192D36` FOREIGN KEY (source_annotation_id) REFERENCES annotations (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              annotations
            ADD
              CONSTRAINT `FK_489318053DC5BC10` FOREIGN KEY (report_image_id) REFERENCES report_images (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              annotations
            ADD
              CONSTRAINT `FK_489318054BD2A4C0` FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            "Migration can only be executed safely on a MySQL-compatible database."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE iiif_manifests (
              id INT AUTO_INCREMENT NOT NULL,
              manifest_id VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              title VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              source VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              source_url LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              thumbnail_url LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              data JSON NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              UNIQUE INDEX uniq_iiif_manifest_id (manifest_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            "Migration can only be executed safely on a MySQL-compatible database."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE object_manifests (
              id INT AUTO_INCREMENT NOT NULL,
              role VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              created_at DATETIME NOT NULL,
              object_record_id INT NOT NULL,
              manifest_id INT NOT NULL,
              INDEX IDX_2ECB4B74E424A8B (object_record_id),
              INDEX IDX_2ECB4B74E697B2FB (manifest_id),
              UNIQUE INDEX uniq_object_manifest (object_record_id, manifest_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              object_manifests
            ADD
              CONSTRAINT `FK_2ECB4B74E424A8B` FOREIGN KEY (object_record_id) REFERENCES object_records (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              object_manifests
            ADD
              CONSTRAINT `FK_2ECB4B74E697B2FB` FOREIGN KEY (manifest_id) REFERENCES iiif_manifests (id) ON DELETE CASCADE
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            "Migration can only be executed safely on a MySQL-compatible database."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE object_records (
              id INT AUTO_INCREMENT NOT NULL,
              inventory_number VARCHAR(100) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              title VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              creator LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              publisher VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              object_type VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              custom_object_type VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              current_location VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              description LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              image_url LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              thumbnail_url LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              external_image_url LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              source VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              external_id VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              sync_status VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              last_synced_at DATETIME DEFAULT NULL,
              source_data JSON NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              UNIQUE INDEX uniq_object_inventory_number (inventory_number),
              INDEX idx_object_title (title),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            "Migration can only be executed safely on a MySQL-compatible database."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE organization_contacts (
              id INT AUTO_INCREMENT NOT NULL,
              function_title VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              organization_id INT NOT NULL,
              person_id INT NOT NULL,
              INDEX idx_organization_contact_person (person_id),
              UNIQUE INDEX uniq_organization_contact (organization_id, person_id),
              INDEX idx_organization_contact_organization (organization_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              organization_contacts
            ADD
              CONSTRAINT `FK_DC58D7D1217BBB47` FOREIGN KEY (person_id) REFERENCES actors (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              organization_contacts
            ADD
              CONSTRAINT `FK_DC58D7D132C8A3DE` FOREIGN KEY (organization_id) REFERENCES actors (id) ON DELETE CASCADE
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            "Migration can only be executed safely on a MySQL-compatible database."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE projects (
              id INT AUTO_INCREMENT NOT NULL,
              title VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              type VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              custom_type VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              status VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              reference_code VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              description LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              environmental_conditions JSON NOT NULL,
              start_date DATETIME DEFAULT NULL,
              end_date DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            "Migration can only be executed safely on a MySQL-compatible database."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE project_actors (
              id INT AUTO_INCREMENT NOT NULL,
              role VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              custom_role VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              project_id INT NOT NULL,
              actor_id INT NOT NULL,
              contact_person_id INT DEFAULT NULL,
              INDEX IDX_C88F1BAF4F8A983C (contact_person_id),
              INDEX idx_project_actor_project (project_id),
              INDEX idx_project_actor_actor (actor_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project_actors
            ADD
              CONSTRAINT `FK_C88F1BAF10DAF24A` FOREIGN KEY (actor_id) REFERENCES actors (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project_actors
            ADD
              CONSTRAINT `FK_C88F1BAF166D1F9C` FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project_actors
            ADD
              CONSTRAINT `FK_C88F1BAF4F8A983C` FOREIGN KEY (contact_person_id) REFERENCES organization_contacts (id) ON DELETE
            SET
              NULL
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            "Migration can only be executed safely on a MySQL-compatible database."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE project_objects (
              id INT AUTO_INCREMENT NOT NULL,
              status VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              notes LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              sort_order INT NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              project_id INT NOT NULL,
              object_record_id INT NOT NULL,
              INDEX IDX_2404C096E424A8B (object_record_id),
              UNIQUE INDEX uniq_project_object (project_id, object_record_id),
              INDEX IDX_2404C096166D1F9C (project_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project_objects
            ADD
              CONSTRAINT `FK_2404C096166D1F9C` FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project_objects
            ADD
              CONSTRAINT `FK_2404C096E424A8B` FOREIGN KEY (object_record_id) REFERENCES object_records (id) ON DELETE CASCADE
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            "Migration can only be executed safely on a MySQL-compatible database."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE project_object_actors (
              id INT AUTO_INCREMENT NOT NULL,
              role VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              custom_role VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              project_object_id INT NOT NULL,
              actor_id INT NOT NULL,
              contact_person_id INT DEFAULT NULL,
              INDEX idx_project_object_actor_project_object (project_object_id),
              INDEX idx_project_object_actor_actor (actor_id),
              INDEX IDX_453AC7E34F8A983C (contact_person_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project_object_actors
            ADD
              CONSTRAINT `FK_453AC7E310DAF24A` FOREIGN KEY (actor_id) REFERENCES actors (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project_object_actors
            ADD
              CONSTRAINT `FK_453AC7E3321113FC` FOREIGN KEY (project_object_id) REFERENCES project_objects (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project_object_actors
            ADD
              CONSTRAINT `FK_453AC7E34F8A983C` FOREIGN KEY (contact_person_id) REFERENCES organization_contacts (id) ON DELETE
            SET
              NULL
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            "Migration can only be executed safely on a MySQL-compatible database."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE reports (
              id INT AUTO_INCREMENT NOT NULL,
              type VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              custom_type VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              title VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              status VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              description LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              started_at DATETIME DEFAULT NULL,
              ended_at DATETIME DEFAULT NULL,
              data JSON NOT NULL,
              created_by_id INT DEFAULT NULL,
              finalized_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              series_id INT NOT NULL,
              object_record_id INT NOT NULL,
              project_id INT DEFAULT NULL,
              based_on_report_id INT DEFAULT NULL,
              INDEX IDX_F11FA745F07BCDCA (based_on_report_id),
              INDEX IDX_F11FA7455278319C (series_id),
              INDEX idx_report_type (type),
              INDEX IDX_F11FA745E424A8B (object_record_id),
              INDEX idx_report_status (status),
              INDEX IDX_F11FA745166D1F9C (project_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              reports
            ADD
              CONSTRAINT `FK_F11FA745166D1F9C` FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              reports
            ADD
              CONSTRAINT `FK_F11FA7455278319C` FOREIGN KEY (series_id) REFERENCES report_series (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              reports
            ADD
              CONSTRAINT `FK_F11FA745E424A8B` FOREIGN KEY (object_record_id) REFERENCES object_records (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              reports
            ADD
              CONSTRAINT `FK_F11FA745F07BCDCA` FOREIGN KEY (based_on_report_id) REFERENCES reports (id) ON DELETE
            SET
              NULL
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            "Migration can only be executed safely on a MySQL-compatible database."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE report_actors (
              id INT AUTO_INCREMENT NOT NULL,
              role VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              custom_role VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              report_id INT NOT NULL,
              actor_id INT NOT NULL,
              contact_person_id INT DEFAULT NULL,
              INDEX IDX_F6D8257A4F8A983C (contact_person_id),
              INDEX idx_report_actor_report (report_id),
              INDEX idx_report_actor_actor (actor_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              report_actors
            ADD
              CONSTRAINT `FK_F6D8257A10DAF24A` FOREIGN KEY (actor_id) REFERENCES actors (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              report_actors
            ADD
              CONSTRAINT `FK_F6D8257A4BD2A4C0` FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              report_actors
            ADD
              CONSTRAINT `FK_F6D8257A4F8A983C` FOREIGN KEY (contact_person_id) REFERENCES organization_contacts (id) ON DELETE
            SET
              NULL
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            "Migration can only be executed safely on a MySQL-compatible database."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE report_images (
              id INT AUTO_INCREMENT NOT NULL,
              source VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              path LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              thumbnail_path LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              hash VARCHAR(64) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              original_name VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              mime_type VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              sort_order INT NOT NULL,
              created_at DATETIME NOT NULL,
              report_id INT NOT NULL,
              object_record_id INT DEFAULT NULL,
              INDEX idx_report_image_hash (hash),
              INDEX IDX_C9EC6BF54BD2A4C0 (report_id),
              INDEX IDX_C9EC6BF5E424A8B (object_record_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              report_images
            ADD
              CONSTRAINT `FK_C9EC6BF54BD2A4C0` FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              report_images
            ADD
              CONSTRAINT `FK_C9EC6BF5E424A8B` FOREIGN KEY (object_record_id) REFERENCES object_records (id) ON DELETE
            SET
              NULL
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            "Migration can only be executed safely on a MySQL-compatible database."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE report_manifests (
              id INT AUTO_INCREMENT NOT NULL,
              role VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              created_at DATETIME NOT NULL,
              report_id INT NOT NULL,
              manifest_id INT NOT NULL,
              INDEX IDX_43C019EB4BD2A4C0 (report_id),
              INDEX IDX_43C019EBE697B2FB (manifest_id),
              UNIQUE INDEX uniq_report_manifest (report_id, manifest_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              report_manifests
            ADD
              CONSTRAINT `FK_43C019EB4BD2A4C0` FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              report_manifests
            ADD
              CONSTRAINT `FK_43C019EBE697B2FB` FOREIGN KEY (manifest_id) REFERENCES iiif_manifests (id) ON DELETE CASCADE
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            "Migration can only be executed safely on a MySQL-compatible database."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE report_series (
              id INT AUTO_INCREMENT NOT NULL,
              title VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              status VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              description LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`,
              started_at DATETIME DEFAULT NULL,
              ended_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              object_record_id INT NOT NULL,
              project_id INT DEFAULT NULL,
              INDEX idx_report_series_status (status),
              INDEX IDX_13E3D4B2E424A8B (object_record_id),
              INDEX IDX_13E3D4B2166D1F9C (project_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              report_series
            ADD
              CONSTRAINT `FK_13E3D4B2166D1F9C` FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              report_series
            ADD
              CONSTRAINT `FK_13E3D4B2E424A8B` FOREIGN KEY (object_record_id) REFERENCES object_records (id) ON DELETE CASCADE
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            "Migration can only be executed safely on a MySQL-compatible database."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE users (
              id INT AUTO_INCREMENT NOT NULL,
              email VARCHAR(180) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              full_name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              roles JSON NOT NULL,
              password VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`,
              active TINYINT NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              UNIQUE INDEX uniq_user_email (email),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');
        $this->addSql('DROP TABLE `actors`');
        $this->addSql('DROP TABLE `annotations`');
        $this->addSql('DROP TABLE `iiif_manifests`');
        $this->addSql('DROP TABLE `object_manifests`');
        $this->addSql('DROP TABLE `object_records`');
        $this->addSql('DROP TABLE `organization_contacts`');
        $this->addSql('DROP TABLE `projects`');
        $this->addSql('DROP TABLE `project_actors`');
        $this->addSql('DROP TABLE `project_objects`');
        $this->addSql('DROP TABLE `project_object_actors`');
        $this->addSql('DROP TABLE `reports`');
        $this->addSql('DROP TABLE `report_actors`');
        $this->addSql('DROP TABLE `report_images`');
        $this->addSql('DROP TABLE `report_manifests`');
        $this->addSql('DROP TABLE `report_series`');
        $this->addSql('DROP TABLE `users`');
        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }
}
