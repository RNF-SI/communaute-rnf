<?php

namespace App\Tests\Service\OnlyOffice;

use App\Entity\Document;
use App\Entity\File;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Service\OnlyOffice\OnlyOfficeJwt;
use App\Service\OnlyOffice\OnlyOfficeService;
use App\Service\OnlyOffice\OnlyOfficeToken;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * Issue #43 — l'édition en ligne des documents bureautiques.
 *
 * Ce qui est éprouvé ici tient en trois questions, et ce sont les trois qui
 * coûtent cher quand la réponse est fausse : **qui a le droit d'enregistrer**,
 * **ce que le serveur de documents peut atteindre**, et **ce qui se passe
 * quand rien n'est configuré**.
 */
class OnlyOfficeServiceTest extends TestCase {
	private const SECRET = 'secret-de-lapplication';

	/**************************************************
	 * LA SIGNATURE PARTAGÉE
	 **************************************************/

	public function testASignedPayloadComesBackIntact () {
		$jwt = new OnlyOfficeJwt( 'shared' );

		$payload = [ 'document' => [ 'key' => 'abc', 'title' => 'Compte rendu' ], 'n' => 3 ];

		$this->assertSame( $payload, $jwt->decode( $jwt->encode( $payload ) ) );
	}

	/**
	 * Le point de tout l'exercice : une charge utile modifiée en chemin ne
	 * doit pas passer, sans quoi n'importe qui pourrait nous faire enregistrer
	 * n'importe quoi.
	 */
	public function testATamperedSignatureIsRefused () {
		$jwt = new OnlyOfficeJwt( 'shared' );

		$token = $jwt->encode( [ 'status' => 4 ] );

		list( $header, , $signature ) = explode( '.', $token );

		$forged = $header . '.'
				  . rtrim( strtr( base64_encode( json_encode( [ 'status' => 2 ] ) ), '+/', '-_' ), '=' )
				  . '.' . $signature;

		$this->assertNull( $jwt->decode( $forged ) );
	}

	public function testAnotherSecretDoesNotOpenIt () {
		$mine   = new OnlyOfficeJwt( 'shared' );
		$theirs = new OnlyOfficeJwt( 'autre' );

		$this->assertNull( $theirs->decode( $mine->encode( [ 'status' => 2 ] ) ) );
	}

	public function testNoSecretMeansNothingIsSigned () {
		$this->assertFalse( ( new OnlyOfficeJwt( '' ) )->isEnabled() );
		$this->assertFalse( ( new OnlyOfficeJwt( '   ' ) )->isEnabled() );
	}

	/**************************************************
	 * LE JETON DES DEUX ROUTES SANS SESSION
	 **************************************************/

	public function testATokenSaysWhichDocumentAndWhatRight () {
		$tokens = new OnlyOfficeToken( self::SECRET );

		$claims = $tokens->read( $tokens->create( 17, OnlyOfficeToken::WRITE ) );

		$this->assertSame( 17, $claims[ 'document' ] );
		$this->assertSame( OnlyOfficeToken::WRITE, $claims[ 'mode' ] );
	}

	/**
	 * Un jeton fabriqué à la main ne passe pas : ces deux routes n'ont que lui
	 * pour se défendre.
	 */
	public function testAForgedTokenIsRefused () {
		$tokens = new OnlyOfficeToken( self::SECRET );

		$this->assertNull( $tokens->read( '17.w.99999999999.deadbeef' ) );
		$this->assertNull( $tokens->read( 'nawak' ) );
		$this->assertNull( $tokens->read( '' ) );
	}

	/**
	 * Changer le droit inscrit dans un jeton de lecture ne suffit pas à en
	 * faire un jeton d'écriture : la signature couvre le droit.
	 */
	public function testTheRightCannotBeRaisedAfterTheFact () {
		$tokens = new OnlyOfficeToken( self::SECRET );

		$read = $tokens->create( 17, OnlyOfficeToken::READ );

		$this->assertNull( $tokens->read( str_replace( '17.r.', '17.w.', $read ) ) );
	}

	public function testAnExpiredTokenIsRefused () {
		$tokens = new OnlyOfficeToken( self::SECRET );

		$issued = $tokens->create( 17, OnlyOfficeToken::WRITE, 1000 );

		$this->assertNotNull( $tokens->read( $issued, 1000 ) );
		$this->assertNull( $tokens->read( $issued, 1000 + 86401 ) );
	}

	/**************************************************
	 * CE QUI S'OUVRE, ET CE QUI SE MODIFIE
	 **************************************************/

	/**
	 * Rien de configuré, rien de proposé : pas de bouton, donc pas de page
	 * morte au bout.
	 */
	public function testNothingIsOfferedWithoutAServer () {
		$service = $this->service( '' );

		$this->assertFalse( $service->isEnabled() );
		$this->assertFalse( $service->supports( $this->file( 'notes.docx' ) ) );
		$this->assertFalse( $service->isEditable( $this->file( 'notes.docx' ) ) );
	}

	public function testTheThreeFamiliesAreRecognised () {
		$service = $this->service();

		$this->assertSame( 'word', $service->documentType( $this->file( 'compte-rendu.odt' ) ) );
		$this->assertSame( 'cell', $service->documentType( $this->file( 'suivi.xlsx' ) ) );
		$this->assertSame( 'slide', $service->documentType( $this->file( 'presentation.odp' ) ) );
		$this->assertNull( $service->documentType( $this->file( 'archive.zip' ) ) );
	}

	/**
	 * Un PDF ne passe pas par le serveur de documents : le navigateur
	 * l'affiche seul, et le nom de son type a changé d'une version à l'autre.
	 */
	public function testAPdfIsNotHandledHere () {
		$this->assertFalse( $this->service()->supports( $this->file( 'plan.pdf' ) ) );
	}

	/**
	 * Les formats hérités s'ouvrent, mais ne se réenregistrent pas : le faire
	 * reviendrait à convertir le fichier de quelqu'un sans le lui demander.
	 */
	public function testLegacyFormatsOpenButDoNotSave () {
		$service = $this->service();

		$this->assertTrue( $service->supports( $this->file( 'vieux.doc' ) ) );
		$this->assertFalse( $service->isEditable( $this->file( 'vieux.doc' ) ) );

		$this->assertTrue( $service->isEditable( $this->file( 'recent.docx' ) ) );
		$this->assertTrue( $service->isEditable( $this->file( 'recent.ODT' ) ) );
	}

	/**************************************************
	 * LA CONFIGURATION REMISE À L'ÉDITEUR
	 **************************************************/

	/**
	 * C'est l'invariant à ne pas perdre : la configuration est rendue dans la
	 * page, donc lue par celui qui regarde. Une consultation ne doit pas
	 * emporter de quoi réécrire le document.
	 */
	public function testAReaderGetsNoWayToSave () {
		$config = $this->service()->editorConfig( $this->document(), $this->user(), FALSE );

		$this->assertArrayNotHasKey( 'callbackUrl', $config[ 'editorConfig' ] );
		$this->assertSame( 'view', $config[ 'editorConfig' ][ 'mode' ] );
		$this->assertFalse( $config[ 'document' ][ 'permissions' ][ 'edit' ] );
	}

	public function testAnEditorGetsOne () {
		$config = $this->service()->editorConfig( $this->document(), $this->user(), TRUE );

		$this->assertStringContainsString( '/office/', $config[ 'editorConfig' ][ 'callbackUrl' ] );
		$this->assertSame( 'edit', $config[ 'editorConfig' ][ 'mode' ] );
		$this->assertTrue( $config[ 'document' ][ 'permissions' ][ 'edit' ] );
	}

	/**
	 * Le droit d'écrire vu par le voteur ne suffit pas : un format qui ne se
	 * réenregistre pas s'ouvre en lecture, même pour un animateur.
	 */
	public function testAnEditorStillGetsNoneOnALegacyFormat () {
		$config = $this->service()->editorConfig( $this->document( 'vieux.doc' ), $this->user(), TRUE );

		$this->assertArrayNotHasKey( 'callbackUrl', $config[ 'editorConfig' ] );
		$this->assertSame( 'view', $config[ 'editorConfig' ][ 'mode' ] );
	}

	/**
	 * Le jeton d'écriture ne doit jamais se retrouver dans la configuration
	 * d'une consultation, fût-ce dans l'adresse du fichier.
	 */
	public function testTheReadConfigCarriesAReadToken () {
		$tokens = new OnlyOfficeToken( self::SECRET );
		$config = $this->service()->editorConfig( $this->document(), $this->user(), FALSE );

		preg_match( '#/office/([^/]+)/content#', $config[ 'document' ][ 'url' ], $matches );

		$this->assertNotEmpty( $matches, 'Assert the file URL carries a token' );
		$this->assertSame( OnlyOfficeToken::READ, $tokens->read( $matches[ 1 ] )[ 'mode' ] );
	}

	/**
	 * Sans cela, le serveur de documents rouvre la version qu'il a en cache et
	 * l'enregistre par-dessus la nouvelle.
	 */
	public function testTheVersionKeyFollowsTheFile () {
		$service = $this->service();

		$first  = $this->file( 'notes.docx', 12, 'group-1/notes.docx', 400 );
		$second = $this->file( 'notes.docx', 13, 'group-1/notes-2.docx', 512 );

		$this->assertNotSame( $service->key( $first ), $service->key( $second ) );
	}

	/**
	 * Le serveur de documents vit ailleurs : les adresses qu'on lui donne
	 * doivent être celles sous lesquelles **il** nous joint, et pas celles du
	 * navigateur.
	 */
	public function testTheServerIsGivenAnAddressItCanReach () {
		$service = $this->service( 'https://docs.exemple.fr', 'http://plateforme:8000' );

		$config = $service->editorConfig( $this->document(), $this->user(), TRUE );

		$this->assertStringStartsWith( 'http://plateforme:8000/office/', $config[ 'document' ][ 'url' ] );
		$this->assertStringStartsWith( 'http://plateforme:8000/office/', $config[ 'editorConfig' ][ 'callbackUrl' ] );
	}

	public function testTheConfigIsSignedWhenASecretIsShared () {
		$signed   = $this->service( 'https://docs.exemple.fr', '', 'shared' );
		$unsigned = $this->service();

		$this->assertArrayHasKey( 'token', $signed->editorConfig( $this->document(), $this->user(), TRUE ) );
		$this->assertArrayNotHasKey( 'token', $unsigned->editorConfig( $this->document(), $this->user(), TRUE ) );
	}

	/**************************************************
	 * OUTILLAGE
	 **************************************************/

	/**
	 * @param string $serverUrl
	 * @param string $platformUrl
	 * @param string $jwtSecret
	 *
	 * @return \App\Service\OnlyOffice\OnlyOfficeService
	 */
	private function service ( $serverUrl = 'https://docs.exemple.fr', $platformUrl = '', $jwtSecret = '' ) {
		return new OnlyOfficeService(
				new OnlyOfficeJwt( $jwtSecret ),
				new OnlyOfficeToken( self::SECRET ),
				new FakeUrlGenerator(),
				$serverUrl,
				$platformUrl
		);
	}

	/**
	 * @param string $name
	 * @param int    $id
	 * @param string $path
	 * @param int    $size
	 *
	 * @return \App\Entity\File
	 */
	private function file ( $name, $id = 12, $path = 'group-1/notes', $size = 400 ) {
		$file = new File();
		$file->setFilesystem( File::USERGROUP_FILES );
		$file->setName( $name );
		$file->setPath( $path );
		$file->setSize( $size );

		self::setId( $file, $id );

		return $file;
	}

	/**
	 * @param string $name
	 *
	 * @return \App\Entity\Document
	 */
	private function document ( $name = 'compte-rendu.docx' ) {
		$group = new Usergroup();
		$group->setSlug( 'groupe-de-test' );

		$document = new Document();
		$document->setTitle( 'Compte rendu' );
		$document->setUsergroup( $group );
		$document->setFile( $this->file( $name ) );

		self::setId( $document, 42 );

		return $document;
	}

	/**
	 * @return \App\Entity\User
	 */
	private function user () {
		$user = new User();
		$user->setName( 'Jeanne Dupont' );

		self::setId( $user, 7 );

		return $user;
	}

	/**
	 * Les identifiants sont posés par Doctrine, et ces objets ne passent pas
	 * par lui : ils n'existent que le temps d'une assertion.
	 *
	 * @param object $entity
	 * @param int    $id
	 */
	private static function setId ( $entity, $id ) {
		$property = ( new ReflectionClass( $entity ) )->getProperty( 'id' );
		$property->setAccessible( TRUE );
		$property->setValue( $entity, $id );
	}
}

/**
 * Le routeur, réduit à ce dont ces tests ont besoin : rendre une adresse d'où
 * l'on puisse relire le jeton.
 */
class FakeUrlGenerator implements UrlGeneratorInterface {
	private $context;

	public function __construct () {
		$this->context = new RequestContext( '', 'GET', 'exemple.fr', 'https' );
	}

	public function generate ( $name, array $parameters = [], $referenceType = self::ABSOLUTE_PATH ) {
		switch ( $name ) {
			case 'onlyoffice_content':
				$path = '/office/' . $parameters[ 'token' ] . '/content';
				break;

			case 'onlyoffice_callback':
				$path = '/office/' . $parameters[ 'token' ] . '/callback';
				break;

			default:
				$path = '/groups/' . ( $parameters[ 'groupSlug' ] ?? '' )
						. '/documents/' . ( $parameters[ 'documentId' ] ?? '' );
		}

		return ( $referenceType === self::ABSOLUTE_URL ) ? 'https://exemple.fr' . $path : $path;
	}

	public function setContext ( RequestContext $context ) {
		$this->context = $context;
	}

	public function getContext () {
		return $this->context;
	}
}
