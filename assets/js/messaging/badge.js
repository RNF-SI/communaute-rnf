import live from './live';

/**
 * Les nombres de l'en-tête, tenus à jour sans recharger la page : la pastille
 * sur l'avatar, et le détail ligne par ligne dans le menu qu'elle déroule.
 *
 * Le serveur reste seul juge de ces nombres. Ce fichier n'en calcule aucun —
 * il ne fait que recopier ce que `/messages/live` renvoie. Additionner ou
 * décrémenter ici, « pour que ça réagisse tout de suite », donnerait deux
 * façons de compter qui divergeraient d'une unité au premier cas de bord, et
 * c'est précisément l'écart qu'un lecteur remarque.
 *
 * Chaque endroit qui affiche un de ces nombres porte `data-unread` avec le
 * nom du compteur. Le gabarit rend l'élément même quand le compteur vaut
 * zéro, simplement caché : sans cela, il n'y aurait rien à remplir quand le
 * premier message arrive.
 */

// Ce qu'on écrit plutôt qu'un nombre à trois chiffres, qui ferait déborder un
// rond de 18 pixels.
const CROWD = 99;

/**
 * @param {number} count
 *
 * @returns {string}
 */
function label (count) {
	return (count > CROWD) ? (CROWD + '+') : String(count);
}

/**
 * @param {object} counts
 *
 * @returns {void}
 */
function paint (counts) {
	if (!counts) {
		return;
	}

	Array.from(document.querySelectorAll('[data-unread]')).forEach((node) => {
		const name = node.dataset.unread;

		if (!(name in counts)) {
			return;
		}

		const count = Number(counts[name]) || 0;

		node.textContent = label(count);
		node.hidden = (count === 0);

		// Le nombre nu ne dit pas de quoi il parle. Le gabarit fournit la
		// phrase, avec un jeton à remplacer : la traduction reste en Twig.
		if (node.dataset.unreadLabel) {
			node.setAttribute('aria-label', node.dataset.unreadLabel.replace('%count%', String(count)));
		}
	});

	title(Number(counts.total) || 0);
}

/**
 * Le nombre en tête du titre de l'onglet — ce qui se voit quand la page est
 * derrière une autre.
 *
 * @param {number} total
 *
 * @returns {void}
 */
function title (total) {
	const clean = document.title.replace(/^\(\d+\+?\)\s*/, '');

	document.title = total ? ('(' + label(total) + ') ' + clean) : clean;
}

live.subscribe((payload) => paint(payload.counts));

export { paint, label, title };
