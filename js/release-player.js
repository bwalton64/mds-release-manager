/**
 * MDS Release Manager — Mobile-First Unified Release Player Engine.
 *
 * Features:
 * - Zero bandwidth until user taps Play (JIT REST manifest resolution + preload="none").
 * - Respects provider native timed previews (`isTimedPreview === true` plays clip end-to-end;
 *   `isTimedPreview === false` caps at configured previewSeconds with fade-out/crossfade).
 * - Progressive dual-audio crossfade on desktop/Android with clean iOS Safari fallback.
 * - Single active playback coordinator across all players on the page.
 */
( function () {
	'use strict';

	const i18n = window.mdsRmPlayerI18n || {};
	let activePlayer = null;

	function formatTime( seconds ) {
		if ( ! Number.isFinite( seconds ) || seconds < 0 ) {
			return '0:00';
		}
		const total = Math.floor( seconds );
		const mins = Math.floor( total / 60 );
		const secs = total % 60;
		return mins + ':' + ( secs < 10 ? '0' : '' ) + secs;
	}

	function detectVolumeWritable() {
		try {
			const probe = document.createElement( 'audio' );
			probe.volume = 0.5;
			return Math.abs( probe.volume - 0.5 ) < 0.01;
		} catch ( e ) {
			return false;
		}
	}

	const canFadeVolume = detectVolumeWritable();

	class ReleasePlayerController {
		constructor( root ) {
			this.root = root;
			this.restUrl = root.dataset.restUrl || '';
			this.provider = root.dataset.provider || '';
			this.samplerMode = root.dataset.sampler === '1';
			this.previewSeconds = Math.max( 10, parseInt( root.dataset.previewSeconds || '20', 10 ) || 20 );
			this.crossfadeMs = Math.max( 0, parseInt( root.dataset.crossfadeMs || '1500', 10 ) || 0 );
			this.showTracklist = root.dataset.showTracklist === '1';
			this.showBadge = root.dataset.showBadge === '1';

			this.playBtn = root.querySelector( '[data-action="toggle"]' );
			this.prevBtn = root.querySelector( '[data-action="prev"]' );
			this.nextBtn = root.querySelector( '[data-action="next"]' );
			this.tracksBtn = root.querySelector( '[data-action="tracklist"]' );
			this.playIcon = root.querySelector( '.mds-rm-player__icon--play' );
			this.pauseIcon = root.querySelector( '.mds-rm-player__icon--pause' );
			this.spinner = root.querySelector( '.mds-rm-player__spinner' );
			this.titleEl = root.querySelector( '.mds-rm-player__title' );
			this.scrubber = root.querySelector( '.mds-rm-player__scrubber' );
			this.timeEl = root.querySelector( '.mds-rm-player__time' );
			this.footerEl = root.querySelector( '.mds-rm-player__footer' );
			this.badgeEl = root.querySelector( '.mds-rm-player__badge' );
			this.statusEl = root.querySelector( '.mds-rm-player__status' );
			this.tracklistEl = root.querySelector( '.mds-rm-player__tracklist' );
			this.embedEl = root.querySelector( '.mds-rm-player__embed' );

			this.manifest = null;
			this.trackIndex = 0;
			this.isPlaying = false;
			this.isLoading = false;
			this.isCrossfading = false;
			this.fadeRaf = null;

			this.deckA = this.createAudio();
			this.deckB = this.createAudio();
			this.activeDeck = this.deckA;
			this.standbyDeck = this.deckB;

			this.bindEvents();
		}

		createAudio() {
			const audio = document.createElement( 'audio' );
			audio.preload = 'none';
			audio.setAttribute( 'playsinline', '' );
			return audio;
		}

		bindEvents() {
			if ( this.playBtn ) {
				this.playBtn.addEventListener( 'click', () => this.togglePlay() );
			}
			if ( this.prevBtn ) {
				this.prevBtn.addEventListener( 'click', () => this.stepTrack( -1 ) );
			}
			if ( this.nextBtn ) {
				this.nextBtn.addEventListener( 'click', () => this.stepTrack( 1 ) );
			}
			if ( this.tracksBtn && this.tracklistEl ) {
				this.tracksBtn.addEventListener( 'click', () => {
					const open = ! this.tracklistEl.hidden;
					this.tracklistEl.hidden = open;
					this.tracksBtn.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
				} );
			}
			if ( this.scrubber ) {
				this.scrubber.addEventListener( 'input', () => {
					const limit = this.getEffectiveDuration( this.activeDeck, this.currentTrack() );
					if ( limit > 0 ) {
						this.activeDeck.currentTime = ( parseFloat( this.scrubber.value ) / 100 ) * limit;
					}
				} );
			}

			[ this.deckA, this.deckB ].forEach( ( deck ) => {
				deck.addEventListener( 'timeupdate', () => {
					if ( deck === this.activeDeck ) {
						this.handleTimeUpdate( deck );
					}
				} );
				deck.addEventListener( 'ended', () => {
					if ( deck === this.activeDeck ) {
						this.handleTrackEnded();
					}
				} );
				deck.addEventListener( 'error', () => {
					if ( deck === this.activeDeck && this.isPlaying ) {
						this.handlePlaybackError();
					}
				} );
			} );
		}

		currentTrack() {
			if ( ! this.manifest || ! Array.isArray( this.manifest.tracks ) ) {
				return null;
			}
			return this.manifest.tracks[ this.trackIndex ] || null;
		}

		getEffectiveDuration( deck, track ) {
			const nativeDur = Number.isFinite( deck.duration ) && deck.duration > 0
				? deck.duration
				: ( track && track.duration > 0 ? track.duration : 0 );
			if ( ! track ) {
				return nativeDur;
			}
			// Honor native timed previews (Apple Music, Deezer, SoundCloud /preview, Spotify preview_url).
			if ( track.isTimedPreview ) {
				return nativeDur;
			}
			// Full-length stream (e.g. Bandcamp): enforce client-side sampler duration cap.
			return nativeDur > 0 ? Math.min( nativeDur, this.previewSeconds ) : this.previewSeconds;
		}

		isManifestFresh() {
			if ( ! this.manifest ) {
				return false;
			}
			const expiresAt = Number( this.manifest.expiresAt || 0 );
			return expiresAt > Math.floor( Date.now() / 1000 ) + 30;
		}

		async ensureManifest() {
			if ( this.isManifestFresh() ) {
				return this.manifest;
			}
			this.setLoading( true );
			this.setStatus( i18n.loading || 'Loading preview…' );

			try {
				const url = new URL( this.restUrl, window.location.origin );
				if ( this.provider ) {
					url.searchParams.set( 'provider', this.provider );
				}
				const resp = await fetch( url.toString(), {
					headers: { Accept: 'application/json' },
					credentials: 'same-origin',
				} );
				if ( ! resp.ok ) {
					throw new Error( 'HTTP ' + resp.status );
				}
				const data = await resp.json();
				this.manifest = data;
				this.hydrateUiFromManifest();
				this.setStatus( '' );
				return data;
			} catch ( err ) {
				this.setStatus( i18n.unavailable || 'Audio preview unavailable.' );
				return null;
			} finally {
				this.setLoading( false );
			}
		}

		hydrateUiFromManifest() {
			if ( ! this.manifest ) {
				return;
			}
			const tracks = Array.isArray( this.manifest.tracks ) ? this.manifest.tracks : [];
			const multi = tracks.length > 1;

			if ( this.prevBtn ) {
				this.prevBtn.hidden = ! multi;
			}
			if ( this.nextBtn ) {
				this.nextBtn.hidden = ! multi;
			}
			if ( this.tracksBtn ) {
				this.tracksBtn.hidden = ! ( this.showTracklist && multi );
			}

			if ( this.showBadge && this.badgeEl && this.manifest.providerLabel ) {
				this.badgeEl.textContent = '';
				const prefix = document.createTextNode( ( i18n.previewVia || 'Preview via' ) + ' ' );
				this.badgeEl.appendChild( prefix );
				if ( this.manifest.sourceUrl ) {
					const link = document.createElement( 'a' );
					link.href = this.manifest.sourceUrl;
					link.target = '_blank';
					link.rel = 'noopener noreferrer';
					link.textContent = this.manifest.providerLabel;
					this.badgeEl.appendChild( link );
				} else {
					this.badgeEl.appendChild( document.createTextNode( this.manifest.providerLabel ) );
				}
				this.badgeEl.hidden = false;
				if ( this.footerEl ) {
					this.footerEl.hidden = false;
				}
			}

			if ( this.showTracklist && this.tracklistEl && multi ) {
				this.tracklistEl.textContent = '';
				tracks.forEach( ( track, idx ) => {
					const li = document.createElement( 'li' );
					const btn = document.createElement( 'button' );
					btn.type = 'button';
					btn.className = 'mds-rm-player__track-btn';
					btn.dataset.trackIndex = String( idx );

					const name = document.createElement( 'span' );
					name.className = 'mds-rm-player__track-name';
					name.textContent = ( idx + 1 ) + '. ' + track.title;

					const dur = document.createElement( 'span' );
					dur.className = 'mds-rm-player__track-dur';
					const displayDur = track.isTimedPreview
						? track.duration
						: ( track.duration > 0 ? Math.min( track.duration, this.previewSeconds ) : this.previewSeconds );
					dur.textContent = displayDur > 0 ? formatTime( displayDur ) : '';

					btn.appendChild( name );
					btn.appendChild( dur );
					btn.addEventListener( 'click', () => this.playTrack( idx ) );
					li.appendChild( btn );
					this.tracklistEl.appendChild( li );
				} );
			}
		}

		async togglePlay() {
			if ( this.isLoading ) {
				return;
			}
			if ( this.isPlaying ) {
				this.pause();
				return;
			}

			const manifest = await this.ensureManifest();
			if ( ! manifest ) {
				return;
			}

			if ( ( ! manifest.tracks || manifest.tracks.length === 0 ) && manifest.embedHtml && this.embedEl ) {
				this.embedEl.innerHTML = manifest.embedHtml;
				this.embedEl.hidden = false;
				if ( this.playBtn ) {
					this.playBtn.disabled = true;
				}
				return;
			}

			if ( this.activeDeck.src && this.activeDeck.currentTime > 0 && ! this.activeDeck.ended ) {
				this.claimActive();
				try {
					await this.activeDeck.play();
					this.setPlayingState( true );
				} catch ( e ) {
					this.handlePlaybackError();
				}
				return;
			}

			await this.playTrack( this.trackIndex );
		}

		async playTrack( index ) {
			if ( ! this.manifest || ! Array.isArray( this.manifest.tracks ) || ! this.manifest.tracks[ index ] ) {
				return;
			}
			this.cancelFade();
			this.claimActive();
			this.trackIndex = index;
			const track = this.manifest.tracks[ index ];

			this.standbyDeck.pause();
			this.activeDeck.pause();
			this.activeDeck.volume = 1;
			this.activeDeck.src = track.streamUrl;
			this.activeDeck.currentTime = 0;

			if ( this.titleEl && track.title ) {
				this.titleEl.textContent = track.title;
			}
			if ( this.scrubber ) {
				this.scrubber.disabled = false;
				this.scrubber.value = '0';
			}
			this.highlightTracklist( index );

			try {
				await this.activeDeck.play();
				this.setPlayingState( true );
				this.setStatus( '' );
			} catch ( err ) {
				this.handlePlaybackError();
			}
		}

		stepTrack( delta ) {
			if ( ! this.manifest || ! Array.isArray( this.manifest.tracks ) || this.manifest.tracks.length < 2 ) {
				return;
			}
			const next = ( this.trackIndex + delta + this.manifest.tracks.length ) % this.manifest.tracks.length;
			this.playTrack( next );
		}

		pause() {
			this.cancelFade();
			this.activeDeck.pause();
			this.standbyDeck.pause();
			this.setPlayingState( false );
		}

		claimActive() {
			if ( activePlayer && activePlayer !== this ) {
				activePlayer.pause();
			}
			activePlayer = this;
		}

		handleTimeUpdate( deck ) {
			const track = this.currentTrack();
			if ( ! track ) {
				return;
			}
			const limit = this.getEffectiveDuration( deck, track );
			const current = deck.currentTime;

			if ( this.scrubber && limit > 0 ) {
				this.scrubber.value = String( Math.min( 100, ( current / limit ) * 100 ) );
			}
			if ( this.timeEl ) {
				this.timeEl.textContent = formatTime( current ) + ' / ' + formatTime( limit );
			}

			if ( ! this.isPlaying || this.isCrossfading || limit <= 0 ) {
				return;
			}

			const fadeSec = this.crossfadeMs / 1000;
			const hasNext = this.samplerMode && this.manifest && this.trackIndex + 1 < this.manifest.tracks.length;

			if ( hasNext && fadeSec > 0 && current >= Math.max( 0.5, limit - fadeSec ) ) {
				this.beginCrossfadeToNext();
			} else if ( ! track.isTimedPreview && current >= limit ) {
				this.handleTrackEnded();
			}
		}

		beginCrossfadeToNext() {
			const nextIndex = this.trackIndex + 1;
			const nextTrack = this.manifest && this.manifest.tracks ? this.manifest.tracks[ nextIndex ] : null;
			if ( ! nextTrack ) {
				return;
			}

			// iOS Safari fallback: volume is read-only, so advance cleanly without fade overlap.
			if ( ! canFadeVolume || this.crossfadeMs <= 0 ) {
				this.playTrack( nextIndex );
				return;
			}

			this.isCrossfading = true;
			const outgoing = this.activeDeck;
			const incoming = this.standbyDeck;

			incoming.pause();
			incoming.src = nextTrack.streamUrl;
			incoming.currentTime = 0;
			incoming.volume = 0;

			incoming.play().then( () => {
				this.trackIndex = nextIndex;
				this.activeDeck = incoming;
				this.standbyDeck = outgoing;
				if ( this.titleEl && nextTrack.title ) {
					this.titleEl.textContent = nextTrack.title;
				}
				this.highlightTracklist( nextIndex );

				const start = performance.now();
				const duration = this.crossfadeMs;
				const step = ( now ) => {
					const progress = Math.min( 1, ( now - start ) / duration );
					outgoing.volume = Math.max( 0, 1 - progress );
					incoming.volume = Math.min( 1, progress );
					if ( progress < 1 && this.isPlaying ) {
						this.fadeRaf = requestAnimationFrame( step );
					} else {
						outgoing.pause();
						outgoing.volume = 1;
						incoming.volume = 1;
						this.isCrossfading = false;
						this.fadeRaf = null;
					}
				};
				this.fadeRaf = requestAnimationFrame( step );
			} ).catch( () => {
				this.isCrossfading = false;
				this.playTrack( nextIndex );
			} );
		}

		cancelFade() {
			if ( this.fadeRaf !== null ) {
				cancelAnimationFrame( this.fadeRaf );
				this.fadeRaf = null;
			}
			this.isCrossfading = false;
		}

		handleTrackEnded() {
			if ( this.samplerMode && this.manifest && this.trackIndex + 1 < this.manifest.tracks.length ) {
				this.playTrack( this.trackIndex + 1 );
				return;
			}
			this.pause();
			if ( this.scrubber ) {
				this.scrubber.value = '0';
			}
		}

		handlePlaybackError() {
			this.pause();
			this.setStatus( i18n.unavailable || 'Audio preview unavailable.' );
		}

		highlightTracklist( index ) {
			if ( ! this.tracklistEl ) {
				return;
			}
			const buttons = this.tracklistEl.querySelectorAll( '.mds-rm-player__track-btn' );
			buttons.forEach( ( btn, idx ) => {
				if ( idx === index ) {
					btn.setAttribute( 'aria-current', 'true' );
				} else {
					btn.removeAttribute( 'aria-current' );
				}
			} );
		}

		setPlayingState( playing ) {
			this.isPlaying = playing;
			if ( this.playBtn ) {
				this.playBtn.setAttribute( 'aria-pressed', playing ? 'true' : 'false' );
				this.playBtn.setAttribute( 'aria-label', playing ? ( i18n.pause || 'Pause preview' ) : ( i18n.play || 'Play preview' ) );
			}
			if ( this.playIcon ) {
				this.playIcon.hidden = playing;
			}
			if ( this.pauseIcon ) {
				this.pauseIcon.hidden = ! playing;
			}
		}

		setLoading( loading ) {
			this.isLoading = loading;
			if ( this.spinner ) {
				this.spinner.hidden = ! loading;
			}
			if ( this.playIcon ) {
				this.playIcon.hidden = loading || this.isPlaying;
			}
			if ( this.pauseIcon ) {
				this.pauseIcon.hidden = loading || ! this.isPlaying;
			}
		}

		setStatus( message ) {
			if ( ! this.statusEl ) {
				return;
			}
			this.statusEl.textContent = message;
			if ( this.footerEl && ( message || ( this.badgeEl && ! this.badgeEl.hidden ) ) ) {
				this.footerEl.hidden = false;
			}
		}
	}

	function bootPlayers() {
		document.querySelectorAll( '.mds-rm-player:not([data-mds-init])' ).forEach( ( el ) => {
			el.dataset.mdsInit = '1';
			new ReleasePlayerController( el );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bootPlayers );
	} else {
		bootPlayers();
	}
} )();
