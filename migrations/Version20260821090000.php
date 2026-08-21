<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #40 — le rythme des e-mails se choisit catégorie par catégorie, et
 * plus une fois pour toutes.
 *
 * Deux choses ici.
 *
 * La colonne `rhythm` sur les notifications : le jour où part le résumé se
 * lisait sur le membre, il se lit maintenant sur chaque notification, puisque
 * deux notifications d'une même personne ne partent plus forcément le même
 * jour. Les notifications déjà en file restent à NULL, ce qui vaut le
 * quotidien — elles partiront au prochain passage de la commande, comme
 * prévu.
 *
 * Le drapeau `noticePending` sur les comptes existants : le défaut passe de
 * « un e-mail par message » à « résumé quotidien », et cela ne se voit pas
 * tout seul. Le drapeau fait afficher l'annonce, une fois, aux comptes que la
 * bascule traverse. Personne ne le pose ensuite : les inscrits d'après ne
 * liront jamais l'annonce d'un changement qu'ils n'ont pas connu.
 *
 * Ce qu'on ne fait pas : réécrire les réglages. Un « email » enregistré avant
 * #40 est relu à la volée avec l'ancien `discussionRhythm` du membre, si bien
 * que ceux qui avaient choisi gardent leur choix, et que seuls ceux qui
 * n'avaient rien choisi changent — c'est exactement la bascule voulue. Voir
 * NotificationLevel::fromLegacy().
 */
final class Version20260821090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-notification rhythm, and announce the change to existing members';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_notifications ADD rhythm VARCHAR(20) DEFAULT NULL');

        // JSON_SET crée la clé ou l'écrase ; un compte sans réglages du tout
        // part d'un objet vide plutôt que de NULL, sur quoi JSON_SET ne rend
        // rien.
        // 1, c'est User::STATUS_ACTIVE. Écrit en clair : une migration doit
        // continuer de dire la même chose le jour où la constante bouge.
        $this->addSql(
            'UPDATE communaute_rnf_users '
            . "SET notifications_settings = JSON_SET(COALESCE(notifications_settings, '{}'), '$.noticePending', TRUE) "
            . 'WHERE status = 1'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_notifications DROP rhythm');
        $this->addSql(
            'UPDATE communaute_rnf_users '
            . "SET notifications_settings = JSON_REMOVE(notifications_settings, '$.noticePending') "
            . 'WHERE notifications_settings IS NOT NULL'
        );
    }
}
