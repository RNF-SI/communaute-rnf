/**
 * Issue #32 — la barre d'outils de l'éditeur.
 *
 * Deux choses se vérifient ici sans démarrer un éditeur : ce que la barre
 * propose, et surtout le piège qui va avec — un bouton dont le format n'est
 * pas accepté ne fait rien, et un format retiré de la liste efface en silence
 * ce que les pages contiennent déjà.
 */

import { TOOLBAR, FORMATS, formatOf, toolbarFormats } from '../ui/wysiwyg-config';

// « clean » enlève la mise en forme : c'est une action, pas un format.
const ACTIONS = [ 'clean' ];

describe('ce que la barre propose', () => {
	test('l’essentiel de l’écriture est là', () => {
		const formats = toolbarFormats();

		[ 'header', 'bold', 'italic', 'underline', 'list', 'link', 'image' ].forEach((format) => {
			expect(formats).toContain(format);
		});
	});

	test('ce qui n’a pas d’usage dans le réseau est parti', () => {
		const formats = toolbarFormats();

		// Sens d’écriture de droite à gauche, bloc de code, exposant/indice,
		// tailles libres : hérités de NaturAdapt, jamais utilisés ici.
		[ 'direction', 'code-block', 'script', 'size' ].forEach((format) => {
			expect(formats).not.toContain(format);
		});
	});

	test('les listes à puces restent, elles ont déjà fait l’objet d’un bug', () => {
		expect(toolbarFormats()).toContain('list');
	});

	test('la barre tient en moins de dix groupes', () => {
		expect(TOOLBAR.length).toBeLessThanOrEqual(9);
	});

	test('aucun groupe n’est vide', () => {
		TOOLBAR.forEach((group) => expect(group.length).toBeGreaterThan(0));
	});

	test('aucun bouton n’est proposé deux fois', () => {
		const buttons = TOOLBAR.flat().map((button) => JSON.stringify(button));

		expect(new Set(buttons).size).toBe(buttons.length);
	});
});

describe('l’accord entre la barre et les formats acceptés', () => {
	test('chaque bouton correspond à un format accepté', () => {
		toolbarFormats()
			.filter((format) => !ACTIONS.includes(format))
			.forEach((format) => {
				expect(FORMATS).toContain(format);
			});
	});

	test('les formats retirés de la barre restent acceptés', () => {
		// Quill supprime au chargement tout format absent de cette liste :
		// la retirer d’un format encore présent dans des pages effacerait
		// leur mise en forme sans prévenir.
		[ 'direction', 'code-block', 'script', 'size', 'strike' ].forEach((format) => {
			expect(FORMATS).toContain(format);
		});
	});

	test('aucun format n’est déclaré deux fois', () => {
		expect(new Set(FORMATS).size).toBe(FORMATS.length);
	});
});

describe('la lecture d’une entrée de barre', () => {
	test('une chaîne se lit telle quelle', () => {
		expect(formatOf('bold')).toBe('bold');
	});

	test('un objet donne sa clé', () => {
		expect(formatOf({ header: [ 1, 2, 3, false ] })).toBe('header');
	});
});
