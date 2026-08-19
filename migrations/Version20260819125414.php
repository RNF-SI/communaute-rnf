<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #8 — des sous-dossiers, pour que le classement des documents reste
 * possible quand un groupe en accumule.
 */
final class Version20260819125414 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow document folders to be nested';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_document_folder ADD parent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE communaute_rnf_document_folder ADD CONSTRAINT FK_72C47FBE727ACA70 FOREIGN KEY (parent_id) REFERENCES communaute_rnf_document_folder (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_72C47FBE727ACA70 ON communaute_rnf_document_folder (parent_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_document_folder DROP FOREIGN KEY FK_72C47FBE727ACA70');
        $this->addSql('DROP INDEX IDX_72C47FBE727ACA70 ON communaute_rnf_document_folder');
        $this->addSql('ALTER TABLE communaute_rnf_document_folder DROP parent_id');
    }
}
