/**
 * Le moteur du direct de la messagerie.
 *
 * Ce qui est éprouvé ici, ce sont les trois propriétés qui font qu'un sondage
 * est tenable sur un hébergement où les processus PHP se comptent :
 *
 * - **un seul onglet interroge le serveur** — le bail, et ce qui se passe
 *   quand celui qui le tient s'en va ;
 * - **le rythme suit ce qu'on regarde** — trois secondes devant une
 *   conversation, une minute dans un onglet caché, plus rien du tout au bout
 *   d'une demi-heure ;
 * - **rien ne casse quand le stockage refuse** — une fenêtre privée, un quota
 *   plein : le direct doit s'éteindre, pas emporter la page.
 *
 * Le curseur a son propre lot : c'est lui qui décide de ce qu'on redemande, et
 * une erreur d'une unité s'y traduit par un message qui n'arrive jamais.
 */

import { Live, QUICK, NORMAL, HIDDEN, LEASE_KEY, CURSOR_KEY } from '../messaging/live';

/**
 * Un onglet neuf, déjà démarré et branché sur un serveur qui répond ce qu'on
 * lui dit de répondre.
 *
 * @param {object} [answer]
 *
 * @returns {Live}
 */
function tab (answer) {
	const engine = new Live();

	engine.url = '/messages/live';
	engine.started = true;

	window.fetch = jest.fn(() => Promise.resolve({
		ok: true,
		status: 200,
		json: () => Promise.resolve(answer || { cursor: 0, counts: {} }),
	}));

	return engine;
}

/**
 * @param {boolean} hidden
 *
 * @returns {void}
 */
function visibility (hidden) {
	Object.defineProperty(document, 'hidden', { value: hidden, configurable: true });
}

beforeEach(() => {
	window.localStorage.clear();
	visibility(false);
	jest.useRealTimers();
});

describe('un seul onglet interroge le serveur', () => {
	test('le premier venu prend le bail', () => {
		expect(tab().claim()).toBe(true);
	});

	test('le second ne le prend pas', () => {
		const first = tab();
		const second = tab();

		expect(first.claim()).toBe(true);
		expect(second.claim()).toBe(false);
	});

	test('un bail périmé se reprend', () => {
		const first = tab();
		const second = tab();

		first.claim();

		// Le meneur a disparu sans rendre son bail : il y a deux minutes.
		window.localStorage.setItem(LEASE_KEY, JSON.stringify({
			tab: 'un-onglet-parti',
			at: Date.now() - (2 * 60 * 1000),
		}));

		expect(second.claim()).toBe(true);
	});

	test('un meneur garde son bail d’un tour à l’autre', () => {
		const engine = tab();

		expect(engine.claim()).toBe(true);
		expect(engine.claim()).toBe(true);
	});

	test('sans stockage, chaque onglet se débrouille seul', () => {
		const engine = tab();

		// Une fenêtre privée de Safari : écrire lève.
		jest.spyOn(window.localStorage.__proto__, 'setItem').mockImplementation(() => {
			throw new Error('QuotaExceededError');
		});

		// Sonder deux fois vaut mieux que ne pas sonder du tout.
		expect(engine.claim()).toBe(true);

		window.localStorage.__proto__.setItem.mockRestore();
	});
});

describe('le rythme suit ce qu’on regarde', () => {
	test('une conversation dépliée : trois secondes', () => {
		const engine = tab();

		engine.threads = [ 12 ];

		expect(engine.pace()).toBe(QUICK);
	});

	test('rien de déplié : dix secondes', () => {
		expect(tab().pace()).toBe(NORMAL);
	});

	test('onglet caché : une minute', () => {
		const engine = tab();

		engine.threads = [ 12 ];
		visibility(true);

		// Ce qui est déplié ne compte plus : personne ne le regarde.
		expect(engine.pace()).toBe(HIDDEN);
	});

	test('caché et oublié depuis une demi-heure : on se tait', () => {
		const engine = tab();

		visibility(true);
		engine.awakeAt = Date.now() - (31 * 60 * 1000);

		expect(engine.pace()).toBe(0);
	});

	test('un onglet oublié mais visible continue de demander', () => {
		const engine = tab();

		engine.awakeAt = Date.now() - (31 * 60 * 1000);

		expect(engine.pace()).toBe(NORMAL);
	});

	test('le serveur ne répond plus : on attend de plus en plus', () => {
		const engine = tab();

		engine.failures = 1;
		const once = engine.pace();

		engine.failures = 3;
		const thrice = engine.pace();

		expect(once).toBeGreaterThan(NORMAL);
		expect(thrice).toBeGreaterThan(once);
	});

	test('l’attente a un plafond', () => {
		const engine = tab();

		engine.failures = 5;

		expect(engine.pace()).toBeLessThanOrEqual(5 * 60 * 1000);
	});
});

describe('le curseur', () => {
	test('n’avance que vers l’avant', () => {
		const engine = tab();

		engine.absorb({ cursor: 40 });
		engine.absorb({ cursor: 12 });

		// Une charge en retard ne doit pas faire redemander ce qui est déjà
		// affiché : le fil se doublerait.
		expect(engine.cursor).toBe(40);
	});

	test('il se garde, pour qu’un onglet neuf ne rejoue pas l’historique', () => {
		tab().absorb({ cursor: 77, notice: 9 });

		expect(JSON.parse(window.localStorage.getItem(CURSOR_KEY))).toEqual({ cursor: 77, notice: 9 });
	});

	test('une charge sans curseur ne le fait pas retomber à zéro', () => {
		const engine = tab();

		engine.absorb({ cursor: 40 });
		engine.absorb({ counts: { messages: 2 } });

		expect(engine.cursor).toBe(40);
	});

	test('un stockage illisible ne fait pas tomber le démarrage', () => {
		window.localStorage.setItem(CURSOR_KEY, 'ceci n’est pas du JSON');

		const engine = new Live();

		expect(() => engine.start({ url: '/messages/live' })).not.toThrow();
		expect(engine.cursor).toBe(0);
	});
});

describe('ce qui arrive est transmis', () => {
	test('les abonnés reçoivent la charge', () => {
		const engine = tab();
		const seen = [];

		engine.subscribe((payload) => seen.push(payload));
		engine.dispatch({ counts: { messages: 3 } });

		expect(seen).toEqual([ { counts: { messages: 3 } } ]);
	});

	test('un abonné qui casse n’emporte pas les autres', () => {
		const engine = tab();
		const seen = [];

		engine.subscribe(() => {
			throw new Error('un rappel maladroit');
		});
		engine.subscribe((payload) => seen.push(payload));

		expect(() => engine.dispatch({ counts: {} })).not.toThrow();
		expect(seen).toHaveLength(1);
	});

	test('annoncer retient le curseur et prévient la page', () => {
		const engine = tab();
		const seen = [];

		engine.subscribe((payload) => seen.push(payload));
		engine.announce({ cursor: 5, counts: { messages: 1 } });

		expect(engine.cursor).toBe(5);
		expect(seen).toHaveLength(1);
	});
});

describe('les fils dépliés', () => {
	test('en déclarer de nouveaux relance un tour tout de suite', () => {
		const engine = tab();

		engine.schedule = jest.fn();
		engine.watch([ 3, 4 ]);

		expect(engine.threads).toEqual([ 3, 4 ]);
		expect(engine.schedule).toHaveBeenCalledWith(0);
	});

	test('déclarer les mêmes ne relance rien', () => {
		const engine = tab();

		engine.watch([ 3 ]);
		engine.schedule = jest.fn();
		engine.watch([ 3 ]);

		expect(engine.schedule).not.toHaveBeenCalled();
	});

	test('ce qui n’est pas un identifiant est écarté', () => {
		const engine = tab();

		engine.watch([ 3, null, 0, undefined, '5' ]);

		expect(engine.threads).toEqual([ 3, 5 ]);
	});
});

describe('la session expirée', () => {
	test('une réponse refusée arrête le sondage', async () => {
		const engine = tab();

		engine.claim();

		window.fetch = jest.fn(() => Promise.resolve({ ok: false, status: 403 }));
		engine.schedule = jest.fn();

		engine.poll();

		// Cogner contre la page de connexion toutes les trois secondes ne
		// reconnecte personne.
		await Promise.resolve();
		await Promise.resolve();

		expect(engine.stopped).toBe(true);
	});
});
