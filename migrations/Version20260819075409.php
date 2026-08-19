<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #34 — choices that apply to every group at once: whether e-mails go
 * out at all, and at what rhythm discussion e-mails leave.
 *
 * The per-group preferences need no migration: they live in the JSON column
 * that already exists on the memberships, and the accessors read the legacy
 * `unsubscribed` flag as a full opt-out. Nobody is resubscribed.
 */
final class Version20260819075409 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notifications_settings to users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_users ADD notifications_settings JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_users DROP notifications_settings');
    }
}
