<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep TMS project/object identities and source snapshots independently of local and DataHub fields.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration requires MySQL or MariaDB.');
        $this->addSql('ALTER TABLE projects ADD tms_type VARCHAR(20) DEFAULT NULL, ADD tms_id VARCHAR(18) DEFAULT NULL, ADD tms_data JSON DEFAULT NULL, ADD tms_present TINYINT(1) DEFAULT 1 NOT NULL, ADD last_tms_synced_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_tms ON projects (tms_type, tms_id)');
        $this->addSql('ALTER TABLE object_records ADD tms_id VARCHAR(18) DEFAULT NULL, ADD tms_data JSON DEFAULT NULL, ADD last_tms_synced_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_object_tms ON object_records (tms_id)');
        $this->addSql('ALTER TABLE project_objects ADD tms_data JSON DEFAULT NULL, ADD tms_present TINYINT(1) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_project_tms ON projects');
        $this->addSql('ALTER TABLE projects DROP tms_type, DROP tms_id, DROP tms_data, DROP tms_present, DROP last_tms_synced_at');
        $this->addSql('DROP INDEX uniq_object_tms ON object_records');
        $this->addSql('ALTER TABLE object_records DROP tms_id, DROP tms_data, DROP last_tms_synced_at');
        $this->addSql('ALTER TABLE project_objects DROP tms_data, DROP tms_present');
    }
}
