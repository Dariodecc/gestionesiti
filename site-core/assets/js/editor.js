/**
 * Editor inline Site Core. Gira solo per utenti loggati con permesso e solo
 * quando sccEditor e' localizzato (vedi SCC_Editable_Text::enqueue_editor_assets).
 *
 * Tre parti indipendenti:
 *  1) Testo:  [data-scc-edit]        -> contenteditable, salva su blur (scc/v1/text)
 *  2) Stile:  stesso nodo             -> pannello larghezza/altezza (scc/v1/text-style)
 *  3) Immagini: [data-scc-edit-image] -> Media Library, allineamento, riempimento
 *     (object-fit) e rotazione a 90 gradi (scc/v1/image)
 */
( function () {
	if ( typeof window.sccEditor === 'undefined' ) {
		return;
	}

	function flash( el, cls ) {
		el.classList.add( cls );
		setTimeout( function () {
			el.classList.remove( cls );
		}, 1200 );
	}

	/* ---------------- 1) Testo ---------------- */

	function saveText( el ) {
		var id = el.getAttribute( 'data-scc-edit' );
		var value = el.innerHTML;
		if ( el.getAttribute( 'data-scc-original' ) === value ) {
			return; // niente da salvare
		}
		el.classList.add( 'scc-saving' );
		fetch( window.sccEditor.restUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': window.sccEditor.nonce,
			},
			body: JSON.stringify( { id: id, value: value } ),
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( data ) {
				el.classList.remove( 'scc-saving' );
				if ( data && data.success ) {
					if ( typeof data.value === 'string' ) {
						el.innerHTML = data.value; // rispecchia il valore ripulito dal server
					}
					el.setAttribute( 'data-scc-original', el.innerHTML );
					flash( el, 'scc-saved' );
				} else {
					flash( el, 'scc-error' );
				}
			} )
			.catch( function () {
				el.classList.remove( 'scc-saving' );
				flash( el, 'scc-error' );
			} );
	}

	function initText() {
		var nodes = document.querySelectorAll( '[data-scc-edit]' );
		Array.prototype.forEach.call( nodes, function ( el ) {
			el.setAttribute( 'contenteditable', 'true' );
			el.setAttribute( 'spellcheck', 'false' );
			el.setAttribute( 'data-scc-original', el.innerHTML );

			el.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' && ! e.shiftKey ) {
					e.preventDefault();
					el.blur();
				} else if ( e.key === 'Escape' ) {
					el.innerHTML = el.getAttribute( 'data-scc-original' );
					el.blur();
				}
			} );
			el.addEventListener( 'blur', function () {
				saveText( el );
				scheduleHideStylePanel( el );
			} );

			if ( window.sccEditor.restUrlStyle ) {
				el.addEventListener( 'focus', function () {
					showStylePanel( el );
				} );
			}
		} );
	}

	/* ---------------- 2) Stile (larghezza/altezza) ---------------- */

	var stylePanel = null;
	var stylePanelTarget = null;
	var hideTimer = null;

	function buildStylePanel() {
		if ( stylePanel ) {
			return stylePanel;
		}
		var panel = document.createElement( 'div' );
		panel.className = 'scc-style-panel';
		panel.innerHTML =
			'<label>' + 'L' + '<input type="text" data-scc-style="width" placeholder="es. 300px" /></label>' +
			'<label>' + 'H' + '<input type="text" data-scc-style="height" placeholder="es. auto" /></label>';
		document.body.appendChild( panel );

		// Non far perdere il focus al box di testo quando si clicca sul bordo/
		// etichette del pannello - MA senza impedire il click sui campi stessi,
		// altrimenti il browser non li mette mai a fuoco e non ci si scrive.
		panel.addEventListener( 'mousedown', function ( e ) {
			clearTimeout( hideTimer );
			if ( e.target.tagName !== 'INPUT' ) {
				e.preventDefault();
			}
		} );

		Array.prototype.forEach.call( panel.querySelectorAll( 'input' ), function ( input ) {
			input.addEventListener( 'focus', function () {
				clearTimeout( hideTimer );
			} );
			input.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' ) {
					e.preventDefault();
					input.blur(); // forza cambio/salvataggio subito, non solo al click fuori.
				}
			} );
			input.addEventListener( 'change', function () {
				applyStyle( input );
			} );
			input.addEventListener( 'blur', function () {
				scheduleHideStylePanel( stylePanelTarget );
			} );
		} );

		stylePanel = panel;
		return panel;
	}

	function applyStyle( input ) {
		if ( ! stylePanelTarget ) {
			return;
		}
		var el = stylePanelTarget;
		var widthInput = stylePanel.querySelector( '[data-scc-style="width"]' );
		var heightInput = stylePanel.querySelector( '[data-scc-style="height"]' );
		var width = widthInput.value.trim();
		var height = heightInput.value.trim();

		// Anteprima immediata; il server ha comunque l'ultima parola sulla
		// whitelist delle unita', quindi il valore definitivo puo' differire.
		if ( width || height ) {
			el.style.display = 'inline-block';
			el.style.width = width || '';
			el.style.height = height || '';
			if ( height ) {
				el.style.overflow = 'auto';
			}
		} else {
			el.style.display = '';
			el.style.width = '';
			el.style.height = '';
			el.style.overflow = '';
		}

		fetch( window.sccEditor.restUrlStyle, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': window.sccEditor.nonce,
			},
			body: JSON.stringify( { id: el.getAttribute( 'data-scc-edit' ), width: width, height: height } ),
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( data ) {
				if ( data && data.success ) {
					el.setAttribute( 'data-scc-width', data.width || '' );
					el.setAttribute( 'data-scc-height', data.height || '' );
					flash( el, 'scc-saved' );
				} else {
					flash( el, 'scc-error' );
				}
			} )
			.catch( function () {
				flash( el, 'scc-error' );
			} );
	}

	function showStylePanel( el ) {
		clearTimeout( hideTimer );
		var panel = buildStylePanel();
		stylePanelTarget = el;
		panel.querySelector( '[data-scc-style="width"]' ).value = el.getAttribute( 'data-scc-width' ) || '';
		panel.querySelector( '[data-scc-style="height"]' ).value = el.getAttribute( 'data-scc-height' ) || '';

		var rect = el.getBoundingClientRect();
		panel.style.top = ( window.scrollY + rect.top - panel.offsetHeight - 6 ) + 'px';
		panel.style.left = ( window.scrollX + rect.left ) + 'px';
		panel.classList.add( 'is-visible' );
	}

	function scheduleHideStylePanel( el ) {
		if ( el !== stylePanelTarget ) {
			return;
		}
		clearTimeout( hideTimer );
		hideTimer = setTimeout( function () {
			if ( stylePanel ) {
				stylePanel.classList.remove( 'is-visible' );
			}
			stylePanelTarget = null;
		}, 200 );
	}

	/* ---------------- 3) Immagini ---------------- */

	function currentFit( wrap ) {
		return wrap.getAttribute( 'data-scc-fit' ) || '';
	}

	function currentRotate( wrap ) {
		return parseInt( wrap.getAttribute( 'data-scc-rotate' ), 10 ) || 0;
	}

	function currentAlign( wrap ) {
		return wrap.getAttribute( 'data-scc-align' ) || '';
	}

	/**
	 * Salva lo stato completo dell'immagine (allineamento, riempimento,
	 * rotazione, ed eventualmente una nuova immagine sostituita). I tre
	 * valori vengono sempre inviati tutti e tre insieme: evita che un
	 * salvataggio parziale (es. solo la rotazione) cancelli per sbaglio un
	 * fit gia' impostato in precedenza.
	 */
	function saveImage( wrap, attachmentId, align, fit, rotate ) {
		var id = wrap.getAttribute( 'data-scc-edit-image' );
		var body = { id: id, align: align, fit: fit, rotate: rotate };
		if ( attachmentId ) {
			body.attachment_id = attachmentId;
		}
		wrap.classList.add( 'scc-saving' );
		fetch( window.sccEditor.restUrlImage, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': window.sccEditor.nonce,
			},
			body: JSON.stringify( body ),
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( data ) {
				wrap.classList.remove( 'scc-saving' );
				if ( data && data.success ) {
					if ( data.src ) {
						var img = wrap.querySelector( 'img' );
						if ( img ) {
							img.src = data.src;
							if ( data.alt ) {
								img.alt = data.alt;
							}
						}
					}
					wrap.setAttribute( 'data-scc-align', data.align || '' );
					wrap.setAttribute( 'data-scc-fit', data.fit || '' );
					wrap.setAttribute( 'data-scc-rotate', data.rotate || 0 );
					applyImageAlign( wrap, data.align || '' );
					applyImageFit( wrap, data.fit || '' );
					applyImageRotate( wrap, data.rotate || 0 );
					var fitSelect = wrap.querySelector( '.scc-image-fit-select' );
					if ( fitSelect ) {
						fitSelect.value = data.fit || '';
					}
					flash( wrap, 'scc-saved' );
				} else {
					flash( wrap, 'scc-error' );
				}
			} )
			.catch( function () {
				wrap.classList.remove( 'scc-saving' );
				flash( wrap, 'scc-error' );
			} );
	}

	function applyImageAlign( wrap, align ) {
		wrap.style.textAlign = ( align === 'left' || align === 'center' || align === 'right' ) ? align : '';
	}

	/** Riempimento: si applica all'<img>, mai al box che lo contiene. */
	function applyImageFit( wrap, fit ) {
		var img = wrap.querySelector( 'img' );
		if ( img ) {
			img.style.objectFit = fit || '';
		}
	}

	/** Rotazione: idem, solo sull'<img>. */
	function applyImageRotate( wrap, rotate ) {
		var img = wrap.querySelector( 'img' );
		if ( img ) {
			img.style.transform = rotate ? 'rotate(' + rotate + 'deg)' : '';
		}
	}

	function initImages() {
		if ( ! window.sccEditor.restUrlImage ) {
			return;
		}
		var wraps = document.querySelectorAll( '[data-scc-edit-image]' );
		Array.prototype.forEach.call( wraps, function ( wrap ) {
			var editBtn = wrap.querySelector( '.scc-image-edit-btn' );
			if ( editBtn ) {
				editBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					if ( typeof wp === 'undefined' || ! wp.media ) {
						return; // wp_enqueue_media() non caricato: niente da fare, non rompere la pagina.
					}
					var frame = wp.media( { title: editBtn.getAttribute( 'data-scc-media-title' ) || 'Scegli immagine', multiple: false } );
					frame.on( 'select', function () {
						var att = frame.state().get( 'selection' ).first().toJSON();
						saveImage( wrap, att.id, currentAlign( wrap ), currentFit( wrap ), currentRotate( wrap ) );
					} );
					frame.open();
				} );
			}

			var alignBtns = wrap.querySelectorAll( '[data-scc-align-set]' );
			Array.prototype.forEach.call( alignBtns, function ( btn ) {
				btn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					var align = btn.getAttribute( 'data-scc-align-set' );
					wrap.setAttribute( 'data-scc-align', align );
					applyImageAlign( wrap, align );
					saveImage( wrap, 0, align, currentFit( wrap ), currentRotate( wrap ) );
				} );
			} );

			var rotateBtn = wrap.querySelector( '.scc-image-rotate-btn' );
			if ( rotateBtn ) {
				rotateBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					var next = ( currentRotate( wrap ) + 90 ) % 360;
					wrap.setAttribute( 'data-scc-rotate', next );
					applyImageRotate( wrap, next );
					saveImage( wrap, 0, currentAlign( wrap ), currentFit( wrap ), next );
				} );
			}

			var fitSelect = wrap.querySelector( '.scc-image-fit-select' );
			if ( fitSelect ) {
				// Non far perdere il focus del pulsante/box quando si apre la
				// tendina: e' un controllo a se', non deve chiudere il resto.
				fitSelect.addEventListener( 'mousedown', function ( e ) {
					e.stopPropagation();
				} );
				fitSelect.addEventListener( 'change', function () {
					var fit = fitSelect.value;
					wrap.setAttribute( 'data-scc-fit', fit );
					applyImageFit( wrap, fit );
					saveImage( wrap, 0, currentAlign( wrap ), fit, currentRotate( wrap ) );
				} );
			}
		} );
	}

	function init() {
		initText();
		initImages();
	}

	if ( document.readyState !== 'loading' ) {
		init();
	} else {
		document.addEventListener( 'DOMContentLoaded', init );
	}
} )();
