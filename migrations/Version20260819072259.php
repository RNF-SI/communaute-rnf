<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #7 — a few words describing a resource document, to give it some
 * visibility in the lists and in the search.
 */
final class Version20260819072259 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add an optional description to documents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_document ADD description LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_document DROP description');
    }
}
