<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #33 — la colonne `edition_restricted` des pages n'a jamais été
 * exposée : aucun formulaire, aucun écran, aucune fixture ne l'a jamais
 * écrite depuis sa création en 2019. Elle vaut donc NULL partout, et le seul
 * code qui la lisait vient d'être remplacé par une règle unique — une page se
 * modifie par son auteur ou par un animateur du groupe.
 *
 * La retirer plutôt que la laisser dormir : un réglage qui ne se règle nulle
 * part est un piège pour qui relira ce voter.
 */
final class Version20260820120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the page edition_restricted flag, never settable and now unused';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_pages DROP edition_restricted');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_pages ADD edition_restricted TINYINT(1) DEFAULT NULL');
    }
}
