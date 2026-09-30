/**
 * Issue #42 (3) — un séparateur dans l'éditeur.
 *
 * Éprouvé avec le vrai Quill : ce qui compte est que le bouton insère un
 * `<hr>` qui **survive** — à l'enregistrement, et au rechargement de la page
 * dans l'éditeur, où Quill jette en silence tout ce qu'il ne connaît pas.
 */

import Quill from 'quill';
import { registerDivider, insertDivider, labelDividerButton, DIVIDER } from '../ui/wysiwyg-divider';
import { TOOLBAR, FORMATS, toolbarFormats } from '../ui/wysiwyg-config';

registerDivider(Quill);

// jsdom ne mesure rien ; Quill en a besoin pour faire défiler jusqu'au curseur.
const rect = { top: 0, bottom: 0, left: 0, right: 0, width: 0, height: 0 };
Range.prototype.getBoundingClientRect = () => rect;
Range.prototype.getClientRects = () => ({ length: 0, item: () => null });

const editor = (html = '') => {
	document.body.innerHTML = '<div id="editor"></div>';

	const quill = new Quill('#editor', {
		theme: 'snow',
		modules: {
			toolbar: {
				container: TOOLBAR,
				handlers: { divider () { insertDivider(this.quill); } },
			},
		},
		formats: FORMATS,
	});

	if (html) {
		quill.clipboard.dangerouslyPasteHTML(html);
	}

	return quill;
};

describe('le séparateur', () => {
	test('la barre le propose, et le format est accepté', () => {
		expect(toolbarFormats()).toContain(DIVIDER);
		expect(FORMATS).toContain(DIVIDER);
	});

	test('le bouton insère un trait horizontal', () => {
		const quill = editor('<p>Avant</p>');

		quill.setSelection(5, 0);
		document.querySelector('button.ql-divider').click();

		expect(quill.root.innerHTML).toContain('<hr>');
	});

	test('le curseur passe après le trait', () => {
		const quill = editor('<p>Avant</p>');

		quill.setSelection(5, 0);
		insertDivider(quill);
		quill.insertText(quill.getSelection().index, 'Après');

		const html = quill.root.innerHTML;

		expect(html.indexOf('<hr>')).toBeLessThan(html.indexOf('Après'));
	});

	test('un trait déjà enregistré survit au rechargement dans l’éditeur', () => {
		const quill = editor('<p>Une partie</p><hr><p>Une autre</p>');

		expect(quill.root.innerHTML).toContain('<hr>');
	});

	test('le bouton porte un nom, venu du gabarit', () => {
		editor();

		labelDividerButton(document.querySelector('.ql-toolbar'), 'Insérer un séparateur');

		const button = document.querySelector('button.ql-divider');

		expect(button.getAttribute('aria-label')).toBe('Insérer un séparateur');
		expect(button.innerHTML).toContain('<svg');
	});
});
