/**
 * Attaches a submit handler to every [data-scc-form] on the page and posts
 * to the REST endpoint registered by SCC_REST_Controller. No-ops entirely
 * if there's no such element (most page loads) or if sccForms wasn't
 * localized (asset somehow enqueued without site-core.php's wp_localize_script).
 */
( function () {
	if ( typeof window.sccForms === 'undefined' ) {
		return;
	}

	function onReady( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function setStatus( form, type, message ) {
		var el = form.querySelector( '.scc-form-status' );
		if ( ! el ) {
			el = document.createElement( 'div' );
			el.className = 'scc-form-status';
			form.appendChild( el );
		}
		el.textContent = message || '';
		el.setAttribute( 'data-type', type );
	}

	function handleSubmit( event ) {
		var form = event.target;
		var slug = form.getAttribute( 'data-scc-form' );
		if ( ! slug ) {
			return;
		}
		event.preventDefault();

		var submitBtn = form.querySelector( '[type="submit"]' );
		if ( submitBtn ) {
			submitBtn.disabled = true;
		}

		var formData = new FormData( form );
		var payload = {};
		formData.forEach( function ( value, key ) {
			payload[ key ] = value;
		} );

		fetch( window.sccForms.restUrl + '/' + encodeURIComponent( slug ), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': window.sccForms.nonce,
			},
			body: JSON.stringify( payload ),
		} )
			.then( function ( res ) {
				return res.json().then( function ( data ) {
					return { ok: res.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				var data = result.data;
				if ( data.success ) {
					if ( data.redirect ) {
						window.location.href = data.redirect;
						return;
					}
					setStatus( form, 'success', data.message );
					form.reset();
				} else {
					setStatus( form, 'error', data.message || 'Something went wrong, please try again.' );
				}
			} )
			.catch( function () {
				setStatus( form, 'error', 'Something went wrong, please try again.' );
			} )
			.finally( function () {
				if ( submitBtn ) {
					submitBtn.disabled = false;
				}
			} );
	}

	onReady( function () {
		var forms = document.querySelectorAll( '[data-scc-form]' );
		forms.forEach( function ( form ) {
			form.addEventListener( 'submit', handleSubmit );
		} );
	} );
} )();

