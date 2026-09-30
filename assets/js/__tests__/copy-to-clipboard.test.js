/**
 * Issue #42 (6) — le bouton « Copier » de la fiche membre est devenu un
 * picto légendé. Dire « copié » ne doit changer que la légende : remplacer
 * tout le contenu du bouton, comme avant, effaçait l'icône.
 */

import { attachCopy } from '../ui/copy-to-clipboard';

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

describe( 'copier une adresse', () => {
	beforeEach( () => {
		jest.useRealTimers();
		Object.assign( navigator, { clipboard: { writeText: jest.fn( () => Promise.resolve() ) } } );
	} );

	test( 'copie la valeur, et ne change que la légende', async () => {
		document.body.innerHTML = `
			<button data-copy-value="jeanne@example.org" data-copy-done-label="Adresse copiée !">
				<svg><use></use></svg>
				<span data-copy-label>Copier</span>
			</button>`;

		const button = document.querySelector( 'button' );
		attachCopy( button );

		button.click();
		await flush();

		expect( navigator.clipboard.writeText ).toHaveBeenCalledWith( 'jeanne@example.org' );
		expect( button.querySelector( '[data-copy-label]' ).textContent ).toBe( 'Adresse copiée !' );
		expect( button.querySelector( 'svg' ) ).not.toBeNull();
		expect( button.classList.contains( 'is-copied' ) ).toBe( true );
	} );

	test( 'la légende revient au bout de deux secondes', async () => {
		jest.useFakeTimers();

		document.body.innerHTML = '<button data-copy-value="x" data-copy-done-label="Copié"><span data-copy-label>Copier</span></button>';

		const button = document.querySelector( 'button' );
		attachCopy( button );
		button.click();

		await Promise.resolve();
		await Promise.resolve();

		jest.advanceTimersByTime( 2000 );

		expect( button.textContent ).toBe( 'Copier' );
		expect( button.classList.contains( 'is-copied' ) ).toBe( false );
	} );

	test( 'sans légende désignée, le bouton entier la porte', async () => {
		document.body.innerHTML = '<button data-copy-value="x" data-copy-done-label="Copié">Copier l’adresse</button>';

		const button = document.querySelector( 'button' );
		attachCopy( button );
		button.click();
		await flush();

		expect( button.textContent ).toBe( 'Copié' );
	} );
} );
