(function () {
	var data = window.mdsReleaseArtistPickerData;
	var input = document.getElementById('mds-artist-search');
	var selection = document.getElementById('artist_id');
	var chip = document.getElementById('mds-artist-chip');
	var selectedLabel = document.getElementById('mds-artist-selected');
	var editLink = document.getElementById('mds-artist-edit-link');
	var clearButton = document.getElementById('mds-clear-artist-selection');
	var results = document.getElementById('mds-artist-results');
	var pagination = document.getElementById('mds-artist-pagination');
	var status = document.getElementById('mds-artist-picker-status');

	if (!data || !input || !selection || !selectedLabel || !clearButton || !results || !pagination || !status) {
		return;
	}

	var speak = (window.wp && window.wp.a11y && typeof window.wp.a11y.speak === 'function')
		? window.wp.a11y.speak
		: function () {};

	var debounceTimer;
	var requestTimer;
	var requestController;
	var requestNumber = 0;
	var activeIndex = -1;

	function getOptions() {
		return Array.prototype.slice.call(results.querySelectorAll('[role="option"]'));
	}

	function setActiveOption(index) {
		var options = getOptions();
		options.forEach(function (opt, i) {
			var isCurrent = i === index;
			opt.setAttribute('aria-selected', isCurrent ? 'true' : 'false');
			opt.classList.toggle('is-active', isCurrent);
		});
		activeIndex = index;
		if (index >= 0 && options[index]) {
			input.setAttribute('aria-activedescendant', options[index].id);
			options[index].scrollIntoView({ block: 'nearest' });
		} else {
			input.removeAttribute('aria-activedescendant');
		}
	}

	function clearResults() {
		while (results.firstChild) {
			results.removeChild(results.firstChild);
		}
		while (pagination.firstChild) {
			pagination.removeChild(pagination.firstChild);
		}
		activeIndex = -1;
		input.setAttribute('aria-expanded', 'false');
		input.removeAttribute('aria-activedescendant');
	}

	function makeButton(label, className, onClick) {
		var button = document.createElement('button');
		button.type = 'button';
		button.className = className;
		button.textContent = label;
		button.addEventListener('click', onClick);
		return button;
	}

	function selectArtist(artist) {
		selection.value = artist.uuid;
		selectedLabel.textContent = artist.title;
		if (chip) {
			chip.hidden = false;
		} else {
			selectedLabel.hidden = false;
		}
		if (editLink) {
			editLink.hidden = true;
		}
		input.value = '';
		clearResults();
		var msg = (data.selected || 'Selected: %s').replace('%s', artist.title);
		status.textContent = msg;
		speak(msg, 'polite');
		input.focus();
	}

	function renderResults(response, term, page) {
		clearResults();
		if (!Array.isArray(response.items) || !response.items.length) {
			status.textContent = data.no_results;
			speak(data.no_results, 'polite');
			return;
		}

		response.items.forEach(function (artist, idx) {
			var option = makeButton(artist.title, 'mds-artist-option', function () {
				selectArtist(artist);
			});
			option.id = 'mds-artist-opt-' + idx;
			option.setAttribute('role', 'option');
			option.setAttribute('aria-selected', 'false');
			results.appendChild(option);
		});

		input.setAttribute('aria-expanded', 'true');
		setActiveOption(0);

		var pages = Math.max(1, parseInt(response.pages, 10) || 1);
		if (pages > 1) {
			var paginationControls = document.createElement('div');
			paginationControls.className = 'mds-artist-picker-pagination';
			if (page > 1) {
				paginationControls.appendChild(makeButton(data.previous, 'button button-small', function () {
					search(term, page - 1);
				}));
			}
			var pageLabel = document.createElement('span');
			pageLabel.textContent = ' ' + page + ' / ' + pages + ' ';
			paginationControls.appendChild(pageLabel);
			if (page < pages) {
				paginationControls.appendChild(makeButton(data.next, 'button button-small', function () {
					search(term, page + 1);
				}));
			}
			pagination.appendChild(paginationControls);
		}
		status.textContent = '';
	}

	function search(term, page) {
		if (requestController) {
			requestController.abort();
		}
		window.clearTimeout(requestTimer);

		requestController = new AbortController();
		var controller = requestController;
		var sequence = ++requestNumber;
		requestTimer = window.setTimeout(function () {
			controller.abort();
			if (sequence === requestNumber) {
				status.textContent = data.search_failed;
			}
		}, 15000);

		status.textContent = data.loading;
		var url = new URL(data.rest_url, window.location.origin);
		url.searchParams.set('search', term);
		url.searchParams.set('page', String(page));

		window.fetch(url.toString(), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': data.nonce },
			signal: controller.signal,
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('Request failed');
			}
			return response.json();
		}).then(function (payload) {
			window.clearTimeout(requestTimer);
			if (sequence !== requestNumber) {
				return;
			}
			renderResults(payload, term, page);
		}).catch(function (error) {
			window.clearTimeout(requestTimer);
			if (error && 'AbortError' === error.name) {
				return;
			}
			if (sequence === requestNumber) {
				clearResults();
				status.textContent = data.search_failed;
			}
		});
	}

	input.addEventListener('input', function () {
		window.clearTimeout(debounceTimer);
		var term = input.value.trim();
		if (!term) {
			clearResults();
			status.textContent = data.type_to_search;
			return;
		}
		if (term.length < 2) {
			clearResults();
			status.textContent = data.minimum_length;
			return;
		}
		debounceTimer = window.setTimeout(function () {
			search(term, 1);
		}, 250);
	});

	input.addEventListener('keydown', function (event) {
		var options = getOptions();
		if (!options.length) {
			if ('Escape' === event.key && input.value) {
				input.value = '';
				clearResults();
			}
			return;
		}
		if ('ArrowDown' === event.key) {
			event.preventDefault();
			setActiveOption((activeIndex + 1) % options.length);
		} else if ('ArrowUp' === event.key) {
			event.preventDefault();
			setActiveOption((activeIndex - 1 + options.length) % options.length);
		} else if ('Enter' === event.key && activeIndex >= 0 && options[activeIndex]) {
			event.preventDefault();
			options[activeIndex].click();
		} else if ('Escape' === event.key) {
			event.preventDefault();
			clearResults();
		}
	});

	clearButton.addEventListener('click', function () {
		selection.value = '';
		selectedLabel.textContent = '';
		if (chip) {
			chip.hidden = true;
		} else {
			selectedLabel.hidden = true;
		}
		clearResults();
		status.textContent = data.cleared;
		speak(data.cleared, 'polite');
		input.focus();
	});
})();
