<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260818210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace the prototype annotations with damage cases, free drawings and editable legends.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE annotations');
        $this->addSql(<<<'SQL'
            CREATE TABLE damage_cases (
              id INT AUTO_INCREMENT NOT NULL,
              client_id VARCHAR(255) NOT NULL,
              label VARCHAR(255) NOT NULL,
              color VARCHAR(30) NOT NULL,
              legend_geometry JSON NOT NULL,
              sort_order INT NOT NULL,
              deleted TINYINT(1) NOT NULL,
              created_by_id INT DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              report_id INT NOT NULL,
              INDEX IDX_8A9B46934BD2A4C0 (report_id),
              UNIQUE INDEX uniq_damage_case_client_id (report_id, client_id),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE annotations (
              id INT AUTO_INCREMENT NOT NULL,
              source_key VARCHAR(255) NOT NULL,
              client_id VARCHAR(255) NOT NULL,
              target JSON NOT NULL,
              bodies JSON NOT NULL,
              sort_order INT NOT NULL,
              deleted TINYINT(1) NOT NULL,
              created_by_id INT DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              report_id INT NOT NULL,
              damage_case_id INT NOT NULL,
              report_image_id INT DEFAULT NULL,
              INDEX IDX_489318054BD2A4C0 (report_id),
              INDEX IDX_489318054BBD6F33 (damage_case_id),
              INDEX IDX_489318053DC5BC10 (report_image_id),
              UNIQUE INDEX uniq_annotation_client_id (report_id, client_id),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql('ALTER TABLE damage_cases ADD CONSTRAINT FK_DAMAGE_CASE_REPORT FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE annotations ADD CONSTRAINT FK_ANNOTATION_REPORT FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE annotations ADD CONSTRAINT FK_ANNOTATION_DAMAGE_CASE FOREIGN KEY (damage_case_id) REFERENCES damage_cases (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE annotations ADD CONSTRAINT FK_ANNOTATION_REPORT_IMAGE FOREIGN KEY (report_image_id) REFERENCES report_images (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE annotations');
        $this->addSql('DROP TABLE damage_cases');
        $this->addSql(<<<'SQL'
            CREATE TABLE annotations (
              id INT AUTO_INCREMENT NOT NULL,
              client_id VARCHAR(255) NOT NULL,
              geometry JSON NOT NULL,
              body JSON NOT NULL,
              damage_type VARCHAR(100) DEFAULT NULL,
              label VARCHAR(255) DEFAULT NULL,
              color VARCHAR(30) DEFAULT NULL,
              notes LONGTEXT DEFAULT NULL,
              sort_order INT NOT NULL,
              deleted TINYINT(1) NOT NULL,
              created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              report_id INT NOT NULL,
              report_image_id INT NOT NULL,
              source_annotation_id INT DEFAULT NULL,
              INDEX IDX_4893180525192D36 (source_annotation_id),
              INDEX IDX_489318054BD2A4C0 (report_id),
              INDEX IDX_489318053DC5BC10 (report_image_id),
              UNIQUE INDEX uniq_annotation_client_id (report_image_id, client_id),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql('ALTER TABLE annotations ADD CONSTRAINT FK_4893180525192D36 FOREIGN KEY (source_annotation_id) REFERENCES annotations (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE annotations ADD CONSTRAINT FK_489318053DC5BC10 FOREIGN KEY (report_image_id) REFERENCES report_images (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE annotations ADD CONSTRAINT FK_489318054BD2A4C0 FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE');
    }
}
