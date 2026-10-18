<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve annotated main image versions independently of the current Datahub image.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE object_image_versions (id INT AUTO_INCREMENT NOT NULL, object_record_id INT NOT NULL, fingerprint VARCHAR(64) NOT NULL, source JSON NOT NULL, images JSON NOT NULL, created_at DATETIME NOT NULL, replaced_at DATETIME DEFAULT NULL, INDEX idx_image_version_object (object_record_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE report_main_images (id INT AUTO_INCREMENT NOT NULL, report_id INT NOT NULL, image_version_id INT NOT NULL, UNIQUE INDEX uniq_main_image_report (report_id), INDEX idx_main_image_version (image_version_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE object_image_versions ADD CONSTRAINT fk_image_version_object FOREIGN KEY (object_record_id) REFERENCES object_records (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE report_main_images ADD CONSTRAINT fk_main_image_report FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE report_main_images ADD CONSTRAINT fk_main_image_version FOREIGN KEY (image_version_id) REFERENCES object_image_versions (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE report_main_images');
        $this->addSql('DROP TABLE object_image_versions');
    }
}
