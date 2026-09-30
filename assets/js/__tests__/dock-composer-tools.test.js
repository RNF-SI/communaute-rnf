/**
 * Issue #42 (7) — « Insérer un lien » et les emojis dans la petite fenêtre du
 * dock, pas seulement sur la grande page.
 *
 * Le gabarit pose une copie des outils dans un <template> ; chaque fenêtre la
 * clone et la rattache à son propre champ. Deux fenêtres ouvertes ne doivent
 * pas se partager un panneau.
 */

import { composerTools } from '../messaging/dock';

const TEMPLATE = '<template id="dock-composer-tools">'
	+ '<div class="message-composer--tools" hidden>'
	+ '<button type="button" class="tag-picker--open" aria-controls="__field__-picker">Insérer un lien</button>'
	+ '<div class="tag-picker" id="__field__-picker" hidden data-picker="/messages/picker" data-picker-for="__field__"></div>'
	+ '<button type="button" class="emoji-picker--open" aria-controls="__field__-emoji"></button>'
	+ '<div class="emoji-picker" id="__field__-emoji" hidden data-emoji-for="__field__"></div>'
	+ '</div></template>';

describe('les outils d’une fenêtre du dock', () => {
	beforeEach(() => {
		document.body.innerHTML = TEMPLATE;
	});

	test('sont rattachés au champ de la fenêtre', () => {
		const tools = composerTools('dock-field-3');

		expect(tools.querySelector('.tag-picker').id).toBe('dock-field-3-picker');
		expect(tools.querySelector('.tag-picker').dataset.pickerFor).toBe('dock-field-3');
		expect(tools.querySelector('.tag-picker--open').getAttribute('aria-controls')).toBe('dock-field-3-picker');
		expect(tools.querySelector('.emoji-picker').dataset.emojiFor).toBe('dock-field-3');
		expect(tools.innerHTML).not.toContain('__field__');
	});

	test('deux fenêtres ont chacune les leurs', () => {
		const one = composerTools('dock-field-1');
		const two = composerTools('dock-field-2');

		expect(one).not.toBe(two);
		expect(one.querySelector('.tag-picker').id).not.toBe(two.querySelector('.tag-picker').id);
	});

	test('le modèle lui-même reste intact', () => {
		composerTools('dock-field-1');

		expect(document.getElementById('dock-composer-tools').innerHTML).toContain('__field__');
	});

	test('sans modèle, rien — la fenêtre garde son champ', () => {
		document.body.innerHTML = '';

		expect(composerTools('dock-field-1')).toBeNull();
	});
});
