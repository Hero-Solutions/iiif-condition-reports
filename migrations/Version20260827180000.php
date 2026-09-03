<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260827180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link local users to their Microsoft Entra object ID.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD entra_object_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_ENTRA_OBJECT_ID ON users (entra_object_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_USER_ENTRA_OBJECT_ID ON users');
        $this->addSql('ALTER TABLE users DROP entra_object_id');
    }
}
