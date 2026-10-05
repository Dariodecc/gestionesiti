( function ( $, _, wp ) {
	'use strict';

	if ( ! wp || ! wp.media || ! wp.media.view || ! wp.media.view.AttachmentsBrowser ) {
		return;
	}
	if ( typeof sccMediaFolders === 'undefined' || ! Array.isArray( sccMediaFolders.cartelle ) ) {
		return;
	}

	var cartelle = sccMediaFolders.cartelle.slice();
	var cartellaAttiva = '';
	var browserAttivo = null;

	function escapeHtml( value ) {
		return $( '<div>' ).text( value == null ? '' : value ).html();
	}

	function costruisciAlbero( piatte ) {
		var perId = {};
		var radici = [];
		_.each( piatte, function ( c ) {
			perId[ c.id ] = _.extend( {}, c, { figli: [] } );
		} );
		_.each( piatte, function ( c ) {
			if ( c.parent && perId[ c.parent ] ) {
				perId[ c.parent ].figli.push( perId[ c.id ] );
			} else {
				radici.push( perId[ c.id ] );
			}
		} );
		return radici;
	}

	function opzioniParent( escluso ) {
		var html = '<option value="0">' + escapeHtml( sccMediaFolders.radice ) + '</option>';
		_.each( cartelle, function ( c ) {
			if ( parseInt( c.id, 10 ) !== parseInt( escluso || 0, 10 ) ) {
				html += '<option value="' + parseInt( c.id, 10 ) + '">' + escapeHtml( c.nome ) + '</option>';
			}
		} );
		return html;
	}

	function creaNodo( nodo ) {
		var $li = $( '<li class="scc-folder-item"></li>' );
		var $row = $( '<div class="scc-folder-row"></div>' ).attr( 'data-folder-id', nodo.id );
		var $link = $( '<button type="button" class="scc-cartelle-link"></button>' )
			.attr( 'data-scc-cartella', nodo.id )
			.attr( 'title', nodo.nome )
			.append( '<span class="dashicons dashicons-category" aria-hidden="true"></span>' )
			.append( $( '<span class="scc-folder-name"></span>' ).text( nodo.nome ) )
			.append( $( '<span class="scc-folder-count"></span>' ).text( nodo.count || 0 ) );
		var $azioni = $( '<span class="scc-folder-actions"></span>' )
			.append( '<button type="button" class="scc-folder-action scc-folder-rename" aria-label="' + escapeHtml( sccMediaFolders.rinomina ) + '"><span class="dashicons dashicons-edit"></span></button>' )
			.append( '<button type="button" class="scc-folder-action scc-folder-delete" aria-label="' + escapeHtml( sccMediaFolders.elimina ) + '"><span class="dashicons dashicons-trash"></span></button>' );

		if ( String( cartellaAttiva ) === String( nodo.id ) ) {
			$link.addClass( 'is-selected' );
		}
		$row.append( $link, $azioni );
		$li.append( $row );
		if ( nodo.figli.length ) {
			var $ul = $( '<ul></ul>' );
			_.each( nodo.figli, function ( figlio ) { $ul.append( creaNodo( figlio ) ); } );
			$li.append( $ul );
		}
		return $li;
	}

	function renderSidebar( $sidebar ) {
		var $tree = $sidebar.find( '.scc-folder-tree' ).empty();
		var $tutti = $( '<button type="button" class="scc-cartelle-link scc-folder-system"></button>' )
			.attr( 'data-scc-cartella', '' )
			.append( '<span class="dashicons dashicons-images-alt2" aria-hidden="true"></span>' )
			.append( $( '<span class="scc-folder-name"></span>' ).text( sccMediaFolders.tutte ) )
			.append( $( '<span class="scc-folder-count"></span>' ).text( sccMediaFolders.totale || 0 ) );
		if ( cartellaAttiva === '' ) {
			$tutti.addClass( 'is-selected' );
		}
		$tree.append( $tutti );

		var $senza = $( '<button type="button" class="scc-cartelle-link scc-folder-system"></button>' )
			.attr( 'data-scc-cartella', '-1' )
			.append( '<span class="dashicons dashicons-archive" aria-hidden="true"></span>' )
			.append( $( '<span class="scc-folder-name"></span>' ).text( sccMediaFolders.nonAssegnati ) )
			.append( $( '<span class="scc-folder-count"></span>' ).text( sccMediaFolders.senza || 0 ) );
		if ( String( cartellaAttiva ) === '-1' ) {
			$senza.addClass( 'is-selected' );
		}
		$tree.append( $senza );

		var albero = costruisciAlbero( cartelle );
		if ( albero.length ) {
			var $ul = $( '<ul></ul>' );
			_.each( albero, function ( nodo ) { $ul.append( creaNodo( nodo ) ); } );
			$tree.append( $ul );
		} else {
			$tree.append( $( '<p class="scc-folder-empty"></p>' ).text( sccMediaFolders.vuota ) );
		}
	}

	function mostraDialogo( $sidebar, titolo, nome, parent, escluso, onSave ) {
		var $dialog = $sidebar.find( '.scc-folder-dialog' );
		$dialog.find( '.scc-folder-dialog-title' ).text( titolo );
		$dialog.find( '.scc-folder-input' ).val( nome || '' );
		$dialog.find( '.scc-folder-parent' ).html( opzioniParent( escluso ) ).val( String( parent || 0 ) );
		$dialog.removeAttr( 'hidden' ).addClass( 'is-open' );
		$dialog.data( 'save', onSave );
		window.setTimeout( function () { $dialog.find( '.scc-folder-input' ).trigger( 'focus' ).select(); }, 20 );
	}

	function chiudiDialogo( $sidebar ) {
		$sidebar.find( '.scc-folder-dialog' ).attr( 'hidden', true ).removeClass( 'is-open' ).removeData( 'save' );
	}

	function richiesta( action, dati, $sidebar ) {
		dati = $.extend( {}, dati, { action: action, _ajax_nonce: sccMediaFolders.nonce } );
		$sidebar.addClass( 'is-busy' ).find( '.scc-folder-status' ).text( sccMediaFolders.attendi );
		return $.post( sccMediaFolders.ajaxUrl, dati ).done( function ( risposta ) {
			if ( ! risposta || ! risposta.success ) {
				var messaggio = risposta && risposta.data && risposta.data.message ? risposta.data.message : sccMediaFolders.errore;
				$sidebar.find( '.scc-folder-status' ).text( messaggio );
				return;
			}
			if ( risposta.data.cartelle ) {
				cartelle = risposta.data.cartelle;
				sccMediaFolders.totale = risposta.data.totale;
				sccMediaFolders.senza = risposta.data.senza;
				renderSidebar( $sidebar );
			}
			$sidebar.find( '.scc-folder-status' ).text( risposta.data.message || '' );
		} ).fail( function () {
			$sidebar.find( '.scc-folder-status' ).text( sccMediaFolders.errore );
		} ).always( function () {
			$sidebar.removeClass( 'is-busy' );
		} );
	}

	function eSchermataIntera( controller ) {
		return !! ( wp.media.view.MediaFrame.Manage && controller instanceof wp.media.view.MediaFrame.Manage );
	}

	function creaSidebar( browser ) {
		var $sidebar = $( '<aside class="scc-cartelle-sidebar" aria-label="' + escapeHtml( sccMediaFolders.titolo ) + '"></aside>' );
		$sidebar.append(
			'<div class="scc-folder-head"><div><strong>' + escapeHtml( sccMediaFolders.titolo ) + '</strong><span>' + escapeHtml( sccMediaFolders.trascina ) + '</span></div>' +
			'<button type="button" class="scc-folder-add button button-small"><span class="dashicons dashicons-plus-alt2"></span>' + escapeHtml( sccMediaFolders.nuova ) + '</button></div>' +
			'<label class="scc-folder-search"><span class="screen-reader-text">' + escapeHtml( sccMediaFolders.cerca ) + '</span><span class="dashicons dashicons-search"></span><input type="search" placeholder="' + escapeHtml( sccMediaFolders.cerca ) + '"></label>' +
			'<div class="scc-folder-tree"></div>' +
			'<div class="scc-folder-status" role="status" aria-live="polite"></div>' +
			'<div class="scc-folder-dialog" hidden><strong class="scc-folder-dialog-title"></strong><label><span>' + escapeHtml( sccMediaFolders.nome ) + '</span><input type="text" class="scc-folder-input" maxlength="120"></label><label><span>' + escapeHtml( sccMediaFolders.parent ) + '</span><select class="scc-folder-parent"></select></label><div class="scc-folder-dialog-actions"><button type="button" class="button scc-folder-cancel">' + escapeHtml( sccMediaFolders.annulla ) + '</button><button type="button" class="button button-primary scc-folder-save">' + escapeHtml( sccMediaFolders.salva ) + '</button></div></div>'
		);
		renderSidebar( $sidebar );

		$sidebar.on( 'click', '.scc-cartelle-link', function () {
			$sidebar.find( '.scc-cartelle-link' ).removeClass( 'is-selected' );
			$( this ).addClass( 'is-selected' );
			cartellaAttiva = $( this ).attr( 'data-scc-cartella' );
			browser.collection.props.set( { scc_media_folder: cartellaAttiva === '' ? '' : parseInt( cartellaAttiva, 10 ) } );
		} );

		$sidebar.on( 'input', '.scc-folder-search input', function () {
			var cerca = String( $( this ).val() || '' ).toLowerCase();
			$sidebar.find( '.scc-folder-item' ).each( function () {
				var visibile = ! cerca || $( this ).children( '.scc-folder-row' ).find( '.scc-folder-name' ).text().toLowerCase().indexOf( cerca ) !== -1;
				$( this ).toggle( visibile );
			} );
		} );

		$sidebar.on( 'click', '.scc-folder-add', function () {
			mostraDialogo( $sidebar, sccMediaFolders.nuova, '', 0, 0, function ( nome, parent ) {
				richiesta( 'scc_media_folder_create', { nome: nome, parent: parent }, $sidebar );
			} );
		} );
		$sidebar.on( 'click', '.scc-folder-rename', function () {
			var id = parseInt( $( this ).closest( '.scc-folder-row' ).attr( 'data-folder-id' ), 10 );
			var cartella = _.findWhere( cartelle, { id: id } );
			if ( ! cartella ) { return; }
			mostraDialogo( $sidebar, sccMediaFolders.rinomina, cartella.nome, cartella.parent, id, function ( nome, parent ) {
				richiesta( 'scc_media_folder_update', { id: id, nome: nome, parent: parent }, $sidebar );
			} );
		} );
		$sidebar.on( 'click', '.scc-folder-delete', function () {
			var id = parseInt( $( this ).closest( '.scc-folder-row' ).attr( 'data-folder-id' ), 10 );
			var cartella = _.findWhere( cartelle, { id: id } );
			if ( cartella && window.confirm( sccMediaFolders.confermaElimina.replace( '%s', cartella.nome ) ) ) {
				richiesta( 'scc_media_folder_delete', { id: id }, $sidebar ).done( function () {
					if ( String( cartellaAttiva ) === String( id ) ) {
						cartellaAttiva = '';
						browser.collection.props.set( { scc_media_folder: '' } );
					}
				} );
			}
		} );
		$sidebar.on( 'click', '.scc-folder-cancel', function () { chiudiDialogo( $sidebar ); } );
		$sidebar.on( 'click', '.scc-folder-save', function () {
			var $dialog = $( this ).closest( '.scc-folder-dialog' );
			var nome = $.trim( $dialog.find( '.scc-folder-input' ).val() );
			if ( ! nome ) { $dialog.find( '.scc-folder-input' ).trigger( 'focus' ); return; }
			var save = $dialog.data( 'save' );
			chiudiDialogo( $sidebar );
			if ( save ) { save( nome, parseInt( $dialog.find( '.scc-folder-parent' ).val(), 10 ) || 0 ); }
		} );
		$sidebar.on( 'keydown', '.scc-folder-input', function ( e ) {
			if ( e.key === 'Enter' ) { e.preventDefault(); $sidebar.find( '.scc-folder-save' ).trigger( 'click' ); }
			if ( e.key === 'Escape' ) { chiudiDialogo( $sidebar ); }
		} );

		$sidebar.on( 'dragover', '.scc-folder-row, .scc-folder-system', function ( e ) {
			e.preventDefault();
			$( this ).addClass( 'is-drop-target' );
		} ).on( 'dragleave drop', '.scc-folder-row, .scc-folder-system', function () {
			$( this ).removeClass( 'is-drop-target' );
		} ).on( 'drop', '.scc-folder-row, .scc-folder-system', function ( e ) {
			e.preventDefault();
			var raw = e.originalEvent.dataTransfer.getData( 'text/scc-media-ids' );
			var ids = raw ? raw.split( ',' ).map( Number ).filter( Boolean ) : [];
			if ( ! ids.length ) { return; }
			var $target = $( this ).hasClass( 'scc-folder-row' ) ? $( this ).find( '.scc-cartelle-link' ).first() : $( this );
			var folder = parseInt( $target.attr( 'data-scc-cartella' ), 10 );
			if ( folder < 0 || isNaN( folder ) ) { folder = 0; }
			richiesta( 'scc_media_folder_assign', { ids: ids, folder: folder }, $sidebar ).done( function ( risposta ) {
				if ( risposta && risposta.success ) { browser.collection.props.set( { scc_media_folder: cartellaAttiva === '' ? '' : parseInt( cartellaAttiva, 10 ) } ); }
			} );
		} );

		return $sidebar;
	}

	var renderOriginale = wp.media.view.AttachmentsBrowser.prototype.render;
	wp.media.view.AttachmentsBrowser.prototype.render = function () {
		renderOriginale.apply( this, arguments );
		if ( ! eSchermataIntera( this.controller ) || this.$el.find( '.scc-cartelle-sidebar' ).length ) {
			return this;
		}
		var $contenuto = this.$el.children( '.uploader-inline, .attachments-wrapper, .no-media' );
		if ( ! $contenuto.length ) { return this; }
		$contenuto.wrapAll( '<div class="scc-cartelle-layout"></div>' );
		var $layout = this.$el.children( '.scc-cartelle-layout' );
		var $toolbar = this.$el.children( '.media-toolbar' );
		if ( $toolbar.length ) { $layout.insertAfter( $toolbar.last() ); }
		browserAttivo = this;
		$layout.prepend( creaSidebar( this ) );

		$layout.on( 'mouseenter', '.attachment', function () { $( this ).attr( 'draggable', 'true' ); } );
		$layout.on( 'dragstart', '.attachment', function ( e ) {
			var ids = [];
			if ( browserAttivo.controller && browserAttivo.controller.state && browserAttivo.controller.state() ) {
				var selection = browserAttivo.controller.state().get( 'selection' );
				if ( selection && selection.length > 1 && selection.get( $( this ).data( 'id' ) ) ) {
					ids = selection.pluck( 'id' );
				}
			}
			if ( ! ids.length ) { ids = [ parseInt( $( this ).data( 'id' ), 10 ) ]; }
			ids = ids.filter( Boolean );
			e.originalEvent.dataTransfer.setData( 'text/scc-media-ids', ids.join( ',' ) );
			e.originalEvent.dataTransfer.effectAllowed = 'move';
			$( this ).addClass( 'is-dragging' );
		} ).on( 'dragend', '.attachment', function () { $( this ).removeClass( 'is-dragging' ); } );
		return this;
	};
} )( jQuery, _, wp );

