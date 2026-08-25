<?php

namespace App\Service;

use Swift_Mailer;
use Swift_Transport;
use Swift_Transport_SpoolTransport;
use Throwable;

/**
 * Vider la file, et dire ce qui en est vraiment sorti.
 *
 * Swiftmailer est configuré en **file mémoire** : `Swift_Mailer::send()` rend
 * le nombre de destinataires sans avoir joint personne, et l'envoi n'a lieu
 * qu'à la fin de la requête — ou de la commande. C'est ce qu'il faut pour une
 * page web, où l'on ne fait pas attendre quelqu'un pendant un aller-retour
 * chez Postmark.
 *
 * Mais en ligne de commande, cela veut dire que le résumé quotidien marquait
 * `emailedAt` sur des e-mails **mis en file**, pas sur des e-mails partis. Un
 * refus de Postmark — une signature d'expéditeur non confirmée, par exemple —
 * arrivait après coup, sans destinataire pour l'entendre, et la notification
 * était perdue pour de bon. C'est le même défaut que celui du transport en
 * lot, sur le troisième chemin.
 *
 * D'où ce service : vider la file **maintenant**, et rendre le compte. Aucun
 * appelant web ne s'en sert — leur file continue de partir à la fin de la
 * requête.
 */
class MailSpool {
	/**
	 * @var \Swift_Mailer
	 */
	private $mailer;

	/**
	 * Le transport réel, celui qui joint Postmark. Absent quand aucune file
	 * n'est configurée : l'envoi a alors déjà eu lieu.
	 *
	 * @var \Swift_Transport|null
	 */
	private $transport;

	public function __construct ( Swift_Mailer $mailer, Swift_Transport $transport = NULL ) {
		$this->mailer    = $mailer;
		$this->transport = $transport;
	}

	/**
	 * @return bool whether what is sent goes through a queue at all
	 */
	public function isSpooled () {
		return $this->spool() !== NULL;
	}

	/**
	 * Vide la file et rend ce qui est parti.
	 *
	 * NULL quand il n'y a pas de file : l'appelant ne doit alors rien
	 * conclure de ce service, l'envoi ayant eu lieu à l'appel de `send()`.
	 * Confondre ce NULL avec un zéro ferait tenir pour perdu ce qui est parti.
	 *
	 * @return int|null
	 */
	public function flush () {
		$spool = $this->spool();

		if ( !$spool || !$this->transport ) {
			return NULL;
		}

		try {
			return (int) $spool->flushQueue( $this->transport );
		}
		catch ( Throwable $error ) {
			// Un transport qui refuse ne doit pas faire échouer la commande :
			// zéro dit « rien n'est parti », et l'appelant ne marque rien.
			return 0;
		}
	}

	/**
	 * @return \Swift_Spool|null
	 */
	private function spool () {
		$transport = $this->mailer->getTransport();

		return $transport instanceof Swift_Transport_SpoolTransport
				? $transport->getSpool()
				: NULL;
	}
}
