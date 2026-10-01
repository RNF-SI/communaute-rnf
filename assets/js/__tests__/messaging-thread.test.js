/**
 * Issue #46 — le fil d'une conversation s'ouvre en bas, comme une messagerie
 * instantanée : sur le dernier message, ou sur la barre « Nouveaux messages »
 * s'il y en a une. Une adresse qui vise un message précis garde la main.
 */

import { settle } from '../messaging/thread';

/**
 * jsdom ne calcule aucune mise en page : on pose à la main les dimensions que
 * le navigateur aurait mesurées.
 *
 * @param {boolean} separator
 *
 * @returns {HTMLElement}
 */
function thread (separator) {
	document.body.innerHTML = `
		<ol class="thread" data-thread-scroll>
			<li class="message" id="message-1"></li>
			${separator ? '<li class="thread--separator">Nouveaux messages</li>' : ''}
			<li class="message" id="message-2"></li>
		</ol>
	`;

	const element = document.querySelector('.thread');

	Object.defineProperty(element, 'scrollHeight', { value: 900 });

	const bar = element.querySelector('.thread--separator');

	if (bar) {
		Object.defineProperty(bar, 'offsetTop', { value: 400 });
	}

	return element;
}

describe('settle', () => {
	test('opens at the bottom of the thread', () => {
		const element = thread(false);

		settle(element, '');

		expect(element.scrollTop).toBe(900);
	});

	test('opens on the new messages bar when there is one', () => {
		const element = thread(true);

		settle(element, '');

		expect(element.scrollTop).toBe(400);
	});

	test('leaves the browser alone when the address aims at a message of the thread', () => {
		const element = thread(true);

		settle(element, '#message-1');

		expect(element.scrollTop).toBe(0);
	});

	test('ignores an address that aims at nothing in the thread', () => {
		const element = thread(false);

		settle(element, '#ailleurs');

		expect(element.scrollTop).toBe(900);
	});
});
