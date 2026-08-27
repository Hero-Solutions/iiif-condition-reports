<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260818220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add an optional note to each damage legend item.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE damage_cases ADD legend_note LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE damage_cases DROP legend_note');
    }
}
