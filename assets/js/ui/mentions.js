/**
 * Proposer les membres du groupe quand on tape « @ » dans un message. (#37)
 *
 * Ce que la liste insère est du texte, « @Prénom Nom », et rien d'autre : le
 * serveur reconnaît la mention en rapprochant ce texte des membres du groupe,
 * si bien qu'un message reste lisible tel quel dans un e-mail et qu'un
 * copier-coller ne perd rien.
 */

const MAX_SUGGESTIONS = 8;

// Ce qui peut précéder un « @ » qui ouvre une mention : rien d'autre qu'un
// début de ligne ou une séparation. Sans quoi chaque adresse e-mail écrite
// dans un message ouvrirait la liste.
const QUERY = /(?:^|[\s(])@([^\s@]*(?:[^\S\n][^\s@]*){0,3})$/;

/**
 * Ni la casse ni les accents ne distinguent deux noms.
 *
 * @param {string} value
 * @returns {string}
 */
function fold (value) {
	return (value || '')
		.normalize('NFD')
		.replace(/[\u0300-\u036F]/g, '')
		.toLowerCase()
		.trim();
}

/**
 * Les membres dont le nom commence, ou dont un des mots commence, par ce qui
 * a été tapé. Chercher au milieu d'un mot ramènerait trop de monde.
 *
 * @param {Array} members
 * @param {string} query
 * @returns {Array}
 */
function matching (members, query) {
	const needle = fold(query);

	if (needle === '') {
		return members.slice(0, MAX_SUGGESTIONS);
	}

	return members
		.filter((member) => {
			const name = fold(member.name);

			return name.startsWith(needle) || name.split(/[\s'-]+/).some((word) => word.startsWith(needle));
		})
		.slice(0, MAX_SUGGESTIONS);
}

/**
 * @param {Object} quill
 * @param {HTMLElement} container l'élément .wysiwyg-editor
 */
export default function attachMentions (quill, container) {
	const url = container.dataset.mentions;

	if (!url) {
		return;
	}

	const list = document.createElement('ul');
	list.className = 'mentions-suggestions';
	list.setAttribute('role', 'listbox');
	list.hidden = true;
	// Ancré sur le conteneur de l'éditeur : c'est le repère dont Quill donne
	// les coordonnées.
	quill.container.appendChild(list);

	let members = null;
	let loading = false;
	let matches = [];
	let active = 0;
	let anchor = null;

	function close () {
		list.hidden = true;
		list.innerHTML = '';
		matches = [];
		anchor = null;
	}

	const isOpen = () => !list.hidden;

	function load () {
		if (members !== null || loading) {
			return Promise.resolve();
		}

		loading = true;

		return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			.then((response) => (response.ok ? response.json() : []))
			.catch(() => [])
			.then((data) => {
				members = Array.isArray(data) ? data : [];
				loading = false;
			});
	}

	function draw () {
		list.innerHTML = '';

		matches.forEach((member, index) => {
			const item = document.createElement('li');
			item.className = 'mentions-suggestions--item' + (index === active ? ' is-active' : '');
			item.setAttribute('role', 'option');
			item.setAttribute('aria-selected', index === active ? 'true' : 'false');
			item.textContent = member.name;

			// mousedown plutôt que click : le clic ferait d'abord perdre le
			// curseur à l'éditeur, et on ne saurait plus où insérer.
			item.addEventListener('mousedown', (event) => {
				event.preventDefault();
				choose(index);
			});

			list.appendChild(item);
		});

		const bounds = quill.getBounds(anchor.index);
		list.style.left = bounds.left + 'px';
		list.style.top = (bounds.top + bounds.height) + 'px';
		list.hidden = false;
	}

	function choose (index) {
		const member = matches[index];

		if (!member || !anchor) {
			return;
		}

		const at = anchor;

		close();

		quill.deleteText(at.index, at.length, 'user');
		quill.insertText(at.index, '@' + member.name + ' ', 'user');
		quill.setSelection(at.index + member.name.length + 2, 0, 'user');
	}

	function refresh () {
		const selection = quill.getSelection();

		if (!selection || selection.length > 0) {
			close();

			return;
		}

		const before = quill.getText(0, selection.index);
		const found = QUERY.exec(before);

		if (!found) {
			close();

			return;
		}

		// La position et la longueur de ce qui sera remplacé : le « @ » et ce
		// qui a été tapé derrière.
		anchor = { index: selection.index - found[1].length - 1, length: found[1].length + 1 };

		load().then(() => {
			if (!anchor) {
				return;
			}

			matches = matching(members || [], found[1]);

			if (matches.length === 0) {
				close();

				return;
			}

			active = 0;
			draw();
		});
	}

	quill.on('editor-change', (event) => {
		if (event === 'text-change' || event === 'selection-change') {
			refresh();
		}
	});

	// En capture, avant que Quill ne traite la touche : tant que la liste est
	// ouverte, les flèches et Entrée lui appartiennent.
	quill.root.addEventListener('keydown', (event) => {
		if (!isOpen()) {
			return;
		}

		if (event.key === 'Escape') {
			event.preventDefault();
			event.stopPropagation();
			close();

			return;
		}

		if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
			event.preventDefault();
			event.stopPropagation();

			active = (active + (event.key === 'ArrowDown' ? 1 : matches.length - 1)) % matches.length;
			draw();

			return;
		}

		if (event.key === 'Enter' || event.key === 'Tab') {
			event.preventDefault();
			event.stopPropagation();
			choose(active);
		}
	}, true);

	quill.root.addEventListener('blur', () => {
		// Laisse le temps au mousedown d'un item d'être traité.
		window.setTimeout(close, 150);
	});
}

export { matching, fold, QUERY };
