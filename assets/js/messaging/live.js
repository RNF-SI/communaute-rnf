/**
 * Ce qui arrive pendant qu'on lit autre chose : les nouveaux messages, les
 * nouvelles notifications, et les compteurs.
 *
 * **C'est un sondage, pas une connexion ouverte.** Le choix n'est pas une
 * économie de moyens : une connexion persistante — SSE, WebSocket — immobilise
 * un processus PHP par onglet ouvert, et la plateforme tourne derrière
 * PHP-FPM, où ces processus se comptent. Quinze personnes connectées
 * suffiraient à ce que le seizième visiteur attende. Le sondage, lui, rend la
 * main entre deux tours.
 *
 * Trois précautions font qu'il ne coûte presque rien :
 *
 * - **Un seul onglet interroge le serveur.** Les onglets s'élisent un meneur
 *   par un bail posé dans `localStorage` ; les autres écoutent ce qu'il
 *   rapporte par `BroadcastChannel`. Ouvrir six onglets ne fait pas six fois
 *   le travail.
 * - **Le rythme suit ce qu'on regarde.** Trois secondes quand une
 *   conversation est dépliée sous les yeux, dix quand l'onglet est actif sans
 *   fil ouvert, une minute quand il est caché — et plus rien du tout au bout
 *   d'une demi-heure sans personne. Revenir sur l'onglet réveille tout.
 * - **La réponse est presque vide.** Le serveur ne renvoie la liste des
 *   conversations que lorsqu'il s'est passé quelque chose. Le tour ordinaire,
 *   c'est deux compteurs.
 *
 * Rien de tout cela n'est nécessaire au fonctionnement de la messagerie : la
 * page `/messages` s'affiche entièrement côté serveur. Si ce fichier ne
 * s'exécute pas, il ne manque que le direct.
 */

// Le rythme, selon ce qu'on est en train de faire.
const QUICK = 3000;   // une conversation est dépliée, l'onglet est actif
const NORMAL = 10000; // l'onglet est actif, rien n'est déplié
const HIDDEN = 60000; // l'onglet est en arrière-plan

// Au bout de quoi un onglet caché et sans personne devant cesse de demander.
// Le réveil se fait au retour, pas à l'horloge.
const SLEEP = 30 * 60 * 1000;

// Ce qu'on attend de plus en plus longtemps quand le serveur ne répond pas.
// Une panne ne doit pas se transformer en charge.
const BACKOFF_MAX = 5 * 60 * 1000;

// Le bail du meneur. Renouvelé à chaque tour ; repris par un autre onglet
// quand il a expiré — c'est-à-dire quand celui qui le tenait a été fermé.
const LEASE_KEY = 'rnf-live-leader';

// Plus long que le tour le plus lent : un meneur dont l'onglet est caché ne
// sonde qu'une fois par minute, et laisserait son propre bail expirer entre
// deux tours. Le prix de cette marge est le seul cas où elle se voit — un
// onglet meneur qui disparaît sans prévenir (un plantage, la batterie) :
// personne ne sonde jusqu'à l'expiration. Fermer proprement rend le bail tout
// de suite, et c'est ce qui arrive presque toujours.
const LEASE_TTL = 75000;

// Là où le curseur se garde, pour qu'un onglet neuf ne rejoue pas ce que les
// autres ont déjà vu.
const CURSOR_KEY = 'rnf-live-cursor';

// Le canal entre onglets, et son repli pour les navigateurs qui ne le
// connaissent pas : une clé de `localStorage` qu'on réécrit, dont l'événement
// `storage` prévient les autres onglets (et jamais celui qui écrit).
const CHANNEL = 'rnf-live';
const ECHO_KEY = 'rnf-live-echo';

/**
 * Lire une valeur JSON du stockage local sans jamais lever.
 *
 * `localStorage` jette dans une fenêtre privée de Safari et quand l'espace
 * est plein. Le direct est un confort : il doit s'éteindre, pas casser la
 * page qui l'héberge.
 *
 * @param {string} key
 *
 * @returns {*} null si la valeur est absente ou illisible
 */
function read (key) {
	try {
		const raw = window.localStorage.getItem(key);

		return raw ? JSON.parse(raw) : null;
	} catch (error) {
		return null;
	}
}

/**
 * @param {string} key
 * @param {*} value
 *
 * @returns {boolean} FALSE quand le stockage a refusé
 */
function write (key, value) {
	try {
		window.localStorage.setItem(key, JSON.stringify(value));

		return true;
	} catch (error) {
		return false;
	}
}

/**
 * Le moteur du direct. Un seul par page.
 */
class Live {
	constructor () {
		this.url = null;
		this.token = null;

		// Ce que le serveur nous a dit en dernier : le dernier message reçu,
		// la dernière notification. Deux curseurs et non des dates — deux
		// enregistrements de la même seconde ne se marcheraient pas dessus,
		// et l'horloge du navigateur n'entre pas en ligne de compte.
		this.cursor = 0;
		this.notice = 0;

		this.listeners = [];
		this.threads = [];

		this.timer = null;
		this.channel = null;
		this.leader = false;
		this.failures = 0;
		this.started = false;
		this.stopped = false;

		// Le dernier signe de vie de quelqu'un devant l'écran. Sert à décider
		// qu'un onglet oublié peut se taire.
		this.awakeAt = Date.now();

		this.tab = Math.random().toString(36).slice(2) + Date.now().toString(36);
	}

	/**
	 * @param {{url: string, token: string, cursor?: number, notice?: number}} config
	 *
	 * @returns {void}
	 */
	start (config) {
		if (this.started || !config || !config.url) {
			return;
		}

		this.started = true;
		this.url = config.url;
		this.token = config.token || null;

		const kept = read(CURSOR_KEY) || {};

		this.cursor = Number(kept.cursor) || Number(config.cursor) || 0;
		this.notice = Number(kept.notice) || Number(config.notice) || 0;

		this.listen();

		// Le premier tour demande l'état complet : un dock qui s'ouvre veut la
		// liste, pas seulement ce qui a changé depuis un curseur qu'il n'a
		// pas encore.
		this.poll(true);
	}

	/**
	 * S'abonner à ce qui arrive. Le rappel reçoit la charge renvoyée par le
	 * serveur, qu'elle vienne de cet onglet ou d'un autre.
	 *
	 * @param {function(object): void} listener
	 *
	 * @returns {void}
	 */
	subscribe (listener) {
		this.listeners.push(listener);
	}

	/**
	 * Dire quelles conversations sont dépliées : c'est ce qui décide à la
	 * fois du rythme et des messages que le serveur prend la peine de
	 * renvoyer.
	 *
	 * @param {Array<number>} ids
	 *
	 * @returns {void}
	 */
	watch (ids) {
		const next = (ids || []).map(Number).filter(Boolean);
		const changed = next.join(',') !== this.threads.join(',');

		this.threads = next;

		if (changed) {
			// Un fil qu'on vient d'ouvrir ne doit pas attendre le tour
			// suivant pour vivre.
			this.schedule(0);
		}
	}

	/**
	 * Demander un tour tout de suite — après avoir envoyé un message, par
	 * exemple, pour que les autres onglets se mettent à jour sans délai.
	 *
	 * @returns {void}
	 */
	refresh () {
		this.awakeAt = Date.now();
		this.schedule(0);
	}

	/**
	 * Faire suivre aux autres onglets, et à cette page, quelque chose qu'on
	 * vient d'apprendre autrement que par le sondage : la réponse à un envoi,
	 * une conversation marquée lue.
	 *
	 * @param {object} payload
	 *
	 * @returns {void}
	 */
	announce (payload) {
		if (!payload) {
			return;
		}

		this.absorb(payload);
		this.broadcast(payload);
		this.dispatch(payload);
	}

	/**
	 * Le délai avant le prochain tour, tel que l'état de la page le dicte.
	 *
	 * @returns {number} en millisecondes, 0 pour « ne plus rien demander »
	 */
	pace () {
		if (this.stopped) {
			return 0;
		}

		const idle = Date.now() - this.awakeAt;

		if (document.hidden && idle > SLEEP) {
			return 0;
		}

		if (this.failures) {
			// 20 s, 40 s, 80 s… plafonnées. On repart au rythme normal dès
			// qu'une réponse arrive.
			return Math.min(BACKOFF_MAX, NORMAL * Math.pow(2, this.failures));
		}

		if (document.hidden) {
			return HIDDEN;
		}

		return this.threads.length ? QUICK : NORMAL;
	}

	/**
	 * Brancher les écouteurs : le canal entre onglets, le retour sur
	 * l'onglet, et ce qui prouve que quelqu'un est là.
	 *
	 * @returns {void}
	 */
	listen () {
		if (typeof window.BroadcastChannel === 'function') {
			try {
				this.channel = new window.BroadcastChannel(CHANNEL);
				this.channel.onmessage = (event) => {
					this.absorb(event.data);
					this.dispatch(event.data);
				};
			} catch (error) {
				this.channel = null;
			}
		}

		// Le repli, et le seul chemin dans les navigateurs sans
		// `BroadcastChannel` : `storage` ne prévient que les *autres* onglets,
		// ce qui est exactement ce qu'on veut.
		window.addEventListener('storage', (event) => {
			if (event.key !== ECHO_KEY || !event.newValue) {
				return;
			}

			try {
				const echo = JSON.parse(event.newValue);

				if (echo && echo.from !== this.tab && echo.payload) {
					this.absorb(echo.payload);
					this.dispatch(echo.payload);
				}
			} catch (error) {
				// Un écho illisible n'est pas une raison de couper le direct.
			}
		});

		document.addEventListener('visibilitychange', () => {
			if (!document.hidden) {
				this.awakeAt = Date.now();
				this.schedule(0);
			} else {
				this.schedule();
			}
		});

		['pointerdown', 'keydown', 'focus'].forEach((name) => {
			window.addEventListener(name, () => {
				this.awakeAt = Date.now();
			}, true);
		});

		// Un onglet qui se ferme rend son bail : le suivant reprend la main
		// sans attendre l'expiration.
		window.addEventListener('pagehide', () => {
			if (this.leader) {
				write(LEASE_KEY, { tab: null, at: 0 });
			}
		});
	}

	/**
	 * Prendre ou garder le bail. Un seul onglet interroge le serveur.
	 *
	 * L'écriture puis la relecture ne sont pas atomiques : deux onglets qui
	 * se réveillent dans la même milliseconde peuvent tous deux se croire
	 * meneurs, le temps d'un tour. C'est sans conséquence — deux sondages au
	 * lieu d'un —, et la relecture suffit à ce que cela ne dure pas.
	 *
	 * @returns {boolean}
	 */
	claim () {
		const lease = read(LEASE_KEY);
		const now = Date.now();

		if (lease && lease.tab && (lease.tab !== this.tab) && ((now - lease.at) < LEASE_TTL)) {
			this.leader = false;

			return false;
		}

		if (!write(LEASE_KEY, { tab: this.tab, at: now })) {
			// Pas de stockage : chaque onglet se débrouille seul. Une fenêtre
			// privée avec deux onglets ouverts sonde deux fois, ce qui est
			// préférable à ne pas sonder du tout.
			this.leader = true;

			return true;
		}

		const back = read(LEASE_KEY);

		this.leader = !!back && (back.tab === this.tab);

		return this.leader;
	}

	/**
	 * @param {number|undefined} delay laisser vide pour le rythme courant
	 *
	 * @returns {void}
	 */
	schedule (delay) {
		// Le dock rétablit ses fenêtres avant que le moteur soit démarré :
		// `watch` et `refresh` sont donc appelés alors qu'aucune adresse n'est
		// encore connue. Le premier tour, c'est `start` qui le donne.
		if (!this.url) {
			return;
		}

		if (this.timer) {
			window.clearTimeout(this.timer);
			this.timer = null;
		}

		const wait = (delay === undefined) ? this.pace() : delay;

		if (wait === 0 && delay === undefined) {
			// L'onglet s'endort. `visibilitychange` le réveillera.
			return;
		}

		this.timer = window.setTimeout(() => this.poll(), wait);
	}

	/**
	 * Un tour.
	 *
	 * @param {boolean} full demander l'état complet plutôt que la différence
	 *
	 * @returns {void}
	 */
	poll (full) {
		if (this.stopped || !this.url) {
			return;
		}

		if (!this.claim()) {
			// Suiveur : on ne demande rien, on écoute. Le bail est vérifié à
			// nouveau au tour suivant, pour reprendre la main si le meneur
			// disparaît.
			this.schedule();

			return;
		}

		const query = [
			'since=' + encodeURIComponent(this.cursor),
			'notice=' + encodeURIComponent(this.notice),
		];

		if (full || !this.cursor) {
			query.push('full=1');
		}

		if (this.threads.length) {
			query.push('threads=' + encodeURIComponent(this.threads.join(',')));
		}

		window.fetch(this.url + '?' + query.join('&'), {
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
		})
			.then((response) => {
				if (response.status === 401 || response.status === 403) {
					// La session a expiré. Continuer reviendrait à cogner
					// contre la page de connexion toutes les trois secondes.
					this.stopped = true;

					throw new Error('session');
				}

				if (!response.ok) {
					throw new Error('http');
				}

				return response.json();
			})
			.then((payload) => {
				this.failures = 0;

				this.absorb(payload);
				this.broadcast(payload);
				this.dispatch(payload);
				this.schedule();
			})
			.catch(() => {
				this.failures = Math.min(this.failures + 1, 5);
				this.schedule();
			});
	}

	/**
	 * Retenir les curseurs d'une charge, d'où qu'elle vienne.
	 *
	 * @param {object} payload
	 *
	 * @returns {void}
	 */
	absorb (payload) {
		if (!payload) {
			return;
		}

		let moved = false;

		if (payload.cursor && (payload.cursor > this.cursor)) {
			this.cursor = payload.cursor;
			moved = true;
		}

		if (payload.notice && (payload.notice > this.notice)) {
			this.notice = payload.notice;
			moved = true;
		}

		if (moved) {
			write(CURSOR_KEY, { cursor: this.cursor, notice: this.notice });
		}
	}

	/**
	 * @param {object} payload
	 *
	 * @returns {void}
	 */
	broadcast (payload) {
		if (this.channel) {
			try {
				this.channel.postMessage(payload);

				return;
			} catch (error) {
				// Le canal a été fermé sous nos pieds : on retombe sur l'écho.
			}
		}

		write(ECHO_KEY, { from: this.tab, at: Date.now(), payload: payload });
	}

	/**
	 * @param {object} payload
	 *
	 * @returns {void}
	 */
	dispatch (payload) {
		if (!payload) {
			return;
		}

		this.listeners.forEach((listener) => {
			try {
				listener(payload);
			} catch (error) {
				// Un abonné qui casse ne doit pas emporter les autres, ni le
				// tour suivant.
			}
		});
	}
}

const live = new Live();

export default live;
export { Live, QUICK, NORMAL, HIDDEN, LEASE_KEY, CURSOR_KEY };
