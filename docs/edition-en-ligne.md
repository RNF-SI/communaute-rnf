# Voir un document, et le modifier sans le télécharger

Deux choses distinctes, et il faut les garder distinctes : **l'aperçu** ne
demande rien à installer, **l'édition en ligne** demande un serveur de plus.

---

## 1. L'aperçu — rien à faire

Sur la fiche d'un document (`/groups/{slug}/documents/{id}`) :

- un **PDF** s'affiche dans la page, avec le lecteur du navigateur ;
- une **image** s'affiche telle quelle ;
- tout le reste garde son bouton « Télécharger ».

Rien n'est chargé chez un tiers, et le fichier reste servi par la route de la
plateforme : un document de groupe privé n'est pas plus visible qu'avant, c'est
`GroupDocumentVoter` qui tranche, comme pour le téléchargement.

Trois détails qui ont l'air de rien :

- **Le bouton « Télécharger » télécharge**, y compris un PDF. Sans le
  `?download=1` qu'il porte, il aurait ouvert le lecteur au lieu d'enregistrer
  le fichier — le contraire de ce qu'annonce le bouton.
- **Un SVG, un HTML ou un XML déposé comme document se télécharge toujours**,
  jamais ne s'affiche. Ouvert dans l'onglet, un SVG exécute son `<script>` dans
  notre domaine, avec le cookie de session de celui qui l'ouvre. La règle est
  dans `FileMimeManager::mustDownload()`. Elle ne casse pas l'aperçu des images :
  une sous-ressource — le `src` d'une balise `<img>` — ignore
  `Content-Disposition`.
- **`X-Content-Type-Options: nosniff`** est posé sur tout fichier servi : un
  navigateur ne doit pas deviner un type que nous avons déclaré.

---

## 2. L'édition en ligne — un serveur de documents

`ONLYOFFICE_URL` vide, **tout ce qui suit est éteint** : aucun bouton
n'apparaît, aucune route ne répond, `app:preflight` le dit en une ligne. C'est
voulu — une intégration non configurée ne doit pas fabriquer des pages mortes.

### Ce qui parle à quoi

Trois liens, et c'est le point sur lequel une installation échoue :

```
  navigateur  ──(1) api.js, éditeur──►  serveur de documents
       │                                        │
       │                                        │
       └──(2) page /office ──► plateforme ◄──(3)┘
                                        va chercher le fichier modifié
```

1. Le **navigateur** charge le script de l'éditeur : `ONLYOFFICE_URL`.
2. Le **serveur de documents** vient chercher le fichier chez nous, et rend la
   version modifiée : `ONLYOFFICE_PLATFORM_URL`, ou l'hôte de la requête si
   elle est vide.
3. La **plateforme** va chercher le fichier modifié chez le serveur de
   documents, à l'adresse qu'il lui indique.

Le lien 3 est celui qu'on oublie. Une installation où seul le navigateur voit
le serveur de documents **enregistre zéro modification, en silence** : la
séance se ferme normalement, et rien n'a changé. `app:preflight` éprouve
précisément ce lien-là (`/healthcheck`).

### Variables

| Variable | Qui la lit | Ce qu'elle vaut |
|---|---|---|
| `ONLYOFFICE_URL` | le navigateur | `https://docs.exemple.fr`. En **https** si la plateforme est en https, sinon le navigateur refuse le script. |
| `ONLYOFFICE_JWT_SECRET` | les deux | le `JWT_SECRET` du serveur de documents. Vide ⇒ rien n'est signé. |
| `ONLYOFFICE_INTERNAL_URL` | la plateforme | `http://10.0.20.44`. À renseigner dès que la plateforme ne peut pas joindre le serveur de documents à son adresse publique — voir « Une seule adresse publique » ci-dessous. Vide, on garde l'adresse publique. |
| `ONLYOFFICE_PLATFORM_URL` | le serveur de documents | `https://communaute-rnf.fr`. À ne renseigner que si elle diffère de l'adresse publique — un conteneur pour qui « localhost » désigne lui-même. |

### Une seule adresse publique pour les deux services

Le cas le plus fréquent en auto-hébergement, et celui qui casse le lien 3.

`only-office.exemple.fr` et `communaute.exemple.fr` pointent sur la même IP
publique, parce qu'un reverse proxy les distingue au nom d'hôte. De
l'extérieur, tout répond. De l'**intérieur**, joindre cette IP revient à taper
sur sa propre passerelle, et la plupart ne savent pas renvoyer le paquet vers
le réseau interne — le « hairpin NAT » ne se fait pas. La connexion échoue en
une fraction de milliseconde, ce qui la distingue d'un filtrage (qui, lui,
laisse expirer le délai) :

```
curl: (7) Failed to connect ... port 443 after 0 ms: Could not connect to server
```

**Le symptôme, si on ne le traite pas :** tout s'ouvre, tout s'édite, et rien
ne s'enregistre. Le lien 2 souffre du même mal, en sens inverse.

Trois remèdes, du meilleur au moindre :

1. **Le hairpin NAT sur la passerelle.** Un réglage, une fois, et les deux sens
   sont réglés sans rien changer ailleurs.
2. **`ONLYOFFICE_INTERNAL_URL`** pour le lien 3 (`http://10.0.20.44`), et une
   entrée `/etc/hosts` sur le CT du serveur de documents pour le lien 2 —
   faisant pointer le nom de la plateforme vers l'IP interne de ce qui termine
   son TLS. Ne demande rien à la passerelle.
3. **Une entrée `/etc/hosts` de chaque côté**, si et seulement si l'adresse
   interne sert elle-même le TLS avec le bon certificat. Souvent faux : le CT
   du serveur de documents n'écoute qu'en clair sur 80, le TLS étant terminé
   par le reverse proxy.

Pour savoir dans quel cas on est, depuis la plateforme :

```bash
curl -sS -m 5 -o /dev/null -w 'http/80  → %{http_code}\n'    http://10.0.20.44/healthcheck
curl -sS -m 5 -k -o /dev/null -w 'https/443 → %{http_code}\n' https://10.0.20.44/healthcheck
```

`http/80` répond et `https/443` non : c'est le remède 2.

**Le lien 2, en clair et par le réseau interne.** Quand le reverse proxy n'est
joignable que de l'extérieur, le serveur de documents ne peut atteindre la
plateforme que par son IP interne, en HTTP. Deux choses le rendent possible.

`ONLYOFFICE_PLATFORM_URL` porte l'adresse à employer — et il faut y mettre le
**nom d'hôte**, pas l'IP, avec une entrée `/etc/hosts` sur le CT du serveur de
documents qui fait pointer ce nom vers l'IP interne de la plateforme. Une URL
bâtie sur l'IP enverrait `Host: 10.0.200.35`, et Apache servirait son hôte
virtuel par défaut plutôt que celui de la plateforme.

Et les deux routes répondent bien en HTTP là où tout le reste du site est
renvoyé vers HTTPS : leur pare-feu dédié (`security: false`) ne pose aucun
écouteur, donc pas de `ChannelListener`, donc pas de `requires_channel`. Ce
n'est pas un effet de bord à corriger, c'est ce qui rend ce montage possible —
`OnlyOfficeChannelTest` l'éprouve, et vérifie du même coup que la redirection
est bien active pour le reste.

**Une propriété qui vient avec.** Le fichier modifié n'est jamais cherché
ailleurs que sur le serveur de documents configuré : `fetchUrl()` ne garde de
l'adresse annoncée que son chemin, et le repose sur la nôtre. Un rappel forgé
qui désignerait un autre hôte — possible si le secret partagé venait à
manquer — ne ferait donc pas sortir la plateforme de son réseau.

### Installer le serveur de documents

L'édition communautaire d'OnlyOffice suffit ; elle est sous AGPL et se limite à
une vingtaine de connexions simultanées, ce qui couvre largement le réseau.

**Un CT dédié, pas celui de la plateforme.** Ce n'est pas une bibliothèque
qu'on ajoute à côté de PHP : c'est une pile autonome — son propre nginx, un
PostgreSQL, un RabbitMQ, un Redis — qui occupe 2 à 4 Go de RAM en permanence et
voudrait le port 80. Dans le CT de la plateforme, elle entre en collision avec
Apache et fait ramer les pages à chaque pointe d'édition.

Dimensionnement : Debian 12, **2 vCPU, 4 Go de RAM** (2 Go est le minimum
absolu), **40 Go** de disque, 4 Go de swap.

> ⚠️ Les commandes qui suivent donnent la **forme** de l'installation. L'adresse
> du dépôt et la clé de signature d'OnlyOffice changent d'une année à l'autre :
> les reprendre de <https://helpcenter.onlyoffice.com/> au moment de le faire,
> et non d'ici.

#### Chemin A — CT LXC + paquet Debian (recommandé)

Natif, sans Docker, cohérent avec le reste du Proxmox. Le CT doit être
**privilégié** ou porter `nesting=1` : OnlyOffice suppose un systemd complet,
et RabbitMQ comme PostgreSQL s'en servent.

```bash
# Les services dont dépend le serveur de documents
apt update && apt install -y postgresql rabbitmq-server nginx-extras \
                             gnupg2 ca-certificates curl

# La base, avec les noms attendus par défaut
sudo -u postgres psql -c "CREATE DATABASE onlyoffice;"
sudo -u postgres psql -c "CREATE USER onlyoffice WITH PASSWORD 'onlyoffice';"
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE onlyoffice TO onlyoffice;"

# Le dépôt OnlyOffice (vérifier l'URL et la clé sur helpcenter.onlyoffice.com)
curl -fsSL https://download.onlyoffice.com/GPG-KEY-ONLYOFFICE \
  | gpg --dearmor -o /usr/share/keyrings/onlyoffice.gpg
echo "deb [signed-by=/usr/share/keyrings/onlyoffice.gpg] \
https://download.onlyoffice.com/repo/debian squeeze main" \
  > /etc/apt/sources.list.d/onlyoffice.list

# Répondre d'avance aux questions de l'installateur
echo onlyoffice-documentserver onlyoffice/db-host string localhost | debconf-set-selections
echo onlyoffice-documentserver onlyoffice/db-user string onlyoffice | debconf-set-selections
echo onlyoffice-documentserver onlyoffice/db-pwd  password onlyoffice | debconf-set-selections
echo onlyoffice-documentserver onlyoffice/db-name string onlyoffice | debconf-set-selections

apt update && apt install -y onlyoffice-documentserver
```

**Le secret JWT.** Depuis la version 7.2, le paquet l'active tout seul et en
tire un au hasard. Le lire plutôt que d'en poser un :

```bash
documentserver-jwt-status.sh
```

C'est cette valeur qui va dans `ONLYOFFICE_JWT_SECRET`. Pour en imposer une
autre, `documentserver-jwt-status.sh --help` donne la marche à suivre de la
version installée ; le réglage vit dans
`/etc/onlyoffice/documentserver/local.json`, sous
`services.CoAuthoring.secret` et `services.CoAuthoring.token.enable`.

**Ne rien toucher aux ports.** Le CT étant dédié, le nginx d'OnlyOffice garde
le port 80 sur l'IP du CT ; c'est l'Apache de la plateforme qui le publie. Il
n'y a de port à déplacer que si l'on cohabite avec autre chose — ce qu'on
évite précisément.

#### Chemin B — VM + Docker (repli)

Le chemin officiellement documenté par OnlyOffice, à prendre si l'installation
en LXC résiste. Une **VM**, pas un CT : Proxmox déconseille Docker en LXC.

```yaml
# docker-compose.yml
services:
  onlyoffice:
    image: onlyoffice/documentserver:latest
    restart: always
    ports:
      - "80:80"
    environment:
      JWT_ENABLED: "true"
      JWT_SECRET: "le-meme-que-ONLYOFFICE_JWT_SECRET"
      JWT_HEADER: "Authorization"
    volumes:
      - onlyoffice-data:/var/www/onlyoffice/Data
      - onlyoffice-logs:/var/log/onlyoffice
      - onlyoffice-cache:/var/lib/onlyoffice

volumes:
  onlyoffice-data:
  onlyoffice-logs:
  onlyoffice-cache:
```

#### L'hôte virtuel qui le publie

Sur l'Apache qui sert déjà la plateforme. `10.0.0.42` est à remplacer par l'IP
du CT ; c'est la seule chose qui change entre les deux chemins.

```apache
<VirtualHost *:443>
    ServerName docs.exemple.fr

    # a2enmod proxy proxy_http proxy_wstunnel rewrite headers

    # L'éditeur parle en WebSocket ; sans ces deux lignes il se charge,
    # affiche le document, et ne rend jamais la main.
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} =websocket [NC]
    RewriteRule /(.*) ws://10.0.0.42/$1 [P,L]

    ProxyPass        / http://10.0.0.42/
    ProxyPassReverse / http://10.0.0.42/
    ProxyPreserveHost On
    RequestHeader set X-Forwarded-Proto "https"

    SSLEngine on
    # SSLCertificateFile / SSLCertificateKeyFile
</VirtualHost>
```

**Le CT ne doit pas être joignable autrement.** Son nginx écoute en clair : le
pare-feu Proxmox n'ouvre son port 80 qu'à l'IP de la plateforme et, si les
postes sont sur le même réseau, à eux. Rien ne sort vers Internet sans passer
par cet hôte virtuel.

#### Puis, côté plateforme

Dans `.env.local` :

```bash
ONLYOFFICE_URL=https://docs.exemple.fr
ONLYOFFICE_JWT_SECRET=<ce que rend documentserver-jwt-status.sh>
ONLYOFFICE_PLATFORM_URL=
```

Puis vider le cache et contrôler :

```bash
php bin/console cache:clear
php bin/console app:preflight
```

Trois lignes à lire dans la sortie : `Serveur de documents … joignable depuis
la plateforme` (c'est le lien 3), `ONLYOFFICE_JWT_SECRET … renseigné`, et
l'absence de tout blocage par ailleurs.

### En développement

Le serveur de documents tourne dans un conteneur ; « localhost » y désigne le
conteneur, pas le poste :

```bash
ONLYOFFICE_URL=http://localhost:8081
ONLYOFFICE_JWT_SECRET=
ONLYOFFICE_PLATFORM_URL=http://host.docker.internal:8000
```

…et le conteneur lancé avec `--add-host=host.docker.internal:host-gateway`
sous Linux.

---

## Ce qui se modifie, et ce qui ne fait que s'ouvrir

| | |
|---|---|
| **Modifiables** | `.docx` `.xlsx` `.pptx` `.odt` `.ods` `.odp` `.rtf` `.txt` `.csv` (et quelques variantes : `.docm`, `.xlsm`, `.pptm`, `.ott`, `.ots`, `.otp`, `.dotx`, `.xltx`, `.potx`) |
| **Lecture seule** | `.doc` `.xls` `.ppt` — les modifier reviendrait à les convertir, donc à changer le format du fichier sous les pieds de celui qui l'a déposé. La page le dit avant d'ouvrir l'éditeur. |
| **Pas concernés** | Les **PDF** : le navigateur les affiche seul, sans dépendance et sans aller-retour. Les images aussi. |

Le droit de modifier est celui de la fiche, et rien d'autre : l'auteur du
document et les animateurs du groupe (`GroupDocumentVoter::EDIT`). Un lecteur
ouvre l'éditeur en consultation.

---

## Comment c'est fait, et ce qu'il ne faut pas « simplifier »

- **Le serveur de documents n'a pas de session**, et il a pire : il signe ses
  appels avec un entête `Authorization: Bearer`, sur lequel
  `RnfAuthenticatorGuard` se déclenche — il répond OUI à *toute* requête qui en
  porte un. Dans le pare-feu principal, le callback partait s'authentifier
  contre GeoNature et n'atteignait jamais son contrôleur : l'enregistrement
  était perdu, sans une ligne dans les journaux. `/office/{token}/content` et
  `/office/{token}/callback` vivent donc dans **leur propre pare-feu**,
  `security: false`. Ce qui les protège est le jeton signé qu'elles portent
  (`OnlyOfficeToken`) : identifiant du document, droit, échéance, signature par
  le secret de l'application.
- **Le droit est dans le jeton.** La configuration de l'éditeur est rendue dans
  la page, donc lue par celui qui regarde. Un lecteur reçoit un jeton de
  lecture seule, et la route d'enregistrement refuse — sans quoi ouvrir un
  document en consultation donnerait de quoi le réécrire. Le voteur est
  consulté **une fois**, au moment d'ouvrir la page.
- **`callbackUrl` n'est mis dans la configuration que pour une séance
  d'édition.** Pas pour une consultation.
- **On ne répond `{"error":0}` qu'après avoir vraiment enregistré.** Le serveur
  de documents jette sa copie sur cette réponse : la donner d'avance, c'est
  perdre le travail d'une séance. En cas d'échec, il réessaie.
- **`document.key` change à chaque enregistrement.** C'est l'identifiant de
  version que le serveur de documents met en cache ; s'il ne change pas, il
  rouvre la version précédente et l'enregistre par-dessus la nouvelle.
- **Enregistrer crée un nouveau fichier et efface l'ancien ensuite**, jamais
  l'inverse : si l'écriture échouait, on perdrait les deux. C'est le
  remplacement de fichier déjà en place à l'édition d'une fiche (#41).

## Ce que l'édition à plusieurs sait faire, et ce qu'elle ne sait pas

Deux personnes qui ouvrent le même document **en même temps** le modifient
ensemble : c'est le serveur de documents qui les met en commun, autour de
`document.key`.

La limite, et elle est la même chez tous ceux qui intègrent OnlyOffice de cette
façon : **cette clé change à chaque enregistrement**, puisqu'elle suit le
fichier — c'est ce qui empêche l'éditeur de rouvrir une version périmée depuis
son cache. Quelqu'un qui ouvre le document *après* un enregistrement, alors
qu'une séance est encore en cours, entre donc dans une séance **séparée**, et
le dernier des deux à fermer écrase l'autre.

Sur un réseau de cette taille, où les documents se relaient plus qu'ils ne
s'écrivent à quatre mains, c'est un cas rare. Il vaut mieux le savoir que le
découvrir : pour une rédaction vraiment simultanée, ouvrez le document
**ensemble**, au début.

## Quand ça ne marche pas

| Ce qu'on voit | Ce que c'est |
|---|---|
| Cadre blanc, rien ne se charge | Le navigateur ne joint pas `ONLYOFFICE_URL`. Page en https et serveur de documents en http : le navigateur refuse le script sans rien dire. Au bout de quinze secondes la page l'annonce. |
| « Le document n'a pas pu être téléchargé » dans l'éditeur | Le serveur de documents ne joint pas la plateforme. Renseigner `ONLYOFFICE_PLATFORM_URL`. |
| Tout s'ouvre et se modifie, mais rien n'est enregistré | Lien 3 : la plateforme ne joint pas le serveur de documents. `app:preflight`, puis les journaux de la plateforme — l'échec y est écrit. Si les deux services partagent une adresse publique, voir « Une seule adresse publique ». |
| `app:preflight` dit « joignable », et pourtant rien ne s'enregistre | Le lien 3 se fait en **deux temps** : joindre le serveur, puis aller chercher le fichier à l'adresse qu'il indique — et cette adresse-là, il la fabrique avec le nom public (`docs.exemple.fr`), pas avec son IP. La plateforme doit donc résoudre ce nom **et** l'atteindre, en repassant par l'hôte virtuel. Un DNS interne qui ne connaît pas le sous-domaine, ou un certificat que le CT de la plateforme ne valide pas, coupe là. À éprouver depuis le CT de la plateforme : `curl -I https://docs.exemple.fr/healthcheck`. |
| « Le jeton n'est pas valide » | `ONLYOFFICE_JWT_SECRET` diffère du `JWT_SECRET` du serveur de documents. |
| L'éditeur se charge puis se fige | Le WebSocket ne passe pas dans l'hôte virtuel. |
| L'éditeur reste sur son écran de chargement, et **rien** n'apparaît dans les journaux du serveur de documents | Regarder la console du navigateur. Rencontré en préproduction : une extension bloquait `Analytics.js` — servi correctement (HTTP 200 en direct), mais refusé côté poste parce que son nom ressemble à du pistage. Le chargement d'OnlyOffice attend ce script. Fenêtre de navigation privée, ou autoriser le domaine de l'éditeur dans l'extension. La page l'annonce d'elle-même au bout de trente secondes. |
| L'éditeur s'installe dans un cadre écrasé | `DocsAPI.DocEditor` **remplace** l'élément qu'on lui désigne par une iframe qui en reprend l'identifiant, pas la classe. La hauteur doit donc être portée par le cadre parent (`.document-office`), jamais par l'élément remplacé — sinon le cadre s'effondre au moment où l'éditeur s'installe, et ses propres messages d'erreur deviennent invisibles. |
| L'aperçu d'un PDF reste blanc | L'aperçu est une `iframe` de même origine. Un `X-Frame-Options: DENY` posé par le serveur web la bloque ; `SAMEORIGIN` convient. |
