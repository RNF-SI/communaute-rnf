import domready from 'mf-js/modules/dom/ready';

/**
 * Un bouton qui copie une valeur, et le dit.
 *
 * Seule la légende change le temps de le dire : un bouton à picto (#42)
 * perdrait son icône si l'on remplaçait tout son contenu. Sans légende
 * désignée (`data-copy-label`), c'est le bouton entier qui la porte.
 *
 * @param {HTMLElement} button
 */
export function attachCopy ( button ) {
	const value     = button.dataset.copyValue;
	const doneLabel = button.dataset.copyDoneLabel;
	const label     = button.querySelector( '[data-copy-label]' ) || button;
	const initial   = label.textContent;

	const feedback = () => {
		label.textContent = doneLabel;
		button.classList.add( 'is-copied' );

		window.setTimeout( () => {
			label.textContent = initial;
			button.classList.remove( 'is-copied' );
		}, 2000 );
	};

	button.addEventListener( 'click', ( event ) => {
		event.preventDefault();

		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( value ).then( feedback );

			return;
		}

		// Sans l'API presse-papiers, ou en http simple.
		const input = document.createElement( 'input' );
		input.value = value;
		document.body.appendChild( input );
		input.select();
		document.execCommand( 'copy' );
		document.body.removeChild( input );

		feedback();
	} );
}

domready( () => Array.from( document.querySelectorAll( '[data-copy-value]' ) ).forEach( attachCopy ) );
