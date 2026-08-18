<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #12 — a reply sent by e-mail could be recorded several times when
 * Postmark retried the inbound webhook. Stores the identifier of the inbound
 * e-mail so an already handled delivery can be recognised.
 */
final class Version20260818151610 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add inbound_message_id to discussion messages, to make the inbound webhook idempotent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_discussion_message ADD inbound_message_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A7D8F2E987E90FE ON communaute_rnf_discussion_message (inbound_message_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_A7D8F2E987E90FE ON communaute_rnf_discussion_message');
        $this->addSql('ALTER TABLE communaute_rnf_discussion_message DROP inbound_message_id');
    }
}
