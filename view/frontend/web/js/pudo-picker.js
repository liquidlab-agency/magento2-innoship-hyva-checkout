/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 *
 * Alpine.js component for the InnoShip PUDO picker map modal.
 *
 * The component is registered globally as `innoShipPudoPicker` and consumes a
 * configuration object exposed via `window.innoShipPudoPickerConfig` set by the
 * phtml template before this script runs.
 */
(function () {
    'use strict';

    function factory() {
        const cfg = window.innoShipPudoPickerConfig || {};

        return {
            // Component State
            isMapLoading: false,
            showModal: !!cfg.initialShowModal,
            mapInitialized: false,
            mapError: null,
            mapInstance: null,

            // Search State
            selectedCounty: cfg.initialSelectedCounty || '',
            selectedCity: cfg.initialSelectedCity || '',

            // Data + assets injected by the server
            innoShipData: cfg.innoShipData || { pins: [], counties: [], cities: [] },
            iconUrls: cfg.iconUrls || {},
            leafletAssets: cfg.leafletAssets || { js: '', css: '' },
            translations: cfg.translations || {},
            leafletIcons: {},

            init() {
                this.initLeafletIcons();

                this.$nextTick(() => {
                    this.syncStateWithMagewire();

                    this.$watch('showModal', (value) => {
                        if (this.$wire) {
                            this.$wire.set('showModal', value);
                        }
                    });

                    if (this.showModal) {
                        this.openMapModal();
                    }
                });
            },

            syncStateWithMagewire() {
                const wire = this.$wire;
                if (!wire || typeof wire.get !== 'function') {
                    return;
                }

                this.selectedCounty = wire.get('selectedCounty') || '';
                this.selectedCity = wire.get('selectedCity') || '';
                this.showModal = !!wire.get('showModal');

                if (window.L && Object.keys(this.leafletIcons).length === 0) {
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
                        this.leafletIcons[key] = window.L.icon({
                            iconUrl: this.iconUrls[key],
                            iconSize: sizes[key].size,
                            iconAnchor: sizes[key].anchor,
                            popupAnchor: sizes[key].popup
                        });
                    }
                });
            },

            // Event Handlers
            onPudoPointsUpdated(event) {
                if (event.detail && event.detail.pins) {
                    this.updateMarkers(event.detail.pins);
                }
            },

            onPudoDataUpdated(event) {
                if (event.detail && event.detail.data) {
                    this.innoShipData = event.detail.data;
                    this.selectedCounty = event.detail.data.selectedCounty || '';
                    this.selectedCity = event.detail.data.selectedCity || '';
                }
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

            hasNoCustomerLocation() {
                return !this.innoShipData.customerLocation;
            },

            updateMarkers(pins) {
                this.innoShipData.pins = pins;
                if (!this.mapInstance) return;

                this.mapInstance.eachLayer((layer) => {
                    if (layer instanceof window.L.Marker) {
                        this.mapInstance.removeLayer(layer);
                    }
                });

                this.addMapMarkers();

                if (pins.length > 0) {
                    const markers = [];
                    this.mapInstance.eachLayer((layer) => {
                        if (layer instanceof window.L.Marker) {
                            markers.push(layer);
                        }
                    });

                    if (markers.length > 0) {
                        const group = new window.L.featureGroup(markers);
                        this.mapInstance.fitBounds(group.getBounds().pad(0.2), {
                            maxZoom: 14,
                            padding: [20, 20]
                        });
                    }
                }
            },

            // Map Management
            async openMapModal() {
                this.showModal = true;
                this.isMapLoading = true;
                this.mapError = null;

                try {
                    await this.loadMapResources();
                    await this.initializeMap();
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
                if (this.mapInstance) {
                    this.mapInstance.remove();
                    this.mapInstance = null;
                    this.mapInitialized = false;
                }
            },

            async loadMapResources() {
                if (window.innoShipMapInitialized && window.innoShipMapObj) {
                    return;
                }
                await this.loadExternalResources();
                this.initializeInnoShipMapObject();
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
                    element.onerror = () => reject(new Error('Failed to load: ' + url));

                    document.head.appendChild(element);
                });
            },

            initializeInnoShipMapObject() {
                if (window.innoShipMapInitialized) return;

                window.innoShipMapInitialized = true;
                window.innoShipMapObj = {
                    map: null,
                    init: (elemId, component) => {
                        if (!window.L) {
                            throw new Error('Leaflet library not loaded');
                        }
                        const container = document.getElementById(elemId);
                        if (!container) {
                            throw new Error('Map container not found');
                        }

                        let mapPosition = [44.4268, 26.1025];
                        let zoomLevel = 8;

                        const data = component.innoShipData;

                        if (component.selectedCounty && component.selectedCity && data.pins && data.pins.length > 0) {
                            mapPosition = [data.pins[0].latitude, data.pins[0].longitude];
                            zoomLevel = 13;
                        } else if (data.customerLocation) {
                            mapPosition = [data.customerLocation.lat, data.customerLocation.lng];
                            zoomLevel = 15;
                        } else if (data.pins && data.pins.length > 0) {
                            mapPosition = [data.pins[0].latitude, data.pins[0].longitude];
                            zoomLevel = 13;
                        }

                        const map = window.L.map(container).setView(mapPosition, zoomLevel);

                        window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                            minZoom: 5,
                            maxZoom: 18,
                            attribution: '&copy; <a href="https://openstreetmap.org/copyright">OpenStreetMap</a>'
                        }).addTo(map);

                        component.mapInstance = map;
                        component.addMapMarkers();
                        return map;
                    }
                };
            },

            async initializeMap() {
                await this.$nextTick();
                const containerId = this.getMapContainerId();
                const container = document.getElementById(containerId);
                if (!container) return;

                if (container._leaflet_id && this.mapInstance) {
                    this.addMapMarkers();
                    return;
                }

                try {
                    this.mapInstance = window.innoShipMapObj.init(containerId, this);

                    this.mapInstance.on('popupopen', (e) => {
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
                } catch (error) {
                    throw new Error('Failed to initialize map: ' + error.message);
                }
            },

            addMapMarkers() {
                if (!this.mapInstance || !this.innoShipData.pins) return;

                if (window.L && Object.keys(this.leafletIcons).length === 0) {
                    this.initLeafletIcons();
                }

                const markers = [];
                this.innoShipData.pins.forEach((pin) => {
                    const courierId = pin.courier_id || 0;
                    const icon = this.leafletIcons[courierId] || this.leafletIcons[0];
                    const markerOptions = icon ? { icon: icon } : {};
                    const marker = window.L.marker([pin.latitude, pin.longitude], markerOptions)
                        .addTo(this.mapInstance);

                    marker.bindPopup(this.createPopupContent(pin));
                    markers.push(marker);
                });

                if (
                    markers.length > 0 &&
                    markers.length < 100 &&
                    (this.innoShipData.customerLocation || (this.selectedCounty && this.selectedCity))
                ) {
                    const group = new window.L.featureGroup(markers);
                    this.mapInstance.fitBounds(group.getBounds().pad(0.2), {
                        maxZoom: 16,
                        padding: [20, 20]
                    });
                }
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
                        <div class="flex-1 overflow-y-auto p-3 space-y-2 max-h-[200px]">
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
