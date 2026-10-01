/**
 * Le fil d'une conversation, sur `/messages` (#46).
 *
 * Le fil est une zone qui défile ; le serveur n'y met que les derniers
 * messages. Ouverte telle quelle, elle montrerait son haut — le plus ancien
 * de ce qu'elle porte —, là où l'on vient lire le plus récent. On la fait
 * donc partir d'en bas, ou de la barre « Nouveaux messages » quand il y en a
 * une, pour qu'aucun message non lu ne soit sauté.
 *
 * Une adresse qui vise un message (`#message-N` : le fil qu'on vient de
 * déplier, un message qu'on vient de modifier) garde la main : le navigateur
 * s'y pose de lui-même, et le contrarier ferait sauter la page.
 *
 * Sans JavaScript, la zone s'ouvre en haut : tout y est, il suffit de défiler.
 */

/**
 * @param {HTMLElement} thread
 * @param {string}      hash
 *
 * @returns {void}
 */
export function settle (thread, hash) {
	if (hash && (hash.length > 1)) {
		try {
			if (thread.querySelector(hash)) {
				return;
			}
		} catch (error) {
			// Une adresse qui n'est pas un sélecteur ne vise rien ici.
		}
	}

	const bar = thread.querySelector('.thread--separator');

	thread.scrollTop = bar ? bar.offsetTop : thread.scrollHeight;
}

document.addEventListener('DOMContentLoaded', () => {
	document.querySelectorAll('[data-thread-scroll]').forEach((thread) => {
		settle(thread, window.location.hash);
	});
});
