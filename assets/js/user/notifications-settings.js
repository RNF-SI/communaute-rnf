/**
 * Les réglages de notifications, quand on siège dans beaucoup de groupes.
 *
 * La page tient déjà debout sans JavaScript : le réglage général en haut,
 * chaque groupe replié en dessous avec, dans ses listes, « Comme le réglage
 * général » en premier choix. Ce fichier n'ajoute que ce qui se voit :
 *
 * - un champ pour retrouver un groupe dans une liste qui en compte trente ;
 * - le résumé de chaque groupe recalculé pendant qu'on choisit, plutôt qu'au
 *   rechargement — sans quoi le repli, qui est ce qui rend la page lisible,
 *   masquerait ce qu'on vient de faire ;
 * - un bouton par groupe pour tout y remettre au réglage général d'un coup,
 *   ce que les quatre listes savent faire mais en quatre gestes.
 *
 * Rien ici n'est nécessaire pour enregistrer quoi que ce soit.
 */

import domready from 'mf-js/modules/dom/ready';

/**
 * À partir de combien de groupes le champ de recherche a un intérêt. En
 * dessous, il encombre plus qu'il n'aide.
 */
const WORTH_FILTERING = 6;

/**
 * @param {string} text
 * @returns {string}
 */
export function normalise (text) {
	const lowered = String(text === null || text === undefined ? '' : text).toLowerCase();

	// « Réserve » doit se trouver en tapant « reserve ».
	return typeof lowered.normalize === 'function'
		? lowered.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
		: lowered;
}

/**
 * Tous les mots tapés doivent être là : « commission montagne » trouve la
 * « Commission Montagne » comme le « Groupe de travail montagne, commission
 * milieux ».
 *
 * @param {string} name
 * @param {string} query
 * @returns {boolean}
 */
export function matches (name, query) {
	const haystack = normalise(name);

	return normalise(query)
		.split(/\s+/)
		.filter(term => term.length > 0)
		.every(term => haystack.indexOf(term) !== -1);
}

/**
 * Ce que le résumé d'un groupe annonce : ce en quoi il s'écarte du réglage
 * général, ou qu'il le suit.
 *
 * La même phrase que celle rendue par le gabarit — si l'une change, l'autre
 * doit suivre, sinon le résumé saute d'une formulation à l'autre au premier
 * clic.
 *
 * @param {Array} choices [{ label: 'Discussions', level: 'Aucune notification' }]
 * @param {Object} labels
 * @returns {string}
 */
export function stateOf (choices, labels = {}) {
	const apart = choices
		.filter(choice => choice.level)
		.map(choice => choice.label + ' : ' + choice.level.toLowerCase());

	return apart.length === 0 ? (labels.follows || '') : apart.join(' · ');
}

/**
 * @param {string} template
 * @param {number} shown
 * @param {number} total
 * @returns {string}
 */
export function countLabel (template, shown, total) {
	return String(template || '%shown% / %total%')
		.replace('%shown%', shown)
		.replace('%total%', total);
}

/**
 * Ce que disent les listes d'un groupe, dans l'ordre où elles sont posées.
 *
 * @param {HTMLElement} group
 * @returns {Array}
 */
function choicesOf (group) {
	return Array.from(group.querySelectorAll('select')).map((select) => {
		const label  = group.querySelector('label[for="' + select.id + '"]');
		const option = select.options[select.selectedIndex];

		return {
			label: label ? label.textContent.trim() : '',
			level: select.value ? (option ? option.textContent.trim() : select.value) : '',
		};
	});
}

/**
 * @param {HTMLElement} group
 * @param {Object} labels
 */
function refresh (group, labels) {
	const state = group.querySelector('.notifications-settings--group-state');

	if (!state) {
		return;
	}

	const choices = choicesOf(group);
	const apart   = choices.some(choice => choice.level);

	state.textContent = stateOf(choices, labels);
	state.classList.toggle('notifications-settings--group-state__apart', apart);
}

/**
 * Le bouton qui remet un groupe entier sous le réglage général.
 *
 * @param {HTMLElement} group
 * @param {Object} labels
 */
function addFollowButton (group, labels) {
	const button = document.createElement('button');
	button.type = 'button';
	button.className = 'notifications-settings--follow';
	button.textContent = labels.follow || '';

	button.addEventListener('click', () => {
		Array.from(group.querySelectorAll('select')).forEach((select) => {
			select.value = '';
		});

		refresh(group, labels);
	});

	group.appendChild(button);
}

/**
 * @param {HTMLElement} list
 * @param {Array} groups
 * @param {Object} labels
 */
function addFilter (list, groups, labels) {
	const bar = document.createElement('div');
	bar.className = 'notifications-settings--filter';

	const field = document.createElement('input');
	field.type = 'search';
	field.className = 'notifications-settings--filter-field';
	field.setAttribute('aria-label', labels.filter || '');
	field.placeholder = labels.filter || '';

	const count = document.createElement('p');
	count.className = 'notifications-settings--count';
	count.textContent = countLabel(labels.count, groups.length, groups.length);

	const empty = document.createElement('p');
	empty.className = 'notifications-settings--empty';
	empty.textContent = labels.empty || '';
	empty.hidden = true;

	// Entrée dans un champ de recherche enregistrerait le formulaire : ce
	// n'est pas ce qu'on demande en filtrant une liste.
	field.addEventListener('keydown', (event) => {
		if (event.key === 'Enter') {
			event.preventDefault();
		}
	});

	field.addEventListener('input', () => {
		let shown = 0;

		groups.forEach((group) => {
			const seen = matches(group.dataset.name || '', field.value);

			group.hidden = !seen;

			if (seen) {
				shown += 1;
			}
		});

		count.textContent = countLabel(labels.count, shown, groups.length);
		empty.hidden = shown > 0;
	});

	bar.appendChild(field);
	bar.appendChild(count);

	list.parentNode.insertBefore(bar, list);
	list.appendChild(empty);
}

domready(() => {
	const list = document.querySelector('.notifications-settings--list');

	if (!list) {
		return;
	}

	const labels = {
		filter:  list.dataset.filterLabel,
		count:   list.dataset.countLabel,
		follow:  list.dataset.followLabel,
		follows: list.dataset.followsLabel,
		empty:   list.dataset.emptyLabel,
	};

	const groups = Array.from(list.querySelectorAll('.notifications-settings--group'));

	groups.forEach((group) => {
		addFollowButton(group, labels);

		Array.from(group.querySelectorAll('select')).forEach((select) => {
			select.addEventListener('change', () => refresh(group, labels));
		});
	});

	if (groups.length >= WORTH_FILTERING) {
		addFilter(list, groups, labels);
	}
});
