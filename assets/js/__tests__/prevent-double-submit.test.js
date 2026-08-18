/**
 * Issue #12 — a double click on "envoyer" posted the discussion form twice,
 * recording the message twice and notifying every member twice.
 */

const buildForm = () => {
	document.body.innerHTML = '<form><button type="submit">Envoyer</button></form>';

	return document.querySelector( 'form' );
};

const loadModule = () => {
	jest.isolateModules( () => {
		require( '../ui/prevent-double-submit' );
	} );
};

const submit = ( form ) => {
	const event = new Event( 'submit', { bubbles: true, cancelable: true } );

	form.dispatchEvent( event );

	return !event.defaultPrevented;
};

describe( 'double submission guard', () => {
	beforeEach( () => {
		jest.resetModules();
	} );

	test( 'lets the first submission through', () => {
		const form = buildForm();
		loadModule();

		expect( submit( form ) ).toBe( true );
	} );

	test( 'blocks every later submission of the same form', () => {
		const form = buildForm();
		loadModule();

		submit( form );

		expect( submit( form ) ).toBe( false );
		expect( submit( form ) ).toBe( false );
	} );

	test( 'guards each form independently', () => {
		document.body.innerHTML = `
			<form id="first"><button type="submit">Envoyer</button></form>
			<form id="second"><button type="submit">Envoyer</button></form>`;
		loadModule();

		const first  = document.getElementById( 'first' );
		const second = document.getElementById( 'second' );

		submit( first );

		expect( submit( first ) ).toBe( false );
		expect( submit( second ) ).toBe( true );
	} );
} );
