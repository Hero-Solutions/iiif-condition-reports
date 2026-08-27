<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260818170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add report finalization author and optimistic locking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reports ADD version INT DEFAULT 1 NOT NULL, ADD finalized_by_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reports DROP version, DROP finalized_by_id');
    }
}
