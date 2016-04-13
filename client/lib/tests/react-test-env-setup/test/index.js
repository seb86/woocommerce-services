import { jsdom } from 'jsdom';
import { expect } from 'chai';

const setupEnvironment = require( '../' );

describe( 'react-test-env-setupEnvironment', function() {
	afterEach( function() {
		delete global.window;
		delete global.document;
		delete global.navigator;
		delete global.XMLHttpRequest;
	} );

	it( 'should accept an empty set of parameters', function() {
		setupEnvironment();

		expect( global.window ).to.not.be.undefined;
		expect( global.document ).to.not.be.undefined;
		expect( global.navigator ).to.not.be.undefined;
		expect( global.document.body ).to.be.an.instanceof( jsdom().defaultView.window.Element );
		expect( global.document.body.innerHTML ).to.be.empty;
	} );

	it( 'should accept a markup string to be used as the document body', function() {
		var markup = '<html><head></head><body><div id="hello-world"></div></body></html>';
		setupEnvironment( markup );

		expect( global.window ).to.not.be.undefined;
		expect( global.document ).to.not.be.undefined;
		expect( global.navigator ).to.not.be.undefined;
		expect( global.document.documentElement.outerHTML ).to.equal( markup );
	} );

	it( 'should add support for XMLHttpRequest by default', function() {
		setupEnvironment();

		expect( global.XMLHttpRequest ).to.be.a( 'function' );
	} );

	it( 'should allow you to disable one or more additional features', function() {
		setupEnvironment( null, {
			XMLHttpRequest: false,
		} );

		expect( global.XMLHttpRequest ).to.be.undefined;
	} );
} );