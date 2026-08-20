<?php

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Les réserves naturelles suivies par un compte, telles que GeoNature les
 * connaît. (#28)
 *
 * GeoNature publie un export « Liens utilisateurs-réserves »
 * (`/api/exports/api/{id}`) filtrable par `role_id` — c'est-à-dire par le
 * `rnfIdRole` que le SSO nous donne déjà à chaque connexion. C'est la seule
 * des trois informations attendues par #28 que l'API fournit réellement :
 * l'organisme n'arrive que sous forme d'identifiant, et la fonction n'a pas
 * de source identifiée.
 *
 * L'export demande un jeton. Tant qu'il n'est pas configuré, ce service se
 * tait et le champ reste saisi à la main : mieux vaut une saisie manuelle
 * qu'une case vide.
 */
class RnfReserves {
	/**
	 * Les noms de colonne sous lesquels le libellé d'une réserve peut arriver.
	 *
	 * L'export renvoie `area_x`, `area_y`, `rn_id`, `rn_nom` et `role_id` —
	 * constaté contre le serveur, le schéma n'étant publié nulle part. Les
	 * autres noms sont là pour qu'un renommage côté GeoNature ne vide pas
	 * silencieusement les fiches.
	 */
	private const NAME_COLUMNS = [ 'rn_nom', 'nom_rn', 'nom', 'libelle', 'rn_libelle' ];

	/**
	 * À défaut de libellé, l'identifiant de la réserve : une fiche qui affiche
	 * un code reste plus utile qu'une fiche vide, et se remarque.
	 */
	private const ID_COLUMNS = [ 'rn_id', 'id_rn', 'code_rn' ];

	/**
	 * Longueur de la colonne `reserves` : au-delà, la liste est tronquée à la
	 * dernière réserve entière plutôt qu'au milieu d'un nom.
	 */
	private const MAX_LENGTH = 255;

	/**
	 * @var \Symfony\Contracts\HttpClient\HttpClientInterface
	 */
	private $httpClient;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	private $logger;

	/**
	 * @var string
	 */
	private $endpoint;

	/**
	 * @var string
	 */
	private $token;

	/**
	 * @var int
	 */
	private $exportId;

	public function __construct (
			HttpClientInterface $httpClient,
			LoggerInterface $logger,
			string $endpoint = '',
			string $token = '',
			int $exportId = 3
	) {
		$this->httpClient = $httpClient;
		$this->logger     = $logger;
		$this->endpoint   = rtrim( $endpoint, '/' );
		$this->token      = trim( $token );
		$this->exportId   = $exportId;
	}

	/**
	 * Sans jeton, rien n'est possible : l'export répond 403.
	 *
	 * @return bool
	 */
	public function isConfigured () {
		return ( $this->endpoint !== '' ) && ( $this->token !== '' );
	}

	/**
	 * GeoNature tient-il les réserves de ce compte ?
	 *
	 * C'est la question que pose le formulaire de profil : si oui, le champ
	 * est verrouillé, parce que la prochaine synchronisation écraserait ce
	 * qu'on y saisirait. (#28, même règle que le nom en #36)
	 *
	 * @param \App\Entity\User|null $user
	 *
	 * @return bool
	 */
	public function feedsProfileOf ( $user ) {
		return $this->isConfigured() && ( $user instanceof User ) && !empty( $user->getRnfIdRole() );
	}

	/**
	 * Ce que l'export renvoie pour un compte, brut.
	 *
	 * Exposé tel quel pour la commande de diagnostic : le schéma de la vue
	 * n'étant pas publié, la seule façon honnête de le connaître est de le
	 * regarder.
	 *
	 * @param int $roleId
	 *
	 * @return array
	 *
	 * @throws \Exception si l'export refuse de répondre
	 */
	public function fetch ( $roleId ) {
		if ( !$this->isConfigured() ) {
			throw new \RuntimeException( 'RNF_EXPORT_TOKEN is not configured' );
		}

		$response = $this->httpClient->request(
				'GET',
				sprintf( '%s/api/exports/api/%d', $this->endpoint, $this->exportId ),
				[
						// Le jeton passe en paramètre, pas en en-tête : le
						// swagger propose les deux, l'API refuse le second
						// (403), constaté contre le serveur réel.
						'query'   => [
								'token'   => $this->token,
								'role_id' => (int) $roleId,
								'limit'   => 100,
						],
						'headers' => [ 'Accept' => 'application/json' ],
				]
		);

		if ( $response->getStatusCode() !== 200 ) {
			throw new \RuntimeException( sprintf(
					'The reserves export answered %d',
					$response->getStatusCode()
			) );
		}

		$payload = $response->toArray( FALSE );

		return isset( $payload[ 'items' ] ) && is_array( $payload[ 'items' ] )
				? $payload[ 'items' ]
				: [];
	}

	/**
	 * Les réserves d'un compte, prêtes à être affichées : « RN du Marais, RN
	 * de la Bassée ».
	 *
	 * Renvoie NULL — et non une chaîne vide — quand l'export n'a rien à dire,
	 * pour que l'appelant distingue « GeoNature ne sait pas » de « GeoNature
	 * dit : aucune réserve ».
	 *
	 * @param \App\Entity\User $user
	 *
	 * @return string|null
	 */
	public function forUser ( User $user ) {
		if ( !$this->isConfigured() || !$user->getRnfIdRole() ) {
			return NULL;
		}

		try {
			$items = $this->fetch( $user->getRnfIdRole() );
		}
		catch ( Throwable $error ) {
			// Un annuaire ne se vide pas parce qu'une API est indisponible.
			$this->logger->warning( 'Reserves export unreachable', [
					'role_id' => $user->getRnfIdRole(),
					'error'   => $error->getMessage(),
			] );

			return NULL;
		}

		return $this->format( $items );
	}

	/**
	 * @param array $items
	 *
	 * @return string|null
	 */
	public function format ( array $items ) {
		$names = [];

		foreach ( $items as $item ) {
			if ( !is_array( $item ) ) {
				continue;
			}

			$name = $this->nameOf( $item );

			// Une même réserve peut revenir par plusieurs liens : elle ne se
			// lit qu'une fois.
			if ( ( $name !== NULL ) && !in_array( $name, $names, TRUE ) ) {
				$names[] = $name;
			}
		}

		if ( empty( $names ) ) {
			return NULL;
		}

		sort( $names );

		return $this->fit( $names );
	}

	/**
	 * @param array $item
	 *
	 * @return string|null
	 */
	private function nameOf ( array $item ) {
		foreach ( array_merge( self::NAME_COLUMNS, self::ID_COLUMNS ) as $column ) {
			if ( isset( $item[ $column ] ) && ( trim( (string) $item[ $column ] ) !== '' ) ) {
				return $this->withoutCode( trim( (string) $item[ $column ] ), $item );
			}
		}

		return NULL;
	}

	/**
	 * GeoNature écrit le libellé « Tourbière de Mathon (RNN8) », code compris.
	 * Dans un annuaire, le code n'apprend rien à un collègue et allonge une
	 * liste déjà longue — cinq réserves dépassent la place disponible.
	 *
	 * Retiré seulement quand la parenthèse finale **est** l'identifiant de la
	 * réserve : un nom qui contient de vraies parenthèses garde les siennes.
	 *
	 * @param string $name
	 * @param array  $item
	 *
	 * @return string
	 */
	private function withoutCode ( $name, array $item ) {
		foreach ( self::ID_COLUMNS as $column ) {
			$code = isset( $item[ $column ] ) ? trim( (string) $item[ $column ] ) : '';

			if ( $code === '' ) {
				continue;
			}

			$suffix = sprintf( ' (%s)', $code );

			if ( ( $name !== $suffix ) && ( mb_substr( $name, -mb_strlen( $suffix ) ) === $suffix ) ) {
				return trim( mb_substr( $name, 0, -mb_strlen( $suffix ) ) );
			}
		}

		return $name;
	}

	/**
	 * Coupe à la dernière réserve entière, et dit combien manquent plutôt que
	 * de laisser croire que la liste est complète.
	 *
	 * @param string[] $names
	 *
	 * @return string
	 */
	private function fit ( array $names ) {
		$joined = implode( ', ', $names );

		if ( mb_strlen( $joined ) <= self::MAX_LENGTH ) {
			return $joined;
		}

		$kept = [];

		foreach ( $names as $name ) {
			$candidate = implode( ', ', array_merge( $kept, [ $name ] ) );

			// On garde de la place pour le « (+n) » qui dira ce qui manque.
			if ( mb_strlen( $candidate ) > ( self::MAX_LENGTH - 8 ) ) {
				break;
			}

			$kept[] = $name;
		}

		if ( empty( $kept ) ) {
			return mb_substr( $names[ 0 ], 0, self::MAX_LENGTH );
		}

		return implode( ', ', $kept ) . sprintf( ' (+%d)', count( $names ) - count( $kept ) );
	}
}
