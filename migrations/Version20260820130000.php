<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #30 — ce qu'on cherche dans un annuaire professionnel : « nom prénom,
 * fonction, RN(s) gérée(s), OG ou structure ». Trois champs, saisis à la main
 * en attendant que GeoNature les fournisse (#28).
 *
 * La colonne `bio` n'est pas supprimée ici. Contrairement à
 * `edition_restricted`, elle contient du texte que des gens ont écrit : elle
 * est retirée du profil et de la fiche annuaire, mais reste en base le temps
 * que RNF vérifie ce qu'elle garde encore. Pour compter :
 *
 *   SELECT COUNT(*) FROM communaute_rnf_users WHERE bio IS NOT NULL AND bio <> '';
 */
final class Version20260820130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add job title, organisation and reserves to the directory profile';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_users ADD job_title VARCHAR(100) DEFAULT NULL, ADD organisation VARCHAR(150) DEFAULT NULL, ADD reserves VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_users DROP job_title, DROP organisation, DROP reserves');
    }
}
