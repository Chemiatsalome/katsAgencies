import {
    Chart,
    BarController,
    LineController,
    BarElement,
    LineElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
} from 'chart.js';

Chart.register(
    BarController,
    LineController,
    BarElement,
    LineElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend
);

const NAVY = '#17324D';
const TEAL = '#168A8A';
const GRID = '#E2E8F0';
const TICK = '#64748B';

// A validation error can land anywhere on a long page (e.g. a form far
// below the fold) and, since Livewire updates the DOM in place rather than
// navigating, nothing otherwise draws the eye to it — it's easy to click
// "Submit" and see no visible change at all. Whenever a new, non-empty
// <x-input-error> list appears anywhere on the page, scroll straight to the
// first one and focus its field, the same way a native browser form would.
// A MutationObserver (rather than hooking Livewire's own lifecycle events)
// catches this uniformly for every form without each one needing its own
// x-init scroll handler.
(() => {
    let scheduled = false;

    const handleNewErrors = () => {
        scheduled = false;

        const errorList = document.querySelector('ul.text-danger:not(:empty)');
        if (! errorList) {
            return;
        }

        errorList.scrollIntoView({ behavior: 'smooth', block: 'center' });

        const field = errorList.closest('div, td')?.querySelector('input, select, textarea');
        field?.focus({ preventScroll: true });
    };

    new MutationObserver((mutations) => {
        const hasNewErrorList = mutations.some((mutation) =>
            Array.from(mutation.addedNodes).some((node) => {
                if (node.nodeType !== Node.ELEMENT_NODE) {
                    return false;
                }

                return (node.matches?.('ul.text-danger') && node.textContent.trim())
                    || Array.from(node.querySelectorAll?.('ul.text-danger') ?? []).some((el) => el.textContent.trim());
            })
        );

        if (hasNewErrorList && ! scheduled) {
            scheduled = true;
            // Livewire morphs the DOM in several small steps — waiting a
            // tick lets the error's own container finish laying out
            // before scrollIntoView measures its position.
            requestAnimationFrame(handleNewErrors);
        }
    }).observe(document.body, { childList: true, subtree: true });
})();

document.addEventListener('alpine:init', () => {
    // Address autocomplete for Add/Edit Property (see
    // resources/views/components/location-autocomplete.blade.php). Talks
    // only to this app's own /manage/geocoding/autocomplete proxy — never
    // to a mapping API directly, so no API key is ever present in the
    // browser. A suggestion only reaches the Livewire component's actual
    // `address`/lat/lon properties when the landlord explicitly clicks (or
    // arrows-to + Enters) one; plain typing alone commits as manual text
    // on blur, with coordinates left null, which is exactly the existing
    // (pre-autocomplete) behaviour for that field.
    window.Alpine.data('locationAutocomplete', ({ endpoint, addressProperty, latProperty, lonProperty, initial }) => ({
        query: initial || '',
        suggestions: [],
        open: false,
        loading: false,
        errorMessage: '',
        confirmed: !!initial,
        highlightedIndex: -1,
        _debounceTimer: null,

        onInput() {
            this.confirmed = false;
            this.errorMessage = '';
            this.highlightedIndex = -1;
            // Deferred (3rd arg false) — every keystroke updates the
            // component's local state without a network round trip; the
            // value is only actually sent on the next real Livewire action
            // (e.g. the form's own submit).
            this.$wire.set(addressProperty, this.query, false);
            if (latProperty) this.$wire.set(latProperty, null, false);
            if (lonProperty) this.$wire.set(lonProperty, null, false);

            clearTimeout(this._debounceTimer);

            if (this.query.trim().length < 3) {
                this.suggestions = [];
                this.open = false;
                return;
            }

            this._debounceTimer = setTimeout(() => this.search(), 350);
        },

        async search() {
            this.loading = true;

            try {
                const response = await fetch(`${endpoint}?q=${encodeURIComponent(this.query)}`, {
                    headers: { Accept: 'application/json' },
                });
                const data = await response.json();

                this.suggestions = data.suggestions || [];
                this.errorMessage = data.error || '';
                this.open = this.suggestions.length > 0;
                this.highlightedIndex = -1;
            } catch (e) {
                this.suggestions = [];
                this.errorMessage = 'Location search is temporarily unavailable — enter the address manually.';
                this.open = false;
            } finally {
                this.loading = false;
            }
        },

        select(suggestion) {
            this.query = suggestion.label;
            this.confirmed = true;
            this.open = false;
            this.suggestions = [];
            this.$wire.set(addressProperty, suggestion.label);
            if (latProperty) this.$wire.set(latProperty, suggestion.lat);
            if (lonProperty) this.$wire.set(lonProperty, suggestion.lon);
        },

        selectHighlighted() {
            if (this.highlightedIndex >= 0 && this.suggestions[this.highlightedIndex]) {
                this.select(this.suggestions[this.highlightedIndex]);
            } else {
                this.commitManual();
            }
        },

        highlightNext() {
            if (! this.open || this.suggestions.length === 0) return;
            this.highlightedIndex = Math.min(this.highlightedIndex + 1, this.suggestions.length - 1);
        },

        highlightPrev() {
            if (! this.open || this.suggestions.length === 0) return;
            this.highlightedIndex = Math.max(this.highlightedIndex - 1, 0);
        },

        commitManual() {
            this.open = false;
            this.$wire.set(addressProperty, this.query);
        },

        clearConfirmation() {
            this.confirmed = false;
            this.$refs.input?.focus();
        },
    }));

    window.Alpine.data('platformRentChart', (months) => ({
        init() {
            new Chart(this.$refs.canvas, {
                type: 'bar',
                data: {
                    labels: months.map((m) => m.label),
                    datasets: [
                        {
                            label: 'Expected',
                            data: months.map((m) => m.expected),
                            backgroundColor: NAVY,
                            borderRadius: 4,
                            maxBarThickness: 24,
                        },
                        {
                            label: 'Collected',
                            data: months.map((m) => m.collected),
                            backgroundColor: TEAL,
                            borderRadius: 4,
                            maxBarThickness: 24,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => `${ctx.dataset.label}: KSh ${ctx.parsed.y.toLocaleString()}`,
                            },
                        },
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: TICK, font: { family: 'Figtree', size: 12 } },
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: GRID },
                            border: { display: false },
                            ticks: {
                                color: TICK,
                                font: { family: 'Figtree', size: 12 },
                                callback: (value) => 'KSh ' + Number(value).toLocaleString(),
                            },
                        },
                    },
                },
            });
        },
    }));

    window.Alpine.data('occupancyStatusChart', (rows) => ({
        init() {
            new Chart(this.$refs.canvas, {
                type: 'bar',
                data: {
                    labels: rows.map((r) => r.label),
                    datasets: [
                        {
                            data: rows.map((r) => r.count),
                            backgroundColor: rows.map((r) => r.color),
                            borderRadius: 4,
                            maxBarThickness: 20,
                        },
                    ],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => `${ctx.parsed.x} unit${ctx.parsed.x === 1 ? '' : 's'}`,
                            },
                        },
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: { color: GRID },
                            border: { display: false },
                            ticks: { color: TICK, font: { family: 'Figtree', size: 12 }, precision: 0 },
                        },
                        y: {
                            grid: { display: false },
                            ticks: { color: TICK, font: { family: 'Figtree', size: 12 } },
                        },
                    },
                },
            });
        },
    }));

    window.Alpine.data('landlordCashflowChart', (months) => ({
        init() {
            new Chart(this.$refs.canvas, {
                data: {
                    labels: months.map((m) => m.label),
                    datasets: [
                        {
                            type: 'bar',
                            label: 'Income',
                            data: months.map((m) => m.income),
                            backgroundColor: TEAL,
                            borderRadius: 4,
                            maxBarThickness: 24,
                        },
                        {
                            type: 'bar',
                            label: 'Expenses',
                            data: months.map((m) => m.expenses),
                            backgroundColor: '#C94A4A',
                            borderRadius: 4,
                            maxBarThickness: 24,
                        },
                        {
                            type: 'line',
                            label: 'Net',
                            data: months.map((m) => m.net),
                            borderColor: NAVY,
                            backgroundColor: NAVY,
                            tension: 0.3,
                            pointRadius: 3,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: TICK, font: { family: 'Figtree', size: 12 } },
                        },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => `${ctx.dataset.label}: KSh ${ctx.parsed.y.toLocaleString()}`,
                            },
                        },
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: TICK, font: { family: 'Figtree', size: 12 } },
                        },
                        y: {
                            grid: { color: GRID },
                            border: { display: false },
                            ticks: {
                                color: TICK,
                                font: { family: 'Figtree', size: 12 },
                                callback: (value) => 'KSh ' + Number(value).toLocaleString(),
                            },
                        },
                    },
                },
            });
        },
    }));
});
