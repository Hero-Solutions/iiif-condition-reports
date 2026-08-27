<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260818223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store a consistent line width for each damage case.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE damage_cases ADD stroke_width DOUBLE PRECISION DEFAULT 2.2 NOT NULL');
        $this->addSql('ALTER TABLE damage_cases CHANGE stroke_width stroke_width DOUBLE PRECISION NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE damage_cases DROP stroke_width');
    }
}
