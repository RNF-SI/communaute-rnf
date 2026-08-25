#!/usr/bin/env bash
#
# Rendre inscriptibles les répertoires que la plateforme écrit — et **eux
# seuls**.
#
# Ce script existe parce que la commande équivalente, tapée à la main, a mis un
# serveur à terre le 25 août 2026 : elle portait sur `var/` en bloc, donc sur
# `var/sessions/`, et PHP refuse de lire un fichier de session dont le
# propriétaire n'est pas le processus. Toutes les pages ont rendu 500, y compris
# la connexion. Une recette ne protège que ceux qui la lisent ; un script, lui,
# ne se trompe pas de répertoire.
#
#   ./bin/fix-permissions.sh [UTILISATEUR_WEB]
#
# Sans argument, l'utilisateur du serveur web est deviné parmi les comptes
# usuels. Le déployeur est celui qui lance le script.

set -euo pipefail

cd "$(dirname "$0")/.."

DEPLOYEUR="$(id -un)"
WEB="${1:-}"

if [ -z "$WEB" ]; then
	for candidat in www-data apache nginx httpd; do
		if id -u "$candidat" >/dev/null 2>&1; then
			WEB="$candidat"
			break
		fi
	done
fi

if [ -z "$WEB" ] || ! id -u "$WEB" >/dev/null 2>&1; then
	echo "Utilisateur du serveur web introuvable. Passez-le en argument :" >&2
	echo "  ./bin/fix-permissions.sh www-data" >&2
	exit 1
fi

# Les répertoires sont nommés un par un, jamais `var/` en bloc.
#
# `var/sessions/` en est **délibérément absent** : c'est le seul endroit où la
# propriété compte à elle seule, et l'y inclure rend « Failed to start the
# session » sur toutes les pages. Ces fichiers appartiennent au serveur web, et
# à lui seul ; s'ils ont déjà été chownés, la réparation est de les effacer —
# ce que fait l'option ci-dessous, au prix d'une déconnexion générale.
REPERTOIRES=(
	public/media/cache
	var/cache
	var/log
	var/files
)

echo "Déployeur : ${DEPLOYEUR} — serveur web : ${WEB}"
echo

for repertoire in "${REPERTOIRES[@]}"; do
	mkdir -p "$repertoire"
done

sudo chown -R "${DEPLOYEUR}:${WEB}" "${REPERTOIRES[@]}"

# Le `d:` pose une règle **par défaut** : tout fichier créé ensuite est
# inscriptible par le serveur web, quel que soit celui qui l'a créé et quel que
# soit son umask. Sans elle, la panne revient au prochain `search:reindex:all`.
if command -v setfacl >/dev/null 2>&1; then
	sudo setfacl -R -m "g:${WEB}:rwX" -m "d:g:${WEB}:rwX" "${REPERTOIRES[@]}"
else
	echo "⚠️  setfacl absent : installez le paquet « acl », sinon la panne" >&2
	echo "    des droits d'écriture reviendra à la prochaine réindexation." >&2
fi

echo "Droits posés sur : ${REPERTOIRES[*]}"
echo

# Un fichier de session appartenant à quelqu'un d'autre que le serveur web est
# illisible pour lui. On le signale plutôt que de le supprimer sans prévenir :
# les effacer déconnecte tout le monde.
SESSIONS="var/sessions"

if [ -d "$SESSIONS" ]; then
	INTRUS="$(find "$SESSIONS" -name 'sess_*' ! -user "$WEB" -print -quit 2>/dev/null || true)"

	if [ -n "$INTRUS" ]; then
		echo "⚠️  Des fichiers de session n'appartiennent pas à ${WEB} :" >&2
		echo "    $INTRUS" >&2
		echo "    Le serveur web ne peut pas les lire — toutes les pages rendront 500." >&2
		echo "    Réparation (déconnecte tout le monde) :" >&2
		echo "      rm -f ${SESSIONS}/*/sess_*" >&2
		exit 2
	fi

	echo "Fichiers de session : laissés à ${WEB}, rien à signaler."
fi
