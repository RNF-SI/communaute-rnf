<?php

namespace App\Session;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;

/**
 * Un fichier de session illisible déconnecte une personne. Il ne met pas la
 * plateforme à terre.
 *
 * PHP refuse de lire un `sess_*` dont le **propriétaire** n'est pas le
 * processus — ni les droits ni les ACL n'y changent quoi que ce soit. Sa
 * lecture rend alors FALSE, `session_start()` échoue, et
 * `NativeSessionStorage` lève « Failed to start the session ». Comme la
 * session est ouverte à chaque requête, il n'y a pas de page épargnée : pas
 * même l'accueil, pas même la connexion. La plateforme entière tombe pour un
 * fichier.
 *
 * Le 25 août, un `chown -R` sur `var/` — le remède aux droits d'écriture des
 * index de recherche, appliqué un cran trop large — a suffi. La cause était
 * dans la recette de ce dépôt ; elle en est sortie, mais une recette ne
 * protège que ceux qui la lisent.
 *
 * **Ce qu'on fait à la place** : une lecture qui échoue rend une session vide.
 * Le visiteur repart sur une session neuve, c'est-à-dire déconnecté, et se
 * reconnecte — au moment de la connexion, PHP régénère l'identifiant et le
 * fichier suivant appartient à qui de droit. La panne se répare donc d'
 * elle-même, personne par personne.
 *
 * **Et on le dit.** Dégrader en silence transformerait une panne bruyante en
 * déconnexions inexplicables, ce qui est pire : c'est précisément le genre de
 * chose que ce projet a déjà passé des jours à traquer. Chaque échec est
 * journalisé avec le chemin, de sorte que `var/log/prod.log` porte la trace de
 * ce qui se passe même quand plus rien ne casse.
 *
 * C'est la même règle qu'ailleurs sur la plateforme : un index de recherche en
 * échec n'empêche pas de créer un compte, un logo absent n'empêche pas
 * d'afficher une page. Ce qui est dérivé ne doit pas emporter ce qui ne l'est
 * pas.
 */
class ResilientFileSessionHandler extends NativeFileSessionHandler {
	/**
	 * Protégés, et non privés : le constructeur parent appelle `ini_set()`,
	 * impossible sous PHPUnit une fois la sortie commencée. Une épreuve doit
	 * donc pouvoir bâtir l'objet sans lui — ce qu'elle ne peut faire que si
	 * elle atteint ces deux champs.
	 *
	 * @var \Psr\Log\LoggerInterface|null
	 */
	protected $logger;

	/**
	 * @var string|null
	 */
	protected $savePath;

	/**
	 * @param string|null                   $savePath
	 * @param \Psr\Log\LoggerInterface|null $logger
	 */
	public function __construct ( $savePath = NULL, LoggerInterface $logger = NULL ) {
		parent::__construct( $savePath );

		$this->savePath = $savePath;
		$this->logger   = $logger;
	}

	/**
	 * @param string $sessionId
	 *
	 * @return string
	 */
	public function read ( $sessionId ) {
		$data = $this->readFromParent( $sessionId );

		if ( $data !== FALSE ) {
			return $data;
		}

		if ( $this->logger ) {
			$this->logger->warning(
					'Session illisible : une session vide est ouverte à la place. '
					. 'Le plus souvent, un fichier appartenant à un autre utilisateur — '
					. 'PHP vérifie le propriétaire, pas les droits.',
					[ 'save_path' => $this->savePath ]
			);
		}

		return '';
	}

	/**
	 * La lecture native, isolée pour être remplaçable dans une épreuve.
	 *
	 * Ce qui se contrôle ici est notre décision — FALSE devient une session
	 * vide, et on le journalise —, pas le contrôle de propriété de PHP, qui
	 * ne nous appartient pas.
	 *
	 * L'arobase couvre l'avertissement que PHP émet en même temps qu'il rend
	 * FALSE : on le remplace par un message qui dit quoi faire.
	 *
	 * @param string $sessionId
	 *
	 * @return string|false
	 */
	protected function readFromParent ( $sessionId ) {
		return @parent::read( $sessionId );
	}
}
