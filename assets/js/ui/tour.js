/**
 * La visite guidée. (#39)
 *
 * Elle se lance d'elle-même tant qu'elle n'a pas été vue, puis à la demande
 * depuis les paramètres.
 *
 * Elle se déplace. Chaque étape dit sur quelle page elle se joue et quel
 * élément elle entoure ; quand l'étape suivante est ailleurs, le bouton
 * annonce la destination et le navigateur y va. La visite garde alors sa
 * place dans `sessionStorage` et reprend toute seule à l'arrivée. Ce qui
 * suit tient à ce mécanisme :
 *
 * - une étape dont l'élément est absent s'affiche au centre plutôt que de
 *   pointer dans le vide ; aucune étape ne dépend donc de l'endroit où la
 *   visite a été lancée ;
 * - si la page ne correspond pas à l'endroit où la visite s'était arrêtée —
 *   on a cliqué ailleurs, ou fait « précédent » — elle ne se rouvre pas de
 *   force : elle propose de reprendre, dans un coin. Une visite qui ramène
 *   quelqu'un de force là où il ne voulait pas aller est une visite qu'on
 *   ferme ;
 * - fermer vaut avoir vu, où qu'on en soit. Quelqu'un qui referme au premier
 *   écran a dit ce qu'il pensait de la proposition ; la relancer à chaque
 *   page serait la transformer en harcèlement.
 */

const MARGIN = 12;

/**
 * Ce qu'on suppose de la bulle avant de l'avoir mesurée.
 */
const BUBBLE = { width: 420, height: 200 };

/**
 * L'air laissé autour de l'élément entouré.
 */
const PADDING = 8;

export const STORAGE_KEY = 'rnf-guided-tour';

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
 * Le chemin d'une adresse, sans le domaine, la requête ni l'ancre : c'est la
 * seule partie qui dit « c'est la même page ».
 *
 * @param {string} url
 * @returns {string}
 */
export function pathOf (url) {
	if (!url) {
		return '';
	}

	const path     = String(url).split('#')[0].split('?')[0];
	const absolute = path.match(/^[a-z]+:\/\/[^/]+(\/.*)?$/i);

	return absolute ? (absolute[1] || '/') : path;
}

/**
 * @param {string} url
 * @param {string} path
 * @returns {boolean}
 */
export function samePage (url, path) {
	return !url || pathOf(url) === pathOf(path);
}

/**
 * Où il faut aller pour jouer cette étape, ou rien si elle se joue ici.
 *
 * @param {Array} steps
 * @param {number} index
 * @param {string} path
 * @returns {string|null}
 */
export function destinationFor (steps, index, path) {
	const step = steps && steps[index];

	if (!step || !step.url || samePage(step.url, path)) {
		return null;
	}

	return step.url;
}

/**
 * L'adresse d'arrivée redemande la visite, sans quoi elle ne serait pas
 * rendue sur la page suivante pour quelqu'un qui l'avait déjà vue.
 *
 * `tour=on` et pas `tour=1` : les deux rouvrent la visite côté serveur, mais
 * `tour=1` est celui du lien « Revoir la visite guidée », qui doit repartir
 * du début. Distinguer les deux évite qu'un onglet qui traîne depuis une
 * visite précédente fasse reprendre au milieu quelqu'un qui a demandé à
 * tout revoir.
 *
 * @param {string} url
 * @returns {string}
 */
export function withTour (url) {
	const [ address, anchor ] = String(url).split('#');

	if (/[?&]tour=/.test(address)) {
		return url;
	}

	return address + (address.indexOf('?') === -1 ? '?' : '&') + 'tour=on' + (anchor ? '#' + anchor : '');
}

/**
 * A-t-on demandé à tout revoir depuis le début ?
 *
 * @param {string} search
 * @returns {boolean}
 */
export function restartAsked (search) {
	return /[?&]tour=1(&|$)/.test(String(search || ''));
}

/**
 * @param {number} index
 * @param {string} url
 * @returns {string}
 */
export function stateFor (index, url) {
	return JSON.stringify({ index, at: pathOf(url) });
}

/**
 * @param {string} raw
 * @returns {{index: number, at: string}|null}
 */
export function readState (raw) {
	try {
		const parsed = JSON.parse(raw);

		if (!parsed || typeof parsed.index !== 'number' || parsed.index < 0) {
			return null;
		}

		return { index: parsed.index, at: parsed.at || '' };
	} catch (error) {
		return null;
	}
}

/**
 * À quelle étape reprendre d'elle-même, ou `null` s'il vaut mieux se
 * contenter de proposer — parce qu'on n'est pas sur la page où la visite
 * s'était arrêtée.
 *
 * @param {Object|null} state
 * @param {Array} steps
 * @param {string} path
 * @returns {number|null}
 */
export function resumeAt (state, steps, path) {
	if (!state || !Array.isArray(steps) || steps.length === 0) {
		return null;
	}

	if (state.at && pathOf(state.at) !== pathOf(path)) {
		return null;
	}

	// Le nombre d'étapes dépend de la personne : une place gardée avant de
	// changer de compte peut tomber au-delà de la fin.
	return Math.min(Math.max(state.index, 0), steps.length - 1);
}

/**
 * Le trou dans le voile, autour de l'élément dont on parle.
 *
 * @param {Element|null} target
 * @param {number} padding
 * @returns {{top: number, left: number, width: number, height: number}|null}
 */
export function spotlightFor (target, padding = PADDING) {
	if (!target || typeof target.getBoundingClientRect !== 'function') {
		return null;
	}

	const box = target.getBoundingClientRect();

	if (box.width === 0 && box.height === 0) {
		return null;
	}

	return {
		top:    box.top - padding,
		left:   box.left - padding,
		width:  box.width + (2 * padding),
		height: box.height + (2 * padding),
	};
}

/**
 * Où poser la bulle : sous l'élément visé s'il y en a un et qu'il tient dans
 * la page, au-dessus sinon, au centre quand il n'y a rien à viser.
 *
 * @param {Element|null} target
 * @param {{width: number, height: number}} viewport
 * @param {{width: number, height: number}} bubble
 * @returns {{placement: string, top: number, left: number}}
 */
export function placementFor (target, viewport, bubble = BUBBLE) {
	if (!target || typeof target.getBoundingClientRect !== 'function') {
		return { placement: 'center', top: 0, left: 0 };
	}

	const box = target.getBoundingClientRect();

	if (box.width === 0 && box.height === 0) {
		// Un élément masqué — un menu replié, par exemple — ne se montre pas
		// du doigt.
		return { placement: 'center', top: 0, left: 0 };
	}

	// La bulle suit le bord gauche de l'élément, sans jamais sortir de la
	// page : un élément collé à droite ne pousse pas la bulle dehors.
	const left  = Math.min(
		Math.max(MARGIN, box.left),
		Math.max(MARGIN, viewport.width - bubble.width - MARGIN),
	);
	const below = box.bottom + MARGIN;

	if (below + bubble.height <= viewport.height) {
		return { placement: 'below', top: below, left };
	}

	const above = box.top - MARGIN - bubble.height;

	if (above >= MARGIN) {
		return { placement: 'above', top: above, left };
	}

	// Ni dessous ni dessus : l'élément prend toute la hauteur. On se pose en
	// bas, quitte à le recouvrir un peu — le halo dit toujours de quoi on
	// parle.
	return { placement: 'above', top: Math.max(MARGIN, viewport.height - bubble.height - MARGIN), left };
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
 * Le texte d'un bouton qui avance : le libellé de l'étape suivante quand
 * elle emmène ailleurs — on annonce où l'on va —, « Suivant » sinon.
 *
 * @param {Array} steps
 * @param {number} index
 * @param {string} path
 * @param {Object} labels
 * @returns {string}
 */
export function nextLabel (steps, index, path, labels = {}) {
	if (isLast(steps, index)) {
		return labels.end || 'Terminer';
	}

	const next = steps[index + 1];

	if (next && next.label && destinationFor(steps, index + 1, path)) {
		return next.label;
	}

	return labels.next || 'Suivant';
}

/**
 * Est-on en train d'écrire quelque part ? Les flèches lui reviennent alors.
 *
 * @param {Element|null} element
 * @returns {boolean}
 */
export function typing (element) {
	if (!element || !element.tagName) {
		return false;
	}

	if (element.isContentEditable) {
		return true;
	}

	return [ 'INPUT', 'TEXTAREA', 'SELECT' ].indexOf(element.tagName.toUpperCase()) !== -1;
}

/**
 * La place gardée entre deux pages. Un navigateur qui refuse le stockage —
 * navigation privée, réglages verrouillés — ne doit pas faire tomber la
 * visite : elle se contentera alors de repartir du début.
 */
const memory = {
	read () {
		try {
			return readState(window.sessionStorage.getItem(STORAGE_KEY));
		} catch (error) {
			return null;
		}
	},

	write (index, url) {
		try {
			window.sessionStorage.setItem(STORAGE_KEY, stateFor(index, url));
		} catch (error) {
			// Tant pis : la visite reprendra au début à la page suivante.
		}
	},

	forget () {
		try {
			window.sessionStorage.removeItem(STORAGE_KEY);
		} catch (error) {
			// Rien à oublier.
		}
	},
};

/**
 * Dire au serveur que la visite a été vue. Le résultat n'intéresse
 * personne : au pire elle se reproposera.
 *
 * @param {HTMLElement} root
 */
function markAsSeen (root) {
	const form = new FormData();
	form.append('_token', root.dataset.token || '');

	fetch(root.dataset.seenUrl, { method: 'POST', credentials: 'same-origin', body: form }).catch(() => {});
}

/**
 * @param {HTMLElement} root
 * @param {Array} steps
 * @param {Object} labels
 * @param {number} first
 */
function play (root, steps, labels, first) {
	let index    = Math.min(Math.max(first, 0), steps.length - 1);
	let outlined = null;
	let opened   = null;
	let ticking  = false;
	let settling = [];

	const spotlight = document.createElement('div');
	spotlight.className = 'tour-spotlight';

	const bubble = document.createElement('div');
	bubble.className = 'tour-bubble';
	bubble.setAttribute('role', 'dialog');
	bubble.setAttribute('aria-live', 'polite');
	bubble.setAttribute('aria-labelledby', 'tour-bubble-title');

	// Pas d'`aria-modal` : la page reste utilisable derrière la bulle, la
	// masquer aux lecteurs d'écran serait mentir sur ce qui se passe.

	const gauge = document.createElement('div');
	gauge.className = 'tour-bubble--gauge';

	const gaugeFill = document.createElement('span');
	gauge.appendChild(gaugeFill);
	bubble.appendChild(gauge);

	const progress = document.createElement('p');
	progress.className = 'tour-bubble--progress';
	bubble.appendChild(progress);

	const title = document.createElement('h2');
	title.className = 'tour-bubble--title';
	title.id = 'tour-bubble-title';
	bubble.appendChild(title);

	const body = document.createElement('p');
	body.className = 'tour-bubble--body';
	bubble.appendChild(body);

	const footer = document.createElement('div');
	footer.className = 'tour-bubble--footer';
	bubble.appendChild(footer);

	const skip = document.createElement('button');
	skip.type = 'button';
	skip.className = 'tour-bubble--skip';
	skip.textContent = labels.skip || 'Passer la visite';
	footer.appendChild(skip);

	const previous = document.createElement('button');
	previous.type = 'button';
	previous.className = 'tour-bubble--previous';
	previous.textContent = labels.previous || 'Précédent';
	footer.appendChild(previous);

	const next = document.createElement('button');
	next.type = 'button';
	next.className = 'tour-bubble--next';
	footer.appendChild(next);

	const dismiss = document.createElement('button');
	dismiss.type = 'button';
	dismiss.className = 'tour-bubble--close';
	dismiss.setAttribute('aria-label', labels.close || 'Fermer la visite');
	dismiss.textContent = '×';
	bubble.appendChild(dismiss);

	/**
	 * Refermer ce que l'étape avait déplié — le menu du compte, par exemple :
	 * la visite emprunte l'interface, elle ne la laisse pas en désordre.
	 */
	function restore () {
		if (outlined) {
			outlined.classList.remove('tour-target');
			outlined = null;
		}

		if (opened) {
			opened.click();
			opened = null;
		}
	}

	function close () {
		restore();
		memory.forget();
		settling.forEach(window.clearTimeout);
		settling = [];

		spotlight.remove();
		bubble.remove();

		document.removeEventListener('keydown', onKey);
		window.removeEventListener('scroll', onMove, true);
		window.removeEventListener('resize', onMove);

		markAsSeen(root);
	}

	function onKey (event) {
		if (event.key === 'Escape') {
			close();

			return;
		}

		// La page reste utilisable pendant la visite : les flèches
		// appartiennent à qui est en train de saisir quelque chose.
		if (typing(event.target)) {
			return;
		}

		if (event.key === 'ArrowRight') {
			go(1);
		} else if (event.key === 'ArrowLeft') {
			go(-1);
		}
	}

	/**
	 * Pendant un défilement, le halo colle à l'élément sans transition —
	 * sinon il traîne derrière lui.
	 */
	function onMove () {
		if (ticking) {
			return;
		}

		ticking = true;

		window.requestAnimationFrame(() => {
			ticking = false;
			position(false);
		});
	}

	function go (offset) {
		const wanted = move(steps, index, offset);

		if (wanted === index && offset > 0 && isLast(steps, index)) {
			close();

			return;
		}

		if (wanted === index) {
			return;
		}

		const elsewhere = destinationFor(steps, wanted, window.location.pathname);

		if (elsewhere) {
			// On change de page : la place est gardée, la visite reprendra
			// à l'arrivée. Le menu déplié, lui, n'a plus lieu d'être.
			restore();
			memory.write(wanted, elsewhere);
			bubble.classList.add('tour-bubble__leaving');

			window.location.assign(withTour(elsewhere));

			return;
		}

		index = wanted;
		memory.write(index, window.location.pathname);
		draw();
	}

	/**
	 * Déplier ce qu'il faut pour que la cible soit visible, et retenir qu'on
	 * l'a fait.
	 *
	 * @param {Object} step
	 */
	function unfold (step) {
		if (!step.open) {
			return;
		}

		const toggle = document.querySelector(step.open);
		const target = targetOf(step);

		if (!toggle) {
			return;
		}

		if (target && target.getBoundingClientRect().height > 0) {
			// Déjà ouvert : ce n'est pas à la visite de le refermer.
			return;
		}

		toggle.click();
		opened = toggle;
	}

	/**
	 * @param {boolean} animated
	 */
	function position (animated) {
		const target = targetOf(steps[index]);
		const halo   = spotlightFor(target);

		spotlight.classList.toggle('tour-spotlight__still', !animated);
		bubble.classList.toggle('tour-bubble__still', !animated);

		// Sans cible, le halo se referme au centre : son ombre, qui déborde
		// de tous les côtés, devient alors le voile de la page entière.
		spotlight.classList.toggle('tour-spotlight__closed', !halo);

		const box = halo || {
			top:    window.innerHeight / 2,
			left:   window.innerWidth / 2,
			width:  0,
			height: 0,
		};

		spotlight.style.top = box.top + 'px';
		spotlight.style.left = box.left + 'px';
		spotlight.style.width = box.width + 'px';
		spotlight.style.height = box.height + 'px';

		const where = placementFor(
			target,
			{ width: window.innerWidth, height: window.innerHeight },
			{ width: bubble.offsetWidth || BUBBLE.width, height: bubble.offsetHeight || BUBBLE.height },
		);

		bubble.classList.toggle('tour-bubble__center', where.placement === 'center');
		bubble.setAttribute('data-placement', where.placement);

		if (where.placement === 'center') {
			bubble.style.top = '';
			bubble.style.left = '';
		} else {
			bubble.style.top = where.top + 'px';
			bubble.style.left = where.left + 'px';
		}
	}

	/**
	 * Ce qu'on vise met parfois un instant à prendre sa taille : le menu du
	 * compte s'ouvre en une demi-seconde, une carte se dessine après coup.
	 * On repose donc le halo un peu plus tard, deux ou trois fois, plutôt que
	 * de mesurer une fois pour toutes un élément qui n'avait pas fini de
	 * s'ouvrir.
	 */
	function settle () {
		settling.forEach(window.clearTimeout);

		settling = [ 120, 320, 620 ].map(delay => window.setTimeout(() => position(true), delay));
	}

	function draw () {
		const step = steps[index];

		restore();
		unfold(step);

		const target = targetOf(step);

		if (target) {
			target.classList.add('tour-target');
			outlined = target;

			if (typeof target.scrollIntoView === 'function') {
				target.scrollIntoView({ block: 'center', behavior: 'smooth' });
			}
		}

		title.textContent = step.title;
		body.textContent = step.body;

		progress.textContent = (labels.progress || '%d / %d')
			.replace('%d', index + 1)
			.replace('%d', steps.length);

		gaugeFill.style.width = (((index + 1) / steps.length) * 100) + '%';

		previous.hidden = index === 0;
		next.textContent = nextLabel(steps, index, window.location.pathname, labels);
		next.classList.toggle('tour-bubble--next__travels', Boolean(
			!isLast(steps, index) && destinationFor(steps, index + 1, window.location.pathname),
		));

		position(true);
		settle();

		try {
			next.focus({ preventScroll: true });
		} catch (error) {
			next.focus();
		}
	}

	previous.addEventListener('click', () => go(-1));
	next.addEventListener('click', () => go(1));
	skip.addEventListener('click', close);
	dismiss.addEventListener('click', close);

	document.addEventListener('keydown', onKey);
	window.addEventListener('scroll', onMove, true);
	window.addEventListener('resize', onMove);

	document.body.appendChild(spotlight);
	document.body.appendChild(bubble);

	memory.write(index, window.location.pathname);
	draw();
}

/**
 * La proposition de reprendre, quand on n'est pas là où la visite s'était
 * arrêtée. Discrète, dans un coin, et refusable.
 *
 * @param {HTMLElement} root
 * @param {Array} steps
 * @param {Object} labels
 * @param {number} at
 */
function offerToResume (root, steps, labels, at) {
	const pill = document.createElement('div');
	pill.className = 'tour-resume';

	const resume = document.createElement('button');
	resume.type = 'button';
	resume.className = 'tour-resume--go';
	resume.textContent = (labels.resume || 'Reprendre la visite') + ' (' + (at + 1) + '/' + steps.length + ')';

	const dismiss = document.createElement('button');
	dismiss.type = 'button';
	dismiss.className = 'tour-resume--close';
	dismiss.setAttribute('aria-label', labels.close || 'Fermer la visite');
	dismiss.textContent = '×';

	resume.addEventListener('click', () => {
		pill.remove();

		const elsewhere = destinationFor(steps, at, window.location.pathname);

		if (elsewhere) {
			memory.write(at, elsewhere);
			window.location.assign(withTour(elsewhere));

			return;
		}

		play(root, steps, labels, at);
	});

	dismiss.addEventListener('click', () => {
		pill.remove();
		memory.forget();
		markAsSeen(root);
	});

	pill.appendChild(resume);
	pill.appendChild(dismiss);

	document.body.appendChild(pill);
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

	if (restartAsked(window.location.search)) {
		// « Revoir la visite guidée » : du début, quoi qu'il y ait de gardé.
		memory.forget();
		play(root, steps, labels, 0);

		return;
	}

	const state = memory.read();

	if (!state) {
		play(root, steps, labels, 0);

		return;
	}

	const here = resumeAt(state, steps, window.location.pathname);

	if (here === null) {
		// La visite s'était arrêtée ailleurs : on propose, on n'impose pas.
		offerToResume(root, steps, labels, Math.min(Math.max(state.index, 0), steps.length - 1));

		return;
	}

	play(root, steps, labels, here);
}

document.addEventListener('DOMContentLoaded', () => {
	const root = document.getElementById('guided-tour');

	if (!root) {
		return;
	}

	// Après les autres : la visite se sert de l'interface — elle déplie le
	// menu du compte — et ceux qui l'animent s'installent au même moment.
	window.setTimeout(() => start(root), 0);
});
