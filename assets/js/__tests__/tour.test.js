/**
 * Issue #39 — la visite guidée.
 *
 * Ce qui compte ici, c'est qu'aucune étape ne dépende de la page où la visite
 * est lancée : celle qui vise un élément absent doit s'afficher au centre
 * plutôt que de pointer dans le vide, et celle qui vise un élément masqué
 * aussi — on ne montre pas du doigt ce qu'on ne voit pas.
 */

import { targetOf, placementFor, isLast, move } from '../ui/tour';

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
