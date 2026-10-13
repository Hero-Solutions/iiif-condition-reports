<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record manual object changes and Datahub synchronizations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE object_log_entries (
            id INT AUTO_INCREMENT NOT NULL,
            object_record_id INT NOT NULL,
            type VARCHAR(30) NOT NULL,
            user_id INT DEFAULT NULL,
            user_name VARCHAR(255) DEFAULT NULL,
            changes JSON NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_object_log_date (object_record_id, created_at, id),
            PRIMARY KEY(id),
            CONSTRAINT fk_object_log_object FOREIGN KEY (object_record_id) REFERENCES object_records (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE object_log_entries');
    }
}
