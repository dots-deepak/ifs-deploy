/* global jQuery, IfsDeploy */
( function ( $ ) {
	'use strict';

	function notify( message, isError ) {
		var cls = isError ? 'notice-error' : 'notice-success';
		var $n = $( '#ifs-deploy-notice, #ifs-deploy-test-result' ).first();
		var html = '<div class="notice ' + cls + ' is-dismissible"><p>' + message + '</p></div>';
		if ( $( '#ifs-deploy-notice' ).length ) {
			$( '#ifs-deploy-notice' ).html( html );
		} else {
			$( '#ifs-deploy-test-result' ).text( message );
		}
	}

	function request( action, data, $btn ) {
		var original = $btn ? $btn.text() : '';
		if ( $btn ) {
			$btn.prop( 'disabled', true ).text( IfsDeploy.i18n.working );
		}

		return $.post(
			IfsDeploy.ajaxUrl,
			$.extend( { action: action, nonce: IfsDeploy.nonce }, data )
		)
			.done( function ( res ) {
				if ( res && res.success ) {
					notify( res.data.message, false );
					if ( action === 'ifs_deploy_deploy' || action === 'ifs_deploy_ignore' ) {
						setTimeout( function () {
							window.location.reload();
						}, 900 );
					}
				} else {
					notify( ( res && res.data && res.data.message ) || IfsDeploy.i18n.genericError, true );
				}
			} )
			.fail( function () {
				notify( IfsDeploy.i18n.genericError, true );
			} )
			.always( function () {
				if ( $btn ) {
					$btn.prop( 'disabled', false ).text( original );
				}
			} );
	}

	// Whether the user has opted into acting on changes made by OTHER people.
	//
	// Absent for anyone who cannot see all changes, and the server narrows the ids either
	// way — the checkbox is convenience, not the control.
	function includeOthers() {
		return $( '#ifs-deploy-include-others' ).prop( 'checked' ) ? 1 : 0;
	}

	// Narrow a set of rows to the ones the server will actually act on.
	//
	// This exists so the confirmation dialog cannot lie. Before the checkbox, an
	// administrator saw "12 changes will be pushed" and the server then narrowed that to the
	// 3 they owned — a dialog that promises something different from what happens is worse
	// than no dialog at all.
	function scoped( $items ) {
		if ( includeOthers() ) {
			return $items;
		}

		return $items.filter( function () {
			// No data-mine attribute means the rows were not rendered with ownership info,
			// which only happens for a user who can see nothing but their own anyway.
			var mine = $( this ).attr( 'data-mine' );

			return undefined === mine || '1' === mine;
		} );
	}

	function selectedIds() {
		return scoped( $( '.ifs-deploy-item:checked' ) )
			.map( function () {
				return $( this ).val();
			} )
			.get();
	}

	function allIds() {
		return scoped( $( '.ifs-deploy-item' ) )
			.map( function () {
				return $( this ).val();
			} )
			.get();
	}

	// --- Tabs ------------------------------------------------------------------
	//
	// One admin page, six panels, no page load. The tab bar is real anchors, so this
	// only has to intercept the click; with JS off, or on middle-click, the href still
	// navigates to a server-rendered page showing the same tab.
	//
	// Panels are NOT cached. Every one of them shows live state — the pending queue,
	// Production's index, the event log — and a cached panel would quietly show stale
	// data after a push. Re-fetching is the whole point.

	function $panel() {
		return $( '#dp-tab-panel' );
	}

	// Query args a tab needs on the server side. Kept out of the URL-to-panel mapping
	// so a tab can be linked to with its own filters intact.
	function tabArgs( slug, href ) {
		var args = {};
		var keep = { pending: [ 'user' ], settings: [ 'section' ] };
		var names = keep[ slug ];

		if ( ! names || ! href ) {
			return args;
		}

		names.forEach( function ( name ) {
			var m = new RegExp( '[?&]' + name + '=([^&#]*)' ).exec( href );
			if ( m ) {
				args[ name ] = decodeURIComponent( m[ 1 ].replace( /\+/g, ' ' ) );
			}
		} );

		return args;
	}

	function setActiveTab( slug ) {
		$( '.dp-tab' ).each( function () {
			var $t = $( this );
			var isActive = $t.data( 'tab' ) === slug;

			$t.toggleClass( 'is-active', isActive ).attr( 'aria-selected', isActive ? 'true' : 'false' );
		} );
	}

	function loadTab( slug, href, push ) {
		var $p = $panel();

		if ( ! $p.length ) {
			return;
		}

		setActiveTab( slug );
		$p.addClass( 'is-loading' ).attr( 'aria-busy', 'true' );

		// A tab switch invalidates any notice from the previous tab's actions.
		$( '#ifs-deploy-notice' ).empty();

		$.post( IfsDeploy.ajaxUrl, {
			action: 'ifs_deploy_tab',
			nonce: IfsDeploy.nonce,
			tab: slug,
			args: tabArgs( slug, href )
		} )
			.done( function ( res ) {
				if ( res && res.success && res.data ) {
					$p.html( res.data.html ).attr( 'data-active-tab', res.data.tab );

					// Re-run the initialisers that bind to specific elements. Delegated
					// handlers survive the swap on their own.
					$( document ).trigger( 'ifs-deploy:panel' );

					if ( push && window.history && window.history.pushState ) {
						window.history.pushState( { ifsDeployTab: slug }, '', href );
					}

					// A long panel leaves the reader scrolled past the new content.
					if ( $( window ).scrollTop() > 0 ) {
						$( 'html, body' ).scrollTop( 0 );
					}

					return;
				}

				notify( ( res && res.data && res.data.message ) || IfsDeploy.i18n.genericError, true );
			} )
			.fail( function () {
				notify( IfsDeploy.i18n.genericError, true );
			} )
			.always( function () {
				$p.removeClass( 'is-loading' ).removeAttr( 'aria-busy' );
			} );
	}

	function initTabs() {
		if ( ! $panel().length ) {
			return;
		}

		// Delegated, and matched on [data-tab] rather than .dp-tab, so the shortcut
		// buttons on Overview switch tabs too instead of reloading the page.
		$( document ).on( 'click', '[data-tab]', function ( e ) {
			// Leave modified clicks to the browser: they mean "new tab"/"new window".
			if ( e.which > 1 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
				return;
			}

			var $link = $( this );
			var slug = String( $link.data( 'tab' ) );

			e.preventDefault();

			if ( $link.hasClass( 'dp-tab' ) && $link.hasClass( 'is-active' ) ) {
				return;
			}

			loadTab( slug, $link.attr( 'href' ), true );
		} );

		// Settings' inner Connection/Role sections, same treatment.
		$( document ).on( 'click', '[data-section]', function ( e ) {
			if ( e.which > 1 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
				return;
			}

			e.preventDefault();
			loadTab( 'settings', $( this ).attr( 'href' ), true );
		} );

		// Back/forward must move between tabs, since pushState made them history steps.
		$( window ).on( 'popstate', function () {
			var m = /[?&]tab=([^&#]*)/.exec( window.location.search );
			var slug = m ? decodeURIComponent( m[ 1 ] ) : 'overview';

			loadTab( slug, window.location.href, false );
		} );
	}

	// --- Confirmation dialog --------------------------------------------------
	//
	// Replaces window.confirm() for destructive actions, and adds one to the push
	// buttons, which previously fired immediately. Falls back to window.confirm if
	// the dialog markup is absent, so an action is never silently unavailable.
	var pendingConfirm = null;

	// Which deployment the open rollback dialog is describing, so switching objects
	// inside it knows what to ask for.
	var rollbackDeploymentId = 0;

	function $confirmModal() {
		return $( '#ifs-deploy-confirm-modal' );
	}

	function closeConfirm() {
		var $m = $confirmModal();
		if ( ! $m.length || $m.is( '[hidden]' ) ) {
			return;
		}

		$m.attr( 'hidden', 'hidden' );
		$( 'body' ).removeClass( 'ifs-deploy-modal-open' );
		$m.find( '.ifs-deploy-modal-body' ).empty();
		pendingConfirm = null;
		rollbackDeploymentId = 0;
	}

	/**
	 * options: title, text, html, confirmLabel, danger, onConfirm,
	 *          load( done ) — optional async body loader; Confirm stays disabled
	 *          until it calls done( html, enabled ).
	 */
	function openConfirm( options ) {
		var $m = $confirmModal();

		if ( ! $m.length ) {
			if ( window.confirm( options.text || options.title ) ) {
				options.onConfirm();
			}
			return;
		}

		pendingConfirm = options.onConfirm;

		var $body = $m.find( '.ifs-deploy-modal-body' );
		var $ok = $m.find( '#ifs-deploy-confirm-ok' );

		$m.find( '#ifs-deploy-confirm-title' ).text( options.title );
		$ok.text( options.confirmLabel ).toggleClass( 'ifs-deploy-danger', !! options.danger );

		// A diff needs room; a plain question does not.
		$m.find( '.ifs-deploy-modal-dialog' ).toggleClass( 'ifs-deploy-modal-sm', ! options.wide );

		if ( options.load ) {
			// The summary has to be on screen before Confirm becomes clickable.
			$body.empty().append(
				$( '<p class="ifs-deploy-preview-loading"></p>' ).text( IfsDeploy.i18n.working )
			);
			$ok.prop( 'disabled', true );

			options.load( function ( content, enabled ) {
				if ( typeof content === 'string' ) {
					$body.html( content );
				} else {
					$body.empty().append( content );
				}
				$ok.prop( 'disabled', ! enabled );
			} );
		} else if ( options.html ) {
			$body.html( options.html );
			$ok.prop( 'disabled', false );
		} else {
			$body.empty().append( $( '<p></p>' ).text( options.text || '' ) );
			$ok.prop( 'disabled', false );
		}

		$m.removeAttr( 'hidden' );
		$( 'body' ).addClass( 'ifs-deploy-modal-open' );
		$m.find( '.ifs-deploy-modal-close' ).trigger( 'focus' );
	}

	/** "Are you sure…" plus how many items are involved. */
	function pushConfirmBody( count ) {
		var $wrap = $( '<div></div>' );
		$( '<p></p>' ).text( IfsDeploy.i18n.confirmPushBody ).appendTo( $wrap );
		$( '<p class="description"></p>' )
			.text( IfsDeploy.i18n.confirmPushCount.replace( '%d', count ) )
			.appendTo( $wrap );
		return $wrap.html();
	}

	function confirmPush( count, onConfirm ) {
		openConfirm( {
			title: IfsDeploy.i18n.confirmPushTitle,
			html: pushConfirmBody( count ),
			text: IfsDeploy.i18n.confirmPushBody,
			confirmLabel: IfsDeploy.i18n.confirmPushButton,
			onConfirm: onConfirm
		} );
	}

	$( document ).on( 'click', '[data-ifs-deploy-confirm-close]', function () {
		closeConfirm();
	} );

	// Rollback dialog — switch which object's comparison is shown. Only the detail
	// panel is refetched; the object list and the dialog itself stay put.
	$( document ).on( 'click', '.ifs-deploy-rollback-object', function () {
		var $btn = $( this );
		var $list = $btn.closest( '.ifs-deploy-rollback-objects' );
		var $panel = $list.siblings( '.ifs-deploy-rollback-panel' );

		if ( $btn.hasClass( 'is-active' ) || ! $panel.length || ! rollbackDeploymentId ) {
			return;
		}

		$list.find( '.ifs-deploy-rollback-object' ).removeClass( 'is-active' ).attr( 'aria-pressed', 'false' );
		$btn.addClass( 'is-active' ).attr( 'aria-pressed', 'true' );

		$panel.empty().append(
			$( '<p class="ifs-deploy-preview-loading"></p>' ).text( IfsDeploy.i18n.working )
		);

		$.post( IfsDeploy.ajaxUrl, {
			action: 'ifs_deploy_rollback_preview',
			nonce: IfsDeploy.nonce,
			deployment_id: rollbackDeploymentId,
			revision_id: $btn.data( 'revision-id' ),
			panel_only: 1
		} )
			.done( function ( res ) {
				if ( res && res.success && res.data && res.data.html ) {
					$panel.html( res.data.html );
				} else {
					$panel.empty().append(
						$( '<p class="ifs-deploy-preview-message is-error"></p>' ).text(
							( res && res.data && res.data.message ) || IfsDeploy.i18n.genericError
						)
					);
				}
			} )
			.fail( function () {
				$panel.empty().append(
					$( '<p class="ifs-deploy-preview-message is-error"></p>' ).text( IfsDeploy.i18n.genericError )
				);
			} );
	} );

	$( document ).on( 'click', '#ifs-deploy-confirm-ok', function () {
		var callback = pendingConfirm;
		closeConfirm();
		if ( callback ) {
			callback();
		}
	} );

	$( function () {
		// Pending changes — select all.
		$( document ).on( 'change', '#ifs-deploy-select-all', function () {
			$( '.ifs-deploy-item' ).prop( 'checked', $( this ).prop( 'checked' ) );
		} );

		$( document ).on( 'click', '#ifs-deploy-push-selected', function () {
			var $btn = $( this );
			var ids = selectedIds();
			if ( ! ids.length ) {
				notify( 'No items selected.', true );
				return;
			}
			confirmPush( ids.length, function () {
				request( 'ifs_deploy_deploy', { queue_ids: ids, include_others: includeOthers() }, $btn );
			} );
		} );

		$( document ).on( 'click', '#ifs-deploy-push-all', function () {
			var $btn = $( this );
			var ids = allIds();
			if ( ! ids.length ) {
				return;
			}
			confirmPush( ids.length, function () {
				request( 'ifs_deploy_deploy', { queue_ids: ids, include_others: includeOthers() }, $btn );
			} );
		} );

		$( document ).on( 'click', '#ifs-deploy-ignore', function () {
			var ids = selectedIds();
			if ( ! ids.length ) {
				notify( 'No items selected.', true );
				return;
			}
			request( 'ifs_deploy_ignore', { queue_ids: ids, include_others: includeOthers() }, $( this ) );
		} );

		// History — rollback. The change summary is fetched and shown FIRST; Confirm
		// stays disabled until it is on screen, and stays disabled entirely when there
		// is nothing restorable.
		$( document ).on( 'click', '.ifs-deploy-rollback', function () {
			var $btn = $( this );
			var id = $btn.data( 'id' );

			rollbackDeploymentId = id;

			openConfirm( {
				title: IfsDeploy.i18n.confirmRollbackTitle,
				confirmLabel: IfsDeploy.i18n.confirmRollbackButton,
				danger: true,
				wide: true,
				load: function ( done ) {
					$.post( IfsDeploy.ajaxUrl, {
						action: 'ifs_deploy_rollback_preview',
						nonce: IfsDeploy.nonce,
						deployment_id: id
					} )
						.done( function ( res ) {
							if ( res && res.success && res.data ) {
								var $content = $( '<div></div>' ).html( res.data.html );
								$( '<p class="ifs-deploy-rollback-ask"></p>' )
									.text( IfsDeploy.i18n.confirmRollback )
									.appendTo( $content );

								done( $content, res.data.restorable > 0 );
								return;
							}

							done( errorNode( ( res && res.data && res.data.message ) || IfsDeploy.i18n.genericError ), false );
						} )
						.fail( function () {
							done( errorNode( IfsDeploy.i18n.genericError ), false );
						} );
				},
				onConfirm: function () {
					request( 'ifs_deploy_rollback', { deployment_id: id }, $btn ).done( function ( res ) {
						if ( res && res.success ) {
							setTimeout( function () {
								window.location.reload();
							}, 900 );
						}
					} );
				}
			} );
		} );

		// History — clear history. Same dialog as the other destructive actions.
		$( document ).on( 'click', '#ifs-deploy-clear-history', function () {
			var $btn = $( this );

			openConfirm( {
				title: IfsDeploy.i18n.confirmClearTitle,
				text: IfsDeploy.i18n.confirmClear,
				confirmLabel: IfsDeploy.i18n.confirmClearButton,
				danger: true,
				onConfirm: function () {
					request( 'ifs_deploy_clear_history', {}, $btn ).done( function ( res ) {
						if ( res && res.success ) {
							setTimeout( function () {
								window.location.reload();
							}, 900 );
						}
					} );
				}
			} );
		} );

		// Settings — test connection.
		$( document ).on( 'click', '#ifs-deploy-test-connection', function () {
			request( 'ifs_deploy_test_connection', {}, $( this ) );
		} );

		// Compare — sync ids.
		$( document ).on( 'click', '#ifs-deploy-sync-ids', function () {
			request( 'ifs_deploy_sync_ids', {}, $( this ) ).done( function ( res ) {
				if ( res && res.success ) {
					setTimeout( function () {
						window.location.reload();
					}, 900 );
				}
			} );
		} );

		// Compare — push a single object by post id.
		$( document ).on( 'click', '.ifs-deploy-push-one', function () {
			var $btn = $( this );
			var id = $btn.data( 'id' );

			confirmPush( 1, function () {
				request( 'ifs_deploy_deploy_posts', { post_ids: [ id ] }, $btn ).done( function ( res ) {
					if ( res && res.success ) {
						setTimeout( function () {
							window.location.reload();
						}, 900 );
					}
				} );
			} );
		} );

		// Pending changes — before/after preview in a modal.
		//
		// Deliberately does NOT go through request(): that helper reloads the page
		// on success, which would tear down the dialog the moment it opened. Each
		// row's diff is fetched once and cached, so re-opening is instant.
		var previewCache = {};
		var $lastTrigger = null;

		function $modal() {
			return $( '#ifs-deploy-preview-modal' );
		}

		function setBody( content ) {
			var $body = $modal().find( '.ifs-deploy-modal-body' );
			if ( typeof content === 'string' ) {
				$body.html( content );
			} else {
				$body.empty().append( content );
			}
			$body.scrollTop( 0 );
		}

		function errorNode( message ) {
			return $( '<p class="ifs-deploy-preview-message is-error"></p>' ).text( message );
		}

		function openPreview( $btn ) {
			var $m = $modal();
			if ( ! $m.length ) {
				return;
			}

			$lastTrigger = $btn;

			var title = $btn.data( 'title' );
			$m.find( '#ifs-deploy-modal-title' ).text(
				title ? IfsDeploy.i18n.previewTitle + ' — ' + title : IfsDeploy.i18n.previewTitle
			);

			$m.removeAttr( 'hidden' );
			$( 'body' ).addClass( 'ifs-deploy-modal-open' );
			$m.find( '.ifs-deploy-modal-close' ).trigger( 'focus' );

			// Pending Changes rows carry a queue id; Compare & Sync rows carry a post
			// id (they have no queue entry). Send whichever is present.
			var data = {
				action: 'ifs_deploy_preview',
				nonce: IfsDeploy.nonce
			};
			var cacheKey;

			if ( typeof $btn.data( 'queue-id' ) !== 'undefined' ) {
				data.queue_id = $btn.data( 'queue-id' );
				cacheKey = 'q' + data.queue_id;
			} else {
				data.post_id = $btn.data( 'post-id' );
				cacheKey = 'p' + data.post_id;
			}

			if ( previewCache[ cacheKey ] ) {
				setBody( previewCache[ cacheKey ] );
				return;
			}

			setBody( $( '<p class="ifs-deploy-preview-loading"></p>' ).text( IfsDeploy.i18n.loadingPreview ) );

			$.post( IfsDeploy.ajaxUrl, data )
				.done( function ( res ) {
					if ( res && res.success && res.data && res.data.html ) {
						previewCache[ cacheKey ] = res.data.html;
						setBody( res.data.html );
					} else {
						setBody(
							errorNode(
								( res && res.data && res.data.message ) || IfsDeploy.i18n.genericError
							)
						);
					}
				} )
				.fail( function () {
					setBody( errorNode( IfsDeploy.i18n.genericError ) );
				} );
		}

		function closePreview() {
			var $m = $modal();
			if ( ! $m.length || $m.is( '[hidden]' ) ) {
				return;
			}

			$m.attr( 'hidden', 'hidden' );
			$( 'body' ).removeClass( 'ifs-deploy-modal-open' );
			setBody( '' );

			// Return focus to the button that opened the dialog.
			if ( $lastTrigger && $lastTrigger.length ) {
				$lastTrigger.trigger( 'focus' );
				$lastTrigger = null;
			}
		}

		$( document ).on( 'click', '.ifs-deploy-preview-open', function () {
			openPreview( $( this ) );
		} );

		$( document ).on( 'click', '[data-ifs-deploy-close]', function () {
			closePreview();
		} );

		$( document ).on( 'keydown', function ( e ) {
			if ( 'Escape' === e.key || 'Esc' === e.key ) {
				// Escape cancels rather than confirms, for both dialogs.
				closeConfirm();
				closePreview();
			}
		} );

		// Logs — run diagnostics (renders a report, so it does not use request()).
		$( document ).on( 'click', '#ifs-deploy-run-diagnostics', function () {
			var $btn = $( this );
			var original = $btn.text();
			var $out = $( '#ifs-deploy-diagnostics-result' );

			$btn.prop( 'disabled', true ).text( IfsDeploy.i18n.working );
			$out.html( '<p class="ifs-deploy-preview-loading">' + IfsDeploy.i18n.working + '</p>' );

			$.post( IfsDeploy.ajaxUrl, {
				action: 'ifs_deploy_diagnostics',
				nonce: IfsDeploy.nonce
			} )
				.done( function ( res ) {
					if ( res && res.success && res.data && res.data.html ) {
						$out.html( res.data.html );
					} else {
						$out.empty().append(
							$( '<p class="ifs-deploy-preview-message is-error"></p>' ).text(
								( res && res.data && res.data.message ) || IfsDeploy.i18n.genericError
							)
						);
					}
				} )
				.fail( function () {
					$out.empty().append(
						$( '<p class="ifs-deploy-preview-message is-error"></p>' ).text( IfsDeploy.i18n.genericError )
					);
				} )
				.always( function () {
					$btn.prop( 'disabled', false ).text( original );
				} );
		} );

		// Logs — clear the event log.
		// One-click allow / block from the API access log.
		$( document ).on( 'click', '.ifs-deploy-mark-ip', function () {
			var $btn = $( this );
			var ip = String( $btn.data( 'ip' ) );
			var mark = String( $btn.data( 'mark' ) );

			function apply() {
				request( 'ifs_deploy_mark_ip', { ip: ip, mark: mark }, $btn ).done( function ( res ) {
					if ( res && res.success ) {
						// Reload the panel so every row's rule badge and buttons are recomputed —
						// adding the first allow-list entry changes the state of ALL of them.
						loadTab( 'logs', window.location.href, false );
					}
				} );
			}

			// Only the two that change who can reach the API ask first. Undoing either is
			// harmless, so those go straight through.
			if ( mark === 'block' || mark === 'allow' ) {
				openConfirm( {
					title: mark === 'block' ? IfsDeploy.i18n.confirmBlockIpTitle : IfsDeploy.i18n.confirmAllowIpTitle,
					text: ( mark === 'block' ? IfsDeploy.i18n.confirmBlockIp : IfsDeploy.i18n.confirmAllowIp ).replace( '%s', ip ),
					confirmLabel: mark === 'block' ? IfsDeploy.i18n.confirmBlockIpButton : IfsDeploy.i18n.confirmAllowIpButton,
					danger: mark === 'block',
					onConfirm: apply
				} );

				return;
			}

			apply();
		} );

		// API access log — a separate record from the event log, so a separate button.
		$( document ).on( 'click', '#ifs-deploy-clear-api-log', function () {
			var $btn = $( this );

			openConfirm( {
				title: IfsDeploy.i18n.confirmClearApiTitle,
				text: IfsDeploy.i18n.confirmClearApi,
				confirmLabel: IfsDeploy.i18n.confirmClearButton,
				danger: true,
				onConfirm: function () {
					request( 'ifs_deploy_clear_api_log', {}, $btn ).done( function ( res ) {
						if ( res && res.success ) {
							// Reload the panel rather than the page: the tab already knows how.
							loadTab( 'logs', window.location.href, false );
						}
					} );
				}
			} );
		} );

		$( document ).on( 'click', '#ifs-deploy-clear-log', function () {
			request( 'ifs_deploy_clear_log', {}, $( this ) ).done( function ( res ) {
				if ( res && res.success ) {
					setTimeout( function () {
						window.location.reload();
					}, 700 );
				}
			} );
		} );

		// Settings → Role Management — searchable user pickers.
		//
		// Uses jQuery UI autocomplete, which wp-admin already registers, rather than
		// pulling in select2. Chips are rendered server-side too, so an existing
		// selection survives with JS off; only add/remove needs this.
		function chipMarkup( field, user ) {
			var $item = $( '<li class="ifs-deploy-chip-item"></li>' );

			$( '<input type="hidden" />' ).attr( 'name', field + '[]' ).val( user.id ).appendTo( $item );
			$( '<span></span>' ).text( user.name + ' (' + user.email + ')' ).appendTo( $item );
			$( '<button type="button" class="ifs-deploy-chip-remove">&times;</button>' )
				.attr( 'aria-label', IfsDeploy.i18n.remove )
				.appendTo( $item );

			return $item;
		}

		function initUserPickers() {
			$( '.ifs-deploy-user-picker' ).each( function () {
				var $picker = $( this );
				var field = $picker.data( 'field' );
				var $input = $picker.find( '.ifs-deploy-user-search' );
				var $chips = $picker.find( '.ifs-deploy-chips' );

				if ( ! $input.length || ! $.fn.autocomplete ) {
					return;
				}

				// The Settings tab can be swapped in more than once per page life; a
				// second autocomplete() on the same input would stack duplicate menus.
				if ( $input.data( 'dpAutocomplete' ) ) {
					return;
				}
				$input.data( 'dpAutocomplete', true );

				$input.autocomplete( {
					minLength: 2,
					delay: 250,
					source: function ( request, response ) {
						$.post( IfsDeploy.ajaxUrl, {
							action: 'ifs_deploy_search_users',
							nonce: IfsDeploy.nonce,
							term: request.term
						} )
							.done( function ( res ) {
								var users = ( res && res.success && res.data && res.data.users ) || [];
								response(
									$.map( users, function ( user ) {
										return {
											label: user.name + ' (' + user.email + ')',
											value: '',
											user: user
										};
									} )
								);
							} )
							.fail( function () {
								response( [] );
							} );
					},
					select: function ( event, ui ) {
						var user = ui.item && ui.item.user;
						if ( ! user ) {
							return false;
						}

						// Ignore anyone already in this list.
						var exists = false;
						$chips.find( 'input[type="hidden"]' ).each( function () {
							if ( String( $( this ).val() ) === String( user.id ) ) {
								exists = true;
							}
						} );

						if ( ! exists ) {
							$chips.append( chipMarkup( field, user ) );
						}

						$input.val( '' );
						return false;
					}
			} );
			} );
		}

		// Chip removal (delegated — chips are added dynamically).
		$( document ).on( 'click', '.ifs-deploy-chip-remove', function () {
			$( this ).closest( '.ifs-deploy-chip-item' ).remove();
		} );

		// Settings — show remote fields only for the Staging role.
		function syncRoleFields() {
			if ( ! $( 'input[name="role"]' ).length ) {
				return;
			}

			var isProduction = $( 'input[name="role"]:checked' ).val() === 'production';
			$( '.ifs-deploy-staging-only' ).toggle( ! isProduction );
			$( '.ifs-deploy-production-only' ).toggle( isProduction );

			// The choice cards are styled from a class rather than :has( :checked ), so
			// that the selected one is highlighted in every browser we support.
			$( '.dp-choice' ).each( function () {
				var $c = $( this );

				$c.toggleClass( 'is-selected', $c.find( 'input' ).prop( 'checked' ) );
			} );
		}

		// Registered unconditionally. Guarding on the field's presence would mean the
		// binding never happens at all, because Settings is loaded into the panel later.
		$( document ).on( 'change', 'input[name="role"]', syncRoleFields );

		// Everything that has to touch specific elements rather than delegate. Runs on
		// first paint and again after every tab swap; anything added here must be safe
		// to call repeatedly.
		function initPanel() {
			initUserPickers();
			syncRoleFields();
		}

		initPanel();
		$( document ).on( 'ifs-deploy:panel', initPanel );

		initTabs();
	} );
} )( jQuery );
