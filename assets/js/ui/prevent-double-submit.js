import domready from 'mf-js/modules/dom/ready';

/**
 * A double click on a submit button posts the form twice: two messages in the
 * discussion, and two notification e-mails for every member. Only the first
 * submission of a given form is let through. (#12)
 *
 * Programmatic submissions — form.submit() — do not fire this event, so the
 * forms that submit themselves after some asynchronous work are untouched.
 */
domready( () => Array.from( document.querySelectorAll( 'form' ) ).forEach( ( form ) => {
	let submitted = false;

	form.addEventListener( 'submit', ( event ) => {
		if ( submitted ) {
			event.preventDefault();

			return;
		}

		submitted = true;
	} );
} ) );
