/**
 * Issue #42 (7) — un bouton emoji dans la messagerie.
 *
 * Il écrit dans le champ, à l'endroit du curseur, et rien d'autre : un
 * message privé reste du texte.
 */

import { attach, insertAtCaret } from '../ui/emoji-picker';

const PAGE = '<textarea id="reply"></textarea>'
	+ '<div class="message-composer--tools" hidden>'
	+ '<button type="button" class="emoji-picker--open" aria-expanded="false"></button>'
	+ '<div class="emoji-picker" hidden data-emoji-for="reply"><ul>'
	+ '<li><button type="button" class="emoji-picker--item" data-emoji="🙂">🙂</button></li>'
	+ '<li><button type="button" class="emoji-picker--item" data-emoji="🌿">🌿</button></li>'
	+ '</ul></div></div>';

describe('le bouton emoji', () => {
	let field;
	let panel;
	let open;

	beforeEach(() => {
		document.body.innerHTML = PAGE;

		field = document.getElementById('reply');
		panel = document.querySelector('.emoji-picker');
		open  = document.querySelector('.emoji-picker--open');

		attach(panel);
	});

	test('les outils ne s’affichent qu’une fois le JavaScript là', () => {
		expect(document.querySelector('.message-composer--tools').hidden).toBe(false);
	});

	test('le bouton ouvre et ferme la liste', () => {
		open.click();
		expect(panel.hidden).toBe(false);
		expect(open.getAttribute('aria-expanded')).toBe('true');

		open.click();
		expect(panel.hidden).toBe(true);
	});

	test('choisir un emoji l’écrit au curseur et referme', () => {
		field.value = 'Merci  pour tout';
		field.setSelectionRange(6, 6);

		open.click();
		panel.querySelector('[data-emoji="🌿"]').click();

		expect(field.value).toBe('Merci 🌿 pour tout');
		expect(panel.hidden).toBe(true);
		expect(document.activeElement).toBe(field);
	});

	test('Échap referme sans rien écrire', () => {
		field.value = 'Bonjour';

		open.click();
		panel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

		expect(panel.hidden).toBe(true);
		expect(field.value).toBe('Bonjour');
	});

	test('un clic ailleurs referme', () => {
		open.click();
		document.body.click();

		expect(panel.hidden).toBe(true);
	});

	test('l’insertion prévient ceux qui écoutent la frappe', () => {
		const heard = jest.fn();
		field.addEventListener('input', heard);

		insertAtCaret(field, '🙂');

		expect(heard).toHaveBeenCalled();
	});

	test('attacher deux fois ne double pas les écouteurs', () => {
		attach(panel);

		field.value = '';
		open.click();
		panel.querySelector('[data-emoji="🙂"]').click();

		expect(field.value).toBe('🙂');
	});
});
