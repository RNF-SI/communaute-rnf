/**
 * Le séparateur : une ligne horizontale entre deux parties d'une page. (#42)
 *
 * Quill 1.x n'en connaît pas. On lui apprend un bloc `<hr>` — un « embed »,
 * c'est-à-dire un élément sans texte, qui occupe une ligne à lui seul — et le
 * bouton qui l'insère. Le HTML enregistré porte un `<hr>` ordinaire : la page
 * publiée n'a besoin de rien d'autre que d'une règle de style.
 */

export const DIVIDER = 'divider';

// Le trait, dans le dessin des icônes de Quill (18 × 18, classe ql-stroke).
const ICON = '<svg viewBox="0 0 18 18"><line class="ql-stroke" x1="3" x2="15" y1="9" y2="9"></line></svg>';

/**
 * @param {typeof import('quill').default} Quill
 */
export function registerDivider (Quill) {
	const BlockEmbed = Quill.import('blots/block/embed');

	class DividerBlot extends BlockEmbed {}

	DividerBlot.blotName = DIVIDER;
	DividerBlot.tagName  = 'hr';

	Quill.register(DividerBlot, true);
	Quill.import('ui/icons')[ DIVIDER ] = ICON;
}

/**
 * Insère le séparateur à l'endroit du curseur, et place le curseur après lui
 * — sans quoi la frappe suivante irait avant le trait.
 *
 * @param {import('quill').default} quill
 */
export function insertDivider (quill) {
	const range = quill.getSelection(true);
	const index = range ? range.index : quill.getLength();

	// Le schéma du guide de Quill : on coupe la ligne d'abord, le trait
	// prend alors une ligne à lui, et le curseur repart sur la suivante.
	// Insérer le bloc au milieu d'une ligne décalerait les index d'une unité.
	quill.insertText(index, '\n', 'user');
	quill.insertEmbed(index + 1, DIVIDER, true, 'user');
	quill.setSelection(index + 2, 0, 'silent');
}

/**
 * Le bouton n'a pas de texte : son nom vient du gabarit, dans la langue de
 * la page (`data-wysiwyg-divider` sur le body).
 *
 * @param {HTMLElement} toolbar
 * @param {string}      label
 */
export function labelDividerButton (toolbar, label) {
	const button = toolbar ? toolbar.querySelector('button.ql-' + DIVIDER) : null;

	if (button && label) {
		button.setAttribute('title', label);
		button.setAttribute('aria-label', label);
	}
}
