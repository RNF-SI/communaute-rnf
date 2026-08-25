import domready from 'mf-js/modules/dom/ready';
import live from './live';
import { attach as attachTags } from '../ui/message-tags';

/**
 * Les conversations qui restent ouvertes en bas de l'écran pendant qu'on lit
 * autre chose.
 *
 * **Le dock double la page `/messages`, il ne la remplace pas.** Tout ce
 * qu'on y fait se fait aussi là-bas, en HTML, sans une ligne de JavaScript :
 * écrire, lire, remonter le fil. Ce fichier n'ajoute qu'une chose, mais elle
 * ne s'obtient pas autrement — ne pas quitter la page qu'on est en train de
 * lire pour répondre à quelqu'un.
 *
 * **La plateforme n'est pas une application d'une seule page.** Chaque lien
 * recharge tout, dock compris. Ce qui donne l'illusion du contraire tient à
 * trois lignes : les fenêtres ouvertes sont notées dans `sessionStorage`, et
 * remontées au chargement suivant. Une conversation « reste » ouverte parce
 * qu'elle est rouverte, à chaque page, avant que l'œil ne s'en aperçoive.
 *
 * **Le corps des messages arrive en HTML déjà rendu par le serveur.** Ce
 * n'est pas une facilité : un tag vers un document donne un lien à un membre
 * du groupe et un libellé grisé aux autres, et ce tri se fait lecteur par
 * lecteur, côté PHP. Le dock ne recompose donc jamais un message, et ne met
 * rien de tout cela en cache.
 */

// Combien de fenêtres tiennent côte à côte. Au-delà, la plus ancienne se
// ferme — comme une pile de feuilles sur un bureau, pas comme un onglet qu'on
// accumule.
const MAX_WINDOWS = 3;
const MAX_WINDOWS_NARROW = 1;

// En deçà, l'écran n'a pas la place de deux fenêtres et d'une page derrière.
const NARROW = 720;

// Ce qu'un message annoncé en passant reste affiché.
const TOAST_LIFE = 8000;

// Là où l'on note ce qui est ouvert, pour le rouvrir à la page suivante.
// `sessionStorage` et non `localStorage` : le dock suit une session de
// navigation, il n'a pas à ressusciter trois semaines plus tard.
const STATE_KEY = 'rnf-dock';

/**
 * @param {string} tag
 * @param {string} className
 * @param {string} [text]
 *
 * @returns {HTMLElement}
 */
function el (tag, className, text) {
	const node = document.createElement(tag);

	if (className) {
		node.className = className;
	}

	if (text !== undefined) {
		node.textContent = text;
	}

	return node;
}

/**
 * Le dock.
 */
class Dock {
	/**
	 * @param {HTMLElement} root
	 */
	constructor (root) {
		this.root = root;
		this.labels = JSON.parse(root.dataset.labels || '{}');
		this.token = root.dataset.token || '';

		this.urls = {
			live: root.dataset.live,
			thread: root.dataset.thread,
			send: root.dataset.send,
			read: root.dataset.read,
			start: root.dataset.start,
			with: root.dataset.with,
			suggestions: root.dataset.suggestions,
			index: root.dataset.index,
		};

		this.stack = root.querySelector('.dock--stack');
		this.panel = root.querySelector('.dock--panel');
		this.list = root.querySelector('.dock--conversations');
		this.empty = root.querySelector('.dock--panel-empty');
		this.launcher = root.querySelector('.dock--launcher');
		this.toasts = root.querySelector('.dock--toasts');

		// Une fenêtre par conversation ouverte, indexée par identifiant de
		// fil. Une fenêtre ouverte sur quelqu'un à qui l'on n'a jamais écrit
		// n'a pas encore de fil : elle est rangée sous « u<identifiant> », et
		// change de clé au premier message envoyé.
		this.windows = {};

		this.root.hidden = false;

		this.bind();
		this.restore();

		live.subscribe((payload) => this.receive(payload));
	}

	/**
	 * Une adresse construite à partir de son gabarit.
	 *
	 * Les gabarits viennent de Twig, avec un identifiant sentinelle que l'on
	 * remplace ici : c'est le routeur qui sait où vivent ces routes, pas ce
	 * fichier. Une adresse écrite en dur dans le JavaScript serait la
	 * première chose à mentir le jour où un préfixe change.
	 *
	 * @param {string} template
	 * @param {number|string} id
	 *
	 * @returns {string}
	 */
	address (template, id) {
		return (template || '').replace('__id__', String(id));
	}

	/**
	 * @param {string} key
	 * @param {string} [fallback]
	 *
	 * @returns {string}
	 */
	say (key, fallback) {
		return this.labels[key] || fallback || key;
	}

	/**
	 * @returns {number}
	 */
	capacity () {
		return (window.innerWidth < NARROW) ? MAX_WINDOWS_NARROW : MAX_WINDOWS;
	}

	/**
	 * @returns {void}
	 */
	bind () {
		this.launcher.addEventListener('click', () => this.togglePanel());

		const close = this.panel.querySelector('.dock--panel-close');

		if (close) {
			close.addEventListener('click', () => this.togglePanel(false));
		}

		// Les points d'entrée semés dans les pages : la fiche d'un membre,
		// l'annuaire d'un groupe. Ce sont de vrais liens vers `/messages` —
		// on ne les intercepte que si le dock est là, et jamais un clic du
		// milieu ou avec une touche de modification, qui veut dire « dans un
		// autre onglet ».
		document.addEventListener('click', (event) => {
			if (event.defaultPrevented || (event.button !== 0) || event.metaKey || event.ctrlKey || event.shiftKey) {
				return;
			}

			const hook = event.target
				&& event.target.closest
				&& event.target.closest('[data-dock-user], [data-dock-thread]');

			if (!hook) {
				return;
			}

			event.preventDefault();

			if (hook.dataset.dockThread) {
				this.openThread(Number(hook.dataset.dockThread));
			} else {
				// L'adresse du lien est le repli : une boîte fermée, un compte
				// supprimé, et c'est la page qui le dira — correctement, et
				// dans la langue de qui lit.
				this.openWith(Number(hook.dataset.dockUser), hook.dataset.dockName || '', false, hook.href);
			}
		});

		// Une fenêtre de trop quand l'écran rétrécit : on ferme les plus
		// anciennes plutôt que de les laisser se chevaucher.
		window.addEventListener('resize', () => this.trim());

		document.addEventListener('keydown', (event) => {
			if (event.key !== 'Escape') {
				return;
			}

			if (!this.panel.hidden) {
				this.togglePanel(false);
			}
		});
	}

	/**
	 * @param {boolean} [open]
	 *
	 * @returns {void}
	 */
	togglePanel (open) {
		const next = (open === undefined) ? this.panel.hidden : open;

		this.panel.hidden = !next;
		this.launcher.setAttribute('aria-expanded', next ? 'true' : 'false');

		if (next) {
			// Ouvrir le panneau, c'est vouloir la liste à jour tout de suite.
			live.refresh();
		}

		this.remember();
	}

	/**
	 * Rouvrir ce qui était ouvert à la page précédente.
	 *
	 * @returns {void}
	 */
	restore () {
		let state = null;

		try {
			state = JSON.parse(window.sessionStorage.getItem(STATE_KEY) || 'null');
		} catch (error) {
			state = null;
		}

		if (!state) {
			return;
		}

		(state.windows || []).slice(0, this.capacity()).forEach((kept) => {
			if (kept.thread) {
				this.openThread(kept.thread, kept.folded);
			} else if (kept.user) {
				this.openWith(kept.user, kept.name || '', kept.folded);
			}
		});

		if (state.panel) {
			this.togglePanel(true);
		}
	}

	/**
	 * @returns {void}
	 */
	remember () {
		const windows = Object.keys(this.windows).map((key) => {
			const view = this.windows[key];

			return {
				thread: view.thread || 0,
				user: view.user || 0,
				name: view.name || '',
				folded: view.folded,
			};
		});

		try {
			window.sessionStorage.setItem(STATE_KEY, JSON.stringify({
				windows: windows,
				panel: !this.panel.hidden,
			}));
		} catch (error) {
			// Sans stockage, le dock se referme d'une page à l'autre. C'est
			// une perte de confort, pas une panne.
		}

		live.watch(windows.filter((view) => view.thread && !view.folded).map((view) => view.thread));
	}

	/**
	 * Ce que le direct rapporte.
	 *
	 * @param {object} payload
	 *
	 * @returns {void}
	 */
	receive (payload) {
		if (payload.conversations) {
			this.fill(payload.conversations);
		}

		if (payload.messages) {
			Object.keys(payload.messages).forEach((id) => {
				const view = this.windows[id];

				if (!view) {
					return;
				}

				payload.messages[id].forEach((message) => this.append(view, message));
			});
		}

		if (payload.notifications) {
			payload.notifications.forEach((notification) => this.toast(notification));
		}
	}

	/**
	 * La liste du panneau.
	 *
	 * Réécrite entière à chaque fois plutôt que rapiécée : vingt lignes ne
	 * coûtent rien à poser, et une liste reconstruite ne peut pas se
	 * désynchroniser de ce que dit la base.
	 *
	 * @param {Array<object>} conversations
	 *
	 * @returns {void}
	 */
	fill (conversations) {
		this.list.innerHTML = '';

		this.empty.hidden = conversations.length > 0;
		this.empty.textContent = this.say('none', '');

		conversations.forEach((row) => {
			const item = el('li', 'dock-row' + (row.unread ? ' dock-row__unread' : ''));

			const button = el('button', 'dock-row--open');
			button.type = 'button';

			button.appendChild(this.face(row.people));

			const text = el('span', 'dock-row--text');
			text.appendChild(el('span', 'dock-row--who', row.title));
			text.appendChild(el('span', 'dock-row--excerpt', row.excerpt));
			button.appendChild(text);

			button.appendChild(el('span', 'dock-row--when', row.atLabel));

			button.addEventListener('click', () => {
				this.openThread(row.id);
				this.togglePanel(false);
			});

			item.appendChild(button);
			this.list.appendChild(item);
		});

		// Les fenêtres ouvertes portent le même titre que la liste : quand
		// quelqu'un est ajouté à une conversation, son nom apparaît des deux
		// côtés au même tour.
		conversations.forEach((row) => {
			const view = this.windows[row.id];

			if (view && view.title) {
				view.title.textContent = row.title;
			}
		});
	}

	/**
	 * Le visage, ou le disque coloré qui en tient lieu. La teinte vient du
	 * serveur, prise au même filtre que les gabarits Twig : un même nom ne
	 * doit pas changer de couleur selon qui le dessine.
	 *
	 * @param {Array<object>} people
	 *
	 * @returns {HTMLElement}
	 */
	face (people) {
		const person = (people && people[0]) || null;
		const node = el('span', 'dock-face');

		if (person && person.avatar) {
			const image = document.createElement('img');
			image.src = person.avatar;
			image.alt = '';
			node.appendChild(image);

			return node;
		}

		node.classList.add('dock-face__empty');

		if (person) {
			node.style.setProperty('--color', person.color);
			node.textContent = (person.name || '?').trim().charAt(0).toUpperCase();
		}

		return node;
	}

	/**
	 * Ouvrir une fenêtre sur une conversation.
	 *
	 * @param {number} id
	 * @param {boolean} [folded]
	 *
	 * @returns {void}
	 */
	openThread (id, folded) {
		if (!id) {
			return;
		}

		if (this.windows[id]) {
			this.unfold(this.windows[id]);

			return;
		}

		const view = this.frame({ thread: id, folded: !!folded });

		this.load(view);
	}

	/**
	 * Ouvrir une fenêtre sur quelqu'un — la conversation existe peut-être
	 * déjà, et c'est le serveur qui le dit.
	 *
	 * @param {number} userId
	 * @param {string} name
	 * @param {boolean} [folded]
	 *
	 * @returns {void}
	 */
	openWith (userId, name, folded, fallback) {
		if (!userId) {
			return;
		}

		// Déjà une fenêtre ouverte sur cette personne, et pas encore de
		// conversation : recliquer sur « Écrire » la ramène au premier plan
		// plutôt que d'en poser une seconde à côté de la première.
		const already = this.windows['u' + userId];

		if (already) {
			this.unfold(already);

			return;
		}

		window.fetch(this.address(this.urls.with, userId), {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
		})
			.then((response) => response.ok ? response.json() : Promise.reject(response))
			.then((answer) => {
				if (answer.thread) {
					this.openThread(answer.thread, folded);

					return;
				}

				const view = this.frame({
					user: userId,
					name: (answer.person && answer.person.name) || name,
					folded: !!folded,
				});

				view.title.textContent = view.name;
				view.body.appendChild(el('p', 'dock-window--hint', this.say('first', '')));
				view.ready = true;
			})
			.catch(() => {
				// Un clic explicite suit son lien — la page dira correctement
				// ce qui cloche, boîte fermée ou compte parti. Une fenêtre
				// qu'on rétablit au chargement, elle, s'oublie : personne n'a
				// rien demandé, et emmener quelqu'un ailleurs au milieu d'une
				// page qui s'ouvre serait la dernière des surprises.
				if (fallback) {
					window.location.href = fallback;
				}
			});
	}

	/**
	 * Bâtir une fenêtre vide et la poser dans la pile.
	 *
	 * @param {{thread?: number, user?: number, name?: string, folded: boolean}} about
	 *
	 * @returns {object}
	 */
	frame (about) {
		const view = {
			thread: about.thread || 0,
			user: about.user || 0,
			name: about.name || '',
			folded: !!about.folded,
			ready: false,
			seen: {},
		};

		const node = el('section', 'dock-window');
		node.setAttribute('aria-label', this.say('window', 'Conversation'));

		const head = el('div', 'dock-window--head');

		const title = el('button', 'dock-window--title');
		title.type = 'button';
		title.textContent = this.say('loading', '…');
		title.addEventListener('click', () => this.toggleFold(view));

		const tools = el('div', 'dock-window--tools');

		// Un glyphe, et la phrase en infobulle : « Ouvrir en grand » à côté
		// d'une croix, dans une barre de titre de 300 pixels, ne laisserait
		// plus de place au nom de celui à qui l'on parle.
		const open = el('a', 'dock-window--tool dock-window--open', '↗');
		open.title = this.say('open_full', '');
		open.setAttribute('aria-label', this.say('open_full_title', ''));
		open.href = this.urls.index;

		const shut = el('button', 'dock-window--tool dock-window--close');
		shut.type = 'button';
		shut.setAttribute('aria-label', this.say('close', 'Fermer'));
		shut.textContent = '×';
		shut.addEventListener('click', () => this.close(view));

		tools.appendChild(open);
		tools.appendChild(shut);

		const dot = el('span', 'dock-window--dot');
		dot.hidden = true;

		head.appendChild(title);
		head.appendChild(dot);
		head.appendChild(tools);

		const body = el('div', 'dock-window--body');
		// `log` plutôt que `region` : un lecteur d'écran annonce ce qui s'y
		// ajoute sans qu'on ait à le lui demander, et sans relire le fil
		// entier à chaque message.
		body.setAttribute('role', 'log');
		body.setAttribute('aria-live', 'polite');

		const form = el('form', 'dock-window--reply');
		const field = document.createElement('textarea');
		field.className = 'dock-window--field';
		field.rows = 1;
		field.placeholder = this.say('placeholder', '');
		field.setAttribute('aria-label', this.say('write', 'Votre message'));

		// La liste de suggestions des tags, la même que sur la page : une
		// arobase désigne quelqu'un, un croisillon un contenu. C'est le
		// serveur qui dit comment le tag s'écrit.
		if (this.urls.suggestions) {
			field.dataset.tags = this.urls.suggestions;
		}

		const send = el('button', 'dock-window--send');
		send.type = 'submit';
		send.textContent = this.say('send', 'Envoyer');

		form.appendChild(field);
		form.appendChild(send);

		form.addEventListener('submit', (event) => {
			event.preventDefault();
			this.send(view);
		});

		// Entrée envoie, Maj+Entrée va à la ligne. C'est le geste qu'on a
		// dans les doigts ; l'inverse ferait écrire des paragraphes à
		// quelqu'un qui voulait répondre « oui ».
		field.addEventListener('keydown', (event) => {
			if ((event.key === 'Enter') && !event.shiftKey && !event.altKey) {
				// Une suggestion de tag est ouverte : Entrée la choisit, elle
				// n'envoie pas le message.
				const open = field.parentNode.querySelector('.mentions-suggestions:not([hidden])');

				if (open) {
					return;
				}

				event.preventDefault();
				this.send(view);
			}
		});

		field.addEventListener('input', () => {
			// Le champ grandit avec ce qu'on écrit, jusqu'à un point.
			field.style.height = 'auto';
			field.style.height = Math.min(field.scrollHeight, 120) + 'px';
		});

		node.appendChild(head);
		node.appendChild(body);
		node.appendChild(form);

		view.node = node;
		view.title = title;
		view.body = body;
		view.field = field;
		view.form = form;
		view.dot = head.querySelector('.dock-window--dot');
		view.link = open;

		node.classList.toggle('dock-window__folded', view.folded);

		this.stack.appendChild(node);
		this.windows[this.key(view)] = view;

		if (this.urls.suggestions) {
			attachTags(field);
		}

		this.trim();
		this.remember();

		return view;
	}

	/**
	 * @param {object} view
	 *
	 * @returns {string}
	 */
	key (view) {
		return view.thread ? String(view.thread) : ('u' + view.user);
	}

	/**
	 * Charger le fil d'une fenêtre.
	 *
	 * @param {object} view
	 *
	 * @returns {void}
	 */
	load (view) {
		// Repliée, la fenêtre charge son fil sans le marquer lu : elle est
		// rouverte à chaque page par `restore`, et marquer lu à chaque
		// navigation ferait disparaître sans un regard les messages arrivés
		// pendant qu'elle était fermée.
		const quiet = view.folded ? '?read=0' : '';

		window.fetch(this.address(this.urls.thread, view.thread) + quiet, {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
		})
			.then((response) => response.ok ? response.json() : Promise.reject(response))
			.then((answer) => {
				view.ready = true;
				view.title.textContent = answer.thread.title;
				view.link.href = answer.thread.url;
				view.body.innerHTML = '';

				if (answer.more) {
					const more = el('a', 'dock-window--more', this.say('more', ''));
					more.href = answer.thread.url;
					view.body.appendChild(more);
				}

				answer.messages.forEach((message) => this.append(view, message, true));

				this.bottom(view);

				if (!view.folded) {
					view.field.focus();
				}

				// Le fil vient d'être lu côté serveur : les compteurs ont
				// bougé, et les autres onglets doivent le savoir.
				live.announce({ counts: answer.counts });
				live.refresh();
			})
			.catch(() => {
				view.body.textContent = this.say('failed', '');
			});
	}

	/**
	 * Poser un message au bas du fil.
	 *
	 * @param {object} view
	 * @param {object} message
	 * @param {boolean} [quiet] ne pas signaler l'arrivée (chargement initial)
	 *
	 * @returns {void}
	 */
	append (view, message, quiet) {
		// Le même message peut arriver deux fois : par la réponse à l'envoi
		// et par le tour de sondage suivant. On garde le premier.
		if (view.seen[message.id]) {
			return;
		}

		view.seen[message.id] = true;

		const node = el('div', 'dock-message' + (message.mine ? ' dock-message__mine' : ''));

		if (!message.mine && message.author) {
			node.appendChild(el('span', 'dock-message--who', message.author.name));
		}

		const bubble = el('div', 'dock-message--bubble');

		if (message.deleted) {
			bubble.appendChild(el('em', 'dock-message--gone', this.say('deleted', '')));
		} else {
			// Rendu par le serveur, pour ce lecteur-ci. Voir l'en-tête du
			// fichier : ce n'est pas du texte qu'on met en forme ici.
			bubble.innerHTML = message.html;
		}

		node.appendChild(bubble);
		node.appendChild(el('span', 'dock-message--when', message.atLabel));

		view.body.appendChild(node);

		this.bottom(view);

		if (quiet || message.mine) {
			return;
		}

		if (view.folded) {
			// Repliée : la pastille de la fenêtre suffit, le fil se lira à
			// l'ouverture.
			view.node.classList.add('dock-window__ringing');
			view.dot.hidden = false;

			return;
		}

		// Dépliée et sous les yeux : ce qu'on lit ne doit pas rester compté
		// comme en attente.
		if (!document.hidden) {
			this.markRead(view);
		}
	}

	/**
	 * @param {object} view
	 *
	 * @returns {void}
	 */
	bottom (view) {
		view.body.scrollTop = view.body.scrollHeight;
	}

	/**
	 * Envoyer ce qui est écrit dans une fenêtre.
	 *
	 * @param {object} view
	 *
	 * @returns {void}
	 */
	send (view) {
		const body = view.field.value.trim();

		if (!body || view.sending) {
			return;
		}

		view.sending = true;
		view.form.classList.add('dock-window--reply__busy');

		const form = new window.FormData();
		form.append('_token', this.token);
		form.append('body', body);

		const url = view.thread
			? this.address(this.urls.send, view.thread)
			: this.address(this.urls.start, view.user);

		window.fetch(url, { method: 'POST', credentials: 'same-origin', body: form })
			.then((response) => response.ok ? response.json() : Promise.reject(response))
			.then((answer) => {
				view.field.value = '';
				view.field.style.height = 'auto';

				// Première réponse à quelqu'un : la conversation vient de
				// naître, la fenêtre change de clé.
				if (!view.thread && answer.thread) {
					delete this.windows[this.key(view)];
					view.thread = answer.thread;
					view.user = 0;
					this.windows[this.key(view)] = view;

					const hint = view.body.querySelector('.dock-window--hint');

					if (hint) {
						hint.remove();
					}
				}

				this.append(view, answer.message, true);

				(answer.warnings || []).forEach((warning) => {
					view.body.appendChild(el('p', 'dock-window--warning', warning));
					this.bottom(view);
				});

				// Les compteurs partent tout de suite ; le curseur, non.
				// L'avancer ici ferait sauter ce message au tour suivant, et
				// l'onglet d'à côté — où la même conversation peut être
				// ouverte — ne le verrait jamais arriver. Le sondage le
				// rapportera, et `seen` évitera qu'il s'affiche deux fois ici.
				live.announce({ counts: answer.counts });
				live.refresh();
				this.remember();
			})
			.catch(() => {
				view.body.appendChild(el('p', 'dock-window--warning', this.say('failed_send', '')));
				this.bottom(view);
			})
			.then(() => {
				view.sending = false;
				view.form.classList.remove('dock-window--reply__busy');
				view.field.focus();
			});
	}

	/**
	 * @param {object} view
	 *
	 * @returns {void}
	 */
	markRead (view) {
		if (!view.thread) {
			return;
		}

		const form = new window.FormData();
		form.append('_token', this.token);

		window.fetch(this.address(this.urls.read, view.thread), {
			method: 'POST',
			credentials: 'same-origin',
			body: form,
		})
			.then((response) => response.ok ? response.json() : Promise.reject(response))
			.then((answer) => live.announce({ counts: answer.counts }))
			.catch(() => {
				// Le compteur se corrigera au tour suivant : le serveur reste
				// la référence, pas ce que le dock a cru marquer.
			});
	}

	/**
	 * @param {object} view
	 *
	 * @returns {void}
	 */
	toggleFold (view) {
		view.folded ? this.unfold(view) : this.fold(view);
	}

	/**
	 * @param {object} view
	 *
	 * @returns {void}
	 */
	fold (view) {
		view.folded = true;
		view.node.classList.add('dock-window__folded');
		this.remember();
	}

	/**
	 * @param {object} view
	 *
	 * @returns {void}
	 */
	unfold (view) {
		view.folded = false;
		view.node.classList.remove('dock-window__folded', 'dock-window__ringing');
		view.dot.hidden = true;

		this.bottom(view);
		view.field.focus();

		if (view.thread) {
			this.markRead(view);
		}

		this.remember();
	}

	/**
	 * @param {object} view
	 *
	 * @returns {void}
	 */
	close (view) {
		view.node.remove();
		delete this.windows[this.key(view)];
		this.remember();
	}

	/**
	 * Fermer les plus anciennes fenêtres quand il y en a plus que l'écran
	 * n'en porte.
	 *
	 * @returns {void}
	 */
	trim () {
		const keys = Object.keys(this.windows);
		const room = this.capacity();

		if (keys.length <= room) {
			return;
		}

		keys.slice(0, keys.length - room).forEach((key) => this.close(this.windows[key]));
	}

	/**
	 * Annoncer une notification en passant.
	 *
	 * Celles d'un message privé n'arrivent jamais ici — le serveur les
	 * retient : le dock montre déjà le message, et l'annoncer par-dessus
	 * ferait deux bulles pour une phrase reçue.
	 *
	 * @param {object} notification
	 *
	 * @returns {void}
	 */
	toast (notification) {
		const node = el('div', 'dock-toast');

		const link = el('a', 'dock-toast--link');
		link.href = notification.url;
		link.textContent = notification.title;

		node.appendChild(el('span', 'dock-toast--group', notification.group || ''));
		node.appendChild(link);

		const shut = el('button', 'dock-toast--close');
		shut.type = 'button';
		shut.setAttribute('aria-label', this.say('close', 'Fermer'));
		shut.textContent = '×';
		shut.addEventListener('click', () => node.remove());

		node.appendChild(shut);

		this.toasts.appendChild(node);

		window.setTimeout(() => node.remove(), TOAST_LIFE);
	}
}

domready(() => {
	const root = document.getElementById('messaging-dock');

	if (!root) {
		return;
	}

	const dock = new Dock(root);

	live.start({
		url: dock.urls.live,
		token: dock.token,
	});
});

export { Dock, MAX_WINDOWS, STATE_KEY };
