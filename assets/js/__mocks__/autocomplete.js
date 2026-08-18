// Minimal stand-in for autocomplete.js. It records the options and datasets it
// was built with, so the tests can call the `source` function directly and
// check what the widget would display.
const instances = [];

const autocomplete = ( input, options, datasets ) => {
	const instance = {
		input,
		options,
		datasets,
		handlers: {},
		on ( event, handler ) {
			this.handlers[ event ] = handler;

			return this;
		},
		// Runs the first dataset's source and hands back the suggestions.
		suggest ( query ) {
			return new Promise( ( resolve ) => datasets[ 0 ].source( query, resolve ) );
		},
	};

	instances.push( instance );

	return instance;
};

autocomplete.instances = instances;
autocomplete.reset     = () => instances.splice( 0, instances.length );

module.exports         = autocomplete;
module.exports.default = autocomplete;
