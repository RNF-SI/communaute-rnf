/**
 * Issue #39 — la visite guidée.
 *
 * Deux choses comptent ici.
 *
 * La première : aucune étape ne dépend de la page où la visite est lancée.
 * Celle qui vise un élément absent doit s'afficher au centre plutôt que de
 * pointer dans le vide, et celle qui vise un élément masqué aussi — on ne
 * montre pas du doigt ce qu'on ne voit pas.
 *
 * La seconde : la visite se déplace. Elle doit savoir dire « cette étape se
 * joue ailleurs », garder sa place entre deux pages, et ne pas ramener de
 * force quelqu'un qui est parti se promener.
 */

import {
	targetOf,
	placementFor,
	isLast,
	move,
	pathOf,
	samePage,
	destinationFor,
	withTour,
	stateFor,
	readState,
	resumeAt,
	spotlightFor,
	nextLabel,
	typing,
	restartAsked,
} from '../ui/tour';

const VIEWPORT = { width: 1200, height: 800 };

const box = ({ top = 100, left = 40, width = 300, height = 60 }) => ({
	getBoundingClientRect: () => ({
		top,
		left,
		width,
		height,
		bottom: top + height,
		right: left + width,
	}),
});

const TOUR = [
	{ key: 'welcome' },
	{ key: 'groups', url: '/groups', label: 'Voir tous les groupes' },
	{ key: 'groups_graph', url: '/groups' },
	{ key: 'my_groups', url: '/user/groups', label: 'Aller à mes groupes' },
];

describe('l’élément visé', () => {
	beforeEach(() => {
		document.body.innerHTML = '<ul class="my-groups-list"><li>Commission montagne</li></ul>';
	});

	test('est trouvé quand il est sur la page', () => {
		expect(targetOf({ target: '.my-groups-list' })).not.toBeNull();
	});

	test('est absent quand on n’est pas sur la bonne page', () => {
		expect(targetOf({ target: '.group-tabs' })).toBeNull();
	});

	test('une étape sans cible ne vise rien', () => {
		expect(targetOf({ title: 'Bienvenue' })).toBeNull();
	});

	test('une étape vide ne fait pas tomber la visite', () => {
		expect(targetOf(null)).toBeNull();
	});
});

describe('où se pose la bulle', () => {
	test('au centre quand il n’y a rien à viser', () => {
		expect(placementFor(null, VIEWPORT).placement).toBe('center');
	});

	test('au centre quand l’élément est masqué', () => {
		const hidden = box({ top: 0, left: 0, width: 0, height: 0 });

		expect(placementFor(hidden, VIEWPORT).placement).toBe(
			'center',
			'un menu replié ne se montre pas du doigt',
		);
	});

	test('sous l’élément quand la place est là', () => {
		const where = placementFor(box({ top: 100, height: 60 }), VIEWPORT);

		expect(where.placement).toBe('below');
		expect(where.top).toBeGreaterThan(160);
	});

	test('au-dessus quand le bas de la page est trop près', () => {
		const where = placementFor(box({ top: 700, height: 60 }), VIEWPORT);

		expect(where.placement).toBe('above');
	});

	test('la bulle ne sort jamais par la gauche', () => {
		const where = placementFor(box({ top: 100, left: -400 }), VIEWPORT);

		expect(where.left).toBeGreaterThanOrEqual(0);
	});

	test('ni par la droite, quand l’élément est collé au bord', () => {
		const where = placementFor(box({ top: 100, left: 1150 }), VIEWPORT, { width: 420, height: 200 });

		expect(where.left + 420).toBeLessThanOrEqual(VIEWPORT.width);
	});

	test('un élément plus haut que l’écran ne chasse pas la bulle dehors', () => {
		const where = placementFor(box({ top: -200, height: 1200 }), VIEWPORT, { width: 420, height: 200 });

		expect(where.top).toBeGreaterThanOrEqual(0);
		expect(where.top + 200).toBeLessThanOrEqual(VIEWPORT.height);
	});
});

describe('le halo autour de l’élément', () => {
	test('entoure l’élément avec un peu d’air', () => {
		const halo = spotlightFor(box({ top: 100, left: 40, width: 300, height: 60 }), 8);

		expect(halo.top).toBe(92);
		expect(halo.left).toBe(32);
		expect(halo.width).toBe(316);
		expect(halo.height).toBe(76);
	});

	test('n’existe pas quand il n’y a rien à entourer', () => {
		expect(spotlightFor(null)).toBeNull();
	});

	test('n’existe pas non plus autour d’un élément masqué', () => {
		expect(spotlightFor(box({ width: 0, height: 0 }))).toBeNull();
	});
});

describe('l’avancement dans la visite', () => {
	const steps = [ { key: 'a' }, { key: 'b' }, { key: 'c' } ];

	test('la première étape n’est pas la dernière', () => {
		expect(isLast(steps, 0)).toBe(false);
	});

	test('la dernière étape est reconnue', () => {
		expect(isLast(steps, 2)).toBe(true);
	});

	test('on avance d’une étape', () => {
		expect(move(steps, 0, 1)).toBe(1);
	});

	test('on recule d’une étape', () => {
		expect(move(steps, 2, -1)).toBe(1);
	});

	test('on ne recule pas avant le début', () => {
		expect(move(steps, 0, -1)).toBe(0);
	});

	test('on ne dépasse pas la fin', () => {
		expect(move(steps, 2, 1)).toBe(2);
	});

	test('une visite d’une seule étape est déjà finie', () => {
		expect(isLast([ { key: 'seule' } ], 0)).toBe(true);
	});
});

describe('reconnaître la page', () => {
	test('le chemin ignore la requête et l’ancre', () => {
		expect(pathOf('/user/parameters/edit?tour=1#notifications-settings')).toBe('/user/parameters/edit');
	});

	test('et le domaine, quand l’adresse est absolue', () => {
		expect(pathOf('https://communaute.rnf.fr/groups')).toBe('/groups');
	});

	test('une adresse vide ne vaut aucun chemin', () => {
		expect(pathOf(null)).toBe('');
	});

	test('la même page malgré un paramètre en plus', () => {
		expect(samePage('/groups?tour=1', '/groups')).toBe(true);
	});

	test('une étape sans adresse se joue là où l’on est', () => {
		expect(samePage(undefined, '/n-importe-ou')).toBe(true);
	});
});

describe('changer de page', () => {
	test('une étape d’ailleurs donne sa destination', () => {
		expect(destinationFor(TOUR, 3, '/groups')).toBe('/user/groups');
	});

	test('une étape de la page courante n’emmène nulle part', () => {
		expect(destinationFor(TOUR, 2, '/groups')).toBeNull();
	});

	test('une étape sans page non plus', () => {
		expect(destinationFor(TOUR, 0, '/groups')).toBeNull();
	});

	test('l’adresse d’arrivée redemande la visite', () => {
		expect(withTour('/user/groups')).toBe('/user/groups?tour=on');
	});

	test('sans écraser les paramètres déjà là', () => {
		expect(withTour('/groups?page=2')).toBe('/groups?page=2&tour=on');
	});

	test('sans perdre l’ancre', () => {
		expect(withTour('/user/parameters/edit#notifications-settings'))
			.toBe('/user/parameters/edit?tour=on#notifications-settings');
	});

	test('et sans se répéter si elle est déjà demandée', () => {
		expect(withTour('/groups?tour=on')).toBe('/groups?tour=on');
	});

	// Le lien des paramètres, lui, demande à tout revoir : ce n'est pas la
	// même chose que continuer, et un onglet qui traîne ne doit pas faire
	// reprendre au milieu.
	test('« revoir la visite » se reconnaît à son paramètre', () => {
		expect(restartAsked('?tour=1')).toBe(true);
		expect(restartAsked('?page=2&tour=1')).toBe(true);
	});

	test('là où la visite qui se déplace ne le demande pas', () => {
		expect(restartAsked('?tour=on')).toBe(false);
		expect(restartAsked('')).toBe(false);
	});

	test('le bouton annonce où il emmène', () => {
		expect(nextLabel(TOUR, 2, '/groups', { next: 'Suivant' })).toBe('Aller à mes groupes');
	});

	test('et dit seulement « suivant » quand on reste sur place', () => {
		expect(nextLabel(TOUR, 1, '/groups', { next: 'Suivant' })).toBe('Suivant');
	});

	test('la dernière étape termine', () => {
		expect(nextLabel(TOUR, 3, '/user/groups', { end: 'Terminer' })).toBe('Terminer');
	});
});

describe('les flèches du clavier', () => {
	// La page reste utilisable pendant la visite : quelqu'un qui écrit dans
	// un champ ne doit pas voir la visite avancer sous ses doigts.
	test('appartiennent à la visite en temps normal', () => {
		expect(typing(document.createElement('div'))).toBe(false);
	});

	test('mais à qui est en train de saisir', () => {
		expect(typing(document.createElement('input'))).toBe(true);
		expect(typing(document.createElement('textarea'))).toBe(true);
		expect(typing(document.createElement('select'))).toBe(true);
	});

	test('et à qui écrit dans un éditeur', () => {
		const editor = document.createElement('div');
		editor.contentEditable = 'true';

		// jsdom ne calcule pas `isContentEditable` : on le pose nous-mêmes.
		Object.defineProperty(editor, 'isContentEditable', { value: true });

		expect(typing(editor)).toBe(true);
	});

	test('un événement sans cible ne fait rien tomber', () => {
		expect(typing(null)).toBe(false);
	});
});

describe('la place gardée entre deux pages', () => {
	test('se relit telle qu’elle a été écrite', () => {
		expect(readState(stateFor(3, '/user/groups?tour=1'))).toEqual({ index: 3, at: '/user/groups' });
	});

	test('un stockage vide ne dit rien', () => {
		expect(readState(null)).toBeNull();
	});

	test('un contenu abîmé non plus', () => {
		expect(readState('{ pas du json')).toBeNull();
	});

	test('ni un contenu sans étape', () => {
		expect(readState('{"at":"/groups"}')).toBeNull();
	});

	test('la visite reprend là où elle s’était arrêtée', () => {
		expect(resumeAt({ index: 2, at: '/groups' }, TOUR, '/groups')).toBe(2);
	});

	// Quelqu'un qui est parti se promener n'est pas ramené de force : on lui
	// proposera de reprendre, dans un coin.
	test('elle ne reprend pas d’elle-même ailleurs', () => {
		expect(resumeAt({ index: 2, at: '/groups' }, TOUR, '/user/notifications')).toBeNull();
	});

	test('une place au-delà de la fin retombe sur la dernière étape', () => {
		expect(resumeAt({ index: 99, at: '' }, TOUR, '/groups')).toBe(TOUR.length - 1);
	});

	test('sans place gardée, rien à reprendre', () => {
		expect(resumeAt(null, TOUR, '/groups')).toBeNull();
	});
});
