/**
 * Ce que la barre d'outils de l'éditeur propose. (#32)
 *
 * Elle comptait treize groupes de boutons, hérités tels quels de NaturAdapt :
 * exposant et indice, sens d'écriture de droite à gauche, bloc de code,
 * quatre tailles de texte arbitraires. Chercher « mettre en gras » au milieu
 * de tout ça, c'est le « pas très ergonomique, ni intuitif » du retour de
 * Simon Lebret.
 *
 * Ce qui reste est ce dont on se sert pour écrire un compte rendu ou une page
 * de groupe. Ce qui part n'a pas d'usage dans le réseau (le sens d'écriture,
 * le bloc de code), ou fabrique des pages qui ne se ressemblent pas d'une
 * fois sur l'autre (les tailles libres, alors que les titres disent déjà la
 * hiérarchie).
 */
export const TOOLBAR = [
	[ { header: [ 1, 2, 3, false ] } ],
	[ 'bold', 'italic', 'underline' ],
	[ { list: 'ordered' }, { list: 'bullet' } ],
	[ { indent: '-1' }, { indent: '+1' } ],
	[ 'blockquote' ],
	[ { color: [] }, { background: [] } ],
	[ { align: [] } ],
	[ 'link', 'image', 'video' ],
	[ 'clean' ],
];

/**
 * Ce que l'éditeur accepte de **garder** dans un contenu.
 *
 * Volontairement plus large que la barre d'outils : un bouton retiré ne doit
 * pas effacer ce que des pages contiennent déjà. Quill supprime en silence
 * tout format absent de cette liste au chargement du contenu — retirer
 * « size » d'ici rabattrait les textes déjà agrandis, sans prévenir personne.
 *
 * On ne peut donc plus ajouter un exposant ; celui qui existe déjà survit.
 */
export const FORMATS = [
	'header', 'bold', 'italic', 'underline', 'strike',
	'blockquote', 'code-block', 'list', 'bullet',
	'script', 'indent', 'direction', 'size',
	'color', 'background', 'align',
	'link', 'image', 'video',
];

/**
 * Le nom de format derrière une entrée de la barre d'outils : « bold », ou
 * la clé unique d'un objet comme `{ header: [...] }`.
 *
 * @param {string|Object} button
 * @returns {string}
 */
export function formatOf (button) {
	return (typeof button === 'string') ? button : Object.keys(button)[0];
}

/**
 * Tous les formats que la barre d'outils propose, à plat.
 *
 * @returns {string[]}
 */
export function toolbarFormats () {
	return TOOLBAR.reduce((formats, group) => formats.concat(group.map(formatOf)), []);
}
