import domready from 'mf-js/modules/dom/ready';

/**
 * Proposer ce qu'on peut taguer quand on tape « @ » ou « # » dans un message
 * privé.
 *
 * Ce que la liste insère est du texte, et rien d'autre : « @Prénom Nom »,
 * « #Titre du document ». Le serveur reconnaît le tag en rapprochant ce texte
 * de ce qui porte ce nom, si bien qu'un message reste lisible tel quel et
 * qu'un copier-coller n'en perd rien.
 *
 * Le champ est un textarea et non un éditeur riche : le confort ajouté ici est
 * du confort, pas une condition. Sans JavaScript, on écrit le tag à la main et
 * il est reconnu pareil.
 */

// Ce qui peut précéder un préfixe qui ouvre un tag : un début de ligne ou une
// séparation. Sans quoi chaque adresse e-mail écrite dans un message ouvrirait
// la liste. Le « # » n'ouvre rien après « / » ni « & », pour la même raison :
// l'ancre d'une adresse collée n'est pas un tag.
const QUERIES = [
	{ prefix: '@', pattern: /(?:^|[\s(])@([^\s@#]*(?:[^\S\n][^\s@#]*){0,3})$/ },
	{ prefix: '#', pattern: /(?:^|[\s(])#([^\s@#]*(?:[^\S\n][^\s@#]*){0,7})$/ },
];

// Le temps qu'on laisse à quelqu'un de finir son mot avant d'interroger le
// serveur. Une requête par frappe ferait payer l'annuaire du réseau à chaque
// lettre.
const DEBOUNCE = 220;

/**
 * @param {HTMLTextAreaElement} field
 */
function attach (field) {
	const url = field.dataset.tags;

	if (!url) {
		return;
	}

	const list = document.createElement('ul');
	list.className = 'mentions-suggestions';
	list.setAttribute('role', 'listbox');
	list.hidden = true;

	// Ancrée sur le conteneur du champ : c'est lui qui sert de repère aux
	// coordonnées, et il est déjà positionné en CSS.
	const holder = field.parentNode;
	holder.appendChild(list);

	let matches = [];
	let active = 0;
	let anchor = null;
	let timer = null;
	let request = 0;

	function close () {
		list.hidden = true;
		list.innerHTML = '';
		matches = [];
		anchor = null;
	}

	const isOpen = () => !list.hidden;

	function draw () {
		list.innerHTML = '';

		matches.forEach((match, index) => {
			const item = document.createElement('li');
			item.className = 'mentions-suggestions--item' + (index === active ? ' is-active' : '');
			item.setAttribute('role', 'option');
			item.setAttribute('aria-selected', index === active ? 'true' : 'false');
			item.dataset.kind = match.kind || '';
			item.textContent = match.label;

			if (match.hint) {
				const hint = document.createElement('span');
				hint.className = 'mentions-suggestions--hint';
				hint.textContent = match.hint;
				item.appendChild(hint);
			}

			// mousedown plutôt que click : le clic ferait d'abord perdre le
			// curseur au champ, et on ne saurait plus où insérer.
			item.addEventListener('mousedown', (event) => {
				event.preventDefault();
				choose(index);
			});

			list.appendChild(item);
		});

		list.hidden = false;
	}

	function choose (index) {
		const match = matches[index];

		if (!match || !anchor) {
			return;
		}

		const at = anchor;
		const inserted = at.prefix + match.label + ' ';
		const value = field.value;

		close();

		field.value = value.slice(0, at.start) + inserted + value.slice(at.end);

		const caret = at.start + inserted.length;
		field.setSelectionRange(caret, caret);
		field.focus();
	}

	/**
	 * Ce qui est en train d'être tapé, juste avant le curseur.
	 *
	 * @returns {{prefix: string, query: string, start: number, end: number}|null}
	 */
	function reading () {
		const end = field.selectionStart;

		if (end !== field.selectionEnd) {
			return null;
		}

		const before = field.value.slice(0, end);

		for (let i = 0; i < QUERIES.length; i += 1) {
			const found = QUERIES[i].pattern.exec(before);

			if (found) {
				return {
					prefix: QUERIES[i].prefix,
					query: found[1],
					start: end - found[1].length - 1,
					end,
				};
			}
		}

		return null;
	}

	function refresh () {
		const read = reading();

		if (!read || read.query.length < 1) {
			close();

			return;
		}

		anchor = read;

		const ticket = (request += 1);
		const address = url
			+ (url.indexOf('?') === -1 ? '?' : '&')
			+ 'prefix=' + encodeURIComponent(read.prefix)
			+ '&q=' + encodeURIComponent(read.query);

		fetch(address, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			.then((response) => (response.ok ? response.json() : { suggestions: [] }))
			.catch(() => ({ suggestions: [] }))
			.then((data) => {
				// Une réponse en retard ne doit pas écraser une plus récente :
				// on tape plus vite que le réseau ne répond.
				if (ticket !== request || !anchor) {
					return;
				}

				matches = Array.isArray(data.suggestions) ? data.suggestions : [];

				if (matches.length === 0) {
					close();

					return;
				}

				active = 0;
				draw();
			});
	}

	function schedule () {
		window.clearTimeout(timer);
		timer = window.setTimeout(refresh, DEBOUNCE);
	}

	field.addEventListener('input', schedule);
	field.addEventListener('click', schedule);

	field.addEventListener('keydown', (event) => {
		if (!isOpen()) {
			return;
		}

		if (event.key === 'Escape') {
			event.preventDefault();
			close();

			return;
		}

		if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
			event.preventDefault();

			active = (active + (event.key === 'ArrowDown' ? 1 : matches.length - 1)) % matches.length;
			draw();

			return;
		}

		// Entrée choisit dans la liste ; sans liste ouverte, elle fait ce
		// qu'elle fait toujours dans un textarea : un retour à la ligne.
		if (event.key === 'Enter' || event.key === 'Tab') {
			event.preventDefault();
			choose(active);
		}
	});

	field.addEventListener('blur', () => {
		// Laisse le temps au mousedown d'un item d'être traité.
		window.setTimeout(close, 150);
	});
}

domready(() => {
	Array.from(document.querySelectorAll('textarea[data-tags]')).forEach(attach);
});

export { QUERIES, attach };
