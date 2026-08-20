<?php

namespace App\Service;

/**
 * Ce que le DNS du domaine d'envoi doit dire pour qu'un e-mail arrive. (#14)
 *
 * Un jeton renseigné ne prouve rien : la plateforme peut remettre ses messages
 * à Postmark, et les serveurs destinataires les mettre en quarantaine parce
 * que le domaine ne l'a jamais autorisé. C'est exactement ce qui se passe
 * aujourd'hui — SPF et DKIM échouent, DMARC demande la quarantaine, les
 * destinataires obéissent.
 *
 * Personne ne pouvait le vérifier depuis la machine qui envoie. C'est ce que
 * cette classe rend possible.
 *
 * Le résolveur est injectable pour que la règle se vérifie sans réseau : ce
 * qui compte ici est la lecture des enregistrements, pas la capacité de PHP à
 * interroger un serveur DNS.
 */
class MailDeliverability {
	/**
	 * Ce que Postmark demande d'ajouter à SPF.
	 */
	public const SPF_INCLUDE = 'spf.mtasv.net';

	/**
	 * Le sélecteur DKIM de Postmark.
	 */
	public const DKIM_SELECTOR = 'pm._domainkey';

	/**
	 * Le sous-domaine du Return-Path personnalisé.
	 */
	public const RETURN_PATH = 'pm-bounces';

	public const OK      = 'ok';
	public const WARNING = 'warning';
	public const FAILED  = 'failed';

	/**
	 * @var callable
	 */
	private $resolver;

	/**
	 * @param callable|null $resolver ( string $name, int $type ): array
	 */
	public function __construct ( callable $resolver = NULL ) {
		$this->resolver = $resolver ?: function ( $name, $type ) {
			$records = @dns_get_record( $name, $type );

			return is_array( $records ) ? $records : [];
		};
	}

	/**
	 * L'état des quatre enregistrements, dans l'ordre où ils comptent.
	 *
	 * @param string $domain
	 *
	 * @return array[] chaque entrée : key, status, label, detail
	 */
	public function check ( $domain ) {
		$domain = trim( (string) $domain );

		if ( $domain === '' ) {
			return [ [
					'key'    => 'domain',
					'status' => self::FAILED,
					'label'  => 'Domaine d’envoi',
					'detail' => 'inconnu — POSTMARK_LIST_DOMAIN et POSTMARK_SENDER sont vides',
			] ];
		}

		$spf  = $this->spf( $domain );
		$dkim = $this->dkim( $domain );

		return [ $spf, $dkim, $this->returnPath( $domain ), $this->dmarc( $domain, $spf, $dkim ) ];
	}

	/**
	 * Tout est-il en place ?
	 *
	 * @param string $domain
	 *
	 * @return bool
	 */
	public function isReady ( $domain ) {
		foreach ( $this->check( $domain ) as $record ) {
			if ( $record[ 'status' ] === self::FAILED ) {
				return FALSE;
			}
		}

		return TRUE;
	}

	/**
	 * @param string $domain
	 *
	 * @return array
	 */
	private function spf ( $domain ) {
		$records = $this->txt( $domain );
		$spf     = [];

		foreach ( $records as $value ) {
			if ( stripos( $value, 'v=spf1' ) === 0 ) {
				$spf[] = $value;
			}
		}

		if ( empty( $spf ) ) {
			return $this->record( 'spf', self::FAILED, 'SPF', 'aucun enregistrement SPF' );
		}

		// Deux SPF font échouer SPF pour tout le monde, y compris la
		// messagerie principale du domaine : c'est pire que pas de Postmark.
		if ( count( $spf ) > 1 ) {
			return $this->record(
					'spf',
					self::FAILED,
					'SPF',
					sprintf( '%d enregistrements SPF — un domaine ne doit en publier qu’un', count( $spf ) )
			);
		}

		if ( stripos( $spf[ 0 ], self::SPF_INCLUDE ) === FALSE ) {
			return $this->record(
					'spf',
					self::FAILED,
					'SPF',
					sprintf( 'présent, mais sans include:%s — Postmark n’est pas autorisé', self::SPF_INCLUDE )
			);
		}

		// SPF est limité à dix résolutions DNS ; au-delà il échoue en bloc.
		$lookups = preg_match_all( '/\b(include|a|mx|ptr|exists|redirect)[:=]/i', $spf[ 0 ] );

		if ( $lookups > 10 ) {
			return $this->record(
					'spf',
					self::WARNING,
					'SPF',
					sprintf( 'Postmark autorisé, mais %d résolutions DNS — la limite est de 10', $lookups )
			);
		}

		return $this->record( 'spf', self::OK, 'SPF', sprintf( 'Postmark autorisé (%d résolutions)', $lookups ) );
	}

	/**
	 * @param string $domain
	 *
	 * @return array
	 */
	private function dkim ( $domain ) {
		$records = $this->txt( self::DKIM_SELECTOR . '.' . $domain );

		foreach ( $records as $value ) {
			if ( stripos( $value, 'k=rsa' ) !== FALSE || stripos( $value, 'p=' ) !== FALSE ) {
				return $this->record( 'dkim', self::OK, 'DKIM', self::DKIM_SELECTOR . ' publié' );
			}
		}

		// DKIM est le plus important des trois : il survit aux réexpéditions,
		// là où SPF casse dès qu'un message est transféré.
		return $this->record(
				'dkim',
				self::FAILED,
				'DKIM',
				sprintf( '%s.%s absent — c’est celui qui survit aux transferts', self::DKIM_SELECTOR, $domain )
		);
	}

	/**
	 * @param string $domain
	 *
	 * @return array
	 */
	private function returnPath ( $domain ) {
		$records = call_user_func( $this->resolver, self::RETURN_PATH . '.' . $domain, DNS_CNAME );

		foreach ( (array) $records as $record ) {
			if ( !empty( $record[ 'target' ] ) ) {
				return $this->record( 'return_path', self::OK, 'Return-Path', $record[ 'target' ] );
			}
		}

		// Sans lui, le domaine d'enveloppe est celui de Postmark : SPF passe,
		// mais son alignement strict avec le domaine visible non.
		return $this->record(
				'return_path',
				self::WARNING,
				'Return-Path',
				sprintf( '%s.%s absent — l’alignement strict de SPF n’est pas satisfait', self::RETURN_PATH, $domain )
		);
	}

	/**
	 * @param string $domain
	 * @param array  $spf
	 * @param array  $dkim
	 *
	 * @return array
	 */
	private function dmarc ( $domain, array $spf, array $dkim ) {
		$records = $this->txt( '_dmarc.' . $domain );
		$policy  = NULL;

		foreach ( $records as $value ) {
			if ( stripos( $value, 'v=DMARC1' ) === 0 ) {
				$policy = preg_match( '/\bp=([a-z]+)/i', $value, $found ) ? mb_strtolower( $found[ 1 ] ) : 'none';
			}
		}

		if ( $policy === NULL ) {
			return $this->record( 'dmarc', self::WARNING, 'DMARC', 'aucune politique publiée' );
		}

		$authenticated = ( $spf[ 'status' ] !== self::FAILED ) && ( $dkim[ 'status' ] !== self::FAILED );

		// Une politique stricte alors que rien n'authentifie les envois, c'est
		// demander soi-même la mise en quarantaine de son propre courrier.
		if ( !$authenticated && in_array( $policy, [ 'quarantine', 'reject' ], TRUE ) ) {
			return $this->record(
					'dmarc',
					self::FAILED,
					'DMARC',
					sprintf(
							'p=%s alors que SPF ou DKIM échoue — les destinataires font ce qu’on leur demande',
							$policy
					)
			);
		}

		return $this->record( 'dmarc', self::OK, 'DMARC', sprintf( 'p=%s', $policy ) );
	}

	/**
	 * @param string $name
	 *
	 * @return string[]
	 */
	private function txt ( $name ) {
		$values = [];

		foreach ( (array) call_user_func( $this->resolver, $name, DNS_TXT ) as $record ) {
			if ( !empty( $record[ 'txt' ] ) ) {
				$values[] = $record[ 'txt' ];
			}
			elseif ( !empty( $record[ 'entries' ] ) && is_array( $record[ 'entries' ] ) ) {
				$values[] = implode( '', $record[ 'entries' ] );
			}
		}

		return $values;
	}

	/**
	 * @param string $key
	 * @param string $status
	 * @param string $label
	 * @param string $detail
	 *
	 * @return array
	 */
	private function record ( $key, $status, $label, $detail ) {
		return [ 'key' => $key, 'status' => $status, 'label' => $label, 'detail' => $detail ];
	}
}
