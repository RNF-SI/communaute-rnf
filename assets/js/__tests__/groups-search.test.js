/**
 * Issue #23 — les deux filtres de la liste des groupes.
 *
 * La commission dit de qui un groupe dépend, la thématique de quoi il parle.
 * Ils se cumulent, donc chacun doit conserver l'autre : c'est exactement ce
 * qui se perd quand on ajoute un second filtre à côté d'un premier écrit pour
 * être seul. Le rechargement de la liste se fait sans quitter la page, si bien
 * que rien ne rattrape un paramètre oublié.
 */

const buildPage = ( { commission = '', theme = '', withTheme = true } = {} ) => {
	document.body.innerHTML = `
		<input id="form_groups_search_bar" value="">
		<form id="groups-filter">
			<select id="groups-filter-commission" name="commission">
				<option value="" ${ commission === '' ? 'selected' : '' }></option>
				<option value="commission-de-test" ${ commission === 'commission-de-test' ? 'selected' : '' }></option>
			</select>
			${ withTheme ? `
			<select id="groups-filter-theme" name="theme">
				<option value="" ${ theme === '' ? 'selected' : '' }></option>
				<option value="accueil-du-public" ${ theme === 'accueil-du-public' ? 'selected' : '' }></option>
			</select>` : '' }
		</form>
		<div id="all-groups-container"></div>
		<div id="groups-to-activate-elements"></div>`;
};

const loadModule = () => {
	jest.isolateModules( () => {
		require( '../ui/groups-search' );
	} );
};

const change = ( id, value ) => {
	const select = document.getElementById( id );

	select.value = value;
	select.dispatchEvent( new Event( 'change' ) );

	return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
};

/**
 * @return {string[]} les adresses appelées, dans l'ordre
 */
const calls = () => global.fetch.mock.calls.map( ( call ) => call[ 0 ] );

describe( 'groups list filters', () => {
	beforeEach( () => {
		jest.resetModules();

		global.fetch = jest.fn( () => Promise.resolve( {
			json: () => Promise.resolve( { groups: '' } ),
		} ) );

		window.history.replaceState( {}, '', '/groups' );
	} );

	test( 'changing the commission carries the theme already chosen', async () => {
		buildPage( { theme: 'accueil-du-public' } );
		loadModule();

		await change( 'groups-filter-commission', 'commission-de-test' );

		expect( calls().length ).toBeGreaterThan( 0 );
		calls().forEach( ( url ) => {
			expect( url ).toContain( 'commission=commission-de-test' );
			expect( url ).toContain( 'theme=accueil-du-public' );
		} );
	} );

	test( 'changing the theme carries the commission already chosen', async () => {
		buildPage( { commission: 'commission-de-test' } );
		loadModule();

		await change( 'groups-filter-theme', 'accueil-du-public' );

		calls().forEach( ( url ) => {
			expect( url ).toContain( 'commission=commission-de-test' );
			expect( url ).toContain( 'theme=accueil-du-public' );
		} );
	} );

	test( 'the address keeps both filters, so a reload shows the same list', async () => {
		buildPage( { commission: 'commission-de-test' } );
		loadModule();

		await change( 'groups-filter-theme', 'accueil-du-public' );

		const url = new URL( window.location.href );

		expect( url.searchParams.get( 'theme' ) ).toBe( 'accueil-du-public' );
	} );

	test( 'emptying a filter drops it from the address instead of sending nothing', async () => {
		buildPage( { theme: 'accueil-du-public' } );
		loadModule();

		await change( 'groups-filter-theme', '' );

		const url = new URL( window.location.href );

		expect( url.searchParams.has( 'theme' ) ).toBe( false );
	} );

	/**
	 * Le vocabulaire est vide tant que les administrateurs ne l'ont pas
	 * rempli : la seconde liste n'est alors pas affichée du tout, et le
	 * premier filtre doit continuer de fonctionner seul.
	 */
	test( 'the commission filter still works when no theme exists', async () => {
		buildPage( { withTheme: false } );
		loadModule();

		await change( 'groups-filter-commission', 'commission-de-test' );

		expect( calls().length ).toBeGreaterThan( 0 );
		calls().forEach( ( url ) => {
			expect( url ).toContain( 'commission=commission-de-test' );
		} );
	} );
} );
