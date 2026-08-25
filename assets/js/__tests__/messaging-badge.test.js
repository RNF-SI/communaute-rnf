/**
 * Les nombres de l'en-tête : la pastille sur l'avatar, et le détail ligne par
 * ligne dans le menu qu'elle déroule.
 *
 * Ce qui est éprouvé ici tient en une phrase : **ce fichier ne calcule rien**.
 * Il recopie ce que le serveur renvoie. Un compteur qui s'incrémenterait tout
 * seul « pour que ça réagisse tout de suite » finirait par diverger d'une
 * unité de celui que la page suivante affiche, et c'est précisément l'écart
 * qu'un lecteur remarque.
 *
 * Le reste, ce sont les cas où l'on se trompe : un compteur que la charge ne
 * mentionne pas, une pastille à trois chiffres, un titre d'onglet qu'on
 * préfixe deux fois.
 */

import { paint, label, title } from '../messaging/badge';

/**
 * L'en-tête, réduit à ce qui porte un nombre.
 *
 * @returns {void}
 */
function header () {
	document.body.innerHTML = `
		<span data-unread="total" data-unread-label="%count% élément(s) en attente" hidden>0</span>
		<span data-unread="messages" hidden>0</span>
		<span data-unread="notifications" hidden>0</span>
		<span data-unread="discussions" hidden>0</span>
	`;
}

/**
 * @param {string} name
 *
 * @returns {HTMLElement}
 */
function counter (name) {
	return document.querySelector('[data-unread="' + name + '"]');
}

beforeEach(() => {
	header();
	document.title = 'Communauté RNF';
});

describe('les compteurs', () => {
	test('un nombre s’écrit et se montre', () => {
		paint({ messages: 3 });

		expect(counter('messages').textContent).toBe('3');
		expect(counter('messages').hidden).toBe(false);
	});

	test('zéro se cache plutôt que de s’afficher', () => {
		paint({ messages: 3 });
		paint({ messages: 0 });

		expect(counter('messages').hidden).toBe(true);
	});

	test('chaque compteur suit le sien', () => {
		paint({ messages: 2, notifications: 5, discussions: 1, total: 7 });

		expect(counter('messages').textContent).toBe('2');
		expect(counter('notifications').textContent).toBe('5');
		expect(counter('discussions').textContent).toBe('1');
		expect(counter('total').textContent).toBe('7');
	});

	test('un compteur que la charge ne mentionne pas ne bouge pas', () => {
		paint({ messages: 4, notifications: 1 });
		paint({ messages: 0 });

		// Le serveur n'a rien dit des notifications : les remettre à zéro
		// ferait clignoter le menu à chaque envoi de message.
		expect(counter('notifications').textContent).toBe('1');
		expect(counter('notifications').hidden).toBe(false);
	});

	test('une charge vide ne casse rien', () => {
		expect(() => paint(null)).not.toThrow();
		expect(() => paint(undefined)).not.toThrow();
	});

	test('la phrase du lecteur d’écran porte le nombre', () => {
		paint({ total: 4 });

		expect(counter('total').getAttribute('aria-label')).toBe('4 élément(s) en attente');
	});
});

describe('une pastille tient dans un rond de dix-huit pixels', () => {
	test('jusqu’à quatre-vingt-dix-neuf, le nombre', () => {
		expect(label(99)).toBe('99');
	});

	test('au-delà, un signe', () => {
		expect(label(100)).toBe('99+');
		expect(label(4213)).toBe('99+');
	});
});

describe('le titre de l’onglet', () => {
	test('il porte le total', () => {
		title(3);

		expect(document.title).toBe('(3) Communauté RNF');
	});

	test('il ne se préfixe pas deux fois', () => {
		title(3);
		title(5);

		expect(document.title).toBe('(5) Communauté RNF');
	});

	test('à zéro, le titre redevient lui-même', () => {
		title(3);
		title(0);

		expect(document.title).toBe('Communauté RNF');
	});

	test('un total à trois chiffres se raccourcit là aussi', () => {
		title(140);

		expect(document.title).toBe('(99+) Communauté RNF');

		title(0);

		expect(document.title).toBe('Communauté RNF');
	});
});
