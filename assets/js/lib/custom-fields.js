/**
 * Jetonomy — shared custom-field collector.
 *
 * Single source of truth for turning Pro custom-field inputs
 * (jt_cf[<slug>] / jt_cf[<slug>][]) inside a scope element into the
 * { slug: value } `custom_fields` map the REST layer expects. Used by the post
 * composer + inline post editor (view.js, a script-module) and the create/edit
 * space forms (new-space.js, space-edit.js, classic scripts). Exposed as a
 * window global so the module and the classic scripts share ONE implementation
 * instead of each carrying its own copy.
 *
 * Behaviour (matches Pro's validate/sanitize/upsert expectations):
 * - text/select/textarea → the field value.
 * - radio → the checked value; '' when none selected.
 * - single checkbox → its value when checked, else ''.
 * - multi checkbox (name ends with []) → checked values comma-joined; '' when
 *   none checked, so the server can clear a previously-set value.
 *
 * Returns {} when there are no jt_cf inputs (custom-fields extension off) or
 * when handed a bad scope, so callers can safely omit an empty map.
 */
( function () {
	'use strict';

	window.jetonomyCollectCustomFields = function ( scope ) {
		var cf = {};
		if ( ! scope || typeof scope.querySelectorAll !== 'function' ) {
			return cf;
		}
		scope.querySelectorAll( '[name^="jt_cf["]' ).forEach( function ( input ) {
			var m = input.name.match( /^jt_cf\[([^\]]+)\](\[\])?$/ );
			if ( ! m ) { return; }
			var slug = m[ 1 ];
			var isMulti = m[ 2 ] === '[]';
			if ( input.type === 'checkbox' ) {
				if ( isMulti ) {
					if ( input.checked ) {
						cf[ slug ] = cf[ slug ] ? cf[ slug ] + ',' + input.value : input.value;
					} else if ( ! ( slug in cf ) ) {
						cf[ slug ] = '';
					}
				} else {
					cf[ slug ] = input.checked ? input.value : '';
				}
			} else if ( input.type === 'radio' ) {
				if ( input.checked ) {
					cf[ slug ] = input.value;
				} else if ( ! ( slug in cf ) ) {
					cf[ slug ] = '';
				}
			} else {
				cf[ slug ] = input.value;
			}
		} );
		return cf;
	};
}() );
