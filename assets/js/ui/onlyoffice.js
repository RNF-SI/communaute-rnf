import domready from "mf-js/modules/dom/ready";

/**
 * L'éditeur en ligne des documents bureautiques. (#43)
 *
 * Tout ce que ce fichier fait, c'est remettre à OnlyOffice la configuration
 * que le serveur a préparée — jeton compris. Il n'en fabrique aucun morceau :
 * les adresses, les droits et la signature viennent de PHP, où le voteur a
 * tranché. Un navigateur qui recomposerait quoi que ce soit ici ouvrirait une
 * seconde porte à côté de la première.
 *
 * Le script du serveur de documents est chargé par la page, avant celui-ci.
 * On ne s'y fie pas pour autant : il vient d'un autre hôte, qui peut être
 * éteint, mal nommé ou refusé par le navigateur (page en https, serveur de
 * documents en http). On l'attend donc, et passé le délai on le dit — un cadre
 * blanc et silencieux laisserait croire à un document vide.
 */
const TIMEOUT = 15000;
const STEP    = 100;

const waitForApi = ( onReady, onGiveUp ) => {
	let waited = 0;

	const tick = () => {
		if ( window.DocsAPI && window.DocsAPI.DocEditor ) {
			onReady();

			return;
		}

		waited += STEP;

		if ( waited >= TIMEOUT ) {
			onGiveUp();

			return;
		}

		window.setTimeout( tick, STEP );
	};

	tick();
};

domready( () => {
	const container = document.getElementById( 'onlyoffice-editor' );

	if ( !container ) {
		return;
	}

	let config;

	try {
		config = JSON.parse( container.getAttribute( 'data-config' ) );
	} catch ( e ) {
		config = null;
	}

	if ( !config ) {
		return;
	}

	waitForApi(
		() => new window.DocsAPI.DocEditor( 'onlyoffice-editor', config ),
		() => {
			const notice = document.createElement( 'p' );
			notice.className   = 'document-office--notice document-office--notice__error';
			notice.textContent = container.getAttribute( 'data-error' ) || '';
			container.parentNode.insertBefore( notice, container );
		}
	);
} );
