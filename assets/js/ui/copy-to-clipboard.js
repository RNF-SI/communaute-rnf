import domready from 'mf-js/modules/dom/ready';

domready( () => Array.from( document.querySelectorAll( '[data-copy-value]' ) ).forEach( ( button ) => {
	const value        = button.dataset.copyValue;
	const doneLabel    = button.dataset.copyDoneLabel;
	const initialLabel = button.innerHTML;

	const feedback = () => {
		button.innerHTML = doneLabel;
		window.setTimeout( () => {
			button.innerHTML = initialLabel;
		}, 2000 );
	};

	button.addEventListener( 'click', ( event ) => {
		event.preventDefault();

		if( navigator.clipboard ) {
			navigator.clipboard.writeText( value ).then( feedback );

			return;
		}

		// Fallback for browsers without the clipboard API, or on plain http
		const input = document.createElement( 'input' );
		input.value = value;
		document.body.appendChild( input );
		input.select();
		document.execCommand( 'copy' );
		document.body.removeChild( input );

		feedback();
	} );
} ) );
