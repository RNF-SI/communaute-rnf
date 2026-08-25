import domready from 'mf-js/modules/dom/ready';

/**
 * Le bouton « Insérer un lien » : choisir au clic ce qu'on aurait tapé.
 *
 * Il n'ouvre rien de nouveau. Ce qu'il écrit dans le champ, c'est un tag —
 * exactement le texte qu'on aurait frappé —, et c'est le serveur qui le
 * donne : la réponse porte un `insert` par contenu, « #Titre » ou
 * « #"Titre : à rallonge" » selon que le titre se relit nu ou non. La
 * grammaire d'un tag est connue d'un seul endroit, celui qui la relira ; la
 * recomposer ici la ferait diverger le jour où elle changera.
 *
 * Le panneau ne montre que ce que celui qui écrit peut lui-même ouvrir —
 * c'est le serveur qui filtre, pas cette page.
 *
 * Tout le libellé vient du gabarit. Ce fichier ne remplit que la liste, et
 * lit les deux phrases d'état sur des attributs, parce qu'elles n'ont pas de
 * place dans le HTML tant qu'il n'y a rien à dire.
 */

// Le temps qu'on laisse à quelqu'un de finir son mot avant d'interroger le
// serveur, comme pour la liste des tags.
const DEBOUNCE = 220;

/**
 * @param {HTMLElement} panel
 */
function attach (panel) {
	const url   = panel.dataset.picker;
	const field = document.getElementById(panel.dataset.pickerFor);
	const tools = panel.parentNode;
	const open  = tools.querySelector('.tag-picker--open');

	if (!url || !field || !open) {
		return;
	}

	const search = panel.querySelector('.tag-picker--search');
	const kind   = panel.querySelector('.tag-picker--kind');
	const list   = panel.querySelector('.tag-picker--list');
	const state  = panel.querySelector('.tag-picker--state');

	let matches = [];
	let active  = -1;
	let timer   = null;
	let request = 0;

	// Le bouton n'existe que si l'on est là pour le faire marcher.
	tools.hidden = false;

	function say (message) {
		state.textContent = message || '';
		state.hidden      = !message;
	}

	function draw () {
		list.innerHTML = '';

		matches.forEach((match, index) => {
			const item = document.createElement('li');
			item.className = 'tag-picker--item' + (index === active ? ' is-active' : '');
			item.setAttribute('role', 'option');
			item.setAttribute('aria-selected', index === active ? 'true' : 'false');
			item.dataset.kind = match.kind || '';

			const label = document.createElement('span');
			label.className = 'tag-picker--label';
			label.textContent = match.label;
			item.appendChild(label);

			if (match.hint) {
				const hint = document.createElement('span');
				hint.className = 'tag-picker--hint-item';
				hint.textContent = match.hint;
				item.appendChild(hint);
			}

			// mousedown plutôt que click : le clic ferait d'abord perdre le
			// curseur au champ de recherche, et le panneau se refermerait
			// avant d'avoir rien inséré.
			item.addEventListener('mousedown', (event) => {
				event.preventDefault();
				choose(index);
			});

			list.appendChild(item);
		});
	}

	/**
	 * Écrit le tag à l'endroit du curseur.
	 *
	 * Deux espaces peuvent s'ajouter, et ce ne sont pas des détails : un tag
	 * collé au mot d'avant n'en est pas un — « voir#Titre » ne se lit pas —,
	 * et sans celui d'après, le mot suivant serait avalé par le titre.
	 *
	 * @param {number} index
	 */
	function choose (index) {
		const match = matches[index];

		if (!match || !match.insert) {
			return;
		}

		const value  = field.value;
		const start  = field.selectionStart;
		const end    = field.selectionEnd;
		const before = value.slice(0, start);
		const after  = value.slice(end);

		const written = (before === '' || /[\s(]$/.test(before) ? '' : ' ')
			+ match.insert
			+ (/^\s/.test(after) ? '' : ' ');

		close();

		field.value = before + written + after;

		const caret = start + written.length;
		field.setSelectionRange(caret, caret);
		field.focus();
	}

	function refresh () {
		const ticket = (request += 1);
		const query  = search.value.trim();
		const wanted = kind.value;

		say(state.dataset.loading);

		const address = url
			+ (url.indexOf('?') === -1 ? '?' : '&')
			+ 'q=' + encodeURIComponent(query)
			+ '&kind=' + encodeURIComponent(wanted);

		fetch(address, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			.then((response) => (response.ok ? response.json() : null))
			.catch(() => null)
			.then((data) => {
				// Une réponse en retard ne doit pas écraser une plus récente.
				if (ticket !== request || panel.hidden) {
					return;
				}

				if (data === null) {
					matches = [];
					draw();
					say(state.dataset.failed);

					return;
				}

				matches = Array.isArray(data.suggestions) ? data.suggestions : [];
				active  = matches.length ? 0 : -1;

				draw();
				say(matches.length ? '' : state.dataset.empty);
			});
	}

	function schedule () {
		window.clearTimeout(timer);
		timer = window.setTimeout(refresh, DEBOUNCE);
	}

	function show () {
		panel.hidden = false;
		open.setAttribute('aria-expanded', 'true');

		search.focus();
		search.select();
		refresh();
	}

	function close () {
		window.clearTimeout(timer);

		panel.hidden = true;
		open.setAttribute('aria-expanded', 'false');

		matches = [];
		active  = -1;

		list.innerHTML = '';
		say('');
	}

	function move (step) {
		if (!matches.length) {
			return;
		}

		active = (active + step + matches.length) % matches.length;
		draw();

		const item = list.children[active];

		if (item && item.scrollIntoView) {
			item.scrollIntoView({ block: 'nearest' });
		}
	}

	open.addEventListener('click', () => {
		if (panel.hidden) {
			show();
		}
		else {
			close();
			field.focus();
		}
	});

	panel.querySelector('.tag-picker--close').addEventListener('click', () => {
		close();
		field.focus();
	});

	search.addEventListener('input', schedule);
	kind.addEventListener('change', refresh);

	panel.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') {
			event.preventDefault();
			close();
			field.focus();

			return;
		}

		if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
			event.preventDefault();
			move(event.key === 'ArrowDown' ? 1 : -1);

			return;
		}

		// Entrée choisit ce qui est en surbrillance. Dans un panneau ouvert
		// depuis un formulaire, la laisser passer l'enverrait.
		if (event.key === 'Enter') {
			event.preventDefault();
			choose(active);
		}
	});

	// Cliquer ailleurs referme, sans rendre le curseur au champ : on est parti
	// faire autre chose.
	document.addEventListener('mousedown', (event) => {
		if (!panel.hidden && !panel.contains(event.target) && (event.target !== open)) {
			close();
		}
	});
}

domready(() => {
	Array.from(document.querySelectorAll('.tag-picker[data-picker]')).forEach(attach);
});

export { attach };
