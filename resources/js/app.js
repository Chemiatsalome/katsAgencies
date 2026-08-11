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

document.addEventListener('alpine:init', () => {
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
