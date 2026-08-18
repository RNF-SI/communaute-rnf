module.exports = {
	testEnvironment: 'jsdom',
	testMatch:       [ '<rootDir>/assets/js/**/__tests__/**/*.test.js' ],
	transform:       {
		'^.+\\.js$': [ 'babel-jest', { presets: [ [ '@babel/preset-env', { targets: { node: 'current' } } ] ] } ],
	},
	moduleNameMapper: {
		// The DOM-ready helper and the autocomplete widget are third-party
		// code; the tests drive our own modules, not theirs.
		'^mf-js/modules/dom/ready$': '<rootDir>/assets/js/__mocks__/domready.js',
		'^autocomplete\\.js$':       '<rootDir>/assets/js/__mocks__/autocomplete.js',
		'^places\\.js$':             '<rootDir>/assets/js/__mocks__/places.js',
	},
};
