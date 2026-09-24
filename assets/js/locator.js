(function () {
	'use strict';

	var DEFAULTS = {
		search_placeholder: 'Zip code or address',
		search_button: 'Search',
		use_location: 'Use my location',
		locating: 'Finding your location...',
		location_error: 'Your location could not be read. Please try again or search by zip code or address.',
		location_insecure: 'Location is only available on a secure page.',
		searching: 'Searching...',
		network_error: 'We could not complete that search. Please try again.',
		invalid_zip: 'Please enter a valid 5-digit zip code.',
		empty_input: 'Please enter a zip code or an address.',
		results_count_one: '1 dealer',
		results_count_many: '%d dealers',
		distance_away: '%s away',
		unit_mi: 'mi',
		unit_km: 'km',
		phone: 'Phone',
		website: 'Website',
		email: 'Email',
		view_on_map: 'View on map',
		map_label: 'Dealer map'
	};

	function boot() {
		var nodes = document.querySelectorAll('.low-dl-locator');
		var index;

		for (index = 0; index < nodes.length; index++) {
			init(nodes[index]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

	function init(root) {
		if (root.getAttribute('data-low-dl-ready') === '1') {
			return;
		}

		root.setAttribute('data-low-dl-ready', '1');

		var i18n = readI18n(root);
		var messageEl = root.querySelector('.low-dl-message');
		var mapEl = root.querySelector('.low-dl-map');
		var form = root.querySelector('.low-dl-form');
		var resultsEl = root.querySelector('.low-dl-results');
		var headingEl = resultsEl ? resultsEl.querySelector('h1, h2, h3, h4, h5, h6') : null;
		var listEl = resultsEl ? resultsEl.querySelector('ul') : null;
		var geoBtn = root.querySelector('.low-dl-geo');
		var submitBtn = form ? form.querySelector('button[type="submit"]') : null;
		var queryInput = form ? form.querySelector('input[name="q"]') : null;

		function text(key) {
			if (i18n && typeof i18n[key] === 'string' && i18n[key] !== '') {
				return i18n[key];
			}

			if (typeof DEFAULTS[key] === 'string') {
				return DEFAULTS[key];
			}

			return '';
		}

		function showMessage(message, isError) {
			if (!messageEl) {
				return;
			}

			messageEl.textContent = message || '';

			if (isError && message) {
				messageEl.classList.add('is-error');
			} else {
				messageEl.classList.remove('is-error');
			}
		}

		if (!window.L) {
			showMessage(text('network_error') || 'We could not complete that search. Please try again.', true);
			return;
		}

		if (!mapEl || !form) {
			return;
		}

		var zoom = parseInt(root.getAttribute('data-zoom'), 10);

		if (!isFinite(zoom)) {
			zoom = 4;
		}

		var map = window.L.map(mapEl, {
			scrollWheelZoom: false,
			attributionControl: true
		});

		map.setView([39.5, -98.35], zoom);

		function enableWheel() {
			if (map.scrollWheelZoom) {
				map.scrollWheelZoom.enable();
			}
		}

		map.once('click', enableWheel);
		map.once('focus', enableWheel);

		window.L.tileLayer(root.getAttribute('data-tile-url') || '', {
			maxZoom: 19,
			attribution: escapeHtml(root.getAttribute('data-attribution') || '')
		}).addTo(map);

		var markers = window.L.layerGroup().addTo(map);
		var dealerIcon = window.L.divIcon({
			className: 'low-dl-divicon',
			html: '<span class="low-dl-marker"></span>',
			iconSize: [24, 24],
			iconAnchor: [12, 24],
			popupAnchor: [0, -22]
		});
		var originIcon = window.L.divIcon({
			className: 'low-dl-divicon',
			html: '<span class="low-dl-origin"></span>',
			iconSize: [24, 24],
			iconAnchor: [12, 12]
		});
		var searchController = null;
		var searchToken = 0;
		var resultsShown = false;

		map.invalidateSize();

		loadDealers();

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			var raw = queryInput ? queryInput.value : '';
			var parsed = classifyQuery(raw);

			if (parsed.error) {
				showMessage(text(parsed.error), true);
				return;
			}

			runSearch(parsed);
		});

		if (geoBtn && navigator.geolocation) {
			geoBtn.removeAttribute('hidden');
			geoBtn.addEventListener('click', function () {
				if (!window.isSecureContext) {
					showMessage(text('location_insecure'), true);
					return;
				}

				showMessage(text('locating'), false);
				navigator.geolocation.getCurrentPosition(function (position) {
					runSearch({
						type: 'coords',
						lat: roundCoord(position.coords.latitude),
						lng: roundCoord(position.coords.longitude)
					});
				}, function () {
					showMessage(text('location_error'), true);
				}, {
					enableHighAccuracy: false,
					timeout: 10000,
					maximumAge: 60000
				});
			});
		}

		function loadDealers() {
			var url = root.getAttribute('data-dealers-url');

			if (!url) {
				return;
			}

			window.fetch(url, {
				method: 'GET',
				credentials: 'same-origin'
			}).then(function (response) {
				if (!response.ok) {
					throw new Error('dealers');
				}

				return response.json();
			}).then(function (data) {
				if (resultsShown) {
					return;
				}

				var dealers = data && Array.isArray(data.dealers) ? data.dealers : [];
				var points = [];
				var index;

				for (index = 0; index < dealers.length; index++) {
					var point = pointOf(dealers[index]);

					if (!point) {
						continue;
					}

					addDealerMarker(dealers[index], point);
					points.push(point);
				}

				fitInitial(points);
				map.invalidateSize();
			}).catch(function () {
				if (resultsShown || (messageEl && messageEl.textContent)) {
					return;
				}

				showMessage(text('network_error'), true);
			});
		}

		function runSearch(params) {
			var url;

			try {
				url = buildSearchUrl(root.getAttribute('data-search-url') || '', params);
			} catch (error) {
				showMessage(text('network_error'), true);
				return;
			}

			searchToken += 1;
			var token = searchToken;

			if (searchController) {
				searchController.abort();
			}

			searchController = new window.AbortController();
			setBusy(true);

			window.fetch(url, {
				method: 'GET',
				credentials: 'same-origin',
				signal: searchController.signal
			}).then(function (response) {
				if (token !== searchToken) {
					return null;
				}

				if (!response.ok) {
					return response.json().catch(function () {
						return null;
					}).then(function (body) {
						if (token !== searchToken) {
							return;
						}

						if (body && typeof body.message === 'string' && body.message !== '') {
							showMessage(body.message, true);
						} else {
							showMessage(text('network_error'), true);
						}
					});
				}

				return response.json().then(function (data) {
					if (token !== searchToken) {
						return;
					}

					renderResults(data);
				});
			}).catch(function (error) {
				if (error && error.name === 'AbortError') {
					return;
				}

				if (token !== searchToken) {
					return;
				}

				showMessage(text('network_error'), true);
			}).finally(function () {
				if (token !== searchToken) {
					return;
				}

				setBusy(false);
			});
		}

		function renderResults(data) {
			var dealers = data && Array.isArray(data.dealers) ? data.dealers : [];
			var unit = data && data.unit === 'km' ? 'km' : (data && data.unit === 'mi' ? 'mi' : (root.getAttribute('data-unit') === 'km' ? 'km' : 'mi'));
			var notice = data && typeof data.message === 'string' ? data.message.trim() : '';
			var points = [];
			var index;

			resultsShown = true;

			if (headingEl) {
				headingEl.textContent = data && typeof data.heading === 'string' ? data.heading : '';
			}

			clearList();
			markers.clearLayers();

			if (data && data.mode === 'not_found') {
				showMessage(notice, false);
				map.invalidateSize();
				return;
			}

			var origin = pointOf(data ? data.origin : null);
			var occupied = {};

			if (origin) {
				markers.addLayer(window.L.marker(origin, {
					icon: originIcon,
					keyboard: false,
					interactive: false,
					zIndexOffset: -100
				}));
				points.push(origin);
				rememberPoint(occupied, origin);
			}

			for (index = 0; index < dealers.length; index++) {
				var dealer = dealers[index];
				var point = pointOf(dealer);
				var marker = null;

				if (point) {
					point = separatePoint(point, occupied);
					marker = addDealerMarker(dealer, point, unit);
					points.push(point);
				}

				if (listEl) {
					listEl.appendChild(buildCard(dealer, marker, point, unit));
				}
			}

			if (notice) {
				showMessage(notice, false);
			} else if (dealers.length > 0) {
				showMessage(countText(dealers.length), false);
			} else {
				showMessage('', false);
			}

			fitResults(points);
			map.invalidateSize();
		}

		function addDealerMarker(dealer, point, unit) {
			var marker = window.L.marker(point, {
				icon: dealerIcon
			});

			marker.bindPopup(popupNode(dealer, unit), {
				minWidth: 180,
				maxWidth: 280
			});
			markers.addLayer(marker);

			return marker;
		}

		function buildCard(dealer, marker, point, unit) {
			var card = document.createElement('li');
			var name = document.createElement('strong');

			card.className = 'low-dl-dealer';
			name.textContent = asText(dealer && dealer.name);
			card.appendChild(name);
			appendAddress(card, dealer && dealer.address);

			if (dealer && dealer.distance !== null && dealer.distance !== undefined && dealer.distance !== '') {
				var distance = document.createElement('p');
				distance.textContent = formatDistance(dealer.distance, unit);
				card.appendChild(distance);
			}

			appendPhone(card, dealer && dealer.phone);
			appendEmail(card, dealer && dealer.email);
			appendWebsite(card, dealer && dealer.website);

			if (marker && point) {
				var button = document.createElement('button');
				button.setAttribute('type', 'button');
				button.className = 'low-dl-view';
				button.textContent = text('view_on_map');
				button.addEventListener('click', function () {
					activate(card, marker, point, false);
				});
				marker.on('click', function () {
					activate(card, marker, point, true);
				});
				card.appendChild(button);
			}

			return card;
		}

		function activate(card, marker, point, fromMarker) {
			var active;
			var index;

			if (listEl) {
				active = listEl.querySelectorAll('.is-active');

				for (index = 0; index < active.length; index++) {
					active[index].classList.remove('is-active');
				}
			}

			card.classList.add('is-active');

			if (fromMarker && card.scrollIntoView) {
				try {
					card.scrollIntoView({ block: 'nearest' });
				} catch (error) {
					card.scrollIntoView();
				}
			}

			map.setView(point, Math.max(12, map.getZoom()));
			marker.openPopup();
		}

		function rememberPoint(occupied, point) {
			var key = point[0].toFixed(4) + ',' + point[1].toFixed(4);
			occupied[key] = (occupied[key] || 0) + 1;
			return key;
		}

		function separatePoint(point, occupied) {
			var key = point[0].toFixed(4) + ',' + point[1].toFixed(4);
			var count = occupied[key] || 0;

			occupied[key] = count + 1;

			if (count === 0) {
				return point;
			}

			var angle = count * 0.9;
			var radius = 0.02 * (1 + Math.floor((count - 1) / 8));

			return [
				point[0] + (Math.sin(angle) * radius),
				point[1] + (Math.cos(angle) * radius)
			];
		}

		function fitInitial(points) {
			if (points.length >= 2) {
				map.fitBounds(points, {
					padding: [30, 30],
					maxZoom: Math.min(16, zoom + 6)
				});
			} else if (points.length === 1) {
				map.setView(points[0], zoom);
			}
		}

		function fitResults(points) {
			if (points.length >= 2) {
				map.fitBounds(points, {
					padding: [30, 30],
					maxZoom: 14
				});
			} else if (points.length === 1) {
				map.setView(points[0], Math.min(14, zoom + 6));
			}
		}

		function clearList() {
			if (!listEl) {
				return;
			}

			while (listEl.firstChild) {
				listEl.removeChild(listEl.firstChild);
			}
		}

		function setBusy(busy) {
			if (submitBtn) {
				submitBtn.disabled = busy;
			}

			if (geoBtn) {
				geoBtn.disabled = busy;
			}

			if (busy) {
				showMessage(text('searching'), false);
			}
		}

		function countText(count) {
			if (count === 1) {
				return fill(text('results_count_one'), '1');
			}

			return fill(text('results_count_many'), String(count));
		}

		function formatDistance(distance, unit) {
			var number = Number(distance);

			if (!isFinite(number)) {
				return '';
			}

			var label = unit === 'km' ? text('unit_km') : text('unit_mi');

			return fill(text('distance_away'), number.toFixed(1) + ' ' + label);
		}

		function popupNode(dealer, unit) {
			var node = document.createElement('div');
			var name = document.createElement('strong');

			node.className = 'low-dl-popup';
			name.textContent = asText(dealer && dealer.name);
			node.appendChild(name);
			appendAddress(node, dealer && dealer.address);

			if (dealer && dealer.distance !== null && dealer.distance !== undefined && dealer.distance !== '') {
				var distance = document.createElement('p');
				distance.textContent = formatDistance(dealer.distance, unit);
				node.appendChild(distance);
			}

			appendPhone(node, dealer && dealer.phone);
			appendEmail(node, dealer && dealer.email);
			appendWebsite(node, dealer && dealer.website);

			return node;
		}

		function appendAddress(parent, address) {
			var street = asText(address && address.street);
			var city = asText(address && address.city);
			var state = asText(address && address.state);
			var zip = asText(address && address.zip);
			var cityState = joinParts([city, state], ', ');
			var line = joinParts([cityState, zip], ' ');

			if (street) {
				appendLine(parent, street);
			}

			if (line) {
				appendLine(parent, line);
			}
		}

		function appendPhone(parent, phone) {
			var visible = asText(phone);
			var href = telHref(visible);

			if (!visible || !href) {
				return;
			}

			appendLink(parent, href, text('phone'), visible, false);
		}

		function appendEmail(parent, email) {
			var visible = asText(email);

			if (!visible || !isEmail(visible)) {
				return;
			}

			appendLink(parent, 'mailto:' + visible, text('email'), visible, false);
		}

		function appendWebsite(parent, website) {
			var visible = asText(website);

			if (!isHttpUrl(visible)) {
				return;
			}

			appendLink(parent, visible, text('website'), visible, true);
		}

		function appendLink(parent, href, label, visible, external) {
			var line = document.createElement('p');
			var link = document.createElement('a');
			var hidden = document.createElement('span');

			hidden.className = 'low-dl-sr-only';
			hidden.textContent = label + ' ';
			link.setAttribute('href', href);
			link.appendChild(hidden);
			link.appendChild(document.createTextNode(visible));

			if (external) {
				link.setAttribute('rel', 'noopener noreferrer');
				link.setAttribute('target', '_blank');
			}

			line.appendChild(link);
			parent.appendChild(line);
		}

		function appendLine(parent, value) {
			var line = document.createElement('p');
			line.textContent = value;
			parent.appendChild(line);
		}
	}

	function readI18n(root) {
		try {
			var parsed = JSON.parse(root.getAttribute('data-i18n') || '');

			if (parsed && typeof parsed === 'object') {
				return parsed;
			}
		} catch (error) {
			return {};
		}

		return {};
	}

	function classifyQuery(raw) {
		var value = asText(raw);

		if (!value) {
			return { error: 'empty_input' };
		}

		if (/^\d{5}(-\d{4})?$/.test(value)) {
			return {
				type: 'zip',
				q: value.slice(0, 5)
			};
		}

		if (/^[\d-]+$/.test(value)) {
			return { error: 'invalid_zip' };
		}

		if (value.length < 3) {
			return { error: 'empty_input' };
		}

		return {
			type: 'address',
			q: value.slice(0, 200)
		};
	}

	function buildSearchUrl(searchUrl, params) {
		var url = new URL(searchUrl, window.location.href);

		url.searchParams.set('type', params.type);

		if (params.type === 'coords') {
			url.searchParams.set('lat', String(params.lat));
			url.searchParams.set('lng', String(params.lng));
			url.searchParams.delete('q');
		} else {
			url.searchParams.set('q', params.q);
			url.searchParams.delete('lat');
			url.searchParams.delete('lng');
		}

		return url.toString();
	}

	function pointOf(record) {
		if (!record || typeof record !== 'object') {
			return null;
		}

		var lat = coord(record.lat);
		var lng = coord(record.lng);

		if (lat === null || lng === null) {
			return null;
		}

		return [lat, lng];
	}

	function coord(value) {
		if (typeof value === 'number' && isFinite(value)) {
			return value;
		}

		if (typeof value === 'string' && value.trim() !== '' && isFinite(Number(value))) {
			return Number(value);
		}

		return null;
	}

	function roundCoord(value) {
		return Math.round(Number(value) * 100000) / 100000;
	}

	function telHref(phone) {
		var trimmed = phone.trim();
		var digits = phone.replace(/\D/g, '');

		if (!digits) {
			return '';
		}

		return 'tel:' + (trimmed.charAt(0) === '+' ? '+' : '') + digits;
	}

	function isEmail(value) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
	}

	function isHttpUrl(value) {
		var lower = value.toLowerCase();

		return lower.indexOf('https://') === 0 || lower.indexOf('http://') === 0;
	}

	function asText(value) {
		if (value === null || value === undefined) {
			return '';
		}

		return String(value).trim();
	}

	function joinParts(parts, separator) {
		var kept = [];
		var index;

		for (index = 0; index < parts.length; index++) {
			if (parts[index]) {
				kept.push(parts[index]);
			}
		}

		return kept.join(separator);
	}

	function fill(template, value) {
		return String(template).split('%s').join(value).split('%d').join(value);
	}

	function escapeHtml(value) {
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}
})();
