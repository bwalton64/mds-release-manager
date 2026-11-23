(function () {
	document.addEventListener('DOMContentLoaded', function () {
		var root = document.getElementById('mds-artist-routing-manager');
		if (!root || !window.mdsArtistRoutingData) {
			return;
		}

		var artistId = root.getAttribute('data-artist-id');
		var artistPolicySelect = document.getElementById('mds_artist_releases_routing_mode');
		var bulkSelect = document.getElementById('mds-routing-bulk-mode');
		var bulkApplyBtn = document.getElementById('mds-routing-bulk-apply');
		var resetAllBtn = document.getElementById('mds-routing-reset-all');
		var selectAllCb = document.getElementById('mds-routing-select-all');
		var searchInput = document.getElementById('mds-routing-search');
		var saveBtn = document.getElementById('mds-routing-save-btn');
		var statusEl = document.getElementById('mds-routing-status');

		var dirtyRows = {};

		function getRows() {
			return Array.prototype.slice.call(root.querySelectorAll('.mds-routing-row'));
		}

		function syncRowUI(row) {
			var modeSelect = row.querySelector('.mds-routing-mode-select');
			var urlInput = row.querySelector('.mds-routing-custom-url');
			var preview = row.querySelector('.mds-routing-preview');
			var isCustom = modeSelect && modeSelect.value === 'custom_url';
			if (urlInput) {
				urlInput.hidden = !isCustom;
			}
			if (preview) {
				preview.hidden = isCustom;
			}
		}

		function markDirty(row) {
			var id = row.getAttribute('data-release-id');
			if (id) {
				dirtyRows[id] = true;
				row.classList.add('is-dirty');
			}
		}

		function setStatus(text, isError) {
			if (!statusEl) {
				return;
			}
			statusEl.textContent = text;
			statusEl.classList.toggle('is-error', Boolean(isError));
		}

		getRows().forEach(function (row) {
			var modeSelect = row.querySelector('.mds-routing-mode-select');
			var urlInput = row.querySelector('.mds-routing-custom-url');
			if (modeSelect) {
				modeSelect.addEventListener('change', function () {
					syncRowUI(row);
					markDirty(row);
					var preview = row.querySelector('.mds-routing-preview');
					if (preview && modeSelect.value !== 'custom_url') {
						preview.textContent = 'Unsaved change — click Save Routing Changes to update';
						preview.classList.remove('is-warning');
					}
				});
			}
			if (urlInput) {
				urlInput.addEventListener('input', function () {
					markDirty(row);
				});
			}
		});

		if (selectAllCb) {
			selectAllCb.addEventListener('change', function () {
				getRows().forEach(function (row) {
					if (row.hidden) {
						return;
					}
					var cb = row.querySelector('.mds-routing-row-cb');
					if (cb) {
						cb.checked = selectAllCb.checked;
					}
				});
			});
		}

		if (searchInput) {
			searchInput.addEventListener('input', function () {
				var term = searchInput.value.trim().toLowerCase();
				getRows().forEach(function (row) {
					var haystack = row.getAttribute('data-search') || '';
					row.hidden = Boolean(term) && haystack.indexOf(term) === -1;
				});
			});
		}

		if (bulkApplyBtn && bulkSelect) {
			bulkApplyBtn.addEventListener('click', function () {
				var mode = bulkSelect.value;
				if (!mode) {
					return;
				}
				var selected = getRows().filter(function (row) {
					var cb = row.querySelector('.mds-routing-row-cb');
					return cb && cb.checked && !row.hidden;
				});
				if (!selected.length) {
					setStatus(window.mdsArtistRoutingData.no_select, true);
					return;
				}
				selected.forEach(function (row) {
					var modeSelect = row.querySelector('.mds-routing-mode-select');
					if (modeSelect) {
						modeSelect.value = mode;
						syncRowUI(row);
						markDirty(row);
						var preview = row.querySelector('.mds-routing-preview');
						if (preview) {
							preview.textContent = 'Unsaved change — click Save Routing Changes to update';
							preview.classList.remove('is-warning');
						}
					}
				});
				setStatus('', false);
			});
		}

		if (resetAllBtn) {
			resetAllBtn.addEventListener('click', function () {
				getRows().forEach(function (row) {
					var modeSelect = row.querySelector('.mds-routing-mode-select');
					if (modeSelect && modeSelect.value !== 'inherit') {
						modeSelect.value = 'inherit';
						syncRowUI(row);
						markDirty(row);
						var preview = row.querySelector('.mds-routing-preview');
						if (preview) {
							preview.textContent = 'Unsaved change — click Save Routing Changes to update';
							preview.classList.remove('is-warning');
						}
					}
				});
			});
		}

		if (saveBtn) {
			saveBtn.addEventListener('click', function () {
				saveBtn.disabled = true;
				saveBtn.textContent = window.mdsArtistRoutingData.saving;
				setStatus('', false);

				var homepageInput = document.getElementById('artist_homepage_url');
				var releasesPayload = getRows().map(function (row) {
					var modeSelect = row.querySelector('.mds-routing-mode-select');
					var urlInput = row.querySelector('.mds-routing-custom-url');
					return {
						id: Number(row.getAttribute('data-release-id')),
						mode: modeSelect ? modeSelect.value : 'inherit',
						custom_url: urlInput ? urlInput.value.trim() : '',
					};
				});

				var body = {
					artist_releases_routing_mode: artistPolicySelect ? artistPolicySelect.value : 'fallback',
					releases: releasesPayload,
				};
				if (homepageInput) {
					body.artist_homepage_url = homepageInput.value.trim();
				}

				fetch(window.mdsArtistRoutingData.rest_base + encodeURIComponent(artistId) + '/release-routing', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': window.mdsArtistRoutingData.nonce,
					},
					credentials: 'same-origin',
					body: JSON.stringify(body),
				})
					.then(function (resp) {
						return resp.json().then(function (data) {
							if (!resp.ok) {
								throw new Error((data && data.message) || window.mdsArtistRoutingData.error);
							}
							return data;
						});
					})
					.then(function (data) {
						dirtyRows = {};
						var previews = (data && data.previews) || {};
						getRows().forEach(function (row) {
							row.classList.remove('is-dirty');
							var id = row.getAttribute('data-release-id');
							var previewEl = row.querySelector('.mds-routing-preview');
							if (previewEl && previews[id]) {
								previewEl.textContent = previews[id].label || '';
								previewEl.classList.toggle('is-warning', Boolean(previews[id].is_warning));
							}
						});
						setStatus((data && data.message) || window.mdsArtistRoutingData.saved, !(data && data.success));
					})
					.catch(function (err) {
						setStatus(err.message || window.mdsArtistRoutingData.error, true);
					})
					.finally(function () {
						saveBtn.disabled = false;
						saveBtn.textContent = window.mdsArtistRoutingData.save_btn;
					});
			});
		}
	});
})();
