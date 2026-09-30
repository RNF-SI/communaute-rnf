<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Archiver une page plutôt que la supprimer, quand le format ou le projet
 * dont elle parle n'existe plus. (#42)
 */
final class Version20260930110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pages can be archived';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_pages ADD archived_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_pages DROP archived_at');
    }
}
