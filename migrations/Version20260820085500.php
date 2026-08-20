<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #27 — les coordonnées de contact dans l'annuaire : un téléphone que
 * le membre publie s'il le veut, et la possibilité de retirer son adresse.
 */
final class Version20260820085500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contact details to the directory profile';
    }

    public function up(Schema $schema): void
    {
        // Les comptes existants gardent leur adresse visible : c'est ce
        // qu'elle était avant cette migration, la masquer d'office serait
        // décider à leur place.
        $this->addSql('ALTER TABLE communaute_rnf_users ADD phone VARCHAR(30) DEFAULT NULL, ADD email_visible TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_users DROP phone, DROP email_visible');
    }
}
