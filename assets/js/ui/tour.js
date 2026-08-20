/**
 * La visite guidée. (#39)
 *
 * Elle se lance d'elle-même tant qu'elle n'a pas été vue, puis à la demande
 * depuis les paramètres. Chaque étape peut viser un élément de la page : la
 * visite l'entoure alors et se place à côté. Si l'élément n'est pas là — on
 * n'est pas sur la bonne page — l'étape s'affiche au centre. Aucune étape ne
 * dépend donc de l'endroit où la visite est lancée.
 *
 * Fermer vaut avoir vu : quelqu'un qui referme au premier écran a dit ce
 * qu'il pensait de la proposition, la relancer à chaque page serait la
 * transformer en harcèlement.
 */

const MARGIN = 12;

/**
 * @param {Object} step
 * @returns {Element|null}
 */
export function targetOf (step) {
	if (!step || !step.target) {
		return null;
	}

	return document.querySelector(step.target);
}

/**
 * Où poser la bulle : sous l'élément visé s'il y en a un et qu'il tient dans
 * la page, au centre sinon.
 *
 * @param {Element|null} target
 * @param {{width: number, height: number}} viewport
 * @returns {{placement: string, top: number, left: number}}
 */
export function placementFor (target, viewport) {
	if (!target || typeof target.getBoundingClientRect !== 'function') {
		return { placement: 'center', top: 0, left: 0 };
	}

	const box = target.getBoundingClientRect();

	if (box.width === 0 && box.height === 0) {
		// Un élément masqué — un menu replié, par exemple — ne se montre pas
		// du doigt.
		return { placement: 'center', top: 0, left: 0 };
	}

	const below = box.bottom + MARGIN;

	if (below + 160 <= viewport.height) {
		return { placement: 'below', top: below, left: Math.max(MARGIN, box.left) };
	}

	return { placement: 'above', top: Math.max(MARGIN, box.top - MARGIN), left: Math.max(MARGIN, box.left) };
}

/**
 * @param {Array} steps
 * @param {number} index
 * @returns {boolean}
 */
export function isLast (steps, index) {
	return index >= steps.length - 1;
}

/**
 * @param {Array} steps
 * @param {number} index
 * @param {number} step
 * @returns {number}
 */
export function move (steps, index, step) {
	return Math.min(Math.max(index + step, 0), steps.length - 1);
}

/**
 * @param {HTMLElement} root
 */
function start (root) {
	let steps;
	let labels;

	try {
		steps  = JSON.parse(root.dataset.steps || '[]');
		labels = JSON.parse(root.dataset.labels || '{}');
	} catch (error) {
		return;
	}

	if (!Array.isArray(steps) || steps.length === 0) {
		return;
	}

	let index    = 0;
	let outlined = null;

	const overlay = document.createElement('div');
	overlay.className = 'tour-overlay';

	const bubble = document.createElement('div');
	bubble.className = 'tour-bubble';
	bubble.setAttribute('role', 'dialog');
	bubble.setAttribute('aria-live', 'polite');

	function markAsSeen () {
		const body = new FormData();
		body.append('_token', root.dataset.token || '');

		// Le résultat n'intéresse personne : au pire la visite se reproposera.
		fetch(root.dataset.seenUrl, { method: 'POST', credentials: 'same-origin', body }).catch(() => {});
	}

	function clearOutline () {
		if (outlined) {
			outlined.classList.remove('tour-target');
			outlined = null;
		}
	}

	function close () {
		clearOutline();
		overlay.remove();
		bubble.remove();
		document.removeEventListener('keydown', onKey);
		markAsSeen();
	}

	function onKey (event) {
		if (event.key === 'Escape') {
			close();
		} else if (event.key === 'ArrowRight') {
			go(1);
		} else if (event.key === 'ArrowLeft') {
			go(-1);
		}
	}

	function go (offset) {
		const next = move(steps, index, offset);

		if (next === index && offset > 0 && isLast(steps, index)) {
			close();

			return;
		}

		index = next;
		draw();
	}

	function draw () {
		const step   = steps[index];
		const target = targetOf(step);

		clearOutline();

		if (target) {
			target.classList.add('tour-target');
			outlined = target;

			if (typeof target.scrollIntoView === 'function') {
				target.scrollIntoView({ block: 'center' });
			}
		}

		bubble.innerHTML = '';

		const title = document.createElement('h2');
		title.className = 'tour-bubble--title';
		title.textContent = step.title;
		bubble.appendChild(title);

		const body = document.createElement('p');
		body.className = 'tour-bubble--body';
		body.textContent = step.body;
		bubble.appendChild(body);

		if (step.url) {
			const link = document.createElement('a');
			link.className = 'tour-bubble--link';
			link.href = step.url;
			link.textContent = step.label || step.url;
			bubble.appendChild(link);
		}

		const footer = document.createElement('div');
		footer.className = 'tour-bubble--footer';

		const progress = document.createElement('span');
		progress.className = 'tour-bubble--progress';
		progress.textContent = (labels.progress || '%d / %d')
			.replace('%d', index + 1)
			.replace('%d', steps.length);
		footer.appendChild(progress);

		if (index > 0) {
			const previous = document.createElement('button');
			previous.type = 'button';
			previous.className = 'tour-bubble--previous';
			previous.textContent = labels.previous || 'Précédent';
			previous.addEventListener('click', () => go(-1));
			footer.appendChild(previous);
		}

		const next = document.createElement('button');
		next.type = 'button';
		next.className = 'tour-bubble--next';
		next.textContent = isLast(steps, index) ? (labels.end || 'Terminer') : (labels.next || 'Suivant');
		next.addEventListener('click', () => (isLast(steps, index) ? close() : go(1)));
		footer.appendChild(next);

		bubble.appendChild(footer);

		const dismiss = document.createElement('button');
		dismiss.type = 'button';
		dismiss.className = 'tour-bubble--close';
		dismiss.setAttribute('aria-label', labels.close || 'Fermer');
		dismiss.textContent = '×';
		dismiss.addEventListener('click', close);
		bubble.appendChild(dismiss);

		const where = placementFor(target, { width: window.innerWidth, height: window.innerHeight });

		bubble.classList.toggle('tour-bubble__center', where.placement === 'center');

		if (where.placement === 'center') {
			bubble.style.top = '';
			bubble.style.left = '';
		} else {
			bubble.style.top = where.top + 'px';
			bubble.style.left = where.left + 'px';
		}

		next.focus();
	}

	overlay.addEventListener('click', close);
	document.addEventListener('keydown', onKey);

	document.body.appendChild(overlay);
	document.body.appendChild(bubble);

	draw();
}

document.addEventListener('DOMContentLoaded', () => {
	const root = document.getElementById('guided-tour');

	if (root) {
		start(root);
	}
});
