<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #19 — the author may correct their own message; the correction is
 * dated so that it stays visible rather than silent.
 */
final class Version20260819073810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add edited_at to discussion messages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_discussion_message ADD edited_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_discussion_message DROP edited_at');
    }
}
