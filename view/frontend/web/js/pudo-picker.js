/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 *
 * Alpine.js component for the InnoShip PUDO picker map modal.
 *
 * The component is registered globally as `innoShipPudoPicker`. The phtml
 * template passes its settings in `data-` attributes on the component root:
 * `data-config` (icons, Leaflet assets, translations, the points endpoint URL)
 * and the search context (`data-county`, `data-city`, `data-region-id`).
 * The pickup points are fetched from the endpoint when the map opens.
 */
(function () {
    'use strict';

    function factory() {
        // Leaflet objects and the pin list live here, outside Alpine's reactive
        // data. Alpine wraps its data in proxies, while Leaflet matches event
        // listeners by object identity: through a proxy, a closed popup's zoom
        // handler was never removed, and the next zoom threw
        // "Cannot read properties of null (reading '_latLngToNewLayerPoint')".
        let map = null;
        let markers = {};           // pudo_id (string) → Leaflet Marker, for pan + openPopup
        let leafletIcons = {};
        let pins = [];
        let customerLocation = null;
        let loadedPinsUrl = null;   // the endpoint URL the current pins came from
        let wantedPinsUrl = null;   // the endpoint URL of the latest request
        let drawnPinsUrl = null;    // the endpoint URL of the pins on the map
        let pinsPromise = null;
        let resourcesPromise = null;

        function destroyMap() {
            if (!map) return;

            const leafletMap = map;
            map = null;
            markers = {};
            drawnPinsUrl = null;

            // Leaflet ends an animated zoom on a timer that reads the map pane,
            // which remove() deletes ("reading '_leaflet_pos'"). Finish the
            // animation first, so closing the modal mid-zoom is safe.
            if (leafletMap._animatingZoom && typeof leafletMap._onZoomTransitionEnd === 'function') {
                leafletMap._onZoomTransitionEnd();
            }
            leafletMap.remove();
        }

        function fetchPins(url) {
            return window.fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('Pickup points request failed: HTTP ' + response.status);
                    }
                    return response.json();
                });
        }

        return {
            // Component State
            isMapLoading: false,
            showModal: false,
            mapInitialized: false,
            mapError: null,

            // Search State
            selectedCounty: '',
            selectedCity: '',
            regionId: '',

            // Settings from the template's data-config
            iconUrls: {},
            leafletAssets: { js: '', css: '' },
            translations: {},
            pointsUrl: '',

            // Search state — all client-side, no Magewire roundtrips
            searchQuery: '',
            searchResults: [],   // filtered pins visible in the dropdown, capped at 20
            showSearchResults: false,
            showNoResultsRow: false,
            searchPlaceholderText: '',
            noResultsText: '',

            init() {
                const data = this.$el.dataset;
                let cfg = {};
                try {
                    cfg = JSON.parse(data.config || '{}');
                } catch (e) {
                    cfg = {};
                }

                this.iconUrls = cfg.iconUrls || {};
                this.leafletAssets = cfg.leafletAssets || { js: '', css: '' };
                this.translations = cfg.translations || {};
                this.pointsUrl = cfg.pointsUrl || '';
                this.showModal = !!data.showModal;
                this.applyContext({
                    selectedCounty: data.county,
                    selectedCity: data.city,
                    regionId: data.regionId
                });

                this.initLeafletIcons();

                // Hydrate translation strings used by the search dropdown.
                // These are plain string properties so Alpine's CSP build can bind
                // them as bare paths (:placeholder="searchPlaceholderText" etc.)
                const t = this.translations;
                this.searchPlaceholderText = t.searchPlaceholder || '';
                this.noResultsText         = t.searchNoResults   || '';

                this.$nextTick(() => {
                    this.syncStateWithMagewire();

                    // Reactive search: re-filter the loaded pin list on every keystroke.
                    // Client-side only — no Magewire roundtrip per character.
                    this.$watch('searchQuery', () => this.recomputeSearchResults());

                    this.$watch('showModal', (value) => {
                        // A method call, not $wire.set('showModal'): Magewire's
                        // $set skips the updated* hooks, and opening the map
                        // has to send back the current context.
                        if (this.$wire) {
                            this.$wire.setModalOpen(value);
                        }
                    });

                    if (this.showModal) {
                        this.openMapModal();
                    }
                });
            },

            destroy() {
                destroyMap();
            },

            syncStateWithMagewire() {
                const wire = this.$wire;
                if (!wire || typeof wire.get !== 'function') {
                    return;
                }

                this.selectedCounty = wire.get('selectedCounty') || '';
                this.selectedCity = wire.get('selectedCity') || '';
                this.showModal = !!wire.get('showModal');

                if (window.L && Object.keys(leafletIcons).length === 0) {
                    this.initLeafletIcons();
                }
            },

            initLeafletIcons() {
                if (!window.L) return;

                const sizes = {
                    0: { size: [30, 49], anchor: [15, 49], popup: [0, -45] },
                    1: { size: [30, 30], anchor: [15, 30], popup: [0, -30] },
                    2: { size: [30, 30], anchor: [15, 30], popup: [0, -30] },
                    3: { size: [30, 42], anchor: [15, 42], popup: [0, -40] },
                    6: { size: [50, 50], anchor: [25, 50], popup: [0, -45] },
                    11: { size: [50, 50], anchor: [25, 50], popup: [0, -45] },
                    12: { size: [50, 50], anchor: [25, 50], popup: [0, -45] }
                };

                Object.keys(sizes).forEach((key) => {
                    if (this.iconUrls[key]) {
                        leafletIcons[key] = window.L.icon({
                            iconUrl: this.iconUrls[key],
                            iconSize: sizes[key].size,
                            iconAnchor: sizes[key].anchor,
                            popupAnchor: sizes[key].popup
                        });
                    }
                });
            },

            // Event Handlers

            /**
             * Magewire sends the search context (county, city, shipping region)
             * after a county or city change and when the map opens. The pins for
             * a new context come from the points endpoint.
             */
            onPudoDataUpdated(event) {
                if (!event.detail || !event.detail.data) return;

                this.applyContext(event.detail.data);
                if (this.showModal) {
                    this.refreshPins();
                }
            },

            /**
             * Magewire turned down a point that Innoship has deactivated or
             * removed since the pins were cached: drop it from the map.
             */
            onPudoUnavailable(event) {
                const pudoId = String((event.detail && event.detail.pudoId) || '');
                if (!pudoId) return;

                pins = pins.filter((pin) => String(pin.pudo_id) !== pudoId);
                if (map && markers[pudoId]) {
                    map.removeLayer(markers[pudoId]);
                }
                delete markers[pudoId];
            },

            applyContext(context) {
                const regionId = parseInt(context.regionId, 10);
                this.selectedCounty = context.selectedCounty || '';
                this.selectedCity = context.selectedCity || '';
                this.regionId = regionId > 0 ? String(regionId) : '';
            },

            updateSelectedCounty(event) {
                this.selectedCounty = event.target.value;
            },

            updateSelectedCity(event) {
                this.selectedCity = event.target.value;
            },

            getMapContainerId() {
                return 'innoship-picker-map-container';
            },

            isCountyNotSelected() {
                return !this.selectedCounty;
            },

            /**
             * Returns true when a city has been selected.
             * Used by `x-show="isCitySelected"` on the search wrapper in the template.
             * Alpine CSP calls function-valued properties automatically — no arguments needed.
             */
            isCitySelected() {
                return !!this.selectedCity;
            },

            hasNoCustomerLocation() {
                return !customerLocation;
            },

            /**
             * The endpoint URL for the current context: the chosen city's points,
             * or else the points near the shipping region. Empty when neither is
             * known; the map then opens on its default view without pins.
             */
            getPinsUrl() {
                let query = '';
                if (this.selectedCounty && this.selectedCity) {
                    query = 'county=' + encodeURIComponent(this.selectedCounty) +
                        '&city=' + encodeURIComponent(this.selectedCity);
                } else if (this.regionId) {
                    query = 'region_id=' + encodeURIComponent(this.regionId);
                }

                if (!query || !this.pointsUrl) return '';
                return this.pointsUrl + (this.pointsUrl.indexOf('?') === -1 ? '?' : '&') + query;
            },

            /**
             * Loads the pins for the current context. Resolves to true when the
             * pin list changed; an answer for an older context is dropped.
             */
            loadPins() {
                const url = this.getPinsUrl();
                if (url === loadedPinsUrl) {
                    // Back on the loaded context: drop an answer still on its way for another one.
                    wantedPinsUrl = url;
                    pinsPromise = null;
                    return Promise.resolve(false);
                }
                if (url === wantedPinsUrl && pinsPromise) {
                    return pinsPromise;
                }

                wantedPinsUrl = url;
                pinsPromise = (url ? fetchPins(url) : Promise.resolve({})).then((data) => {
                    if (url !== wantedPinsUrl) {
                        return false;
                    }
                    loadedPinsUrl = url;
                    pinsPromise = null;
                    pins = Array.isArray(data.pins) ? data.pins : [];
                    customerLocation = data.customerLocation || null;
                    return true;
                }, (error) => {
                    if (url === wantedPinsUrl) {
                        wantedPinsUrl = null;
                        pinsPromise = null;
                    }
                    throw error;
                });

                return pinsPromise;
            },

            async refreshPins() {
                try {
                    if (await this.loadPins()) {
                        this.updateMarkers();
                    }
                } catch (error) {
                    this.mapError = this.translations.failedToLoad || 'Failed to load map';
                    if (window.console && console.error) {
                        console.error('Pickup points error:', error);
                    }
                }
            },

            updateMarkers() {
                // Opening the map and the context Magewire sends back on
                // opening can both deliver the same pins: draw them once.
                if (map && drawnPinsUrl === loadedPinsUrl) return;

                this.resetSearchState();
                if (!map) return;

                map.eachLayer((layer) => {
                    if (layer instanceof window.L.Marker) {
                        map.removeLayer(layer);
                    }
                });

                const added = this.addMarkers();

                if (this.selectedCounty && this.selectedCity) {
                    if (added.length > 0) {
                        const group = new window.L.featureGroup(added);
                        map.fitBounds(group.getBounds().pad(0.2), {
                            maxZoom: 14,
                            padding: [20, 20]
                        });
                    }
                } else if (!this.fitFewMarkers(added) && customerLocation) {
                    // Many pins near the shipping region: show its centre, as a newly opened map does.
                    map.setView([customerLocation.lat, customerLocation.lng], 15);
                }
            },

            // Map Management
            async openMapModal() {
                this.showModal = true;
                this.isMapLoading = true;
                this.mapError = null;

                try {
                    const loaded = await Promise.all([this.loadMapResources(), this.loadPins()]);
                    if (!this.showModal) return;

                    if (map) {
                        // Retry after a failed pins refresh: the map is still open.
                        if (loaded[1]) this.updateMarkers();
                        map.invalidateSize();
                    } else {
                        await this.initializeMap();
                    }
                } catch (error) {
                    this.mapError = error.message || this.translations.failedToLoad || 'Failed to load map';
                    if (window.console && console.error) {
                        console.error('Map initialization error:', error);
                    }
                } finally {
                    this.isMapLoading = false;
                }
            },

            closeModal() {
                this.showModal = false;
                destroyMap();
                this.mapInitialized = false;
                this.resetSearchState();
            },

            loadMapResources() {
                if (!resourcesPromise) {
                    resourcesPromise = this.loadExternalResources().catch((error) => {
                        resourcesPromise = null;
                        throw error;
                    });
                }
                return resourcesPromise;
            },

            async loadExternalResources() {
                const resources = [this.leafletAssets.css, this.leafletAssets.js].filter(Boolean);
                for (const url of resources) {
                    await this.loadResource(url);
                }
            },

            loadResource(url) {
                return new Promise((resolve, reject) => {
                    const isCSS = url.endsWith('.css');
                    const existing = document.querySelector(
                        isCSS ? `link[href="${url}"]` : `script[src="${url}"]`
                    );
                    if (existing) {
                        resolve();
                        return;
                    }

                    let element;
                    if (isCSS) {
                        element = document.createElement('link');
                        element.rel = 'stylesheet';
                        element.href = url;
                    } else {
                        element = document.createElement('script');
                        element.src = url;
                    }

                    element.onload = resolve;
                    element.onerror = () => {
                        element.remove();
                        reject(new Error('Failed to load: ' + url));
                    };

                    document.head.appendChild(element);
                });
            },

            async initializeMap() {
                await this.$nextTick();
                const container = document.getElementById(this.getMapContainerId());
                if (!container || map) return;

                if (!window.L) {
                    throw new Error('Failed to initialize map: Leaflet library not loaded');
                }
                if (Object.keys(leafletIcons).length === 0) {
                    this.initLeafletIcons();
                }

                let mapPosition = [44.4268, 26.1025];
                let zoomLevel = 8;

                if (this.selectedCounty && this.selectedCity && pins.length > 0) {
                    mapPosition = [pins[0].latitude, pins[0].longitude];
                    zoomLevel = 13;
                } else if (customerLocation) {
                    mapPosition = [customerLocation.lat, customerLocation.lng];
                    zoomLevel = 15;
                } else if (pins.length > 0) {
                    mapPosition = [pins[0].latitude, pins[0].longitude];
                    zoomLevel = 13;
                }

                map = window.L.map(container).setView(mapPosition, zoomLevel);

                window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    minZoom: 5,
                    maxZoom: 18,
                    attribution: '&copy; <a href="https://openstreetmap.org/copyright">OpenStreetMap</a>'
                }).addTo(map);

                this.fitFewMarkers(this.addMarkers());

                map.on('popupopen', (e) => {
                    const popupContainer = e.popup._container;
                    const selectBtn = popupContainer.querySelector('.innoship-select-pudo-btn');
                    if (selectBtn) {
                        selectBtn.addEventListener('click', () => {
                            this.selectPudo(selectBtn.getAttribute('data-pudo-id'));
                        });
                    }
                    const img = popupContainer.querySelector('.pudo-img');
                    if (img) {
                        img.addEventListener('error', () => {
                            img.classList.add('hidden');
                        });
                    }
                });

                this.mapInitialized = true;
            },

            /**
             * Fits the view to the markers when there are few enough to show at
             * a useful zoom. Returns false when it left the view alone.
             */
            fitFewMarkers(added) {
                if (
                    added.length > 0 &&
                    added.length < 100 &&
                    (customerLocation || (this.selectedCounty && this.selectedCity))
                ) {
                    const group = new window.L.featureGroup(added);
                    map.fitBounds(group.getBounds().pad(0.2), {
                        maxZoom: 16,
                        padding: [20, 20]
                    });
                    return true;
                }
                return false;
            },

            addMarkers() {
                // Rebuild the marker index so focusResult() always has a fresh
                // reference to the current set of Leaflet Marker objects.
                markers = {};
                if (!map) return [];

                const added = [];
                pins.forEach((pin) => {
                    const courierId = pin.courier_id || 0;
                    const icon = leafletIcons[courierId] || leafletIcons[0];
                    const markerOptions = icon ? { icon: icon } : {};
                    const marker = window.L.marker([pin.latitude, pin.longitude], markerOptions)
                        .addTo(map);

                    marker.bindPopup(this.createPopupContent(pin));

                    // Index by pudo_id (cast to string for consistent key lookup).
                    markers[String(pin.pudo_id)] = marker;

                    added.push(marker);
                });
                drawnPinsUrl = loadedPinsUrl;

                return added;
            },

            createPopupContent(pin) {
                const t = this.translations;
                const paymentInfo = this.getPaymentInfo(pin.accepted_payment_type);
                const mainPicture = pin.main_picture
                    ? `<div class="mb-2">
                            <img src="${this.escapeHtml(pin.main_picture)}"
                                 alt="${this.escapeHtml(pin.name)}"
                                 class="pudo-img w-full object-cover rounded border">
                        </div>`
                    : '';
                const phoneNumber = pin.phone_number
                    ? `<p class="text-xs text-gray-600 mb-1">
                            <strong>📞 ${this.escapeHtml(t.phone || 'Phone:')}</strong>
                            <a href="tel:${this.escapeHtml(pin.phone_number)}" class="text-blue-600 hover:text-blue-800">
                                ${this.escapeHtml(pin.phone_number)}
                            </a>
                        </p>`
                    : '';
                const openHours = this.formatOpenHours(pin);
                const addressDescription = pin.address_description
                    ? `<div class="text-xs text-gray-600 mb-2 p-2 bg-gray-50 rounded border-l-2 border-blue-200">
                            <strong>ℹ️ ${this.escapeHtml(t.info || 'Info:')}</strong><br>
                            <span class="whitespace-pre-line">${this.escapeHtml(pin.address_description).replace(/\n/g, '<br>')}</span>
                        </div>`
                    : '';

                return `
                    <div class="max-w-sm flex flex-col">
                        <div class="flex-shrink-0 p-3 border-b border-gray-200">
                            ${mainPicture}
                            <h4 class="font-medium text-gray-900 mb-1">${this.escapeHtml(pin.name)}</h4>
                            <p class="text-sm text-gray-600 mb-1">${this.escapeHtml(pin.address)}</p>
                        </div>
                        <div class="flex-1 overflow-y-auto p-1 space-y-2 max-h-[200px]">
                            ${phoneNumber}
                            ${openHours}
                            ${paymentInfo ? `<p class="text-xs text-gray-500">${paymentInfo}</p>` : ''}
                            ${addressDescription}
                        </div>
                        <div class="flex-shrink-0 p-3 border-t border-gray-200 bg-white">
                            <button data-pudo-id="${pin.pudo_id}"
                                    class="innoship-select-pudo-btn w-full px-3 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors">
                                ${this.escapeHtml(t.selectThisPoint || 'Select This Point')}
                            </button>
                        </div>
                    </div>
                `;
            },

            formatOpenHours(pin) {
                const t = this.translations;
                const hasOpenHours = pin.mo_start || pin.tu_start || pin.we_start || pin.th_start ||
                    pin.fr_start || pin.sa_start || pin.su_start;
                if (!hasOpenHours) return '';

                const days = [
                    { label: t.mon || 'Mon', start: pin.mo_start, end: pin.mo_end },
                    { label: t.tue || 'Tue', start: pin.tu_start, end: pin.tu_end },
                    { label: t.wed || 'Wed', start: pin.we_start, end: pin.we_end },
                    { label: t.thu || 'Thu', start: pin.th_start, end: pin.th_end },
                    { label: t.fri || 'Fri', start: pin.fr_start, end: pin.fr_end },
                    { label: t.sat || 'Sat', start: pin.sa_start, end: pin.sa_end },
                    { label: t.sun || 'Sun', start: pin.su_start, end: pin.su_end }
                ];

                const groupedHours = [];
                let currentGroup = null;
                days.forEach((day) => {
                    if (day.start && day.end) {
                        const hours = `${day.start}-${day.end}`;
                        if (currentGroup && currentGroup.hours === hours) {
                            currentGroup.endDay = day.label;
                        } else {
                            if (currentGroup) groupedHours.push(currentGroup);
                            currentGroup = { startDay: day.label, endDay: day.label, hours: hours };
                        }
                    }
                });
                if (currentGroup) groupedHours.push(currentGroup);
                if (groupedHours.length === 0) return '';

                const formattedHours = groupedHours.map((group) => {
                    const dayRange = group.startDay === group.endDay
                        ? group.startDay
                        : `${group.startDay}-${group.endDay}`;
                    return `${dayRange}: ${group.hours}`;
                }).join('<br>');

                return `<div class="text-xs text-gray-600 mb-2"><strong>🕒 ${this.escapeHtml(t.hours || 'Hours:')}</strong><br><span class="font-mono">${formattedHours}</span></div>`;
            },

            getPaymentInfo(paymentType) {
                const t = this.translations;
                try {
                    const payments = JSON.parse(paymentType || '{}');
                    const info = [];
                    if (payments.Cash) info.push(t.cash || 'Cash');
                    if (payments.Card) info.push(t.card || 'Card');
                    if (payments.Online) info.push(t.online || 'Online');
                    return info.length > 0
                        ? (t.paymentMethods || 'Payment methods') + ': ' + info.join(', ')
                        : '';
                } catch (e) {
                    return '';
                }
            },

            /**
             * CSP-safe replacement for x-model on the search input.
             * `x-model` generates an assignment expression that Alpine CSP cannot evaluate;
             * the pattern `:value="searchQuery" @input="setSearchQuery"` is required instead.
             * The $watch('searchQuery', ...) in init() fires automatically after the assignment,
             * triggering recomputeSearchResults() with no extra wiring needed.
             */
            setSearchQuery() {
                this.searchQuery = this.$event.target.value;
            },

            /**
             * Filter the currently-loaded pins against the search query.
             * Matched against: name, street address, city, postal_code.
             * Substring match, case-insensitive. Results capped at 20 to keep the DOM light.
             * Called on every `searchQuery` change via the $watch in init().
             */
            recomputeSearchResults() {
                const q = (this.searchQuery || '').trim().toLowerCase();
                if (q.length < 1) {
                    this.searchResults    = [];
                    this.showSearchResults = false;
                    this.showNoResultsRow = false;
                    return;
                }

                const matches = pins.filter((p) =>
                    (p.name        || '').toLowerCase().includes(q) ||
                    (p.address     || '').toLowerCase().includes(q) ||
                    (p.city        || '').toLowerCase().includes(q) ||
                    (p.postal_code || '').toLowerCase().includes(q)
                ).slice(0, 20);

                // Build a `subline` for each result so the template can bind
                // it as a bare path (`result.subline`) without inline expressions.
                this.searchResults = matches.map((p) => {
                    const cityPostcode = [p.city, p.postal_code].filter(Boolean).join(' ');
                    const subline      = [p.address, cityPostcode].filter(Boolean).join(', ');
                    return Object.assign({}, p, { subline: subline });
                });

                this.showSearchResults = true;
                this.showNoResultsRow  = (matches.length === 0);
            },

            /**
             * Pans the Leaflet map to the pin selected from the search dropdown,
             * zooms to 17 (mirrors legacy Luma), and opens its existing popup so
             * the customer can hit "Select This Point" through the normal flow.
             *
             * The pudo_id is read from `data-pudo-id` on the clicked <li>
             * because Hyvä's CSP-friendly Alpine build cannot pass arguments
             * in directive expressions (`x-on:click="focusResult"` is bare).
             */
            focusResult(event) {
                const el = event && (event.currentTarget || event.target);
                const id = el ? String(el.dataset.pudoId || '') : '';
                const marker = id ? markers[id] : null;

                if (!marker || !map) return;

                map.setView(marker.getLatLng(), 17);
                marker.openPopup();

                // Clear the dropdown — customer now sees the pin on the map.
                this.searchQuery       = '';
                this.searchResults     = [];
                this.showSearchResults = false;
            },

            /**
             * Shared helper: clear all search state.
             * Called on county/city change, modal close, and pin-set updates.
             */
            resetSearchState() {
                this.searchQuery       = '';
                this.searchResults     = [];
                this.showSearchResults = false;
                this.showNoResultsRow  = false;
            },

            escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text == null ? '' : String(text);
                return div.innerHTML;
            },

            selectPudo(pudoId) {
                if (this.$wire && typeof this.$wire.selectPudoPoint === 'function') {
                    this.$wire.selectPudoPoint(pudoId);
                }
                this.closeModal();
            },

            retryMapLoad() {
                this.mapError = null;
                this.mapInitialized = false;
                this.openMapModal();
            }
        };
    }

    function register() {
        if (window.Alpine && typeof window.Alpine.data === 'function') {
            window.Alpine.data('innoShipPudoPicker', factory);
            window.Alpine.data('innoShipPudoLink', function () {
                return {
                    openPicker() {
                        this.$dispatch('open-innoship-pudo-modal');
                    }
                };
            });
        }
    }

    if (window.Alpine && typeof window.Alpine.data === 'function') {
        register();
    } else {
        window.addEventListener('alpine:init', register, { once: true });
    }
})();
