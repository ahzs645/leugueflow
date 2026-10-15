/**
 * LeagueFlow admin enhancements. No dependencies; every control works without it.
 *
 * Long user/team selects get a filter box: typing hides options that do not
 * match (selected options always stay visible) and announces how many match.
 * "Select all listed" boxes tick every record checkbox in their form.
 */
( function () {
	'use strict';

	function enhance( select ) {
		if ( select.getAttribute( 'data-leagueflow-search-ready' ) ) {
			return;
		}

		select.setAttribute( 'data-leagueflow-search-ready', '1' );

		var wrap = document.createElement( 'span' );
		var input = document.createElement( 'input' );
		var status = document.createElement( 'span' );
		var countText = select.getAttribute( 'data-leagueflow-search-count' ) || '%d matching options';

		wrap.className = 'leagueflow-select-search';
		input.type = 'search';
		input.className = 'leagueflow-select-search__input';
		input.setAttribute( 'aria-label', select.getAttribute( 'data-leagueflow-search-label' ) || 'Filter options' );
		input.setAttribute( 'placeholder', select.getAttribute( 'data-leagueflow-search-placeholder' ) || '' );
		input.setAttribute( 'autocomplete', 'off' );

		if ( select.id ) {
			input.id = select.id + '-search';
			input.setAttribute( 'aria-controls', select.id );
		}

		status.className = 'screen-reader-text';
		status.setAttribute( 'aria-live', 'polite' );

		wrap.appendChild( input );
		wrap.appendChild( status );
		select.parentNode.insertBefore( wrap, select );

		function filter() {
			var query = input.value.trim().toLowerCase();
			var shown = 0;

			Array.prototype.forEach.call( select.options, function ( option ) {
				var keep = '' === option.value || option.selected || '' === query || -1 !== option.text.toLowerCase().indexOf( query );

				option.hidden = ! keep;

				if ( keep && '' !== option.value ) {
					shown++;
				}
			} );

			status.textContent = '' === query ? '' : countText.replace( '%d', String( shown ) );
		}

		input.addEventListener( 'input', filter );
		input.addEventListener( 'keydown', function ( event ) {
			// Enter filters; it must not submit the surrounding post form.
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				filter();
			}
		} );
		select.addEventListener( 'change', filter );
	}

	// "Select all listed" checkboxes tick or clear the named checkboxes in the same form.
	function selectAll( box ) {
		box.addEventListener( 'change', function () {
			var form = box.form;
			var name = box.getAttribute( 'data-leagueflow-select-all' );

			if ( ! form ) {
				return;
			}

			Array.prototype.forEach.call( form.querySelectorAll( 'input[type="checkbox"]' ), function ( input ) {
				if ( input.name === name && ! input.disabled ) {
					input.checked = box.checked;
				}
			} );
		} );
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( 'select[data-leagueflow-searchable]' ), enhance );
		Array.prototype.forEach.call( document.querySelectorAll( 'input[data-leagueflow-select-all]' ), selectAll );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
