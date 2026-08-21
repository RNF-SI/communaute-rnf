/**
 * Les réglages de notifications quand on siège dans beaucoup de groupes.
 *
 * Ce qui est éprouvé ici, c'est ce qui rend la liste tenable : retrouver un
 * groupe par son nom sans avoir à en respecter les accents ni la casse, et
 * annoncer en une ligne ce qu'un groupe replié contient — la même phrase que
 * celle rendue par le gabarit, sans quoi le résumé sauterait d'une
 * formulation à l'autre au premier clic.
 */

import { normalise, matches, stateOf, countLabel } from '../user/notifications-settings';

const LABELS = { follows: 'comme le réglage général' };

describe('retrouver un groupe', () => {
	test('la casse ne compte pas', () => {
		expect(matches('Commission Montagne', 'montagne')).toBe(true);
	});

	test('les accents non plus', () => {
		expect(matches('Réserves intégrales', 'reserves')).toBe(true);
		expect(matches('Reserves integrales', 'réserves')).toBe(true);
	});

	test('tous les mots tapés doivent être là', () => {
		expect(matches('Commission montagne', 'commission montagne')).toBe(true);
		expect(matches('Commission montagne', 'commission littoral')).toBe(false);
	});

	test('l’ordre des mots ne compte pas', () => {
		expect(matches('Commission montagne', 'montagne commission')).toBe(true);
	});

	test('un champ vide montre tout', () => {
		expect(matches('Commission montagne', '')).toBe(true);
		expect(matches('Commission montagne', '   ')).toBe(true);
	});

	test('un nom absent ne fait rien tomber', () => {
		expect(normalise(null)).toBe('');
		expect(matches(null, 'montagne')).toBe(false);
	});
});

describe('ce qu’annonce un groupe replié', () => {
	test('qu’il suit le réglage général quand il ne dit rien', () => {
		const choices = [
			{ label: 'Discussions', level: '' },
			{ label: 'Pages', level: '' },
		];

		expect(stateOf(choices, LABELS)).toBe('comme le réglage général');
	});

	test('et seulement ce en quoi il s’en écarte', () => {
		const choices = [
			{ label: 'Discussions', level: 'Aucune notification' },
			{ label: 'Pages', level: '' },
		];

		expect(stateOf(choices, LABELS)).toBe('Discussions : aucune notification');
	});

	test('plusieurs écarts se lisent à la suite', () => {
		const choices = [
			{ label: 'Discussions', level: 'Aucune notification' },
			{ label: 'Pages', level: 'Sur la plateforme seulement' },
		];

		expect(stateOf(choices, LABELS)).toBe(
			'Discussions : aucune notification · Pages : sur la plateforme seulement',
		);
	});
});

describe('le compte des groupes montrés', () => {
	test('remplit les deux nombres', () => {
		expect(countLabel('%shown% groupes sur %total%', 3, 42)).toBe('3 groupes sur 42');
	});

	test('un libellé manquant ne laisse pas la page vide', () => {
		expect(countLabel(undefined, 3, 42)).toBe('3 / 42');
	});
});
