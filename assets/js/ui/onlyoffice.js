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
 * Le reste du fichier ne sert qu'à une chose : **qu'aucune panne ne soit
 * silencieuse**. Il y en a trois, et elles ne se ressemblent pas.
 *
 * 1. Le script du serveur de documents ne vient pas. Il est chargé par la
 *    page, mais il vient d'un autre hôte, qui peut être éteint, mal nommé ou
 *    refusé par le navigateur (page en https, serveur en http). On l'attend,
 *    et passé le délai on le dit.
 * 2. L'éditeur démarre et ne finit jamais de charger. C'est arrivé en
 *    préproduction : une extension de navigateur bloquait `Analytics.js` —
 *    servi correctement par le serveur, mais refusé côté poste parce que son
 *    nom ressemble à du pistage. Le chargement d'OnlyOffice attend ce script,
 *    et l'éditeur restait sur son écran de chargement, indéfiniment, sans une
 *    ligne dans les journaux du serveur ni dans les nôtres. D'où le guetteur
 *    sur `onDocumentReady`.
 * 3. L'éditeur lui-même signale une erreur. On la montre plutôt que de la
 *    laisser dans la console.
 */
const API_TIMEOUT   = 15000;
const READY_TIMEOUT = 30000;
const STEP          = 100;

const waitForApi = ( onReady, onGiveUp ) => {
	let waited = 0;

	const tick = () => {
		if ( window.DocsAPI && window.DocsAPI.DocEditor ) {
			onReady();

			return;
		}

		waited += STEP;

		if ( waited >= API_TIMEOUT ) {
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

	// Tout se lit MAINTENANT : `DocsAPI.DocEditor` ne remplit pas cet élément,
	// il le remplace — et ses attributs partent avec lui.
	const frame     = container.parentNode;
	const messages  = {
		unreachable: container.getAttribute( 'data-error' ) || '',
		stalled:     container.getAttribute( 'data-stalled' ) || '',
	};

	let config;

	try {
		config = JSON.parse( container.getAttribute( 'data-config' ) );
	} catch ( e ) {
		config = null;
	}

	if ( !config ) {
		return;
	}

	let told = false;

	const tell = ( text ) => {
		if ( told || !text || !frame || !frame.parentNode ) {
			return;
		}

		told = true;

		const notice = document.createElement( 'p' );
		notice.className   = 'document-office--notice document-office--notice__error';
		notice.textContent = text;

		frame.parentNode.insertBefore( notice, frame );
	};

	waitForApi(
		() => {
			let ready = false;

			const watchdog = window.setTimeout( () => {
				if ( !ready ) {
					tell( messages.stalled );
				}
			}, READY_TIMEOUT );

			// Les gestionnaires sont ajoutés ici, jamais dans la configuration
			// signée : ce sont des fonctions, elles ne traversent pas le jeton.
			config.events = {
				onDocumentReady: () => {
					ready = true;
					window.clearTimeout( watchdog );
				},
				onError:         ( event ) => {
					window.clearTimeout( watchdog );

					const detail = event && event.data && event.data.errorDescription;

					tell( detail ? messages.stalled + ' (' + detail + ')' : messages.stalled );
				},
			};

			new window.DocsAPI.DocEditor( 'onlyoffice-editor', config );
		},
		() => tell( messages.unreachable )
	);
} );
