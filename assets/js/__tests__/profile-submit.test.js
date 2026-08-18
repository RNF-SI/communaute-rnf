/**
 * Issue #10 — the profile form never reached the server.
 *
 * The submit handler cancels the native submission to geocode the city first,
 * then submits by hand. When the geocoding call failed, nothing resubmitted
 * the form and every change was silently lost.
 */

const buildForm = () => {
	document.body.innerHTML = `
		<form name="user_profile">
			<input name="user_profile[city]" value="Valenciennes">
			<input name="user_profile[country]" value="FR">
			<input name="user_profile[zipcode]" value="">
			<input name="user_profile[latitude]" value="">
			<input name="user_profile[longitude]" value="">
			<button type="submit">Enregistrer</button>
		</form>`;

	const form = document.querySelector( 'form' );

	// jsdom does not implement submission; spy on it instead.
	form.submit = jest.fn();

	return form;
};

const loadModule = () => {
	jest.isolateModules( () => {
		require( '../user/profile' );
	} );
};

const submit = ( form ) => {
	form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );

	// Let the geocoding promise chain settle.
	return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
};

describe( 'profile form submission', () => {
	beforeEach( () => {
		jest.resetModules();
		global.fetch = jest.fn();
	} );

	test( 'submits once the coordinates are found', async () => {
		const form = buildForm();
		global.fetch.mockResolvedValue( {
			ok:   true,
			json: () => Promise.resolve( [ { lat: '50.35', lon: '3.52' } ] ),
		} );
		loadModule();

		await submit( form );

		expect( form.submit ).toHaveBeenCalledTimes( 1 );
		expect( form.querySelector( '[name="user_profile[latitude]"]' ).value ).toBe( '50.35' );
		expect( form.querySelector( '[name="user_profile[longitude]"]' ).value ).toBe( '3.52' );
	} );

	test( 'still submits when the geocoding service is unreachable', async () => {
		const form = buildForm();
		global.fetch.mockRejectedValue( new Error( 'network down' ) );
		loadModule();

		await submit( form );

		expect( form.submit ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'still submits when the geocoding service answers an error', async () => {
		const form = buildForm();
		global.fetch.mockResolvedValue( { ok: false } );
		loadModule();

		await submit( form );

		expect( form.submit ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'still submits when the city is unknown', async () => {
		const form = buildForm();
		global.fetch.mockResolvedValue( { ok: true, json: () => Promise.resolve( [] ) } );
		loadModule();

		await submit( form );

		expect( form.submit ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'submits without geocoding when no city is filled in', async () => {
		const form = buildForm();
		form.querySelector( '[name="user_profile[city]"]' ).value = '';
		loadModule();

		await submit( form );

		expect( form.submit ).toHaveBeenCalledTimes( 1 );
		expect( global.fetch ).not.toHaveBeenCalled();
	} );
} );
