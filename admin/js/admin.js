/**
 * Lwb Migration – administration.
 *
 * Pilote les étapes par requêtes AJAX successives, avec reprise automatique
 * (délai dépassé, erreur 5xx, coupure réseau) au dernier point de sauvegarde.
 */
( function () {
	'use strict';

	var cfg = window.LwbMigration || {};
	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : { __: function ( s ) { return s; } };
	var __ = function ( s ) { return i18n.__( s, 'lwb-migration' ); };

	var MAX_RETRIES = 8;

	function $( sel, root ) { return ( root || document ).querySelector( sel ); }
	function $all( sel, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) ); }

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( 'text' === k ) {
				node.textContent = attrs[ k ];
			} else if ( 'className' === k ) {
				node.className = attrs[ k ];
			} else {
				node.setAttribute( k, attrs[ k ] );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			if ( c ) {
				node.appendChild( 'string' === typeof c ? document.createTextNode( c ) : c );
			}
		} );
		return node;
	}

	/**
	 * Requête AJAX. Résout avec { ok, data } ; rejette sur erreur réseau/serveur (réessayable).
	 */
	function post( action, fields ) {
		var body = new FormData();
		body.append( 'action', 'lwb_migration_' + action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( fields || {} ).forEach( function ( k ) {
			var v = fields[ k ];
			if ( Array.isArray( v ) ) {
				v.forEach( function ( item ) { body.append( k, item ); } );
			} else {
				body.append( k, v );
			}
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( res ) {
			return res.text().then( function ( text ) {
				var json = null;
				try {
					json = JSON.parse( text );
				} catch ( e ) {
					// Réponse non JSON : délai dépassé, erreur fatale PHP, page d'erreur de l'hébergeur…
				}
				if ( ! json || 'object' !== typeof json ) {
					var err = new Error( __( 'Le serveur a renvoyé une réponse invalide' ) + ' (HTTP ' + res.status + ').' );
					err.retryable = true;
					throw err;
				}
				return { ok: !! json.success, data: json.data || {} };
			} );
		}, function () {
			var err = new Error( __( 'Connexion au serveur perdue.' ) );
			err.retryable = true;
			throw err;
		} );
	}

	function notice( message, solution, success ) {
		var box = $( '#lwbm-notice' );
		if ( ! box ) {
			return;
		}
		box.textContent = '';
		if ( ! message ) {
			box.hidden = true;
			return;
		}
		box.className = 'lwbm-notice' + ( success ? ' is-success' : '' );
		box.appendChild( el( 'strong', { text: message } ) );
		if ( solution ) {
			box.appendChild( el( 'p', { className: 'lwbm-solution', text: __( 'Solution :' ) + ' ' + solution } ) );
		}
		box.hidden = false;
		box.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	function formatDuration( ms ) {
		var s = Math.floor( ms / 1000 );
		var m = Math.floor( s / 60 );
		return ( m > 0 ? m + ' min ' : '' ) + ( s % 60 ) + ' s';
	}

	/**
	 * Exécute une tâche étape par étape.
	 */
	function Runner( panel, job, onDone, onFail ) {
		this.panel = panel;
		this.job = job;
		this.onDone = onDone;
		this.onFail = onFail;
		this.retries = 0;
		this.cancelled = false;
		this.started = Date.now();
		this.timer = null;
	}

	Runner.prototype.show = function () {
		var self = this;
		this.panel.hidden = false;
		this.panel.classList.remove( 'is-error', 'is-done' );
		$( '[data-lwbm-cancel]', this.panel ).hidden = false;
		$( '[data-lwbm-cancel]', this.panel ).onclick = function () { self.cancel(); };
		this.timer = window.setInterval( function () {
			$( '[data-lwbm-elapsed]', self.panel ).textContent = __( 'Durée :' ) + ' ' + formatDuration( Date.now() - self.started );
		}, 1000 );
	};

	Runner.prototype.update = function ( d ) {
		var pct = Math.max( 0, Math.min( 100, Number( d.progress ) || 0 ) );
		var chips = $all( '[data-lwbm-phase]', this.panel );
		var order = chips.map( function ( c ) { return c.getAttribute( 'data-lwbm-phase' ); } );
		var idx = 'done' === d.phase ? order.length : order.indexOf( d.phase );
		if ( idx > -1 ) {
			chips.forEach( function ( c, i ) {
				c.classList.toggle( 'is-done', i < idx );
				c.classList.toggle( 'is-current', i === idx );
			} );
		}
		if ( d.label ) {
			$( '[data-lwbm-label]', this.panel ).textContent = d.label;
		}
		$( '[data-lwbm-percent]', this.panel ).textContent = pct.toFixed( 'done' === d.status ? 0 : 1 ) + ' %';
		$( '[data-lwbm-fill]', this.panel ).style.width = pct + '%';
		$( '.lwbm-bar', this.panel ).setAttribute( 'aria-valuenow', String( Math.round( pct ) ) );
		$( '[data-lwbm-detail]', this.panel ).textContent = d.detail || '';
		if ( Array.isArray( d.log ) ) {
			var log = $( '[data-lwbm-log]', this.panel );
			log.textContent = d.log.join( '\n' );
			log.scrollTop = log.scrollHeight;
		}
	};

	Runner.prototype.setRetry = function ( text ) {
		$( '[data-lwbm-retry]', this.panel ).textContent = text || '';
	};

	Runner.prototype.run = function () {
		var self = this;
		if ( this.cancelled ) {
			return;
		}
		post( 'job_step', { job: this.job } ).then( function ( res ) {
			if ( self.cancelled ) {
				return;
			}
			if ( ! res.ok ) {
				self.fail( res.data.message, res.data.solution, false );
				return;
			}
			var d = res.data;
			if ( 'busy' === d.status ) {
				self.setRetry( __( 'Le serveur termine l’étape précédente…' ) );
				window.setTimeout( function () { self.run(); }, 3000 );
				return;
			}
			if ( d.progress !== undefined ) {
				self.update( d );
			}
			if ( 'error' === d.status ) {
				if ( d.retryable && self.retries < MAX_RETRIES ) {
					self.retryLater( d.message );
				} else {
					self.fail( d.message, d.solution, true );
				}
				return;
			}
			self.retries = 0;
			self.setRetry( '' );
			if ( 'done' === d.status ) {
				self.finish( d );
				return;
			}
			window.setTimeout( function () { self.run(); }, 50 );
		} ).catch( function ( err ) {
			if ( self.cancelled ) {
				return;
			}
			if ( err.retryable && self.retries < MAX_RETRIES ) {
				self.retryLater( err.message );
			} else {
				self.fail( err.message, __( 'Vérifiez votre connexion puis cliquez sur « Reprendre » : l’opération repartira du dernier point de sauvegarde.' ), true );
			}
		} );
	};

	Runner.prototype.retryLater = function ( reason ) {
		var self = this;
		this.retries++;
		var delay = Math.min( 30, Math.pow( 2, this.retries ) );
		/* Reprise automatique au dernier point de sauvegarde. */
		this.setRetry( ( reason ? reason + ' ' : '' ) + __( 'Nouvelle tentative' ) + ' (' + this.retries + '/' + MAX_RETRIES + ') ' + __( 'dans' ) + ' ' + delay + ' s…' );
		window.setTimeout( function () { self.run(); }, delay * 1000 );
	};

	Runner.prototype.stop = function () {
		window.clearInterval( this.timer );
		$( '[data-lwbm-cancel]', this.panel ).hidden = true;
	};

	Runner.prototype.finish = function ( d ) {
		this.stop();
		this.panel.classList.add( 'is-done' );
		this.setRetry( '' );
		this.onDone( d );
	};

	Runner.prototype.fail = function ( message, solution, resumable ) {
		var self = this;
		this.stop();
		this.panel.classList.add( 'is-error' );
		this.setRetry( '' );
		notice( message || __( 'Erreur inconnue.' ), solution );
		if ( resumable ) {
			var btn = el( 'button', { type: 'button', className: 'lwbm-btn lwbm-btn-primary', text: __( 'Reprendre' ) } );
			btn.addEventListener( 'click', function () {
				btn.remove();
				notice( '' );
				self.retries = 0;
				self.show();
				self.run();
			} );
			$( '#lwbm-notice' ).appendChild( btn );
		}
		if ( this.onFail ) {
			this.onFail();
		}
	};

	Runner.prototype.cancel = function () {
		if ( ! window.confirm( __( 'Annuler l’opération en cours ?' ) ) ) {
			return;
		}
		this.cancelled = true;
		this.stop();
		this.panel.hidden = true;
		post( 'job_cancel', { job: this.job } ).catch( function () {} );
		if ( this.onFail ) {
			this.onFail();
		}
	};

	/* ------------------------------------------------------------------ */
	/* Onglet Exporter                                                     */
	/* ------------------------------------------------------------------ */

	function icon( name ) {
		return el( 'span', { className: 'dashicons dashicons-' + name, 'aria-hidden': 'true' } );
	}

	function renderPackages( list ) {
		var body = $( '#lwbm-packages-body' );
		if ( ! body ) {
			return;
		}
		body.textContent = '';
		if ( ! list.length ) {
			body.appendChild( el( 'div', { className: 'lwbm-empty', text: __( 'Aucun paquet pour le moment. Cliquez sur « Exporter le site » pour en créer un.' ) } ) );
			return;
		}
		list.forEach( function ( p ) {
			var badges = [];
			if ( p.protected ) {
				badges.push( el( 'span', { className: 'lwbm-pill lwbm-pill-info' }, [ icon( 'lock' ), __( 'protégé' ) ] ) );
			}
			if ( p.direct ) {
				badges.push( el( 'span', { className: 'lwbm-pill' }, [ __( 'migration directe' ) ] ) );
			}
			if ( p.warnings > 0 ) {
				badges.push( el( 'span', { className: 'lwbm-pill lwbm-pill-warn' }, [ p.warnings + ' ' + __( 'avertissement(s)' ) ] ) );
			}
			var actions = el( 'div', { className: 'lwbm-package-actions' }, [
				el( 'a', { className: 'lwbm-btn lwbm-btn-soft lwbm-btn-sm', href: p.urls.archive }, [ icon( 'download' ), __( 'Archive' ) ] ),
				el( 'a', { className: 'lwbm-btn lwbm-btn-ghost lwbm-btn-sm', href: p.urls.installer }, [ icon( 'media-code' ), 'installer.php' ] ),
				p.urls.log ? el( 'a', { className: 'lwbm-btn lwbm-btn-ghost lwbm-btn-sm', href: p.urls.log, title: __( 'Journal' ) }, [ icon( 'media-text' ), __( 'Journal' ) ] ) : null,
			] );
			var del = el( 'button', { type: 'button', className: 'lwbm-btn lwbm-btn-danger lwbm-btn-sm lwbm-btn-icon', title: __( 'Supprimer' ), 'aria-label': __( 'Supprimer' ) }, [ icon( 'trash' ) ] );
			del.addEventListener( 'click', function () {
				if ( ! window.confirm( __( 'Supprimer définitivement ce paquet ?' ) ) ) {
					return;
				}
				post( 'package_delete', { package: p.id } ).then( function ( res ) {
					if ( res.ok ) {
						renderPackages( res.data );
					} else {
						notice( res.data.message, res.data.solution );
					}
				} );
			} );
			actions.appendChild( del );
			body.appendChild( el( 'article', { className: 'lwbm-package' }, [
				el( 'span', { className: 'lwbm-package-icon', text: p.format } ),
				el( 'div', { className: 'lwbm-package-main' }, [
					el( 'div', { className: 'lwbm-package-title' }, [ p.date ].concat( badges ) ),
					el( 'div', { className: 'lwbm-package-meta' }, [
						el( 'span', {}, [ icon( 'database' ), p.size ] ),
						el( 'span', {}, [ icon( 'media-default' ), p.files + ' ' + __( 'fichiers' ) ] ),
						el( 'span', {}, [ icon( 'list-view' ), p.tables + ' ' + __( 'tables' ) ] ),
						el( 'span', { title: p.archive }, [ icon( 'tag' ), p.archive.substring( 0, 16 ) + '…' ] ),
					] ),
				] ),
				actions,
			] ) );
		} );
	}

	function loadPackages() {
		if ( ! $( '#lwbm-packages-body' ) ) {
			return Promise.resolve( [] );
		}
		return post( 'packages' ).then( function ( res ) {
			if ( res.ok ) {
				renderPackages( res.data );
				return res.data;
			}
			notice( res.data.message, res.data.solution );
			return [];
		} ).catch( function ( e ) { notice( e.message ); return []; } );
	}

	function randomPassword() {
		var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
		var out = '';
		var buf = new Uint32Array( 16 );
		window.crypto.getRandomValues( buf );
		for ( var i = 0; i < buf.length; i++ ) {
			out += chars[ buf[ i ] % chars.length ];
		}
		return out;
	}

	function initExport() {
		var button = $( '#lwbm-export' );
		if ( ! button ) {
			return;
		}
		var section = button.closest( '.lwbm-card' );
		var panel = $( '[data-lwbm-progress]', section );
		var result = $( '#lwbm-export-result' );
		var password = $( '#lwbm-password' );

		$( '#lwbm-generate-password' ).addEventListener( 'click', function () {
			password.value = randomPassword();
		} );

		loadPackages();

		button.addEventListener( 'click', function () {
			var pw = password.value;
			if ( pw && pw.length < 8 ) {
				notice( __( 'Le mot de passe doit contenir au moins 8 caractères.' ), __( 'Allongez-le, utilisez « Générer », ou laissez le champ vide.' ) );
				return;
			}
			notice( '' );
			button.disabled = true;
			result.hidden = true;
			post( 'export_start', { password: pw } ).then( function ( res ) {
				if ( ! res.ok ) {
					button.disabled = false;
					notice( res.data.message, res.data.solution );
					return;
				}
				var runner = new Runner( panel, res.data.job, function () {
					button.disabled = false;
					loadPackages().then( function ( list ) {
						var pkg = list.filter( function ( p ) { return p.id === res.data.job; } )[ 0 ];
						if ( pkg ) {
							$( '[data-lwbm-dl="archive"]', result ).href = pkg.urls.archive;
							$( '[data-lwbm-dl="installer"]', result ).href = pkg.urls.installer;
						}
						$( '.lwbm-password-reminder', result ).hidden = ! pw;
						$( '[data-lwbm-password]', result ).textContent = pw;
						result.hidden = false;
					} );
				}, function () {
					button.disabled = false;
				} );
				runner.show();
				runner.update( res.data );
				runner.run();
			} ).catch( function ( e ) {
				button.disabled = false;
				notice( e.message, __( 'Rechargez la page et réessayez.' ) );
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Onglet Migration directe                                            */
	/* ------------------------------------------------------------------ */

	function initDirect() {
		var gen = $( '#lwbm-key-generate' );
		if ( ! gen ) {
			return;
		}
		var revoke = $( '#lwbm-key-revoke' );
		var box = $( '#lwbm-key-box' );
		var output = $( '#lwbm-key-output' );

		gen.addEventListener( 'click', function () {
			if ( ! window.confirm( __( 'Générer une clé permettra à un autre site de REMPLACER entièrement ce site. Continuer ?' ) ) ) {
				return;
			}
			post( 'key_generate' ).then( function ( res ) {
				if ( ! res.ok ) {
					notice( res.data.message, res.data.solution );
					return;
				}
				output.value = res.data.key;
				$( '#lwbm-key-expires' ).textContent = __( 'Valable jusqu’au' ) + ' ' + res.data.expires;
				box.hidden = false;
				revoke.hidden = false;
				var active = $( '#lwbm-key-active' );
				if ( active ) {
					active.hidden = true;
				}
				output.select();
			} ).catch( function ( e ) { notice( e.message ); } );
		} );

		$( '#lwbm-key-copy' ).addEventListener( 'click', function () {
			output.select();
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( output.value );
			} else {
				document.execCommand( 'copy' );
			}
			notice( __( 'Clé copiée. Collez-la dans l’onglet « Migration directe » du site source.' ), '', true );
		} );

		revoke.addEventListener( 'click', function () {
			post( 'key_revoke' ).then( function () {
				box.hidden = true;
				revoke.hidden = true;
				var active = $( '#lwbm-key-active' );
				if ( active ) {
					active.hidden = true;
				}
				notice( __( 'Clé révoquée.' ), '', true );
			} );
		} );

		var start = $( '#lwbm-direct-start' );
		var section = start.closest( '.lwbm-card' );
		var panel = $( '[data-lwbm-progress]', section );
		var result = $( '#lwbm-direct-result' );
		start.addEventListener( 'click', function () {
			var key = $( '#lwbm-direct-key' ).value.trim();
			if ( ! key ) {
				notice( __( 'Collez d’abord la clé de migration.' ), __( 'Générez-la dans l’onglet « Migration directe » du site de destination.' ) );
				return;
			}
			if ( ! window.confirm( __( 'Le site de destination sera entièrement remplacé par ce site. Continuer ?' ) ) ) {
				return;
			}
			notice( '' );
			start.disabled = true;
			result.hidden = true;
			post( 'direct_start', { key: key } ).then( function ( res ) {
				if ( ! res.ok ) {
					start.disabled = false;
					notice( res.data.message, res.data.solution );
					return;
				}
				var runner = new Runner( panel, res.data.job, function ( d ) {
					start.disabled = false;
					$( '#lwbm-direct-open' ).href = d.installer_url;
					result.hidden = false;
				}, function () {
					start.disabled = false;
				} );
				runner.show();
				runner.update( res.data );
				runner.run();
			} ).catch( function ( e ) {
				start.disabled = false;
				notice( e.message );
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Onglet Paramètres                                                   */
	/* ------------------------------------------------------------------ */

	function initSettings() {
		var form = $( '#lwbm-settings' );
		if ( ! form ) {
			return;
		}
		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			var fields = {};
			$all( 'input, select, textarea', form ).forEach( function ( input ) {
				if ( ! input.name || ( 'checkbox' === input.type && ! input.checked ) ) {
					return;
				}
				var name = input.name.replace( '[]', '' );
				var key = 'settings[' + name + ']' + ( input.name.indexOf( '[]' ) > -1 ? '[]' : '' );
				if ( input.name.indexOf( '[]' ) > -1 ) {
					fields[ key ] = ( fields[ key ] || [] ).concat( [ input.value ] );
				} else {
					fields[ key ] = input.value;
				}
			} );
			post( 'settings_save', fields ).then( function ( res ) {
				notice( res.ok ? res.data.message : res.data.message, res.ok ? '' : res.data.solution, res.ok );
			} ).catch( function ( e ) { notice( e.message ); } );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Mises à jour                                                        */
	/* ------------------------------------------------------------------ */

	function renderUpdate( st ) {
		var box = $( '#lwbm-update' );
		if ( ! box || ! st ) {
			return;
		}
		box.textContent = '';
		if ( st.has_update ) {
			box.appendChild( el( 'span', { className: 'lwbm-update-state is-new' }, [ icon( 'update' ), __( 'Version' ) + ' ' + st.latest + ' ' + __( 'disponible' ) ] ) );
			if ( st.update_url ) {
				box.appendChild( el( 'a', { className: 'lwbm-update-btn', href: st.update_url, text: __( 'Mettre à jour' ) } ) );
			}
			return;
		}
		box.appendChild( el( 'span', { className: 'lwbm-update-state' }, [ icon( 'yes-alt' ), st.latest ? __( 'À jour' ) + ' (v' + st.current + ')' : __( 'Mises à jour automatiques' ) ] ) );
		var btn = el( 'button', { type: 'button', className: 'lwbm-update-btn', text: __( 'Vérifier' ) } );
		btn.addEventListener( 'click', function () { checkUpdate( btn ); } );
		box.appendChild( btn );
	}

	function checkUpdate( btn ) {
		if ( btn ) {
			btn.classList.add( 'is-busy' );
			btn.textContent = __( 'Vérification…' );
		}
		return post( 'check_update' ).then( function ( res ) {
			if ( res.ok ) {
				renderUpdate( res.data );
			} else if ( btn ) {
				btn.classList.remove( 'is-busy' );
				btn.textContent = __( 'Vérifier' );
			}
		} ).catch( function () {
			if ( btn ) {
				btn.classList.remove( 'is-busy' );
				btn.textContent = __( 'Vérifier' );
			}
		} );
	}

	function initUpdate() {
		var btn = $( '[data-lwbm-check-update]' );
		if ( btn ) {
			btn.addEventListener( 'click', function () { checkUpdate( btn ); } );
		}
		// Première visite : interroge le serveur en arrière-plan, sans bloquer la page.
		if ( cfg.update && ! cfg.update.known ) {
			checkUpdate( null );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initUpdate();
		initExport();
		initDirect();
		initSettings();
	} );
}() );
