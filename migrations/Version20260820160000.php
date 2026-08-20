<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #39 — la visite guidée se lance d'elle-même tant qu'elle n'a pas été
 * vue. Une date plutôt qu'un oui/non : le jour où la visite change, on saura
 * qui l'a vue dans sa version d'avant.
 *
 * Les comptes existants valent NULL : la visite leur sera proposée une fois,
 * ce qui est exactement l'intention — ils ne l'ont jamais vue.
 */
final class Version20260820160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remember whether a member has seen the guided tour';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_users ADD tour_seen_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_users DROP tour_seen_at');
    }
}
