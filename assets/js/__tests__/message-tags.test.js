/**
 * La liste que la messagerie propose quand on tape « @ » ou « # ».
 *
 * Ce qui est vérifié ici est ce que voit celui qui écrit : quand la liste
 * s'ouvre, sur quel préfixe, et ce que choisir insère dans le champ. Ce que le
 * serveur fait du texte est vérifié de son côté, par TagScannerTest.
 */

import { QUERIES, attach } from '../ui/message-tags';

// Ce que le module lit devant le curseur pour décider d'ouvrir la liste.
const reading = (text) => {
	for (let i = 0; i < QUERIES.length; i += 1) {
		const found = QUERIES[i].pattern.exec(text);

		if (found) {
			return { prefix: QUERIES[i].prefix, query: found[1] };
		}
	}

	return null;
};

describe('ce qui ouvre la liste', () => {
	test('une arobase désigne quelqu’un', () => {
		expect(reading('Bonjour @Jea')).toEqual({ prefix: '@', query: 'Jea' });
	});

	test('un dièse désigne un contenu', () => {
		expect(reading('Regarde #Guide de ges')).toEqual({ prefix: '#', query: 'Guide de ges' });
	});

	test('un titre peut compter plus de mots qu’un nom', () => {
		expect(reading('#Guide de gestion des tourbières de montagne 2024'))
			.toEqual({ prefix: '#', query: 'Guide de gestion des tourbières de montagne 2024' });
	});

	test('une adresse e-mail n’ouvre rien', () => {
		expect(reading('écrivez à jeanne@example')).toBeNull();
	});

	test('un dièse collé à un mot n’ouvre rien', () => {
		expect(reading('numéro n#3')).toBeNull();
	});

	test('un retour à la ligne referme la lecture', () => {
		expect(reading('Bonjour @Jeanne\nla suite')).toBeNull();
	});

	test('rien à chercher quand rien n’a été ouvert', () => {
		expect(reading('Bonjour tout le monde')).toBeNull();
	});
});

describe('ce que choisir insère', () => {
	let field;

	beforeEach(() => {
		document.body.innerHTML = '<div class="message-composer">'
			+ '<textarea data-tags="/messages/suggestions"></textarea>'
			+ '</div>';

		field = document.querySelector('textarea');

		global.fetch = jest.fn(() => Promise.resolve({
			ok: true,
			json: () => Promise.resolve({
				suggestions: [ { label: 'Jeanne Réserve', kind: 'member', hint: 'PNR du Marais' } ],
			}),
		}));

		attach(field);
	});

	afterEach(() => {
		delete global.fetch;
	});

	/**
	 * Le module attend un peu avant d’interroger le serveur : on tape plus
	 * vite que le réseau ne répond.
	 */
	const type = (value) => {
		jest.useFakeTimers();

		field.value = value;
		field.setSelectionRange(value.length, value.length);
		field.dispatchEvent(new Event('input'));

		jest.runOnlyPendingTimers();
		jest.useRealTimers();

		// Laisse la réponse du serveur, et la chaîne de promesses qui la
		// suit, arriver au bout.
		return new Promise((resolve) => { setTimeout(resolve, 0); });
	};

	test('la liste s’ouvre avec ce que le serveur a répondu', async () => {
		await type('Bonjour @Jea');

		const items = document.querySelectorAll('.mentions-suggestions--item');

		expect(items).toHaveLength(1);
		expect(items[0].textContent).toContain('Jeanne Réserve');
		expect(items[0].dataset.kind).toBe('member');
	});

	test('choisir remplace ce qui a été tapé par le tag entier', async () => {
		await type('Bonjour @Jea');

		document.querySelector('.mentions-suggestions--item')
			.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));

		// Du texte, et rien d’autre : c’est ce texte que le serveur relit.
		expect(field.value).toBe('Bonjour @Jeanne Réserve ');
	});

	test('rien n’est demandé au serveur sans « @ » ni « # »', async () => {
		await type('Bonjour tout le monde');

		expect(global.fetch).not.toHaveBeenCalled();
		expect(document.querySelector('.mentions-suggestions').hidden).toBe(true);
	});
});
