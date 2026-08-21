<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #23 — les thématiques deviennent le second axe de tri des groupes.
 *
 * La table existait depuis 2019 mais rien ne permettait de la remplir : ni
 * écran d'administration, ni champ dans le formulaire d'un groupe. Elle est
 * donc vide, et le `slug` ajouté ici n'a en pratique rien à rattraper — le
 * repli sur le nom est là pour l'installation qui ferait exception.
 */
final class Version20260821160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a slug to group categories, so they can be filtered on';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_categories ADD slug VARCHAR(255) DEFAULT NULL');

        // Un repli grossier — les accents restent — sur des lignes qui ne
        // devraient pas exister. L'administrateur les renommera, ce qui
        // recalculera le slug proprement.
        $this->addSql(
            'UPDATE communaute_rnf_categories '
            . "SET slug = LOWER(REPLACE(REPLACE(name, ' ', '-'), '''', '-')) "
            . 'WHERE slug IS NULL'
        );

        // Deux thématiques homonymes rendraient le filtre ambigu : l'unicité
        // est ce qui fait tenir un vocabulaire fermé.
        $this->addSql('ALTER TABLE communaute_rnf_categories CHANGE slug slug VARCHAR(255) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_RNF_CATEGORY_SLUG ON communaute_rnf_categories (slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_RNF_CATEGORY_SLUG ON communaute_rnf_categories');
        $this->addSql('ALTER TABLE communaute_rnf_categories DROP slug');
    }
}
