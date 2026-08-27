<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260818200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add report context and documents, project context and secure password reset tokens.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE password_reset_tokens (id INT AUTO_INCREMENT NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_3967A216A76ED395 (user_id), UNIQUE INDEX uniq_password_reset_token (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE report_documents (id INT AUTO_INCREMENT NOT NULL, category VARCHAR(50) NOT NULL, path LONGTEXT NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INT NOT NULL, sort_order INT NOT NULL, created_at DATETIME NOT NULL, report_id INT NOT NULL, INDEX IDX_8C041F904BD2A4C0 (report_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE password_reset_tokens ADD CONSTRAINT FK_3967A216A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE report_documents ADD CONSTRAINT FK_8C041F904BD2A4C0 FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE projects ADD address LONGTEXT DEFAULT NULL, ADD website VARCHAR(500) DEFAULT NULL, ADD insurance_start_date DATE DEFAULT NULL, ADD insurance_end_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE project_objects ADD room VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE reports ADD reason VARCHAR(50) DEFAULT NULL, ADD custom_reason VARCHAR(255) DEFAULT NULL, ADD receipt_at DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE password_reset_tokens DROP FOREIGN KEY FK_3967A216A76ED395');
        $this->addSql('ALTER TABLE report_documents DROP FOREIGN KEY FK_8C041F904BD2A4C0');
        $this->addSql('DROP TABLE password_reset_tokens');
        $this->addSql('DROP TABLE report_documents');
        $this->addSql('ALTER TABLE projects DROP address, DROP website, DROP insurance_start_date, DROP insurance_end_date');
        $this->addSql('ALTER TABLE project_objects DROP room');
        $this->addSql('ALTER TABLE reports DROP reason, DROP custom_reason, DROP receipt_at');
    }
}
