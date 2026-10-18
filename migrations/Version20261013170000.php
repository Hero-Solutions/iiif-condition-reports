<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Attach report documents to Basis or Recommendations; existing documents belong to Basis.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE report_documents ADD section VARCHAR(30) DEFAULT 'basis' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE report_documents DROP section');
    }
}
