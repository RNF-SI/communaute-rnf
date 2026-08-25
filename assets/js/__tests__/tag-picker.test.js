/**
 * Le bouton « Insérer un lien » de la messagerie.
 *
 * Ce qui est vérifié ici est ce que voit celui qui écrit : que le bouton
 * n'apparaît que s'il peut marcher, ce que le panneau demande au serveur, et
 * ce que choisir écrit dans le champ. Que le texte écrit soit relu comme un
 * tag est vérifié de l'autre côté, par TagScannerTest.
 */

import { attach } from '../ui/tag-picker';

const PANEL = (id) => '<div class="message-composer">'
	+ `<textarea id="${id}"></textarea>`
	+ '<div class="message-composer--tools" hidden>'
	+ '<button type="button" class="tag-picker--open" aria-expanded="false"></button>'
	+ `<div class="tag-picker" hidden data-picker="/messages/picker" data-picker-for="${id}">`
	+ '<div class="tag-picker--head"><button type="button" class="tag-picker--close"></button></div>'
	+ '<input type="search" class="tag-picker--search">'
	+ '<select class="tag-picker--kind">'
	+ '<option value="">Tous</option><option value="document">Documents</option>'
	+ '</select>'
	+ '<p class="tag-picker--state" data-empty="Rien ne correspond." data-failed="Échec." data-loading="Recherche…"></p>'
	+ '<ul class="tag-picker--list"></ul>'
	+ '</div></div></div>';

describe('le panneau d’insertion', () => {
	let field;
	let panel;
	let open;

	const answer = (suggestions) => {
		global.fetch = jest.fn(() => Promise.resolve({
			ok:   true,
			json: () => Promise.resolve({ suggestions }),
		}));
	};

	// Laisse la réponse du serveur, et la chaîne de promesses qui la suit,
	// arriver au bout.
	const settled = () => new Promise((resolve) => { setTimeout(resolve, 0); });

	beforeEach(() => {
		document.body.innerHTML = PANEL('reply-body');

		field = document.getElementById('reply-body');
		panel = document.querySelector('.tag-picker');
		open  = document.querySelector('.tag-picker--open');

		answer([
			{ label: 'Guide : gestion des mares', kind: 'document', hint: 'Marais poitevin', insert: '#"Guide : gestion des mares"' },
			{ label: 'Suivi avifaune', kind: 'document', hint: 'Marais poitevin', insert: '#Suivi avifaune' },
		]);

		attach(panel);
	});

	afterEach(() => {
		delete global.fetch;
	});

	test('le bouton reste caché tant que le JavaScript ne l’a pas allumé', () => {
		document.body.innerHTML = PANEL('other-body');

		expect(document.querySelector('.message-composer--tools').hidden).toBe(true);

		attach(document.querySelector('.tag-picker'));

		expect(document.querySelector('.message-composer--tools').hidden).toBe(false);
	});

	test('ouvrir demande la liste sans qu’on ait rien tapé', async () => {
		open.click();
		await settled();

		expect(global.fetch).toHaveBeenCalledTimes(1);
		expect(global.fetch.mock.calls[0][0]).toBe('/messages/picker?q=&kind=');
		expect(panel.hidden).toBe(false);
		expect(open.getAttribute('aria-expanded')).toBe('true');
	});

	test('chaque résultat porte le type qui fera sa pastille', async () => {
		open.click();
		await settled();

		const items = document.querySelectorAll('.tag-picker--item');

		expect(items).toHaveLength(2);
		expect(items[0].dataset.kind).toBe('document');
		expect(items[0].textContent).toContain('Guide : gestion des mares');
		expect(items[0].textContent).toContain('Marais poitevin');
	});

	/**
	 * Le point de tout l'ouvrage : c'est le serveur qui dit comment s'écrit
	 * un tag. Le panneau recopie son `insert`, il ne le recompose pas — sans
	 * quoi les guillemets d'un titre à ponctuation manqueraient, et le tag
	 * retomberait en texte.
	 */
	test('choisir écrit le texte donné par le serveur, guillemets compris', async () => {
		open.click();
		await settled();

		document.querySelectorAll('.tag-picker--item')[0]
			.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));

		expect(field.value).toBe('#"Guide : gestion des mares" ');
	});

	test('un tag ne se colle pas au mot qui le précède', async () => {
		field.value = 'regarde';
		field.setSelectionRange(7, 7);

		open.click();
		await settled();

		document.querySelectorAll('.tag-picker--item')[1]
			.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));

		expect(field.value).toBe('regarde #Suivi avifaune ');
	});

	test('le tag s’écrit là où était le curseur, pas à la fin', async () => {
		field.value = 'avant après';
		field.setSelectionRange(6, 6);

		open.click();
		await settled();

		document.querySelectorAll('.tag-picker--item')[1]
			.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));

		expect(field.value).toBe('avant #Suivi avifaune après');
	});

	test('choisir referme le panneau et rend le curseur au message', async () => {
		open.click();
		await settled();

		document.querySelectorAll('.tag-picker--item')[0]
			.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));

		expect(panel.hidden).toBe(true);
		expect(open.getAttribute('aria-expanded')).toBe('false');
		expect(document.activeElement).toBe(field);
	});

	test('restreindre le type le dit au serveur', async () => {
		open.click();
		await settled();

		const kind = document.querySelector('.tag-picker--kind');
		kind.value = 'document';
		kind.dispatchEvent(new Event('change'));
		await settled();

		expect(global.fetch.mock.calls[1][0]).toBe('/messages/picker?q=&kind=document');
	});

	test('une recherche part après une pause, pas à chaque lettre', async () => {
		jest.useFakeTimers();

		open.click();

		const search = document.querySelector('.tag-picker--search');
		search.value = 'ma';
		search.dispatchEvent(new Event('input'));
		search.value = 'mar';
		search.dispatchEvent(new Event('input'));

		jest.runOnlyPendingTimers();
		jest.useRealTimers();
		await settled();

		// Une fois à l'ouverture, une fois pour les deux frappes.
		expect(global.fetch).toHaveBeenCalledTimes(2);
		expect(global.fetch.mock.calls[1][0]).toBe('/messages/picker?q=mar&kind=');
	});

	test('rien qui corresponde se dit, plutôt que de laisser une liste vide', async () => {
		answer([]);

		open.click();
		await settled();

		expect(document.querySelectorAll('.tag-picker--item')).toHaveLength(0);
		expect(document.querySelector('.tag-picker--state').textContent).toBe('Rien ne correspond.');
	});

	test('un serveur qui refuse se dit aussi', async () => {
		global.fetch = jest.fn(() => Promise.resolve({ ok: false }));

		open.click();
		await settled();

		expect(document.querySelector('.tag-picker--state').textContent).toBe('Échec.');
	});

	test('échap referme sans rien écrire', async () => {
		open.click();
		await settled();

		panel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

		expect(panel.hidden).toBe(true);
		expect(field.value).toBe('');
	});

	test('les flèches et entrée choisissent au clavier', async () => {
		open.click();
		await settled();

		panel.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
		panel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));

		expect(field.value).toBe('#Suivi avifaune ');
	});
});
