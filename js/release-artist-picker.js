(function() {
    var data = window.mdsReleaseArtistPickerData;
    var input = document.getElementById('mds-artist-search');
    var selection = document.getElementById('artist_id');
    var selectedLabel = document.getElementById('mds-artist-selected');
    var clearButton = document.getElementById('mds-clear-artist-selection');
    var results = document.getElementById('mds-artist-results');
    var pagination = document.getElementById('mds-artist-pagination');
    var status = document.getElementById('mds-artist-picker-status');

    if (!data || !input || !selection || !selectedLabel || !clearButton || !results || !pagination || !status) {
        return;
    }

    var debounceTimer;
    var requestTimer;
    var requestController;
    var requestNumber = 0;

    function clearResults() {
        while (results.firstChild) {
            results.removeChild(results.firstChild);
        }
        while (pagination.firstChild) {
            pagination.removeChild(pagination.firstChild);
        }
    }

    function makeButton(label, className, onClick) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.textContent = label;
        button.addEventListener('click', onClick);
        return button;
    }

    function renderResults(response, term, page) {
        clearResults();
        if (!Array.isArray(response.items) || !response.items.length) {
            status.textContent = data.no_results;
            return;
        }

        response.items.forEach(function(artist) {
            var option = makeButton(artist.title, 'button-link', function() {
                selection.value = artist.uuid;
                selectedLabel.textContent = data.selected.replace('%s', artist.title);
                selectedLabel.hidden = false;
                input.value = '';
                clearResults();
                status.textContent = '';
            });
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            results.appendChild(option);
        });

        var pages = Math.max(1, parseInt(response.pages, 10) || 1);
        if (pages > 1) {
            var paginationControls = document.createElement('div');
            paginationControls.className = 'mds-artist-picker-pagination';
            if (page > 1) {
                paginationControls.appendChild(makeButton(data.previous, 'button button-small', function() {
                    search(term, page - 1);
                }));
            }
            var pageLabel = document.createElement('span');
            pageLabel.textContent = ' ' + page + ' / ' + pages + ' ';
            paginationControls.appendChild(pageLabel);
            if (page < pages) {
                paginationControls.appendChild(makeButton(data.next, 'button button-small', function() {
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
        requestTimer = window.setTimeout(function() {
            controller.abort();
            if (sequence === requestNumber) {
                status.textContent = data.search_failed;
            }
        }, 15000);

        status.textContent = data.loading;
        var url = new URL(data.rest_url, window.location.href);
        url.searchParams.set('search', term);
        url.searchParams.set('page', page);

        fetch(url.toString(), {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': data.nonce },
            signal: controller.signal
        }).then(function(response) {
            if (!response.ok) {
                throw new Error('Artist search returned HTTP ' + response.status);
            }
            return response.json();
        }).then(function(response) {
            if (sequence === requestNumber) {
                renderResults(response, term, page);
            }
        }).catch(function(error) {
            if ('AbortError' !== error.name && sequence === requestNumber) {
                console.error('MDS Release Manager: Artist search failed.', error);
                status.textContent = data.search_failed;
            }
        }).then(function() {
            if (sequence === requestNumber) {
                window.clearTimeout(requestTimer);
            }
        });
    }

    input.addEventListener('input', function() {
        window.clearTimeout(debounceTimer);
        if (requestController) {
            requestController.abort();
        }
        ++requestNumber;
        clearResults();
        var term = input.value.trim();
        if (term.length < 2) {
            status.textContent = term ? data.minimum_length : data.type_to_search;
            return;
        }
        debounceTimer = window.setTimeout(function() {
            search(term, 1);
        }, 250);
    });

    clearButton.addEventListener('click', function() {
        selection.value = '';
        selectedLabel.textContent = '';
        selectedLabel.hidden = true;
        input.value = '';
        clearResults();
        status.textContent = data.cleared;
        if (requestController) {
            requestController.abort();
        }
        ++requestNumber;
    });
})();
