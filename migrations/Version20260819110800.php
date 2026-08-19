<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issues #19 et #20 — un message supprimé laisse une trace pour que le fil
 * garde son sens, et une discussion supprimée est archivée plutôt que
 * détruite : les contributions des autres ne sont pas à l'auteur seul.
 */
final class Version20260819110800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deleted_at to discussion messages and archived_at to discussions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_discussion_message ADD deleted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE communaute_rnf_discussion ADD archived_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_discussion_message DROP deleted_at');
        $this->addSql('ALTER TABLE communaute_rnf_discussion DROP archived_at');
    }
}
