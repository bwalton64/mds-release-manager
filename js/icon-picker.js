(function (wp) {
	const { createElement: el, Fragment, useEffect, useMemo, useState } = wp.element;
	const { Button, Modal, SearchControl, TabPanel, TextControl } = wp.components;
	const { __, sprintf } = wp.i18n;
	const speak = wp.a11y && typeof wp.a11y.speak === 'function' ? wp.a11y.speak : () => {};

	/**
	 * Curated Font Awesome 6 Free (Solid/Brands) + WordPress Dashicons catalog
	 * organized for music releases, streaming providers, commerce, and metadata.
	 */
	const ICON_CATALOG = [
		// Music & Audio
		{ cls: 'fas fa-music', label: 'Music Note', cat: 'music', kw: 'single song track audio' },
		{ cls: 'fas fa-compact-disc', label: 'Compact Disc', cat: 'music', kw: 'cd ep album disc' },
		{ cls: 'fas fa-record-vinyl', label: 'Vinyl Record', cat: 'music', kw: 'lp vinyl ep 7 inch album' },
		{ cls: 'fas fa-headphones', label: 'Headphones', cat: 'music', kw: 'listen stream audio' },
		{ cls: 'fas fa-headphones-simple', label: 'Headphones Simple', cat: 'music', kw: 'listen stream audio' },
		{ cls: 'fas fa-microphone', label: 'Microphone', cat: 'music', kw: 'vocals live podcast' },
		{ cls: 'fas fa-microphone-lines', label: 'Studio Mic', cat: 'music', kw: 'recording studio vocal' },
		{ cls: 'fas fa-radio', label: 'Radio', cat: 'music', kw: 'broadcast airplay' },
		{ cls: 'fas fa-guitar', label: 'Guitar', cat: 'music', kw: 'instrument acoustic rock' },
		{ cls: 'fas fa-drum', label: 'Drum', cat: 'music', kw: 'percussion beat' },
		{ cls: 'fas fa-volume-high', label: 'Volume High', cat: 'music', kw: 'speaker sound audio' },
		{ cls: 'fas fa-volume-low', label: 'Volume Low', cat: 'music', kw: 'speaker sound' },
		{ cls: 'fas fa-sliders', label: 'Mixer Sliders', cat: 'music', kw: 'mix master eq studio' },
		{ cls: 'fas fa-wave-square', label: 'Waveform', cat: 'music', kw: 'synth electronic signal' },
		{ cls: 'fas fa-play', label: 'Play', cat: 'music', kw: 'preview stream start' },
		{ cls: 'fas fa-circle-play', label: 'Circle Play', cat: 'music', kw: 'preview stream player' },
		{ cls: 'fas fa-tape', label: 'Cassette Tape', cat: 'music', kw: 'cassette analog tape' },
		{ cls: 'fas fa-podcast', label: 'Podcast', cat: 'music', kw: 'broadcast radio stream' },
		{ cls: 'fas fa-tower-broadcast', label: 'Broadcast Tower', cat: 'music', kw: 'distributor radio signal' },
		{ cls: 'fas fa-film', label: 'Music Video', cat: 'music', kw: 'video clip visual' },
		{ cls: 'fas fa-clapperboard', label: 'Video Production', cat: 'music', kw: 'video film' },

		// Streaming & Brands
		{ cls: 'fab fa-spotify', label: 'Spotify', cat: 'brands', kw: 'streaming music' },
		{ cls: 'fab fa-apple', label: 'Apple Music', cat: 'brands', kw: 'itunes streaming' },
		{ cls: 'fab fa-itunes-note', label: 'iTunes Note', cat: 'brands', kw: 'apple music store' },
		{ cls: 'fab fa-bandcamp', label: 'Bandcamp', cat: 'brands', kw: 'indie store download' },
		{ cls: 'fab fa-youtube', label: 'YouTube', cat: 'brands', kw: 'video streaming music' },
		{ cls: 'fab fa-soundcloud', label: 'SoundCloud', cat: 'brands', kw: 'streaming audio indie' },
		{ cls: 'fab fa-amazon', label: 'Amazon Music', cat: 'brands', kw: 'store streaming' },
		{ cls: 'fab fa-deezer', label: 'Deezer', cat: 'brands', kw: 'streaming music' },
		{ cls: 'fab fa-napster', label: 'Napster', cat: 'brands', kw: 'streaming music' },
		{ cls: 'fab fa-lastfm', label: 'Last.fm', cat: 'brands', kw: 'scrobble music' },
		{ cls: 'fab fa-mixcloud', label: 'Mixcloud', cat: 'brands', kw: 'dj radio mix' },
		{ cls: 'fab fa-tiktok', label: 'TikTok', cat: 'brands', kw: 'social short video' },
		{ cls: 'fab fa-instagram', label: 'Instagram', cat: 'brands', kw: 'social photo' },
		{ cls: 'fab fa-facebook', label: 'Facebook', cat: 'brands', kw: 'social page' },
		{ cls: 'fab fa-x-twitter', label: 'X (Twitter)', cat: 'brands', kw: 'social twitter' },
		{ cls: 'fab fa-threads', label: 'Threads', cat: 'brands', kw: 'social meta' },
		{ cls: 'fab fa-bluesky', label: 'Bluesky', cat: 'brands', kw: 'social' },
		{ cls: 'fab fa-twitch', label: 'Twitch', cat: 'brands', kw: 'livestream' },
		{ cls: 'fab fa-discord', label: 'Discord', cat: 'brands', kw: 'community fans' },
		{ cls: 'fab fa-patreon', label: 'Patreon', cat: 'brands', kw: 'membership support' },
		{ cls: 'fab fa-shopify', label: 'Shopify', cat: 'brands', kw: 'merch store shop' },
		{ cls: 'fab fa-paypal', label: 'PayPal', cat: 'brands', kw: 'payment buy' },
		{ cls: 'fab fa-stripe', label: 'Stripe', cat: 'brands', kw: 'payment buy' },
		{ cls: 'fab fa-usb', label: 'USB Flash Drive', cat: 'brands', kw: 'flash drive physical digital' },
		{ cls: 'fab fa-vimeo-v', label: 'Vimeo', cat: 'brands', kw: 'video' },

		// Commerce & Actions
		{ cls: 'fas fa-cart-shopping', label: 'Shopping Cart', cat: 'commerce', kw: 'buy now store shop' },
		{ cls: 'fas fa-bag-shopping', label: 'Shopping Bag', cat: 'commerce', kw: 'buy now merch store' },
		{ cls: 'fas fa-store', label: 'Storefront', cat: 'commerce', kw: 'shop merch' },
		{ cls: 'fas fa-download', label: 'Download', cat: 'commerce', kw: 'digital mp3 flac wav' },
		{ cls: 'fas fa-cloud-arrow-down', label: 'Cloud Download', cat: 'commerce', kw: 'digital release download' },
		{ cls: 'fas fa-tag', label: 'Price Tag', cat: 'commerce', kw: 'sale merch edition' },
		{ cls: 'fas fa-tags', label: 'Tags', cat: 'commerce', kw: 'genres categories' },
		{ cls: 'fas fa-ticket', label: 'Ticket', cat: 'commerce', kw: 'tour concert live show' },
		{ cls: 'fas fa-shirt', label: 'Merch Shirt', cat: 'commerce', kw: 'apparel bundle' },
		{ cls: 'fas fa-box-open', label: 'Box Set', cat: 'commerce', kw: 'deluxe physical edition' },
		{ cls: 'fas fa-gift', label: 'Gift / Bundle', cat: 'commerce', kw: 'special edition' },
		{ cls: 'fas fa-credit-card', label: 'Credit Card', cat: 'commerce', kw: 'checkout buy' },
		{ cls: 'fas fa-up-right-from-square', label: 'External Link', cat: 'commerce', kw: 'open store website' },
		{ cls: 'fas fa-link', label: 'Link', cat: 'commerce', kw: 'url website' },
		{ cls: 'fas fa-globe', label: 'Website / Globe', cat: 'commerce', kw: 'official site homepage' },

		// Metadata & UI
		{ cls: 'fas fa-calendar', label: 'Calendar', cat: 'meta', kw: 'release year date' },
		{ cls: 'fas fa-calendar-days', label: 'Calendar Days', cat: 'meta', kw: 'release date schedule' },
		{ cls: 'fas fa-clock', label: 'Clock / Duration', cat: 'meta', kw: 'time length runtime' },
		{ cls: 'fas fa-barcode', label: 'Barcode (UPC/EAN)', cat: 'meta', kw: 'upc ean sku catalog' },
		{ cls: 'fas fa-building', label: 'Record Label', cat: 'meta', kw: 'label company publisher' },
		{ cls: 'fas fa-copyright', label: 'Copyright', cat: 'meta', kw: 'rights publisher' },
		{ cls: 'fas fa-user', label: 'Artist / Person', cat: 'meta', kw: 'solo musician' },
		{ cls: 'fas fa-users', label: 'Band / Group', cat: 'meta', kw: 'artists ensemble' },
		{ cls: 'fas fa-star', label: 'Star', cat: 'meta', kw: 'featured favorite' },
		{ cls: 'fas fa-heart', label: 'Heart', cat: 'meta', kw: 'like love fan' },
		{ cls: 'fas fa-fire', label: 'Trending / Fire', cat: 'meta', kw: 'hot new release' },
		{ cls: 'fas fa-bolt', label: 'Lightning', cat: 'meta', kw: 'energy new' },
		{ cls: 'fas fa-award', label: 'Award', cat: 'meta', kw: 'grammy gold platinum' },
		{ cls: 'fas fa-trophy', label: 'Trophy', cat: 'meta', kw: 'award chart' },
		{ cls: 'fas fa-list-ul', label: 'Tracklist', cat: 'meta', kw: 'tracks songs list' },
		{ cls: 'fas fa-book-open', label: 'Liner Notes', cat: 'meta', kw: 'credits booklet' },
		{ cls: 'fas fa-circle-info', label: 'Info', cat: 'meta', kw: 'details metadata' },
		{ cls: 'fas fa-envelope', label: 'Contact Email', cat: 'meta', kw: 'booking press' },
		{ cls: 'fas fa-location-dot', label: 'Location', cat: 'meta', kw: 'venue city tour' },

		// WordPress Dashicons
		{ cls: 'dashicons dashicons-album', label: 'Album (Dashicon)', cat: 'dashicons', kw: 'disc release music' },
		{ cls: 'dashicons dashicons-format-audio', label: 'Audio (Dashicon)', cat: 'dashicons', kw: 'music note sound' },
		{ cls: 'dashicons dashicons-playlist-audio', label: 'Playlist (Dashicon)', cat: 'dashicons', kw: 'tracks songs' },
		{ cls: 'dashicons dashicons-controls-play', label: 'Play (Dashicon)', cat: 'dashicons', kw: 'stream preview' },
		{ cls: 'dashicons dashicons-calendar-alt', label: 'Calendar (Dashicon)', cat: 'dashicons', kw: 'date year' },
		{ cls: 'dashicons dashicons-groups', label: 'Artist / Group (Dashicon)', cat: 'dashicons', kw: 'band artist' },
		{ cls: 'dashicons dashicons-tag', label: 'Tag (Dashicon)', cat: 'dashicons', kw: 'genre label' },
		{ cls: 'dashicons dashicons-cart', label: 'Cart (Dashicon)', cat: 'dashicons', kw: 'buy now shop' },
		{ cls: 'dashicons dashicons-store', label: 'Store (Dashicon)', cat: 'dashicons', kw: 'shop merch' },
		{ cls: 'dashicons dashicons-download', label: 'Download (Dashicon)', cat: 'dashicons', kw: 'digital' },
		{ cls: 'dashicons dashicons-external', label: 'External (Dashicon)', cat: 'dashicons', kw: 'link open' },
		{ cls: 'dashicons dashicons-admin-links', label: 'Link (Dashicon)', cat: 'dashicons', kw: 'url website' },
		{ cls: 'dashicons dashicons-star-filled', label: 'Star (Dashicon)', cat: 'dashicons', kw: 'featured' },
		{ cls: 'dashicons dashicons-tickets-alt', label: 'Tickets (Dashicon)', cat: 'dashicons', kw: 'tour show' },
		{ cls: 'dashicons dashicons-microphone', label: 'Microphone (Dashicon)', cat: 'dashicons', kw: 'vocal audio' },
	];

	const CATEGORIES = [
		{ name: 'all', title: __('All', 'mds-release-manager') },
		{ name: 'music', title: __('Music & Audio', 'mds-release-manager') },
		{ name: 'brands', title: __('Streaming & Brands', 'mds-release-manager') },
		{ name: 'commerce', title: __('Commerce', 'mds-release-manager') },
		{ name: 'meta', title: __('Metadata & UI', 'mds-release-manager') },
		{ name: 'dashicons', title: __('Dashicons', 'mds-release-manager') },
	];

	function IconPickerControl({
		label,
		help,
		value = '',
		onChange,
		defaultCategory = 'all',
		allowEmpty = true,
	}) {
		const [isOpen, setIsOpen] = useState(false);
		const [search, setSearch] = useState('');
		const [activeCat, setActiveCat] = useState(defaultCategory);
		const [customClass, setCustomClass] = useState(value || '');

		useEffect(() => {
			setCustomClass(value || '');
		}, [value]);

		const filteredIcons = useMemo(() => {
			const q = search.trim().toLowerCase();
			return ICON_CATALOG.filter((item) => {
				if (activeCat !== 'all' && item.cat !== activeCat) {
					return false;
				}
				if (!q) {
					return true;
				}
				return (
					item.label.toLowerCase().includes(q) ||
					item.cls.toLowerCase().includes(q) ||
					item.kw.toLowerCase().includes(q)
				);
			});
		}, [search, activeCat]);

		function choose(nextCls, labelText) {
			onChange(nextCls);
			setIsOpen(false);
			speak(
				nextCls
					? sprintf(__('Selected icon: %s', 'mds-release-manager'), labelText || nextCls)
					: __('Icon cleared.', 'mds-release-manager'),
				'polite'
			);
		}

		const current = String(value || '').trim();

		return el('div', { className: 'mds-icon-picker-control' },
			label && el('span', { className: 'mds-icon-picker-control__label' }, label),
			el('div', { className: 'mds-icon-picker-control__bar' },
				el('span', {
					className: `mds-icon-picker-control__preview${current ? '' : ' is-empty'}`,
					'aria-hidden': true,
				},
					current
						? el('i', { className: current })
						: el('span', { className: 'mds-icon-picker-control__none' }, '—')
				),
				el('code', { className: 'mds-icon-picker-control__code' }, current || __('No icon', 'mds-release-manager')),
				el(Button, {
					variant: 'secondary',
					size: 'compact',
					onClick: () => {
						setSearch('');
						setActiveCat(defaultCategory);
						setIsOpen(true);
					},
				}, __('Choose Icon', 'mds-release-manager')),
				allowEmpty && current && el(Button, {
					variant: 'tertiary',
					size: 'compact',
					isDestructive: true,
					onClick: () => choose('', ''),
				}, __('Clear', 'mds-release-manager'))
			),
			help && el('p', { className: 'mds-icon-picker-control__help description' }, help),

			isOpen && el(Modal, {
				title: label ? sprintf(__('Choose Icon — %s', 'mds-release-manager'), label) : __('Choose Icon', 'mds-release-manager'),
				className: 'mds-icon-picker-modal',
				onRequestClose: () => setIsOpen(false),
			},
				el('div', { className: 'mds-icon-picker-modal__top' },
					el(SearchControl, {
						label: __('Search icons', 'mds-release-manager'),
						placeholder: __('Search by name or keyword (e.g. vinyl, spotify, cart)…', 'mds-release-manager'),
						value: search,
						onChange: setSearch,
						__nextHasNoMarginBottom: true,
					})
				),
				el(TabPanel, {
					className: 'mds-icon-picker-modal__tabs',
					activeClass: 'is-active',
					initialTabName: defaultCategory,
					onSelect: setActiveCat,
					tabs: CATEGORIES,
				}, () =>
					el('div', { className: 'mds-icon-picker-modal__grid', role: 'listbox', 'aria-label': __('Available icons', 'mds-release-manager') },
						filteredIcons.length === 0
							? el('p', { className: 'mds-icon-picker-modal__empty' }, __('No matching icons in this category. Try another search or enter a custom Font Awesome class below.', 'mds-release-manager'))
							: filteredIcons.map((item) => {
								const isSelected = current === item.cls;
								return el('button', {
									key: item.cls,
									type: 'button',
									role: 'option',
									'aria-selected': isSelected,
									className: `mds-icon-picker-tile${isSelected ? ' is-selected' : ''}`,
									title: `${item.label} (${item.cls})`,
									onClick: () => choose(item.cls, item.label),
								},
									el('i', { className: item.cls, 'aria-hidden': true }),
									el('span', { className: 'mds-icon-picker-tile__label' }, item.label)
								);
							})
					)
				),
				el('div', { className: 'mds-icon-picker-modal__custom' },
					el('div', { style: { flex: '1 1 auto' } },
						el(TextControl, {
							label: __('Custom Icon CSS Classes', 'mds-release-manager'),
							help: __('Paste any Font Awesome 6 Free/Pro or Dashicons class string.', 'mds-release-manager'),
							placeholder: 'fas fa-compact-disc',
							value: customClass,
							onChange: setCustomClass,
							__next40pxDefaultSize: true,
							__nextHasNoMarginBottom: true,
						})
					),
					el(Button, {
						variant: 'primary',
						onClick: () => choose(customClass.trim(), customClass.trim()),
					}, __('Use Custom Class', 'mds-release-manager'))
				)
			)
		);
	}

	/**
	 * Auto-mounts IconPickerControl onto PHP taxonomy Add/Edit term fields marked with `[data-mds-icon-picker]`.
	 */
	function TaxonomyIconField({ inputName, inputId, initialValue, defaultCategory, help }) {
		const [val, setVal] = useState(initialValue || '');

		// Reset Add Term form field after WordPress AJAX term insertion.
		useEffect(() => {
			if (!window.jQuery) return;
			const handler = (_event, _xhr, settings) => {
				if (settings && typeof settings.data === 'string' && settings.data.indexOf('action=add-tag') !== -1) {
					setVal(initialValue || '');
				}
			};
			window.jQuery(document).ajaxSuccess(handler);
			return () => window.jQuery(document).off('ajaxSuccess', handler);
		}, [initialValue]);

		return el(Fragment, null,
			el('input', { type: 'hidden', name: inputName, id: inputId || undefined, value: val }),
			el(IconPickerControl, {
				value: val,
				onChange: setVal,
				defaultCategory: defaultCategory || 'music',
				help,
			})
		);
	}

	function mountTaxonomyPickers() {
		const nodes = document.querySelectorAll('[data-mds-icon-picker]');
		Array.prototype.forEach.call(nodes, (node) => {
			if (node.dataset.mdsMounted === '1') return;
			node.dataset.mdsMounted = '1';
			const props = {
				inputName: node.dataset.inputName || '',
				inputId: node.dataset.inputId || '',
				initialValue: node.dataset.initialValue || '',
				defaultCategory: node.dataset.defaultCategory || 'music',
				help: node.dataset.help || '',
			};
			const app = el(TaxonomyIconField, props);
			if (wp.element.createRoot) {
				wp.element.createRoot(node).render(app);
			} else {
				wp.element.render(app, node);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', mountTaxonomyPickers);
	} else {
		mountTaxonomyPickers();
	}

	window.mdsRmIconPicker = {
		IconPickerControl,
		ICON_CATALOG,
	};
})(window.wp);
