/**
 * Issue #16 — the skills list stayed invisible until a letter was typed.
 *
 * The source function split the query into keywords and kept only the entries
 * matching at least one of them. An empty query produced no keyword, therefore
 * no match, therefore an empty list.
 */

const SKILLS = [ 'botanique', 'mycologie', 'pâturage', 'géologie', 'protocoles', 'hydrologie' ];

const buildWidget = ( checked = [] ) => {
	document.body.innerHTML = `
		<div class="checkboxes-autocomplete">
			${ SKILLS.map( ( skill, index ) => `
				<input type="checkbox" id="skill-${ index }" value="${ skill }"
					${ checked.includes( skill ) ? 'checked' : '' }>
				<label for="skill-${ index }">${ skill }</label>
			` ).join( '' ) }
		</div>`;
};

// The module under test is loaded in an isolated registry, so the mock it
// receives is a fresh copy: it has to be read from inside that same scope.
const loadModule = () => {
	let widget;

	jest.isolateModules( () => {
		const autocomplete = require( 'autocomplete.js' );

		autocomplete.reset();
		require( '../ui/input-checkboxes-autocomplete' );

		widget = autocomplete.instances[ 0 ];
	} );

	return widget;
};

const labelsFor = async ( widget, query ) => {
	const suggestions = await widget.suggest( query );

	return suggestions.map( ( item ) => item.label );
};

describe( 'skills autocomplete', () => {
	beforeEach( () => {
		jest.resetModules();
	} );

	test( 'offers the whole list when nothing is typed yet', async () => {
		buildWidget();

		expect( await labelsFor( loadModule(), '' ) ).toEqual( SKILLS );
	} );

	test( 'offers the whole list when only spaces are typed', async () => {
		buildWidget();

		expect( await labelsFor( loadModule(), '   ' ) ).toEqual( SKILLS );
	} );

	test( 'opens on focus so the list is reachable without typing', () => {
		buildWidget();

		expect( loadModule().options.openOnFocus ).toBe( true );
	} );

	test( 'does not cap the number of suggestions below the number of skills', () => {
		buildWidget();

		expect( loadModule().datasets[ 0 ].limit ).toBe( SKILLS.length );
	} );

	test( 'still filters on what is typed', async () => {
		buildWidget();

		expect( await labelsFor( loadModule(), 'log' ) ).toEqual( [ 'mycologie', 'géologie', 'hydrologie' ] );
	} );

	test( 'ignores accents and case when filtering', async () => {
		buildWidget();

		expect( await labelsFor( loadModule(), 'PATURAGE' ) ).toEqual( [ 'pâturage' ] );
	} );

	test( 'shows the skills already chosen as tags', () => {
		buildWidget( [ 'botanique', 'géologie' ] );
		loadModule();

		const tags = Array.from( document.querySelectorAll( '.tags .tag span' ) )
						  .map( ( tag ) => tag.innerHTML );

		expect( tags ).toEqual( [ 'botanique', 'géologie' ] );
	} );
} );
