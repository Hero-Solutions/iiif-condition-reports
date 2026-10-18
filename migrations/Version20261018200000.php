<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Import TMS actors and track their project/object assignments without merging source identities by name.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration requires MySQL or MariaDB.');
        $this->addSql("ALTER TABLE actors ADD name_scope VARCHAR(64) DEFAULT 'local' NOT NULL, ADD tms_key VARCHAR(64) DEFAULT NULL, ADD tms_data JSON DEFAULT NULL");
        $this->addSql('DROP INDEX uniq_actor_name ON actors');
        $this->addSql('CREATE UNIQUE INDEX uniq_actor_name_scope ON actors (name, name_scope)');
        $this->addSql('CREATE UNIQUE INDEX uniq_actor_tms ON actors (tms_key)');
        foreach (['project_actors', 'project_object_actors'] as $table) {
            $this->addSql('ALTER TABLE ' . $table . ' ADD tms_id VARCHAR(18) DEFAULT NULL, ADD tms_data JSON DEFAULT NULL, ADD tms_present TINYINT(1) DEFAULT NULL');
        }
        $this->addSql('CREATE UNIQUE INDEX uniq_project_actor_tms ON project_actors (project_id, tms_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_object_actor_tms ON project_object_actors (project_object_id, tms_id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('TMS actors can have identical names with different identities; reverting requires manual reconciliation.');
    }
}
