/* global jQuery, IfsDeploy */
( function ( $ ) {
	'use strict';

	/*
	 * Toast notifications.
	 *
	 * These used to be a wp-admin notice written into a container near the top of the
	 * panel. Two things were wrong with that. It sat wherever the page happened to be
	 * scrolled from — after pushing from a long Pending Changes table the result appeared
	 * off-screen entirely — and several of these actions RELOAD the page, so the notice
	 * was destroyed a moment after it appeared.
	 *
	 * A toast fixes the first by being positioned against the viewport, and the second by
	 * surviving the reload (see the queue below).
	 *
	 * Errors do NOT auto-dismiss. A success message that disappears has been read or does
	 * not matter; an error that disappears takes the only account of what went wrong with
	 * it — and these actions fail for reasons the user needs to act on ("Production
	 * returned HTTP 500", "another user is pushing this page").
	 */
	var TOAST_KEY = 'ifsDeployToasts';
	var SUCCESS_MS = 6000;

	function toastHost() {
		var $host = $( '#ifs-deploy-toasts' );

		if ( ! $host.length ) {
			$host = $( '<div id="ifs-deploy-toasts" class="ifs-deploy-toasts"></div>' ).appendTo( 'body' );
		}

		return $host;
	}

	function showToast( message, isError ) {
		if ( ! message ) {
			return;
		}

		var $toast = $( '<div class="ifs-deploy-toast"></div>' )
			.addClass( isError ? 'is-error' : 'is-success' )
			/*
			 * assertive + alert for failures so a screen reader interrupts with them, polite
			 * + status for successes so it does not talk over whatever the user is doing.
			 */
			.attr( 'role', isError ? 'alert' : 'status' )
			.attr( 'aria-live', isError ? 'assertive' : 'polite' );

		// .text(), never .html(): these strings pass through server messages that can quote
		// a filename, a post title or a raw response body from the other site.
		$( '<p class="ifs-deploy-toast-text"></p>' ).text( message ).appendTo( $toast );

		$( '<button type="button" class="ifs-deploy-toast-close" aria-label="' + IfsDeploy.i18n.close + '">&times;</button>' )
			.appendTo( $toast );

		toastHost().append( $toast );

		// Next frame, so the element is in the document before the transition starts —
		// otherwise it is applied to an element that was never in its "from" state.
		window.setTimeout( function () {
			$toast.addClass( 'is-visible' );
		}, 10 );

		if ( ! isError ) {
			window.setTimeout( function () {
				dismissToast( $toast );
			}, SUCCESS_MS );
		}
	}

	function dismissToast( $toast ) {
		$toast.removeClass( 'is-visible' );
		window.setTimeout( function () {
			$toast.remove();
		}, 200 );
	}

	/**
	 * Show a message, optionally carrying it across a page reload.
	 *
	 * `persist` is for the actions that reload: the toast is stashed and re-shown once the
	 * new page is up, so the result of a push is still readable afterwards rather than
	 * flashing for a moment and being torn down with the DOM.
	 */
	function notify( message, isError, persist ) {
		if ( persist ) {
			queueToast( message, isError );
			return;
		}

		showToast( message, isError );
	}

	function queueToast( message, isError ) {
		try {
			var queued = JSON.parse( window.sessionStorage.getItem( TOAST_KEY ) || '[]' );
			queued.push( { message: message, error: !! isError } );
			window.sessionStorage.setItem( TOAST_KEY, JSON.stringify( queued ) );
		} catch ( e ) {
			// Private browsing, or storage full. Showing it now is worse than showing it
			// after the reload, but it beats losing the message.
			showToast( message, isError );
		}
	}

	/**
	 * Turn any server-rendered result marker into a toast.
	 *
	 * Saving settings posts a real form, so its outcome is decided during render — long
	 * after `admin_enqueue_scripts` has passed, which is why it cannot simply be localised
	 * into this script. PHP emits a hidden `.ifs-deploy-flash` element instead and this
	 * converts it, so a save reports itself in exactly the same voice as every AJAX action
	 * rather than in WordPress's.
	 *
	 * Run on load AND after a panel swap, because the AJAX tab loader replaces the markup
	 * without a page load ever happening.
	 */
	function drainFlashes() {
		$( '.ifs-deploy-flash' ).each( function () {
			var $flash = $( this );

			showToast( $flash.text(), '1' === String( $flash.attr( 'data-error' ) ) );

			// Removed, or switching away and back would replay a message about something
			// that happened two screens ago.
			$flash.remove();
		} );
	}

	function drainToasts() {
		var queued;

		try {
			queued = JSON.parse( window.sessionStorage.getItem( TOAST_KEY ) || '[]' );
			window.sessionStorage.removeItem( TOAST_KEY );
		} catch ( e ) {
			return;
		}

		$.each( queued, function ( _, item ) {
			showToast( item.message, item.error );
		} );
	}

	$( document ).on( 'click', '.ifs-deploy-toast-close', function () {
		dismissToast( $( this ).closest( '.ifs-deploy-toast' ) );
	} );

	// Escape clears them all. The preview and confirm dialogs handle their own Escape and
	// stop propagation, so this cannot close a toast out from under an open dialog.
	$( document ).on( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			$( '.ifs-deploy-toast' ).each( function () {
				dismissToast( $( this ) );
			} );
		}
	} );

	/*
	 * Actions whose result can only be seen after the page is rebuilt.
	 *
	 * ONE list, so every one of them behaves identically: the toast is stashed, the page
	 * reloads, the toast reappears. Rollback used to reload from its own handler with its
	 * own delay while `request()` showed a non-persisted toast — so the one action people
	 * most want confirmation of was the one whose confirmation was destroyed a moment
	 * after it appeared. Adding a name here is now the whole of what that takes.
	 */
	/**
	 * Report a result and rebuild the page — the ONLY place that reloads.
	 *
	 * The toast is stashed first so it reappears afterwards; showing it and then
	 * destroying it with the DOM is the bug this pairing exists to prevent, and keeping
	 * the two steps together is what stops them being separated again. The batched push
	 * needs exactly the same ending, so it calls this rather than repeating it.
	 */
	function reloadWith( message, isError ) {
		notify( message, isError, true );

		setTimeout( function () {
			window.location.reload();
		}, 300 );
	}

	var RELOAD_ACTIONS = [
		'ifs_deploy_deploy',
		'ifs_deploy_deploy_posts',
		'ifs_deploy_ignore',
		'ifs_deploy_rollback',
		'ifs_deploy_sync_ids',
		'ifs_deploy_clear_history',
		'ifs_deploy_clear_log'
	];

	/* ===========================================================================
	 * Batched push, with real progress
	 * ---------------------------------------------------------------------------
	 * A push used to be ONE blocking request: the browser sent everything and waited,
	 * so there was nothing to report from inside it and nothing to do but spin. Worse,
	 * a large push had to survive the server's execution limit in a single request —
	 * a hundred media downloads never will.
	 *
	 * The browser now drives it. `push_plan` returns the work already ordered the way
	 * Production needs it (media first, options last), split into batches; each batch
	 * is its own short request; the bar moves between them because the browser
	 * genuinely knows how many are done.
	 *
	 * The percentage is therefore REAL. Nothing here estimates or animates towards a
	 * number it has not been told.
	 * ======================================================================== */

	var push = null;

	/* ========================================================================
	 * Media ID conflict
	 * ---------------------------------------------------------------------------
	 * Production refuses to create a new attachment at an id it has already given
	 * away, because this plugin copies meta verbatim and ACF fields, galleries and
	 * `wp-image-N` classes all store the bare attachment number. Landing the file on
	 * a different id would leave every one of those references pointing elsewhere —
	 * which is what used to happen, silently.
	 *
	 * Refusing is honest but not enough on its own: the operator gets a sentence and
	 * no way forward, and the site that can still act is THIS one. So the refusal is
	 * turned into a dialog that asks Staging whether the file is safe to move, and
	 * offers the move only when it provably is.
	 *
	 * The "is it safe" question is asked BEFORE the button is drawn, never after.
	 * Offering an action and then refusing it is how a dialog loses trust.
	 * ======================================================================== */

	function idConflictDialog( conflict ) {
		$( '#ifs-deploy-idconflict' ).remove();

		var occupant = conflict.occupant || {};

		var $d = $(
			'<div id="ifs-deploy-idconflict" class="ifs-deploy-progress" role="dialog" aria-modal="true" aria-labelledby="ifs-deploy-idconflict-title">' +
				'<div class="ifs-deploy-progress-box ifs-deploy-conflict">' +
					'<h2 id="ifs-deploy-idconflict-title" class="ifs-deploy-progress-title"></h2>' +
					'<dl class="ifs-deploy-conflict-facts"></dl>' +
					'<p class="ifs-deploy-conflict-why"></p>' +
					'<p class="ifs-deploy-conflict-verdict" role="status" aria-live="polite"></p>' +
					'<p class="ifs-deploy-progress-actions">' +
						'<button type="button" class="button button-primary" id="ifs-deploy-idconflict-go" hidden></button>' +
						'<button type="button" class="button" id="ifs-deploy-idconflict-close"></button>' +
					'</p>' +
				'</div>' +
			'</div>'
		).appendTo( 'body' );

		$d.find( '.ifs-deploy-progress-title' ).text( IfsDeploy.i18n.conflictTitle );
		$d.find( '.ifs-deploy-conflict-why' ).text( conflict.message || '' );
		$d.find( '#ifs-deploy-idconflict-close' ).text( IfsDeploy.i18n.conflictCancel );
		$d.find( '.ifs-deploy-conflict-verdict' ).text( IfsDeploy.i18n.conflictChecking );

		var facts = [
			[ IfsDeploy.i18n.conflictMedia, conflict.title || '' ],
			[ IfsDeploy.i18n.conflictStagingId, String( conflict.origin_id || '' ) ],
			[
				IfsDeploy.i18n.conflictProdId,
				occupant.id
					? IfsDeploy.i18n.conflictTakenBy
						.replace( '%1$s', occupant.type || '' )
						.replace( '%2$s', occupant.title || '' )
					: IfsDeploy.i18n.conflictNotAvailable
			]
		];

		var $facts = $d.find( '.ifs-deploy-conflict-facts' );

		$.each( facts, function ( _, row ) {
			$( '<dt></dt>' ).text( row[0] ).appendTo( $facts );
			$( '<dd></dd>' ).text( row[1] ).appendTo( $facts );
		} );

		$d.on( 'click', '#ifs-deploy-idconflict-close', function () {
			$d.remove();
		} );

		// Ask whether it can be moved at all. Only a file nothing refers to yet can be,
		// and when it cannot, the answer names what is using it.
		$.post( IfsDeploy.ajaxUrl, {
			action: 'ifs_deploy_media_id_inspect',
			nonce: IfsDeploy.nonce,
			attachment_id: conflict.origin_id
		} )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					$d.find( '.ifs-deploy-conflict-verdict' ).text(
						( res && res.data && res.data.message ) || IfsDeploy.i18n.genericError
					);
					return;
				}

				$d.find( '.ifs-deploy-conflict-verdict' ).text( res.data.message || '' );

				if ( ! res.data.can_renumber ) {
					return;
				}

				$d.find( '#ifs-deploy-idconflict-go' )
					.text( IfsDeploy.i18n.conflictGenerate.replace( '%d', res.data.new_id ) )
					.prop( 'hidden', false )
					.on( 'click', function () {
						var $go = $( this ).prop( 'disabled', true );

						$go.text( IfsDeploy.i18n.conflictWorking );

						$.post( IfsDeploy.ajaxUrl, {
							action: 'ifs_deploy_media_id_renumber',
							nonce: IfsDeploy.nonce,
							attachment_id: conflict.origin_id
						} )
							.done( function ( out ) {
								$d.remove();

								if ( out && out.success ) {
									// Reloaded, because the pending row now names a different
									// attachment id and the list on screen still shows the old one.
									reloadWith( out.data.message, false );
									return;
								}

								notify( ( out && out.data && out.data.message ) || IfsDeploy.i18n.genericError, true );
							} )
							.fail( function () {
								$d.remove();
								notify( IfsDeploy.i18n.genericError, true );
							} );
					} );
			} )
			.fail( function () {
				$d.find( '.ifs-deploy-conflict-verdict' ).text( IfsDeploy.i18n.genericError );
			} );
	}

	function pushDialog() {
		var $d = $( '#ifs-deploy-push-progress' );

		if ( ! $d.length ) {
			$d = $(
				'<div id="ifs-deploy-push-progress" class="ifs-deploy-progress" role="dialog" aria-modal="true" aria-labelledby="ifs-deploy-progress-title">' +
					'<div class="ifs-deploy-progress-box">' +
						'<h2 id="ifs-deploy-progress-title" class="ifs-deploy-progress-title"></h2>' +
						'<div class="ifs-deploy-progress-track"><div class="ifs-deploy-progress-bar"></div></div>' +
						'<p class="ifs-deploy-progress-count" role="status" aria-live="polite"></p>' +
						'<p class="ifs-deploy-progress-phase"></p>' +
						'<p class="ifs-deploy-progress-item"></p>' +
						'<p class="ifs-deploy-progress-note"></p>' +
						'<p class="ifs-deploy-progress-actions">' +
							'<button type="button" class="button" id="ifs-deploy-push-cancel"></button>' +
						'</p>' +
					'</div>' +
				'</div>'
			).appendTo( 'body' );
		}

		return $d;
	}

	/** What kind of work a batch of this type is, in words the user recognises. */
	function pushPhase( type ) {
		var phases = {
			media: IfsDeploy.i18n.pushPhaseMedia,
			term: IfsDeploy.i18n.pushPhaseTerm,
			post: IfsDeploy.i18n.pushPhasePost,
			menu: IfsDeploy.i18n.pushPhaseMenu,
			option: IfsDeploy.i18n.pushPhaseOption
		};

		return phases[ type ] || IfsDeploy.i18n.pushPhaseOther;
	}

	/**
	 * How much longer, from how long it has actually taken so far.
	 *
	 * ── WHY THIS IS NOT ONE AVERAGE ────────────────────────────────────────────────
	 *
	 * Types are wildly unequal: a media item downloads a file and regenerates every image
	 * size, while an option is a single row. A flat average over completed items would
	 * therefore be badly wrong in the one direction that matters — the plan puts media
	 * FIRST, so an average taken during the slow part would go on being applied to the
	 * fast remainder and promise far more time than is left.
	 *
	 * So time is measured per TYPE and the remainder is priced with its own type's
	 * average, falling back to the overall average for a type not seen yet. The estimate
	 * therefore drops sharply when the media is done — which is what actually happens.
	 *
	 * Returns '' until at least one batch has completed. There is nothing to base a
	 * number on before that, and inventing one is exactly what makes a progress dialog
	 * untrustworthy.
	 */
	function pushEta() {
		var remaining = 0;
		var overall = null;
		var totalMs = 0;
		var totalItems = 0;
		var type;

		for ( type in push.spent ) {
			if ( Object.prototype.hasOwnProperty.call( push.spent, type ) ) {
				totalMs += push.spent[ type ].ms;
				totalItems += push.spent[ type ].items;
			}
		}

		if ( ! totalItems ) {
			return '';
		}

		overall = totalMs / totalItems;

		$.each( push.batches, function ( _, batch ) {
			$.each( batch, function ( __, item ) {
				var seen = push.spent[ item.type ];

				remaining += seen && seen.items ? seen.ms / seen.items : overall;
			} );
		} );

		var seconds = Math.round( remaining / 1000 );

		if ( seconds <= 0 ) {
			return IfsDeploy.i18n.pushEtaAlmost;
		}

		if ( seconds < 60 ) {
			return IfsDeploy.i18n.pushEtaSeconds.replace( '%d', seconds );
		}

		return IfsDeploy.i18n.pushEtaMinutes.replace( '%d', Math.ceil( seconds / 60 ) );
	}

	/**
	 * How long the finished dialog stays up before the page reloads.
	 *
	 * Long enough for the completed bar to be seen, short enough not to feel like a delay.
	 */
	var PUSH_SETTLE_MS = 900;

	/** Show or clear the "a request is in flight" animation on the track. */
	function pushWorking( working ) {
		pushDialog().find( '.ifs-deploy-progress-track' ).toggleClass( 'is-working', !! working );
	}

	function pushRender() {
		var $d = pushDialog();
		var percent = push.total ? Math.round( ( push.done / push.total ) * 100 ) : 0;

		$d.find( '.ifs-deploy-progress-title' ).text( IfsDeploy.i18n.pushTitle );
		$d.find( '.ifs-deploy-progress-bar' ).css( 'width', percent + '%' );
		$d.find( '.ifs-deploy-progress-count' ).text(
			IfsDeploy.i18n.pushProgress
				.replace( '%1$d', push.done )
				.replace( '%2$d', push.total )
				.replace( '%3$d', percent )
		);

		// While cancelling, the phase and item lines describe work that is no longer
		// happening — the note replaces them rather than sitting under them.
		$d.find( '.ifs-deploy-progress-phase' ).text( push.cancelling ? '' : push.phase || '' );
		$d.find( '.ifs-deploy-progress-item' ).text( push.cancelling ? '' : push.item || '' );
		$d.find( '.ifs-deploy-progress-note' ).text( push.cancelling ? push.note : ( push.note || pushEta() ) );

		$d.find( '#ifs-deploy-push-cancel' ).text( IfsDeploy.i18n.pushCancel ).prop( 'disabled', !! push.cancelling );
	}

	function pushClose() {
		pushDialog().remove();
		push = null;
	}

	function pushNext() {
		if ( ! push || push.cancelling ) {
			return;
		}

		if ( ! push.batches.length ) {
			var done = push.done;

			/*
			 * FINISH ON SCREEN BEFORE RELOADING.
			 *
			 * This used to close the dialog and reload in the same tick as the last batch
			 * returning — so the browser never painted the finished state, and on a push
			 * small enough to be one request the bar was only ever seen at 0 before the page
			 * went away. It looked broken precisely when it had worked.
			 *
			 * The number is not the reason for the pause; the bar is already at 100 by the
			 * time this runs. The pause exists so a frame is drawn at all.
			 */
			push.phase = '';
			push.item = '';
			push.note = IfsDeploy.i18n.pushComplete;
			pushWorking( false );
			pushRender();

			window.setTimeout( function () {
				pushClose();
				reloadWith( IfsDeploy.i18n.pushDone.replace( '%d', done ), false );
			}, PUSH_SETTLE_MS );

			return;
		}

		var batch = push.batches.shift();
		var startedAt = ( new Date() ).getTime();

		// A batch is one request, so nothing measurable happens until it returns. The bar
		// keeps its honest width and the track animates instead, which shows the push is
		// alive without inventing a number for it.
		pushWorking( true );

		/*
		 * Named BEFORE the request, not after it.
		 *
		 * The plan carries each item's type and title precisely so this line can describe
		 * work that is about to happen. Waiting for the response would mean the dialog
		 * only ever names things it has already finished — which is no use during the
		 * long batch, which is the one people are watching.
		 *
		 * The batch is one request, so the items in it are in flight together; saying
		 * "and N more" is honest where naming a single one would not be.
		 */
		push.phase = pushPhase( batch[0].type );
		push.item = batch.length > 1
			? IfsDeploy.i18n.pushItemMore.replace( '%1$s', batch[0].title ).replace( '%2$d', batch.length - 1 )
			: batch[0].title;

		pushRender();

		$.post( IfsDeploy.ajaxUrl, {
			action: 'ifs_deploy_push_batch',
			nonce: IfsDeploy.nonce,
			uuid: push.uuid,
			queue_ids: $.map( batch, function ( item ) {
				return item.id;
			} ),
			include_others: push.includeOthers
		} )
			.done( function ( res ) {
				if ( ! push || push.cancelling ) {
					return;
				}

				pushWorking( false );

				if ( ! res || ! res.success ) {
					/*
					 * STOP on the first failing batch rather than carrying on.
					 *
					 * Continuing would pile more changes onto a Production that has already
					 * rejected one, and bury the message that says why under later ones. The
					 * push is left where it stopped — what succeeded stays, and the failure
					 * is on screen with the rows still listed.
					 */
					var conflicts = ( res && res.data && res.data.conflicts ) || [];

					pushClose();

					// A media ID clash is the ONE failure this site can still do something
					// about, so it gets a dialog with a button instead of a toast with a
					// sentence. Everything else stays prose, because nothing here could
					// offer an action for it.
					if ( conflicts.length ) {
						idConflictDialog( conflicts[0] );
						return;
					}

					reloadWith( ( res && res.data && res.data.message ) || IfsDeploy.i18n.genericError, true );
					return;
				}

				// Timed per TYPE, because a media batch and an options batch are not
				// comparable work — see pushEta().
				var spent = push.spent[ batch[0].type ] || { ms: 0, items: 0 };

				spent.ms += ( new Date() ).getTime() - startedAt;
				spent.items += batch.length;
				push.spent[ batch[0].type ] = spent;

				push.done += batch.length;
				pushRender();
				pushNext();
			} )
			.fail( function () {
				if ( ! push || push.cancelling ) {
					return;
				}

				pushClose();
				notify( IfsDeploy.i18n.genericError, true );
			} );
	}

	function pushStart( ids, includeOthers ) {
		$.post( IfsDeploy.ajaxUrl, {
			action: 'ifs_deploy_push_plan',
			nonce: IfsDeploy.nonce,
			queue_ids: ids,
			include_others: includeOthers
		} )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					notify( ( res && res.data && res.data.message ) || IfsDeploy.i18n.genericError, true );
					return;
				}

				push = {
					uuid: res.data.uuid,
					batches: res.data.batches,
					// Every id, so a cancel can put them all back — including the ones
					// already marked deployed by batches that completed.
					all: ids,
					includeOthers: includeOthers,
					total: res.data.total,
					done: 0,
					phase: '',
					item: '',
					note: '',
					// Milliseconds and item counts per object type, which is what the
					// estimate is derived from. Empty until a batch has finished, so no
					// number is shown before there is one to show.
					spent: {},
					cancelling: false
				};

				pushRender();
				pushNext();
			} )
			.fail( function () {
				notify( IfsDeploy.i18n.genericError, true );
			} );
	}

	$( document ).on( 'click', '#ifs-deploy-push-cancel', function () {
		if ( ! push || push.cancelling ) {
			return;
		}

		// Marked before the request so no further batch is sent while the undo runs.
		push.cancelling = true;
		push.note = IfsDeploy.i18n.pushCancelling;
		pushRender();

		$.post( IfsDeploy.ajaxUrl, {
			action: 'ifs_deploy_push_cancel',
			nonce: IfsDeploy.nonce,
			uuid: push.uuid,
			queue_ids: push.all,
			include_others: push.includeOthers
		} )
			.done( function ( res ) {
				pushClose();
				reloadWith(
					( res && res.data && res.data.message ) || IfsDeploy.i18n.genericError,
					! ( res && res.success )
				);
			} )
			.fail( function () {
				pushClose();
				notify( IfsDeploy.i18n.genericError, true );
			} );
	} );

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
					var reloads = $.inArray( action, RELOAD_ACTIONS ) !== -1;

					if ( reloads ) {
						reloadWith( res.data.message, false );
					} else {
						notify( res.data.message, false );
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

	/**
	 * What this user may actually push out of a set of rows, and what was left out.
	 *
	 * ── THE BUG THIS REPLACED ──────────────────────────────────────────────────────
	 *
	 * `allIds()` returned only the scoped ids, so an Editor looking at an administrator's
	 * changes got an EMPTY array — and the Push All handler answered an empty array with a
	 * bare `return`. Clicking the button did nothing at all: no dialog, no message, no
	 * indication the click had even registered. Push Selected was barely better, saying
	 * "Nothing is selected" to someone who had selected several rows.
	 *
	 * Reporting the count that was removed is what turns both into an answerable question:
	 * an empty result because there is nothing pending is a different situation from an
	 * empty result because none of it is yours, and only the second is a permission
	 * problem. The server enforces the rule either way — this decides what to SAY.
	 */
	function pushable( $items ) {
		var ids = scoped( $items )
			.map( function () {
				return $( this ).val();
			} )
			.get();

		return { ids: ids, total: $items.length, refused: $items.length - ids.length };
	}

	/**
	 * Explain an empty push, and return true when there is nothing to do.
	 *
	 * `emptyMessage` is what to say when the set really is empty; anything removed by
	 * ownership is a permission problem and says so instead.
	 */
	function explainEmptyPush( set, emptyMessage ) {
		if ( set.ids.length ) {
			return false;
		}

		notify( set.refused ? IfsDeploy.i18n.pushNotYours : emptyMessage, true );

		return true;
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

		/*
		 * A tab switch invalidates any message from the previous tab's actions.
		 *
		 * `#ifs-deploy-notice` is the container the old inline notices were written into.
		 * Nothing writes to it any more — everything goes through the toast — but it is
		 * still emitted by Screen.php, so clearing it stays here as a no-op that costs
		 * nothing and cannot leave a stale notice behind if anything ever uses it again.
		 */
		$( '#ifs-deploy-notice' ).empty();

		// Toasts belong to the action that produced them, not to the screen; a tab switch
		// means the user has moved on, so they go too.
		$( '.ifs-deploy-toast' ).each( function () {
			dismissToast( $( this ) );
		} );

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
	function pushConfirmBody( count, refused ) {
		var $wrap = $( '<div></div>' );
		$( '<p></p>' ).text( IfsDeploy.i18n.confirmPushBody ).appendTo( $wrap );
		$( '<p class="description"></p>' )
			.text( IfsDeploy.i18n.confirmPushCount.replace( '%d', count ) )
			.appendTo( $wrap );

		// Said BEFORE confirming, not after. Quietly pushing 3 of 8 and reporting success
		// is how someone concludes their colleague's work went out with theirs.
		if ( refused ) {
			$( '<p class="description"></p>' )
				.text( IfsDeploy.i18n.pushExcluded.replace( '%d', refused ) )
				.appendTo( $wrap );
		}

		return $wrap.html();
	}

	function confirmPush( count, onConfirm, refused ) {
		openConfirm( {
			title: IfsDeploy.i18n.confirmPushTitle,
			html: pushConfirmBody( count, refused || 0 ),
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
		// Anything stashed by an action that reloaded the page, shown now that the new
		// page is up. Runs first, so a message is never lost to an early failure below.
		drainToasts();

		// And anything the server printed during this render — a settings save, say.
		drainFlashes();

		// Pending changes — select all.
		$( document ).on( 'change', '#ifs-deploy-select-all', function () {
			$( '.ifs-deploy-item' ).prop( 'checked', $( this ).prop( 'checked' ) );
		} );

		$( document ).on( 'click', '#ifs-deploy-push-selected', function () {
			var set = pushable( $( '.ifs-deploy-item:checked' ) );

			if ( explainEmptyPush( set, IfsDeploy.i18n.noneSelected ) ) {
				return;
			}

			// Batched, so the dialog shows real progress and no single request has to
			// survive the whole push. No $btn is passed: the progress dialog is the
			// feedback now, and swapping the button's text under it would be noise.
			confirmPush( set.ids.length, function () {
				pushStart( set.ids, includeOthers() );
			}, set.refused );
		} );

		$( document ).on( 'click', '#ifs-deploy-push-all', function () {
			var set = pushable( $( '.ifs-deploy-item' ) );

			// This used to be a bare `return`, so an Editor pressing Push All on someone
			// else's changes got no dialog, no message, and no sign the click had landed.
			if ( explainEmptyPush( set, IfsDeploy.i18n.nothingPending ) ) {
				return;
			}

			confirmPush( set.ids.length, function () {
				pushStart( set.ids, includeOthers() );
			}, set.refused );
		} );

		$( document ).on( 'click', '#ifs-deploy-ignore', function () {
			// Ignore narrows by ownership exactly as pushing does — `Ajax::ignore()` runs
			// the same `queue_ids()` check — so it had the same silent failure and gets the
			// same explanation. Dismissing a colleague's change is no more yours to do than
			// publishing it.
			var set = pushable( $( '.ifs-deploy-item:checked' ) );

			if ( explainEmptyPush( set, IfsDeploy.i18n.noneSelected ) ) {
				return;
			}

			request( 'ifs_deploy_ignore', { queue_ids: set.ids, include_others: includeOthers() }, $( this ) );
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
				// No reload of its own: `ifs_deploy_rollback` is in RELOAD_ACTIONS, so
				// request() persists the toast and reloads exactly as a deploy does.
				// Duplicating it here is how the two drifted apart in the first place.
				onConfirm: function () {
					request( 'ifs_deploy_rollback', { deployment_id: id }, $btn );
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
					request( 'ifs_deploy_clear_history', {}, $btn );
				}
			} );
		} );

		// Settings — test connection.
		$( document ).on( 'click', '#ifs-deploy-test-connection', function () {
			request( 'ifs_deploy_test_connection', {}, $( this ) );
		} );

		// Compare — sync ids.
		$( document ).on( 'click', '#ifs-deploy-sync-ids', function () {
			request( 'ifs_deploy_sync_ids', {}, $( this ) );
		} );

		// Compare — push a single object by post id.
		$( document ).on( 'click', '.ifs-deploy-push-one', function () {
			var $btn = $( this );
			var id = $btn.data( 'id' );

			confirmPush( 1, function () {
				request( 'ifs_deploy_deploy_posts', { post_ids: [ id ] }, $btn );
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

		// Forget an address: deletes history rather than changing a rule, and cannot be
		// undone, so it asks first.
		$( document ).on( 'click', '.ifs-deploy-forget-ip', function () {
			var $btn = $( this );
			var ip = String( $btn.data( 'ip' ) );

			openConfirm( {
				title: IfsDeploy.i18n.confirmForgetIpTitle,
				text: IfsDeploy.i18n.confirmForgetIp.replace( '%s', ip ),
				confirmLabel: IfsDeploy.i18n.confirmForgetIpButton,
				danger: true,
				onConfirm: function () {
					request( 'ifs_deploy_forget_ip', { ip: ip }, $btn ).done( function ( res ) {
						if ( res && res.success ) {
							loadTab( 'logs', window.location.href, false );
						}
					} );
				}
			} );
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
			request( 'ifs_deploy_clear_log', {}, $( this ) );
		} );

		// Detailed logging. No $btn is passed to request(): it swaps a button's TEXT to
		// "Working…", which would wipe a checkbox's label. The checkbox is disabled for
		// the round trip instead, so it cannot be double-toggled.
		$( document ).on( 'change', '#ifs-deploy-verbose-log', function () {
			var $box = $( this );

			$box.prop( 'disabled', true );

			request( 'ifs_deploy_verbose_log', { on: $box.is( ':checked' ) ? '1' : '0' } ).always( function () {
				$box.prop( 'disabled', false );
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

			// The AJAX tab loader replaces the markup without a page load, so a save whose
			// result arrived in that markup would otherwise never be shown.
			drainFlashes();
		}

		initPanel();
		$( document ).on( 'ifs-deploy:panel', initPanel );

		initTabs();
	} );
} )( jQuery );
