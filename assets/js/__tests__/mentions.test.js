/**
 * Issue #37 — la liste de noms que l'éditeur propose après un « @ ».
 *
 * Ce qui est vérifié ici est ce que voit celui qui écrit : quand la liste
 * s'ouvre, et qui elle propose. Ce que le serveur en fait est vérifié de son
 * côté, par MentionParserTest.
 */

import { matching, fold, QUERY } from '../ui/mentions';

const MEMBERS = [
	{ id: 1, name: 'Jeanne Réserve' },
	{ id: 2, name: 'Paul Marais' },
	{ id: 3, name: 'Simon Forêt' },
	{ id: 4, name: 'Jean-Paul Étang' },
];

// Ce que le module lit devant le curseur pour décider d'ouvrir la liste.
const queryIn = (text) => {
	const found = QUERY.exec(text);

	return found ? found[1] : null;
};

describe('ce qui ouvre la liste', () => {
	test('une arobase en début de message', () => {
		expect(queryIn('@Jea')).toBe('Jea');
	});

	test('une arobase après un espace', () => {
		expect(queryIn('Bonjour @Jea')).toBe('Jea');
	});

	test('une arobase seule, avant même la première lettre', () => {
		expect(queryIn('Bonjour @')).toBe('');
	});

	test('un nom en plusieurs mots', () => {
		expect(queryIn('Bonjour @Jeanne Rés')).toBe('Jeanne Rés');
	});

	test('une adresse e-mail n’ouvre rien', () => {
		expect(queryIn('écrire à contact@rnf')).toBeNull();
	});

	test('rien à faire sans arobase', () => {
		expect(queryIn('Bonjour tout le monde')).toBeNull();
	});

	test('une arobase laissée derrière soi se referme', () => {
		expect(queryIn('@Jeanne Réserve merci beaucoup à tous et à bientôt')).toBeNull();
	});
});

describe('qui la liste propose', () => {
	test('rien de tapé : tout le monde', () => {
		expect(matching(MEMBERS, '')).toHaveLength(4);
	});

	test('le début du prénom', () => {
		expect(matching(MEMBERS, 'Jea').map((m) => m.id)).toEqual([1, 4]);
	});

	test('le début du nom de famille', () => {
		expect(matching(MEMBERS, 'Mar').map((m) => m.id)).toEqual([2]);
	});

	test('sans les accents', () => {
		expect(matching(MEMBERS, 'Foret').map((m) => m.id)).toEqual([3]);
	});

	test('sans la casse', () => {
		expect(matching(MEMBERS, 'PAUL').map((m) => m.id)).toEqual([2, 4]);
	});

	test('le milieu d’un mot ne suffit pas', () => {
		expect(matching(MEMBERS, 'ara')).toEqual([]);
	});

	test('un nom composé se cherche par chacune de ses moitiés', () => {
		expect(matching(MEMBERS, 'Étang').map((m) => m.id)).toEqual([4]);
	});

	test('personne ne répond', () => {
		expect(matching(MEMBERS, 'Zorro')).toEqual([]);
	});

	test('la liste reste courte', () => {
		const crowd = Array.from({ length: 30 }, (value, index) => ({ id: index, name: `Ami ${ index }` }));

		expect(matching(crowd, 'Ami')).toHaveLength(8);
	});
});

describe('la comparaison des noms', () => {
	test('ignore la casse et les accents', () => {
		expect(fold('Jeanne Réserve')).toBe('jeanne réserve'.normalize('NFD').replace(/[̀-ͯ]/g, ''));
	});

	test('supporte une valeur vide', () => {
		expect(fold(undefined)).toBe('');
	});
});
