(function () {
	'use strict';

	if (
		typeof window.wp === 'undefined' ||
		!window.wp.element ||
		!window.wp.components ||
		!window.wp.i18n
	) {
		return;
	}

	var el = window.wp.element.createElement;
	var Fragment = window.wp.element.Fragment;
	var useState = window.wp.element.useState;
	var useMemo = window.wp.element.useMemo;
	var render = window.wp.element.render;
	var createRoot = window.wp.element.createRoot;
	var __ = window.wp.i18n.__;
	var sprintf = window.wp.i18n.sprintf;
	var Button = window.wp.components.Button;
	var Notice = window.wp.components.Notice;
	var Spinner = window.wp.components.Spinner;
	var speak =
		window.wp.a11y && typeof window.wp.a11y.speak === 'function'
			? window.wp.a11y.speak
			: function () {};

	var config = window.mdsCsvWizardData || {};
	var restBase = String(config.restBase || '').replace(/\/+$/, '');
	var restNonce = String(config.nonce || '');
	var isHub = Boolean(config.isHub);
	var isMultisite = Boolean(config.isMultisite);
	var targetFields = config.targetFields || {};
	var aiStatus = config.aiStatus || { available: false, provider: '', label: '' };
	var releaseTypes = Array.isArray(config.releaseTypes) && config.releaseTypes.length
		? config.releaseTypes
		: ['Single', 'EP', 'Album', 'Digital'];

	/**
	 * Performs an authenticated POST request against an MDS CSV REST endpoint.
	 *
	 * @param {string} endpoint Relative path such as '/suggest-map'.
	 * @param {Object} payload  JSON request body.
	 * @return {Promise<Object>} Parsed JSON response.
	 */
	function postCsvApi(endpoint, payload) {
		return window
			.fetch(restBase + endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': restNonce,
				},
				body: JSON.stringify(payload || {}),
			})
			.then(function (response) {
				return response.json().then(function (data) {
					if (!response.ok) {
						var message =
							(data && data.message) ||
							__('Request failed. Please try again.', 'mds-release-manager');
						throw new Error(message);
					}
					return data;
				});
			});
	}

	/**
	 * Pure RFC-4180 CSV parser supporting quoted fields, embedded commas/newlines,
	 * escaped double quotes, UTF-8 BOM, and CRLF/LF line endings.
	 *
	 * @param {string} rawText Raw CSV file contents.
	 * @return {{headers: string[], rows: string[][]}} Parsed headers and data rows.
	 */
	function parseCsvText(rawText) {
		var text = String(rawText || '');
		if (text.charCodeAt(0) === 0xfeff) {
			text = text.slice(1);
		}

		var records = [];
		var currentRow = [];
		var currentField = '';
		var inQuotes = false;
		var i = 0;
		var len = text.length;

		while (i < len) {
			var ch = text.charAt(i);
			if (inQuotes) {
				if (ch === '"') {
					if (i + 1 < len && text.charAt(i + 1) === '"') {
						currentField += '"';
						i += 2;
						continue;
					}
					inQuotes = false;
					i += 1;
					continue;
				}
				currentField += ch;
				i += 1;
				continue;
			}

			if (ch === '"') {
				inQuotes = true;
				i += 1;
				continue;
			}
			if (ch === ',') {
				currentRow.push(currentField);
				currentField = '';
				i += 1;
				continue;
			}
			if (ch === '\r' || ch === '\n') {
				currentRow.push(currentField);
				records.push(currentRow);
				currentRow = [];
				currentField = '';
				if (ch === '\r' && i + 1 < len && text.charAt(i + 1) === '\n') {
					i += 2;
				} else {
					i += 1;
				}
				continue;
			}
			currentField += ch;
			i += 1;
		}

		if (currentField.length > 0 || currentRow.length > 0) {
			currentRow.push(currentField);
			records.push(currentRow);
		}

		var nonEmpty = records.filter(function (row) {
			return row.some(function (cell) {
				return String(cell || '').trim() !== '';
			});
		});

		if (!nonEmpty.length) {
			return { headers: [], rows: [] };
		}

		var headers = nonEmpty[0].map(function (h, idx) {
			var trimmed = String(h || '').trim();
			return trimmed !== '' ? trimmed : 'Column ' + (idx + 1);
		});

		var rows = nonEmpty.slice(1).map(function (row) {
			var padded = row.slice(0, headers.length);
			while (padded.length < headers.length) {
				padded.push('');
			}
			return padded;
		});

		return { headers: headers, rows: rows };
	}

	/**
	 * Extracts sample values (up to 3 non-empty) for a given column index.
	 *
	 * @param {string[][]} rows Parsed CSV data rows.
	 * @param {number}     idx  Column index.
	 * @return {string[]} Up to 3 truncated sample values.
	 */
	function sampleColumnValues(rows, idx) {
		var out = [];
		for (var r = 0; r < rows.length && out.length < 3; r++) {
			var val = String(rows[r][idx] || '').trim();
			if (val !== '') {
				out.push(val.length > 48 ? val.slice(0, 45) + '…' : val);
			}
		}
		return out;
	}

	/**
	 * Converts a raw CSV row into a canonically keyed object based on column mappings.
	 *
	 * @param {string[]} rowsCell Raw cells for a single row.
	 * @param {Array}    mappings Mapping entries per column index.
	 * @return {Object} Canonically keyed release row.
	 */
	function buildMappedRow(rowCells, mappings) {
		var mapped = {};
		for (var i = 0; i < mappings.length; i++) {
			var target = mappings[i] ? mappings[i].target : '';
			if (!target) {
				continue;
			}
			var val = rowCells[i] !== undefined ? String(rowCells[i]).trim() : '';
			if (mapped[target] === undefined || mapped[target] === '') {
				mapped[target] = val;
			}
		}
		return mapped;
	}

	function ConfidenceBadge(props) {
		var confidence = props.confidence || 'none';
		var labelMap = {
			high: __('High', 'mds-release-manager'),
			medium: __('Medium', 'mds-release-manager'),
			ai: __('AI', 'mds-release-manager'),
			manual: __('Manual', 'mds-release-manager'),
			none: __('Skipped', 'mds-release-manager'),
		};
		return el(
			'span',
			{
				className: 'mds-csv-badge mds-csv-badge--' + confidence,
				title: props.reason || '',
			},
			labelMap[confidence] || confidence
		);
	}

	function CsvWizardApp() {
		var _step = useState(1);
		var step = _step[0];
		var setStep = _step[1];

		var _counts = useState(
			config.counts || { releases: 0, artists: 0, spokes: 0 }
		);
		var counts = _counts[0];
		var setCounts = _counts[1];

		var _fileName = useState('');
		var fileName = _fileName[0];
		var setFileName = _fileName[1];

		var _headers = useState([]);
		var headers = _headers[0];
		var setHeaders = _headers[1];

		var _rows = useState([]);
		var rows = _rows[0];
		var setRows = _rows[1];

		var _jsonExtras = useState([]);
		var jsonExtras = _jsonExtras[0];
		var setJsonExtras = _jsonExtras[1];

		var _backupArtists = useState([]);
		var backupArtists = _backupArtists[0];
		var setBackupArtists = _backupArtists[1];

		var _sourceFormat = useState('csv');
		var sourceFormat = _sourceFormat[0];
		var setSourceFormat = _sourceFormat[1];

		var _mode = useState('upsert');
		var mode = _mode[0];
		var setMode = _mode[1];

		var _duplicateMode = useState('skip_identical');
		var duplicateMode = _duplicateMode[0];
		var setDuplicateMode = _duplicateMode[1];

		var _dedupeCsv = useState(true);
		var dedupeCsv = _dedupeCsv[0];
		var setDedupeCsv = _dedupeCsv[1];

		var _purgeConfirm = useState('');
		var purgeConfirm = _purgeConfirm[0];
		var setPurgeConfirm = _purgeConfirm[1];

		var _mappings = useState([]);
		var mappings = _mappings[0];
		var setMappings = _mappings[1];

		var _defaultReleaseType = useState('');
		var defaultReleaseType = _defaultReleaseType[0];
		var setDefaultReleaseType = _defaultReleaseType[1];

		var _isSuggesting = useState(false);
		var isSuggesting = _isSuggesting[0];
		var setIsSuggesting = _isSuggesting[1];

		var _isAiMapping = useState(false);
		var isAiMapping = _isAiMapping[0];
		var setIsAiMapping = _isAiMapping[1];

		var _errorMsg = useState('');
		var errorMsg = _errorMsg[0];
		var setErrorMsg = _errorMsg[1];

		var _infoMsg = useState('');
		var infoMsg = _infoMsg[0];
		var setInfoMsg = _infoMsg[1];

		// Step 3 execution state.
		var _isRunning = useState(false);
		var isRunning = _isRunning[0];
		var setIsRunning = _isRunning[1];

		var _phaseLabel = useState('');
		var phaseLabel = _phaseLabel[0];
		var setPhaseLabel = _phaseLabel[1];

		var _progressPct = useState(0);
		var progressPct = _progressPct[0];
		var setProgressPct = _progressPct[1];

		var _runResult = useState(null);
		var runResult = _runResult[0];
		var setRunResult = _runResult[1];

		// Standalone danger-zone purge state.
		var _showDangerZone = useState(false);
		var showDangerZone = _showDangerZone[0];
		var setShowDangerZone = _showDangerZone[1];

		var _standaloneConfirm = useState('');
		var standaloneConfirm = _standaloneConfirm[0];
		var setStandaloneConfirm = _standaloneConfirm[1];

		var _isPurgingOnly = useState(false);
		var isPurgingOnly = _isPurgingOnly[0];
		var setIsPurgingOnly = _isPurgingOnly[1];

		var targetFieldOptions = useMemo(function () {
			var opts = [
				{
					value: '',
					label: __('— Skip Column —', 'mds-release-manager'),
				},
			];
			Object.keys(targetFields).forEach(function (key) {
				var meta = targetFields[key];
				var label = meta && meta.label ? meta.label : key;
				if (meta && meta.required) {
					label += ' *';
				}
				opts.push({ value: key, label: label });
			});
			return opts;
		}, []);

		var mappedTargets = useMemo(
			function () {
				var set = {};
				mappings.forEach(function (m) {
					if (m && m.target) {
						set[m.target] = true;
					}
				});
				return set;
			},
			[mappings]
		);

		var dryRun = useMemo(
			function () {
				var total = rows.length;
				var ready = 0;
				var missingUpc = 0;
				var missingType = 0;
				var duplicateUpcs = 0;
				var seenUpcs = {};

				for (var i = 0; i < rows.length; i++) {
					var mapped = buildMappedRow(rows[i], mappings);
					var upc = String(mapped.upc_ean || '').replace(/[^0-9]/g, '');
					var rType = String(mapped.release_type || '').trim() || defaultReleaseType;

					if (!upc) {
						missingUpc += 1;
						continue;
					}
					if (seenUpcs[upc]) {
						duplicateUpcs += 1;
					} else {
						seenUpcs[upc] = true;
					}
					if (!rType) {
						missingType += 1;
						continue;
					}
					ready += 1;
				}

				return {
					total: total,
					ready: ready,
					missingUpc: missingUpc,
					missingType: missingType,
					duplicateUpcs: duplicateUpcs,
				};
			},
			[rows, mappings, defaultReleaseType]
		);

		function requestHeuristicMapping(parsedHeaders, parsedRows) {
			setIsSuggesting(true);
			setErrorMsg('');
			return postCsvApi('/suggest-map', {
				headers: parsedHeaders,
				sample_rows: parsedRows.slice(0, 5),
			})
				.then(function (data) {
					if (Array.isArray(data.suggestions)) {
						setMappings(data.suggestions);
					}
				})
				.catch(function (err) {
					setErrorMsg(err.message);
				})
				.finally(function () {
					setIsSuggesting(false);
				});
		}

		function handleFileChange(event) {
			var file = event.target.files && event.target.files[0];
			setErrorMsg('');
			setInfoMsg('');
			if (!file) {
				setFileName('');
				setHeaders([]);
				setRows([]);
				setJsonExtras([]);
				setBackupArtists([]);
				setSourceFormat('csv');
				setMappings([]);
				return;
			}

			var isJsonFile =
				/\.json$/i.test(file.name) ||
				file.type === 'application/json';
			var isXmlFile =
				/\.xml$/i.test(file.name) ||
				file.type === 'application/xml' ||
				file.type === 'text/xml';

			var loadStructuredCatalog = function (releaseList, artistList, formatCode, reasonLabel) {
				var canonicalCols = [
					{ key: 'upc_ean', label: 'UPC/EAN' },
					{ key: 'title', label: 'Release Title' },
					{ key: 'artist', label: 'Artist' },
					{ key: 'release_date', label: 'Release Date' },
					{ key: 'release_type', label: 'Release Type' },
					{ key: 'record_label', label: 'Record Label' },
					{ key: 'distributor', label: 'Distributor' },
					{ key: 'buy_now_link', label: 'Buy Now Link' },
					{ key: 'tags', label: 'Tags' },
					{ key: 'featured_image_url', label: 'Featured Image URL' },
					{ key: 'audio_preview_url', label: 'Audio Preview URL' },
					{ key: 'about', label: 'About' },
					{ key: 'streaming_links_json', label: 'Streaming Links' },
				];
				var outHeaders = canonicalCols.map(function (c) {
					return c.label;
				});
				var outRows = [];
				var extras = [];
				releaseList.forEach(function (item) {
					if (!item || typeof item !== 'object') {
						return;
					}
					var linksVal = item.streaming_links;
					var linksStr =
						typeof linksVal === 'string'
							? linksVal
							: linksVal && typeof linksVal === 'object'
								? JSON.stringify(linksVal)
								: '';
					outRows.push([
						String(item.upc_ean || ''),
						String(item.title || item.release_title || ''),
						String(item.artist || ''),
						String(item.release_date || ''),
						String(item.release_type || ''),
						String(item.record_label || ''),
						String(item.distributor || ''),
						String(item.buy_now_link || ''),
						Array.isArray(item.tags) ? item.tags.join(', ') : String(item.tags || ''),
						String(item.featured_image_url || ''),
						String(item.audio_preview_url || ''),
						String(item.about || ''),
						linksStr,
					]);
					extras.push({
						artist_uuid: item.artist_uuid ? String(item.artist_uuid) : '',
						buy_now_links: Array.isArray(item.buy_now_links) ? item.buy_now_links : null,
					});
				});
				setFileName(file.name);
				setSourceFormat(formatCode);
				setHeaders(outHeaders);
				setRows(outRows);
				setJsonExtras(extras);
				setBackupArtists(Array.isArray(artistList) ? artistList : []);
				setMappings(
					canonicalCols.map(function (c) {
						return {
							header: c.label,
							target: c.key,
							confidence: 'high',
							reason: reasonLabel,
						};
					})
				);
				speak(
					sprintf(
						/* translators: %1$d: release count, %2$s: format name (JSON/XML). */
						__('Loaded %1$d releases from %2$s backup.', 'mds-release-manager'),
						outRows.length,
						formatCode.toUpperCase()
					)
				);
			};

			var reader = new window.FileReader();
			reader.onload = function (loadEvent) {
				var rawContent = String(loadEvent.target.result || '');

				if (isJsonFile) {
					try {
						var parsedJson = JSON.parse(rawContent);
						var list = Array.isArray(parsedJson)
							? parsedJson
							: parsedJson && Array.isArray(parsedJson.releases)
								? parsedJson.releases
								: null;
						var artists =
							parsedJson && !Array.isArray(parsedJson) && Array.isArray(parsedJson.artists)
								? parsedJson.artists
								: [];
						if (!list || !list.length) {
							setErrorMsg(
								__(
									'The JSON file does not contain any releases to import.',
									'mds-release-manager'
								)
							);
							return;
						}
						loadStructuredCatalog(
							list,
							artists,
							'json',
							__('MDS JSON Backup Schema', 'mds-release-manager')
						);
						return;
					} catch (parseErr) {
						setErrorMsg(
							__('The selected JSON file could not be parsed.', 'mds-release-manager')
						);
						return;
					}
				}

				if (isXmlFile) {
					if (/<!(?:DOCTYPE|ENTITY)\b/i.test(rawContent)) {
						setErrorMsg(
							__(
								'XML files containing DOCTYPE or ENTITY declarations are not allowed.',
								'mds-release-manager'
							)
						);
						return;
					}
					try {
						var parser = new window.DOMParser();
						var xmlDoc = parser.parseFromString(rawContent, 'application/xml');
						if (xmlDoc.getElementsByTagName('parsererror').length > 0) {
							setErrorMsg(
								__(
									'The selected XML file could not be parsed. Please ensure it is a valid MDS XML export.',
									'mds-release-manager'
								)
							);
							return;
						}
						var childText = function (parent, tagName) {
							var node = parent.getElementsByTagName(tagName)[0];
							return node && node.textContent ? node.textContent.trim() : '';
						};
						var xmlArtists = [];
						var artistNodes = xmlDoc.querySelectorAll('mds_catalog > artists > artist');
						for (var aIdx = 0; aIdx < artistNodes.length; aIdx++) {
							var aNode = artistNodes[aIdx];
							xmlArtists.push({
								uuid: childText(aNode, 'uuid'),
								name: childText(aNode, 'name'),
								slug: childText(aNode, 'slug'),
								status: childText(aNode, 'status'),
								website: childText(aNode, 'website'),
								portrait_url: childText(aNode, 'portrait_url'),
								routing_policy: childText(aNode, 'routing_policy'),
								bio: childText(aNode, 'bio'),
							});
						}
						var xmlReleases = [];
						var relNodes = xmlDoc.querySelectorAll('mds_catalog > releases > release');
						for (var rIdx = 0; rIdx < relNodes.length; rIdx++) {
							var rNode = relNodes[rIdx];
							var bLinks = [];
							var bNodes = rNode.querySelectorAll('buy_now_links > link');
							for (var bIdx = 0; bIdx < bNodes.length; bIdx++) {
								var bUrl = (bNodes[bIdx].textContent || '').trim();
								if (bUrl) {
									bLinks.push({
										format: bNodes[bIdx].getAttribute('format') || '',
										label: bNodes[bIdx].getAttribute('label') || '',
										url: bUrl,
									});
								}
							}
							var sLinks = {};
							var sNodes = rNode.querySelectorAll('streaming_links > link');
							for (var sIdx = 0; sIdx < sNodes.length; sIdx++) {
								var prov = (sNodes[sIdx].getAttribute('provider') || '').trim();
								var sUrl = (sNodes[sIdx].textContent || '').trim();
								if (prov && sUrl) {
									sLinks[prov] = sUrl;
								}
							}
							xmlReleases.push({
								upc_ean: childText(rNode, 'upc_ean'),
								title: childText(rNode, 'title'),
								artist_uuid: childText(rNode, 'artist_uuid'),
								artist: childText(rNode, 'artist'),
								release_date: childText(rNode, 'release_date'),
								release_type: childText(rNode, 'release_type'),
								record_label: childText(rNode, 'record_label'),
								distributor: childText(rNode, 'distributor'),
								buy_now_link: childText(rNode, 'buy_now_link'),
								buy_now_links: bLinks,
								tags: childText(rNode, 'tags'),
								featured_image_url: childText(rNode, 'featured_image_url'),
								audio_preview_url: childText(rNode, 'audio_preview_url'),
								about: childText(rNode, 'about'),
								streaming_links: sLinks,
							});
						}
						if (!xmlReleases.length) {
							setErrorMsg(
								__(
									'The XML file does not contain any <release> entries under <mds_catalog>.',
									'mds-release-manager'
								)
							);
							return;
						}
						loadStructuredCatalog(
							xmlReleases,
							xmlArtists,
							'xml',
							__('MDS XML Catalog Schema', 'mds-release-manager')
						);
						return;
					} catch (xmlErr) {
						setErrorMsg(
							__('The selected XML file could not be parsed.', 'mds-release-manager')
						);
						return;
					}
				}

				var parsed = parseCsvText(rawContent);
				if (!parsed.headers.length || !parsed.rows.length) {
					setErrorMsg(
						__(
							'The selected CSV file appears to be empty or has no data rows.',
							'mds-release-manager'
						)
					);
					setFileName('');
					setHeaders([]);
					setRows([]);
					setJsonExtras([]);
					setBackupArtists([]);
					setSourceFormat('csv');
					setMappings([]);
					return;
				}
				setFileName(file.name);
				setSourceFormat('csv');
				setHeaders(parsed.headers);
				setRows(parsed.rows);
				setJsonExtras([]);
				setBackupArtists([]);
				speak(
					sprintf(
						/* translators: %1$d: row count, %2$d: column count. */
						__('Parsed %1$d rows and %2$d columns.', 'mds-release-manager'),
						parsed.rows.length,
						parsed.headers.length
					)
				);
				requestHeuristicMapping(parsed.headers, parsed.rows);
			};
			reader.onerror = function () {
				setErrorMsg(
					__('Could not read the selected CSV file.', 'mds-release-manager')
				);
			};
			reader.readAsText(file);
		}

		function handleAiAssist() {
			if (!headers.length) {
				return;
			}
			setIsAiMapping(true);
			setErrorMsg('');
			setInfoMsg('');
			postCsvApi('/ai-map', {
				headers: headers,
				sample_rows: rows.slice(0, 5),
			})
				.then(function (data) {
					if (Array.isArray(data.suggestions)) {
						setMappings(data.suggestions);
						var msg = sprintf(
							/* translators: %s: AI provider label. */
							__('AI column mapping applied using %s.', 'mds-release-manager'),
							aiStatus.label || 'AI'
						);
						setInfoMsg(msg);
						speak(msg);
					}
				})
				.catch(function (err) {
					setErrorMsg(err.message);
				})
				.finally(function () {
					setIsAiMapping(false);
				});
		}

		function updateColumnTarget(index, newTarget) {
			setMappings(function (prev) {
				return prev.map(function (item, idx) {
					if (idx === index) {
						return {
							index: item.index,
							header: item.header,
							target: newTarget,
							confidence: newTarget ? 'manual' : 'none',
							reason: newTarget
								? __('Manually selected', 'mds-release-manager')
								: '',
						};
					}
					// Clear duplicate assignment on another column.
					if (newTarget && item.target === newTarget) {
						return {
							index: item.index,
							header: item.header,
							target: '',
							confidence: 'none',
							reason: '',
						};
					}
					return item;
				});
			});
		}

		function executePurgeLoop(confirmText, onProgress) {
			var totalHub = 0;
			var totalSpokes = 0;
			var latestCounts = counts;

			function nextPurgeBatch() {
				return postCsvApi('/purge', {
					confirm: confirmText,
					batch_size: 50,
				}).then(function (res) {
					totalHub += Number(res.deleted_hub || 0);
					totalSpokes += Number(res.deleted_spokes || 0);
					if (res.counts) {
						latestCounts = res.counts;
						setCounts(res.counts);
					}
					if (onProgress) {
						onProgress(totalHub, totalSpokes, Boolean(res.done));
					}
					if (!res.done) {
						return nextPurgeBatch();
					}
					return {
						deletedHub: totalHub,
						deletedSpokes: totalSpokes,
						counts: latestCounts,
					};
				});
			}

			return nextPurgeBatch();
		}

		function handleStandalonePurge() {
			if (standaloneConfirm.trim().toUpperCase() !== 'PURGE') {
				return;
			}
			setIsPurgingOnly(true);
			setErrorMsg('');
			setInfoMsg('');
			executePurgeLoop('PURGE', function (hubDel, spokeDel) {
				var msg = isMultisite
					? sprintf(
							/* translators: %1$d: Hub deleted count, %2$d: Spoke deleted count. */
							__('Purged %1$d Hub records and %2$d Spoke records…', 'mds-release-manager'),
							hubDel,
							spokeDel
						)
					: sprintf(
							/* translators: %d: Hub deleted count. */
							__('Purged %d catalog records…', 'mds-release-manager'),
							hubDel
						);
				setInfoMsg(msg);
			})
				.then(function (summary) {
					var doneMsg = isMultisite
						? sprintf(
								/* translators: %1$d: Hub deleted count, %2$d: Spoke deleted count. */
								__('Catalog purge complete: removed %1$d Hub entries and %2$d Spoke entries. WooCommerce products were left untouched.', 'mds-release-manager'),
								summary.deletedHub,
								summary.deletedSpokes
							)
						: sprintf(
								/* translators: %d: Hub deleted count. */
								__('Catalog purge complete: removed %d release and artist entries. WooCommerce products were left untouched.', 'mds-release-manager'),
								summary.deletedHub
							);
					setInfoMsg(doneMsg);
					setStandaloneConfirm('');
					speak(doneMsg);
				})
				.catch(function (err) {
					setErrorMsg(err.message);
				})
				.finally(function () {
					setIsPurgingOnly(false);
				});
		}

		function handleStartImport() {
			setStep(3);
			setIsRunning(true);
			setErrorMsg('');
			setInfoMsg('');
			setRunResult(null);
			setProgressPct(5);

			var totalCreated = 0;
			var totalUpdated = 0;
			var totalUnchanged = 0;
			var totalSkippedExisting = 0;
			var totalSkippedCsvDupes = 0;
			var totalArtistsRestored = 0;
			var totalSkipped = 0;
			var totalPurgedHub = 0;
			var totalPurgedSpokes = 0;
			var allErrors = [];
			var batchSize = 25;

			// Pre-map rows and optionally deduplicate repeated UPCs within this file.
			var seenCsvUpcs = {};
			var rowsToImport = [];
			for (var rIdx = 0; rIdx < rows.length; rIdx++) {
				var mappedObj = buildMappedRow(rows[rIdx], mappings);
				mappedObj._row_number = rIdx + 2;
				if (jsonExtras[rIdx]) {
					if (jsonExtras[rIdx].buy_now_links) {
						mappedObj.buy_now_links = jsonExtras[rIdx].buy_now_links;
					}
					if (jsonExtras[rIdx].artist_uuid) {
						mappedObj.artist_uuid = jsonExtras[rIdx].artist_uuid;
					}
				}
				var normUpc = String(mappedObj.upc_ean || '').replace(/[^0-9]/g, '');
				if (dedupeCsv && normUpc) {
					if (seenCsvUpcs[normUpc]) {
						totalSkippedCsvDupes += 1;
						continue;
					}
					seenCsvUpcs[normUpc] = true;
				}
				rowsToImport.push({
					rowNumber: rIdx + 2,
					mapped: mappedObj,
				});
			}

			var chain = Promise.resolve();

			if (mode === 'purge_reload') {
				setPhaseLabel(__('Purging existing artists and releases…', 'mds-release-manager'));
				chain = chain.then(function () {
					return executePurgeLoop('PURGE', function (hubDel, spokeDel) {
						totalPurgedHub = hubDel;
						totalPurgedSpokes = spokeDel;
						setPhaseLabel(
							isMultisite
								? sprintf(
										/* translators: %1$d: Hub deleted count, %2$d: Spoke deleted count. */
										__('Purging catalog (%1$d Hub, %2$d Spoke removed)…', 'mds-release-manager'),
										hubDel,
										spokeDel
									)
								: sprintf(
										/* translators: %d: Hub deleted count. */
										__('Purging catalog (%d removed)…', 'mds-release-manager'),
										hubDel
									)
						);
						setProgressPct(15);
					});
				});
			}

			chain = chain.then(function () {
				var totalRows = rowsToImport.length;
				var startIndex = 0;

				function nextImportBatch() {
					if (startIndex >= totalRows) {
						return Promise.resolve();
					}
					var slice = rowsToImport.slice(startIndex, startIndex + batchSize);
					var mappedSlice = slice.map(function (entry) {
						return entry.mapped;
					});
					var isLast = startIndex + batchSize >= totalRows;
					var currentStartRow = slice[0] ? slice[0].rowNumber : startIndex + 2;

					setPhaseLabel(
						sprintf(
							/* translators: %1$d: current processed count, %2$d: total rows. */
							__('Importing rows %1$d of %2$d…', 'mds-release-manager'),
							Math.min(startIndex + slice.length, totalRows),
							totalRows
						)
					);

					var batchPayload = {
						rows: mappedSlice,
						default_release_type: defaultReleaseType,
						duplicate_mode: mode === 'purge_reload' ? 'overwrite' : duplicateMode,
						start_row_number: currentStartRow,
						is_last_batch: isLast,
					};
					if (startIndex === 0 && backupArtists.length > 0) {
						batchPayload.artists = backupArtists;
					}

					return postCsvApi('/import-batch', batchPayload).then(function (res) {
						totalCreated += Number(res.created || 0);
						totalUpdated += Number(res.updated || 0);
						totalUnchanged += Number(res.unchanged || 0);
						totalSkippedExisting += Number(res.skipped_existing || 0);
						totalArtistsRestored += Number(res.artists_restored || 0);
						totalSkipped += Number(res.skipped || 0);
						if (Array.isArray(res.errors) && res.errors.length) {
							allErrors = allErrors.concat(res.errors);
						}
						if (res.counts) {
							setCounts(res.counts);
						}
						startIndex += slice.length;
						var basePct = mode === 'purge_reload' ? 20 : 5;
						var span = 100 - basePct;
						setProgressPct(
							Math.min(100, Math.round(basePct + (startIndex / Math.max(1, totalRows)) * span))
						);
						return nextImportBatch();
					});
				}

				return nextImportBatch();
			});

			chain
				.then(function () {
					setProgressPct(100);
					setPhaseLabel(__('Import complete', 'mds-release-manager'));
					setRunResult({
						created: totalCreated,
						updated: totalUpdated,
						unchanged: totalUnchanged,
						skippedExisting: totalSkippedExisting,
						skippedCsvDupes: totalSkippedCsvDupes,
						artistsRestored: totalArtistsRestored,
						skipped: totalSkipped,
						purgedHub: totalPurgedHub,
						purgedSpokes: totalPurgedSpokes,
						errors: allErrors,
					});
					speak(
						sprintf(
							/* translators: %1$d: created count, %2$d: updated count, %3$d: unchanged/skipped count. */
							__('CSV import complete: %1$d created, %2$d updated, %3$d unchanged or skipped.', 'mds-release-manager'),
							totalCreated,
							totalUpdated,
							totalUnchanged + totalSkippedExisting + totalSkippedCsvDupes
						)
					);
				})
				.catch(function (err) {
					setErrorMsg(err.message);
					setRunResult({
						created: totalCreated,
						updated: totalUpdated,
						unchanged: totalUnchanged,
						skippedExisting: totalSkippedExisting,
						skippedCsvDupes: totalSkippedCsvDupes,
						skipped: totalSkipped,
						purgedHub: totalPurgedHub,
						purgedSpokes: totalPurgedSpokes,
						errors: allErrors.concat([err.message]),
					});
				})
				.finally(function () {
					setIsRunning(false);
				});
		}

		function resetWizard() {
			setStep(1);
			setFileName('');
			setSourceFormat('csv');
			setHeaders([]);
			setRows([]);
			setJsonExtras([]);
			setBackupArtists([]);
			setMappings([]);
			setPurgeConfirm('');
			setErrorMsg('');
			setInfoMsg('');
			setRunResult(null);
			setProgressPct(0);
			setPhaseLabel('');
			var fileInput = document.getElementById('mds_csv_file');
			if (fileInput) {
				fileInput.value = '';
			}
		}

		if (!isHub) {
			return el(
				Notice,
				{ status: 'info', isDismissible: false },
				__(
					'CSV import and catalog purge are managed on the Hub site. Catalog updates sync automatically to this Spoke site.',
					'mds-release-manager'
				)
			);
		}

		var canContinueStep1 =
			headers.length > 0 &&
			rows.length > 0 &&
			!isSuggesting &&
			(mode !== 'purge_reload' || purgeConfirm.trim().toUpperCase() === 'PURGE');

		var hasRequiredMapping =
			Boolean(mappedTargets.upc_ean) &&
			(Boolean(mappedTargets.release_type) || Boolean(defaultReleaseType));

		return el(
			'div',
			{ className: 'mds-csv-wizard' },
			// Step indicator bar
			el(
				'ol',
				{ className: 'mds-csv-steps', 'aria-label': __('CSV Import Steps', 'mds-release-manager') },
				el(
					'li',
					{ className: 'mds-csv-step' + (step === 1 ? ' is-active' : step > 1 ? ' is-done' : '') },
					el('span', { className: 'mds-csv-step__num' }, '1'),
					el('span', { className: 'mds-csv-step__label' }, __('Upload & Mode', 'mds-release-manager'))
				),
				el(
					'li',
					{ className: 'mds-csv-step' + (step === 2 ? ' is-active' : step > 2 ? ' is-done' : '') },
					el('span', { className: 'mds-csv-step__num' }, '2'),
					el('span', { className: 'mds-csv-step__label' }, __('Map Columns & Validate', 'mds-release-manager'))
				),
				el(
					'li',
					{ className: 'mds-csv-step' + (step === 3 ? ' is-active' : '') },
					el('span', { className: 'mds-csv-step__num' }, '3'),
					el('span', { className: 'mds-csv-step__label' }, __('Run Import', 'mds-release-manager'))
				)
			),

			// Catalog counts strip
			el(
				'div',
				{ className: 'mds-csv-counts' },
				el(
					'span',
					{ className: 'mds-csv-count-pill' },
					sprintf(
						/* translators: %d: release count. */
						__('Hub Releases: %d', 'mds-release-manager'),
						counts.releases || 0
					)
				),
				el(
					'span',
					{ className: 'mds-csv-count-pill' },
					sprintf(
						/* translators: %d: artist count. */
						__('Hub Artists: %d', 'mds-release-manager'),
						counts.artists || 0
					)
				),
				isMultisite &&
					el(
						'span',
						{ className: 'mds-csv-count-pill' },
						sprintf(
							/* translators: %d: spoke count. */
							__('Configured Spokes: %d', 'mds-release-manager'),
							counts.spokes || 0
						)
					)
			),

			errorMsg &&
				el(
					Notice,
					{
						status: 'error',
						isDismissible: true,
						onRemove: function () {
							setErrorMsg('');
						},
					},
					errorMsg
				),

			infoMsg &&
				el(
					Notice,
					{
						status: 'success',
						isDismissible: true,
						onRemove: function () {
							setInfoMsg('');
						},
					},
					infoMsg
				),

			// STEP 1: Upload & Mode
			step === 1 &&
				el(
					Fragment,
					null,
					el(
						'table',
						{ className: 'form-table mds-csv-form-table' },
						el(
							'tbody',
							null,
							el(
								'tr',
								null,
								el(
									'th',
									{ scope: 'row' },
									el('label', { htmlFor: 'mds_csv_file' }, __('CSV File (or JSON / XML)', 'mds-release-manager'))
								),
								el(
									'td',
									null,
									el('input', {
										type: 'file',
										id: 'mds_csv_file',
										name: 'mds_csv_file',
										accept: '.csv,.json,.xml,text/csv,application/json,application/xml,text/xml',
										onChange: handleFileChange,
									}),
									fileName &&
										el(
											'p',
											{ className: 'description mds-csv-file-summary' },
											backupArtists.length > 0
												? sprintf(
														/* translators: %1$s: filename, %2$s: format, %3$d: release count, %4$d: artist count. */
														__('Loaded %1$s (%2$s) — %3$d releases and %4$d Artist profiles ready to import.', 'mds-release-manager'),
														fileName,
														sourceFormat.toUpperCase(),
														rows.length,
														backupArtists.length
													)
												: sprintf(
														/* translators: %1$s: filename, %2$d: row count, %3$d: column count. */
														__('Loaded %1$s — %2$d rows, %3$d columns detected.', 'mds-release-manager'),
														fileName,
														rows.length,
														headers.length
													)
										)
								)
							),
							el(
								'tr',
								null,
								el('th', { scope: 'row' }, __('Import Mode', 'mds-release-manager')),
								el(
									'td',
									null,
									el(
										'fieldset',
										{ className: 'mds-csv-mode-group' },
										el(
											'label',
											{ className: 'mds-csv-mode-option' },
											el('input', {
												type: 'radio',
												name: 'mds_csv_mode',
												value: 'upsert',
												checked: mode === 'upsert',
												onChange: function () {
													setMode('upsert');
													setPurgeConfirm('');
												},
											}),
											el('strong', null, __('Upsert (Recommended)', 'mds-release-manager')),
											el(
												'span',
												{ className: 'description' },
												__(
													'Create new releases and update existing ones matching UPC/EAN in place.',
													'mds-release-manager'
												)
											)
										),
										el(
											'label',
											{ className: 'mds-csv-mode-option mds-csv-mode-option--danger' },
											el('input', {
												type: 'radio',
												name: 'mds_csv_mode',
												value: 'purge_reload',
												checked: mode === 'purge_reload',
												onChange: function () {
													setMode('purge_reload');
												},
											}),
											el('strong', null, __('Purge & Reload', 'mds-release-manager')),
											el(
												'span',
												{ className: 'description' },
												isMultisite
													? __(
															'Permanently delete ALL Release and Artist entries on the Hub and configured Spoke sites before importing this CSV. WooCommerce products are not deleted.',
															'mds-release-manager'
														)
													: __(
															'Permanently delete ALL Release and Artist entries before importing this CSV. WooCommerce products are not deleted.',
															'mds-release-manager'
														)
											)
										)
									)
								)
							),
							el(
								'tr',
								null,
								el('th', { scope: 'row' }, el('label', { htmlFor: 'mds_csv_duplicate_mode' }, __('Duplicate Rules', 'mds-release-manager'))),
								el(
									'td',
									null,
									mode === 'upsert' &&
										el(
											'div',
											{ style: { marginBottom: '10px' } },
											el(
												'select',
												{
													id: 'mds_csv_duplicate_mode',
													value: duplicateMode,
													onChange: function (e) {
														setDuplicateMode(e.target.value);
													},
												},
												el(
													'option',
													{ value: 'skip_identical' },
													__('Update changed fields only — skip identical records (Recommended)', 'mds-release-manager')
												),
												el(
													'option',
													{ value: 'skip_existing' },
													__('Skip all existing UPC/EANs — create new releases only', 'mds-release-manager')
												),
												el(
													'option',
													{ value: 'overwrite' },
													__('Force overwrite all matching UPC/EANs', 'mds-release-manager')
												)
											)
										),
									el(
										'label',
										{ htmlFor: 'mds_csv_dedupe_file' },
										el('input', {
											type: 'checkbox',
											id: 'mds_csv_dedupe_file',
											checked: dedupeCsv,
											onChange: function (e) {
												setDedupeCsv(Boolean(e.target.checked));
											},
										}),
										' ',
										__('Skip duplicate UPC/EAN rows within this CSV file (keep first occurrence)', 'mds-release-manager')
									)
								)
							)
						)
					),

					mode === 'purge_reload' &&
						el(
							'div',
							{ className: 'mds-csv-danger-box', role: 'region', 'aria-label': __('Purge Confirmation', 'mds-release-manager') },
							el(
								'p',
								{ className: 'mds-csv-danger-box__title' },
								sprintf(
									/* translators: %1$d: release count, %2$d: artist count. */
									__('Warning: This will permanently delete %1$d releases and %2$d artists before importing.', 'mds-release-manager'),
									counts.releases || 0,
									counts.artists || 0
								)
							),
							el(
								'p',
								null,
								__('Export a CSV backup first if you may need to restore. Type PURGE below to unlock Purge & Reload.', 'mds-release-manager')
							),
							el(
								'label',
								{ htmlFor: 'mds_csv_purge_confirm', className: 'screen-reader-text' },
								__('Type PURGE to confirm', 'mds-release-manager')
							),
							el('input', {
								type: 'text',
								id: 'mds_csv_purge_confirm',
								className: 'regular-text',
								placeholder: 'PURGE',
								value: purgeConfirm,
								onChange: function (e) {
									setPurgeConfirm(e.target.value);
								},
							})
						),

					el(
						'div',
						{ className: 'mds-csv-actions' },
						el(
							Button,
							{
								variant: 'primary',
								disabled: !canContinueStep1,
								onClick: function () {
									setStep(2);
								},
							},
							isSuggesting
								? __('Analyzing Columns…', 'mds-release-manager')
								: __('Next: Review Field Mapping', 'mds-release-manager')
						),
						isSuggesting && el(Spinner, null)
					),

					// Standalone Danger Zone (Purge Only)
					el(
						'div',
						{ className: 'mds-csv-standalone-purge' },
						el(
							Button,
							{
								variant: 'link',
								isDestructive: true,
								onClick: function () {
									setShowDangerZone(!showDangerZone);
								},
							},
							showDangerZone
								? __('Hide Danger Zone: Purge Catalog Only', 'mds-release-manager')
								: __('Danger Zone: Purge All Artists & Releases Without Importing…', 'mds-release-manager')
						),
						showDangerZone &&
							el(
								'div',
								{ className: 'mds-csv-danger-box' },
								el(
									'p',
									{ className: 'mds-csv-danger-box__title' },
									__('Purge All Artists & Releases', 'mds-release-manager')
								),
								el(
									'p',
									null,
									isMultisite
										? __(
												'Permanently deletes every Release and Artist entry on the Hub and all configured Spoke sites. Linked WooCommerce products remain untouched.',
												'mds-release-manager'
											)
										: __(
												'Permanently deletes every Release and Artist entry on this site. Linked WooCommerce products remain untouched.',
												'mds-release-manager'
											)
								),
								el(
									'div',
									{ className: 'mds-csv-danger-box__controls' },
									el(
										'label',
										{ htmlFor: 'mds_csv_standalone_purge_confirm', className: 'screen-reader-text' },
										__('Type PURGE to confirm standalone purge', 'mds-release-manager')
									),
									el('input', {
										type: 'text',
										id: 'mds_csv_standalone_purge_confirm',
										className: 'regular-text',
										placeholder: 'PURGE',
										value: standaloneConfirm,
										disabled: isPurgingOnly,
										onChange: function (e) {
											setStandaloneConfirm(e.target.value);
										},
									}),
									el(
										Button,
										{
											variant: 'secondary',
											isDestructive: true,
											disabled:
												isPurgingOnly ||
												standaloneConfirm.trim().toUpperCase() !== 'PURGE',
											onClick: handleStandalonePurge,
										},
										isPurgingOnly
											? __('Purging…', 'mds-release-manager')
											: __('Purge Catalog Now', 'mds-release-manager')
									),
									isPurgingOnly && el(Spinner, null)
								)
							)
					)
				),

			// STEP 2: Smart Field Mapping & Dry-Run
			step === 2 &&
				el(
					Fragment,
					null,
					el(
						'div',
						{ className: 'mds-csv-map-toolbar' },
						el(
							'div',
							{ className: 'mds-csv-map-toolbar__left' },
							aiStatus.available &&
								el(
									Button,
									{
										variant: 'secondary',
										disabled: isAiMapping,
										onClick: handleAiAssist,
									},
									isAiMapping
										? __('Asking AI…', 'mds-release-manager')
										: sprintf(
												/* translators: %s: AI provider label. */
												__('Ask AI to Map Columns (%s)', 'mds-release-manager'),
												aiStatus.label
											)
								),
							isAiMapping && el(Spinner, null),
							el(
								Button,
								{
									variant: 'tertiary',
									disabled: isSuggesting || isAiMapping,
									onClick: function () {
										requestHeuristicMapping(headers, rows);
									},
								},
								__('Reset Auto-Mapping', 'mds-release-manager')
							)
						),
						el(
							'div',
							{ className: 'mds-csv-map-toolbar__right' },
							el(
								'label',
								{ htmlFor: 'mds_csv_default_type' },
								__('Default Release Type Fallback:', 'mds-release-manager')
							),
							el(
								'select',
								{
									id: 'mds_csv_default_type',
									value: defaultReleaseType,
									onChange: function (e) {
										setDefaultReleaseType(e.target.value);
									},
								},
								el('option', { value: '' }, __('— None (Require CSV Column) —', 'mds-release-manager')),
								releaseTypes.map(function (typeName) {
									return el('option', { key: typeName, value: typeName }, typeName);
								})
							)
						)
					),

					// Dry-run summary strip
					el(
						'div',
						{ className: 'mds-csv-dryrun' },
						el(
							'div',
							{ className: 'mds-csv-dryrun__item is-ok' },
							el('strong', null, String(dryRun.ready)),
							el('span', null, __('Ready to Import', 'mds-release-manager'))
						),
						el(
							'div',
							{ className: 'mds-csv-dryrun__item' + (dryRun.missingUpc ? ' is-warn' : '') },
							el('strong', null, String(dryRun.missingUpc)),
							el('span', null, __('Missing UPC/EAN', 'mds-release-manager'))
						),
						el(
							'div',
							{ className: 'mds-csv-dryrun__item' + (dryRun.missingType ? ' is-warn' : '') },
							el('strong', null, String(dryRun.missingType)),
							el('span', null, __('Missing Release Type', 'mds-release-manager'))
						),
						el(
							'div',
							{ className: 'mds-csv-dryrun__item' + (dryRun.duplicateUpcs ? ' is-warn' : '') },
							el('strong', null, String(dryRun.duplicateUpcs)),
							el('span', null, __('Duplicate UPCs in CSV', 'mds-release-manager'))
						)
					),

					!hasRequiredMapping &&
						el(
							Notice,
							{ status: 'warning', isDismissible: false },
							__(
								'Map a column to UPC/EAN and either map a Release Type column or choose a Default Release Type Fallback above to continue.',
								'mds-release-manager'
							)
						),

					el(
						'table',
						{ className: 'widefat striped mds-csv-map-table' },
						el(
							'thead',
							null,
							el(
								'tr',
								null,
								el('th', null, __('CSV Column', 'mds-release-manager')),
								el('th', null, __('Sample Values', 'mds-release-manager')),
								el('th', null, __('Target MDS Field', 'mds-release-manager')),
								el('th', null, __('Match', 'mds-release-manager'))
							)
						),
						el(
							'tbody',
							null,
							headers.map(function (header, idx) {
								var item = mappings[idx] || {
									index: idx,
									header: header,
									target: '',
									confidence: 'none',
									reason: '',
								};
								var samples = sampleColumnValues(rows, idx);
								return el(
									'tr',
									{ key: idx },
									el('td', { className: 'mds-csv-col-header' }, el('strong', null, header)),
									el(
										'td',
										{ className: 'mds-csv-col-samples' },
										samples.length
											? samples.map(function (s, sIdx) {
													return el('code', { key: sIdx, className: 'mds-csv-sample-chip' }, s);
												})
											: el('em', null, __('(empty)', 'mds-release-manager'))
									),
									el(
										'td',
										null,
										el(
											'select',
											{
												'aria-label': sprintf(
													/* translators: %s: CSV column header name. */
													__('Target MDS field for %s', 'mds-release-manager'),
													header
												),
												value: item.target || '',
												onChange: function (e) {
													updateColumnTarget(idx, e.target.value);
												},
											},
											targetFieldOptions.map(function (opt) {
												return el('option', { key: opt.value, value: opt.value }, opt.label);
											})
										)
									),
									el(
										'td',
										null,
										el(ConfidenceBadge, {
											confidence: item.confidence,
											reason: item.reason,
										})
									)
								);
							})
						)
					),

					el(
						'div',
						{ className: 'mds-csv-actions' },
						el(
							Button,
							{
								variant: 'secondary',
								onClick: function () {
									setStep(1);
								},
							},
							__('Back', 'mds-release-manager')
						),
						el(
							Button,
							{
								variant: 'primary',
								isDestructive: mode === 'purge_reload',
								disabled: !hasRequiredMapping || dryRun.ready === 0,
								onClick: handleStartImport,
							},
							mode === 'purge_reload'
								? sprintf(
										/* translators: %d: row count. */
										__('Purge Catalog & Import %d Releases', 'mds-release-manager'),
										dryRun.ready
									)
								: sprintf(
										/* translators: %d: row count. */
										__('Start Import (%d Releases)', 'mds-release-manager'),
										dryRun.ready
									)
						)
					)
				),

			// STEP 3: Batched Execution & Progress
			step === 3 &&
				el(
					'div',
					{ className: 'mds-csv-progress-panel' },
					el('h3', null, phaseLabel || __('Running…', 'mds-release-manager')),
					el(
						'div',
						{
							className: 'mds-csv-progressbar',
							role: 'progressbar',
							'aria-valuemin': 0,
							'aria-valuemax': 100,
							'aria-valuenow': progressPct,
						},
						el('div', {
							className: 'mds-csv-progressbar__fill',
							style: { width: progressPct + '%' },
						})
					),
					el('p', { className: 'mds-csv-progressbar__pct' }, progressPct + '%'),

					runResult &&
						el(
							Fragment,
							null,
							el(
								'div',
								{ className: 'mds-csv-dryrun' },
								el(
									'div',
									{ className: 'mds-csv-dryrun__item is-ok' },
									el('strong', null, String(runResult.created)),
									el('span', null, __('Created', 'mds-release-manager'))
								),
								el(
									'div',
									{ className: 'mds-csv-dryrun__item is-ok' },
									el('strong', null, String(runResult.updated)),
									el('span', null, __('Updated', 'mds-release-manager'))
								),
								runResult.artistsRestored > 0 &&
									el(
										'div',
										{ className: 'mds-csv-dryrun__item is-ok' },
										el('strong', null, String(runResult.artistsRestored)),
										el('span', null, __('Artists Restored', 'mds-release-manager'))
									),
								el(
									'div',
									{ className: 'mds-csv-dryrun__item' },
									el(
										'strong',
										null,
										String(
											(runResult.unchanged || 0) +
												(runResult.skippedExisting || 0) +
												(runResult.skippedCsvDupes || 0)
										)
									),
									el(
										'span',
										null,
										sprintf(
											/* translators: %1$d: identical count, %2$d: existing-skipped count, %3$d: duplicate CSV row count. */
											__('Skipped (%1$d identical, %2$d existing, %3$d CSV dupes)', 'mds-release-manager'),
											runResult.unchanged || 0,
											runResult.skippedExisting || 0,
											runResult.skippedCsvDupes || 0
										)
									)
								),
								mode === 'purge_reload' &&
									el(
										'div',
										{ className: 'mds-csv-dryrun__item' },
										el(
											'strong',
											null,
											String(runResult.purgedHub + runResult.purgedSpokes)
										),
										el('span', null, __('Purged Before Import', 'mds-release-manager'))
									),
								el(
									'div',
									{
										className:
											'mds-csv-dryrun__item' +
											(runResult.errors.length ? ' is-warn' : ''),
									},
									el('strong', null, String(runResult.errors.length)),
									el('span', null, __('Errors', 'mds-release-manager'))
								)
							),

							runResult.errors.length > 0 &&
								el(
									'details',
									{ className: 'mds-csv-errors-log', open: true },
									el(
										'summary',
										null,
										sprintf(
											/* translators: %d: error count. */
											__('Row Errors (%d)', 'mds-release-manager'),
											runResult.errors.length
										)
									),
									el(
										'ul',
										null,
										runResult.errors.map(function (errText, eIdx) {
											return el('li', { key: eIdx }, errText);
										})
									)
								),

							el(
								'div',
								{ className: 'mds-csv-actions' },
								el(
									Button,
									{
										variant: 'primary',
										disabled: isRunning,
										onClick: resetWizard,
									},
									__('Import Another File', 'mds-release-manager')
								)
							)
						)
				)
		);
	}

	document.addEventListener('DOMContentLoaded', function () {
		// Wire up Export Options format switcher (CSV / JSON / XML).
		var exportFormatSelect = document.getElementById('mds_export_format');
		var exportSubmitBtn = document.getElementById('mds_export_submit_btn');
		if (exportFormatSelect && exportSubmitBtn) {
			var syncExportFormatUi = function () {
				var fmt = exportFormatSelect.value || 'csv';
				var isCsv = fmt === 'csv';
				var csvOnlyEls = document.querySelectorAll('.mds-export-csv-only');
				csvOnlyEls.forEach(function (node) {
					node.style.display = isCsv ? '' : 'none';
				});
				if (fmt === 'json') {
					exportSubmitBtn.textContent = __('Export to JSON', 'mds-release-manager');
				} else if (fmt === 'xml') {
					exportSubmitBtn.textContent = __('Export to XML', 'mds-release-manager');
				} else {
					exportSubmitBtn.textContent = __('Export to CSV', 'mds-release-manager');
				}
			};
			exportFormatSelect.addEventListener('change', syncExportFormatUi);
			syncExportFormatUi();
		}

		var mount = document.getElementById('mds-csv-wizard-root');
		if (!mount) {
			return;
		}
		if (typeof createRoot === 'function') {
			createRoot(mount).render(el(CsvWizardApp, null));
		} else if (typeof render === 'function') {
			render(el(CsvWizardApp, null), mount);
		}
	});
})();
