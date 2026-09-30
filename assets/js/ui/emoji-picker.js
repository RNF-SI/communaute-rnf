import domready from 'mf-js/modules/dom/ready';

/**
 * Le bouton emoji d'un message. (#42)
 *
 * Même principe que « Insérer un lien » : il écrit dans le champ, à l'endroit
 * du curseur, et rien d'autre — un emoji est du texte, le message reste du
 * texte. La liste est dans le gabarit ; ce fichier ne fait que l'ouvrir, la
 * fermer, et insérer.
 */

/**
 * Insère `text` à la place de la sélection, comme l'aurait fait la frappe.
 *
 * @param {HTMLTextAreaElement} field
 * @param {string} text
 */
export function insertAtCaret (field, text) {
	const start = (typeof field.selectionStart === 'number') ? field.selectionStart : field.value.length;
	const end   = (typeof field.selectionEnd === 'number') ? field.selectionEnd : start;

	field.value = field.value.slice(0, start) + text + field.value.slice(end);

	const caret = start + text.length;
	field.setSelectionRange(caret, caret);

	// Ceux qui écoutent la frappe — le champ du dock qui grandit, la liste
	// des tags — doivent l'apprendre aussi.
	field.dispatchEvent(new Event('input', { bubbles: true }));
}

/**
 * @param {HTMLElement} panel
 */
export function attach (panel) {
	const field = document.getElementById(panel.dataset.emojiFor);
	const tools = panel.parentNode;
	const open  = tools ? tools.querySelector('.emoji-picker--open') : null;

	if (!field || !open || panel.dataset.emojiReady) {
		return;
	}

	panel.dataset.emojiReady = '1';
	tools.hidden = false;

	function close () {
		panel.hidden = true;
		open.setAttribute('aria-expanded', 'false');
	}

	function show () {
		panel.hidden = false;
		open.setAttribute('aria-expanded', 'true');

		const first = panel.querySelector('.emoji-picker--item');

		if (first) {
			first.focus();
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

	panel.addEventListener('click', (event) => {
		const item = event.target.closest('.emoji-picker--item');

		if (!item) {
			return;
		}

		close();
		field.focus();
		insertAtCaret(field, item.dataset.emoji);
	});

	panel.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') {
			event.preventDefault();
			close();
			field.focus();
		}
	});

	document.addEventListener('click', (event) => {
		if (!panel.hidden && !panel.contains(event.target) && !open.contains(event.target)) {
			close();
		}
	});
}

domready(() => {
	Array.from(document.querySelectorAll('.emoji-picker[data-emoji-for]')).forEach(attach);
});
