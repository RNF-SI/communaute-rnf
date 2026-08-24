<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La messagerie : des conversations privées entre membres, hors de tout
 * groupe.
 *
 * Quatre tables et une colonne.
 *
 * - `conversations` porte la date d'activité — recopiée du dernier message,
 *   pour que la liste de la boîte se trie sans jointure — et `pair_key`, les
 *   deux identifiants d'un tête-à-tête, triés. C'est l'unicité de cette
 *   colonne, et non un contrôle dans le code, qui empêche qu'écrire deux fois
 *   à la même personne ouvre deux fils entre lesquels la conversation se
 *   couperait. Elle est NULL dès qu'un troisième participant entre.
 * - `conversations_participants` dit, pour chacun, jusqu'où il a lu, s'il a
 *   rangé la conversation et s'il l'a quittée.
 * - `private_messages` garde le texte tel qu'il a été tapé, tags compris :
 *   « @Prénom Nom », « #Titre » sont relus à l'affichage, jamais réécrits ici.
 * - `message_reports` **recopie** le message signalé et ceux qui le
 *   précèdent. C'est ce qui permet à l'administration de juger sans jamais
 *   ouvrir un échange privé.
 *
 * Et `users.messages_open`, à 1 pour tout le monde : la boîte est ouverte par
 * défaut, et se ferme dans les paramètres.
 */
final class Version20260824120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Private messaging: conversations, messages, participants and reports';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE communaute_rnf_conversations ('
            . 'id INT AUTO_INCREMENT NOT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'last_message_at DATETIME DEFAULT NULL, '
            . 'pair_key VARCHAR(64) DEFAULT NULL, '
            . 'UNIQUE INDEX conversation_pair (pair_key), '
            . 'INDEX conversation_activity (last_message_at), '
            . 'PRIMARY KEY(id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'CREATE TABLE communaute_rnf_conversations_participants ('
            . 'id INT AUTO_INCREMENT NOT NULL, '
            . 'conversation_id INT NOT NULL, '
            . 'user_id INT NOT NULL, '
            . 'joined_at DATETIME NOT NULL, '
            . 'last_read_at DATETIME DEFAULT NULL, '
            . 'archived_at DATETIME DEFAULT NULL, '
            . 'left_at DATETIME DEFAULT NULL, '
            . 'UNIQUE INDEX conversation_participant (conversation_id, user_id), '
            . 'INDEX participant_inbox (user_id, archived_at), '
            . 'PRIMARY KEY(id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'CREATE TABLE communaute_rnf_private_messages ('
            . 'id INT AUTO_INCREMENT NOT NULL, '
            . 'conversation_id INT NOT NULL, '
            . 'author_id INT DEFAULT NULL, '
            . 'body LONGTEXT NOT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'edited_at DATETIME DEFAULT NULL, '
            . 'deleted_at DATETIME DEFAULT NULL, '
            . 'INDEX message_thread (conversation_id, created_at), '
            . 'INDEX IDX_RNF_MESSAGE_AUTHOR (author_id), '
            . 'PRIMARY KEY(id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'CREATE TABLE communaute_rnf_message_reports ('
            . 'id INT AUTO_INCREMENT NOT NULL, '
            . 'message_id INT DEFAULT NULL, '
            . 'reporter_id INT DEFAULT NULL, '
            . 'reported_id INT DEFAULT NULL, '
            . 'handled_by_id INT DEFAULT NULL, '
            . 'reported_name VARCHAR(150) DEFAULT NULL, '
            . 'conversation_id INT DEFAULT NULL, '
            . 'excerpt LONGTEXT DEFAULT NULL, '
            . 'context JSON DEFAULT NULL, '
            . 'reason LONGTEXT DEFAULT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'handled_at DATETIME DEFAULT NULL, '
            . 'INDEX report_pending (handled_at, created_at), '
            . 'INDEX IDX_RNF_REPORT_MESSAGE (message_id), '
            . 'INDEX IDX_RNF_REPORT_REPORTER (reporter_id), '
            . 'INDEX IDX_RNF_REPORT_REPORTED (reported_id), '
            . 'INDEX IDX_RNF_REPORT_HANDLED_BY (handled_by_id), '
            . 'PRIMARY KEY(id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        // Une conversation supprimée emporte ses participants et ses messages ;
        // un compte supprimé, non : ses messages perdent leur auteur et
        // restent, sans quoi le fil de ceux qui restent serait troué.
        $this->addSql(
            'ALTER TABLE communaute_rnf_conversations_participants '
            . 'ADD CONSTRAINT FK_RNF_PARTICIPANT_CONVERSATION FOREIGN KEY (conversation_id) '
            . 'REFERENCES communaute_rnf_conversations (id) ON DELETE CASCADE'
        );
        $this->addSql(
            'ALTER TABLE communaute_rnf_conversations_participants '
            . 'ADD CONSTRAINT FK_RNF_PARTICIPANT_USER FOREIGN KEY (user_id) '
            . 'REFERENCES communaute_rnf_users (id) ON DELETE CASCADE'
        );
        $this->addSql(
            'ALTER TABLE communaute_rnf_private_messages '
            . 'ADD CONSTRAINT FK_RNF_MESSAGE_CONVERSATION FOREIGN KEY (conversation_id) '
            . 'REFERENCES communaute_rnf_conversations (id) ON DELETE CASCADE'
        );
        $this->addSql(
            'ALTER TABLE communaute_rnf_private_messages '
            . 'ADD CONSTRAINT FK_RNF_MESSAGE_AUTHOR FOREIGN KEY (author_id) '
            . 'REFERENCES communaute_rnf_users (id) ON DELETE SET NULL'
        );

        // Un signalement survit à ce qu'il signale : il porte sa propre copie.
        $this->addSql(
            'ALTER TABLE communaute_rnf_message_reports '
            . 'ADD CONSTRAINT FK_RNF_REPORT_MESSAGE FOREIGN KEY (message_id) '
            . 'REFERENCES communaute_rnf_private_messages (id) ON DELETE SET NULL'
        );
        $this->addSql(
            'ALTER TABLE communaute_rnf_message_reports '
            . 'ADD CONSTRAINT FK_RNF_REPORT_REPORTER FOREIGN KEY (reporter_id) '
            . 'REFERENCES communaute_rnf_users (id) ON DELETE SET NULL'
        );
        $this->addSql(
            'ALTER TABLE communaute_rnf_message_reports '
            . 'ADD CONSTRAINT FK_RNF_REPORT_REPORTED FOREIGN KEY (reported_id) '
            . 'REFERENCES communaute_rnf_users (id) ON DELETE SET NULL'
        );
        $this->addSql(
            'ALTER TABLE communaute_rnf_message_reports '
            . 'ADD CONSTRAINT FK_RNF_REPORT_HANDLED_BY FOREIGN KEY (handled_by_id) '
            . 'REFERENCES communaute_rnf_users (id) ON DELETE SET NULL'
        );

        // Ouverte pour tout le monde : personne n'a demandé à fermer sa boîte,
        // et une messagerie qui arriverait fermée n'aurait aucun sens.
        $this->addSql(
            'ALTER TABLE communaute_rnf_users '
            . "ADD messages_open TINYINT(1) DEFAULT '1' NOT NULL"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communaute_rnf_users DROP messages_open');

        $this->addSql('DROP TABLE communaute_rnf_message_reports');
        $this->addSql('DROP TABLE communaute_rnf_private_messages');
        $this->addSql('DROP TABLE communaute_rnf_conversations_participants');
        $this->addSql('DROP TABLE communaute_rnf_conversations');
    }
}
