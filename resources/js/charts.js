/**
 * Charts, rendered with Chart.js (MIT, bundled). Only bar charts are
 * registered, which keeps the bundle small. Colours come from the package
 * CSS tokens, so charts follow the light/dark theme. Every chart has a
 * data-table twin rendered by the server, which stays visible if a chart
 * cannot be drawn.
 */

import { BarController, BarElement, CategoryScale, Chart, LinearScale, Tooltip } from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip);

const SERIES_TOKENS = {
    errors: '--ipx-lv-chart-errors',
    warnings: '--ipx-lv-chart-warnings',
    other: '--ipx-lv-chart-other',
};

function tokens(root) {
    const style = root.ownerDocument.defaultView.getComputedStyle(root);
    const read = (name, fallback) => style.getPropertyValue(name).trim() || fallback;

    return {
        errors: read(SERIES_TOKENS.errors, '#c22f2f'),
        warnings: read(SERIES_TOKENS.warnings, '#d97706'),
        other: read(SERIES_TOKENS.other, '#2a78d6'),
        bar: read('--ipx-lv-chart-bar', '#2a78d6'),
        grid: read('--ipx-lv-chart-grid', '#e2e8f0'),
        axis: read('--ipx-lv-chart-axis', '#64748b'),
        surface: read('--ipx-lv-color-surface', '#ffffff'),
        text: read('--ipx-lv-color-text', '#0f172a'),
        textSecondary: read('--ipx-lv-color-text-secondary', '#334155'),
        border: read('--ipx-lv-color-border', '#e2e8f0'),
        font: read('--ipx-lv-font-sans', 'system-ui, sans-serif'),
    };
}

function reducedMotion(win) {
    return typeof win.matchMedia === 'function' && win.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function baseOptions(colors, win) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: reducedMotion(win) ? false : { duration: 250 },
        font: { family: colors.font },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: colors.surface,
                titleColor: colors.text,
                bodyColor: colors.textSecondary,
                borderColor: colors.border,
                borderWidth: 1,
                padding: 10,
                boxPadding: 4,
                cornerRadius: 6,
                titleFont: { family: colors.font, weight: '600' },
                bodyFont: { family: colors.font },
            },
        },
    };
}

function axis(colors, extra = {}) {
    return {
        border: { display: false },
        grid: { color: colors.grid, drawTicks: false, lineWidth: 1 },
        ticks: { color: colors.axis, padding: 6, font: { family: colors.font, size: 12 } },
        ...extra,
    };
}

/** Rounded data-end only on the topmost non-empty segment of each stack. */
function topRadius(context) {
    const { chart, dataIndex, datasetIndex } = context;
    const datasets = chart.data.datasets;

    for (let i = datasetIndex + 1; i < datasets.length; i += 1) {
        if (chart.isDatasetVisible(i) && Number(datasets[i].data[dataIndex]) > 0) {
            return 0;
        }
    }

    return { topLeft: 4, topRight: 4 };
}

function trendChart(canvas, data, colors, win) {
    return new Chart(canvas, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: data.series.map((series) => ({
                label: series.label,
                data: series.data,
                backgroundColor: colors[series.key] || colors.other,
                hoverBackgroundColor: colors[series.key] || colors.other,
                // A 2px surface-coloured gap separates stacked segments.
                borderColor: colors.surface,
                borderWidth: { top: 2, right: 0, bottom: 0, left: 0 },
                borderSkipped: 'start',
                borderRadius: topRadius,
                maxBarThickness: 24,
                categoryPercentage: 0.8,
                barPercentage: 0.9,
            })),
        },
        options: {
            ...baseOptions(colors, win),
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: axis(colors, { stacked: true, grid: { display: false }, ticks: { color: colors.axis, maxRotation: 0, autoSkip: true, maxTicksLimit: 8, font: { family: colors.font, size: 12 } } }),
                y: axis(colors, { stacked: true, beginAtZero: true, ticks: { color: colors.axis, precision: 0, maxTicksLimit: 5, font: { family: colors.font, size: 12 } } }),
            },
        },
    });
}

/** Draws each bar's value just past its end ("value at the tip"). */
const valueLabels = {
    id: 'ipxValueLabels',
    afterDatasetsDraw(chart, args, options) {
        const { ctx } = chart;
        const meta = chart.getDatasetMeta(0);
        const values = chart.data.datasets[0].data;

        ctx.save();
        ctx.fillStyle = options.color;
        ctx.font = `500 12px ${options.font}`;
        ctx.textBaseline = 'middle';

        meta.data.forEach((bar, index) => {
            const value = Number(values[index]);
            ctx.fillText(value.toLocaleString(), bar.x + 6, bar.y);
        });

        ctx.restore();
    },
};

function levelsChart(canvas, data, colors, win) {
    const levels = data.levels;
    const max = Math.max(1, ...levels.map((level) => level.count));

    return new Chart(canvas, {
        type: 'bar',
        data: {
            labels: levels.map((level) => level.label),
            datasets: [{
                label: data.label || '',
                data: levels.map((level) => level.count),
                backgroundColor: colors.bar,
                hoverBackgroundColor: colors.bar,
                borderSkipped: 'start',
                borderRadius: 4,
                maxBarThickness: 18,
                categoryPercentage: 0.8,
                barPercentage: 0.9,
            }],
        },
        options: {
            ...baseOptions(colors, win),
            indexAxis: 'y',
            layout: { padding: { right: 48 } },
            interaction: { mode: 'nearest', axis: 'y', intersect: false },
            plugins: {
                ...baseOptions(colors, win).plugins,
                ipxValueLabels: { color: colors.textSecondary, font: colors.font },
            },
            scales: {
                x: axis(colors, { beginAtZero: true, suggestedMax: max, ticks: { color: colors.axis, precision: 0, maxTicksLimit: 4, font: { family: colors.font, size: 12 } } }),
                y: axis(colors, { grid: { display: false }, ticks: { color: colors.textSecondary, font: { family: colors.font, size: 12 } } }),
            },
        },
        plugins: [valueLabels],
    });
}

/**
 * Render every [data-ipx-lv-chart] inside a container. Returns the Chart
 * instances so they can be destroyed before the container is replaced.
 */
export function renderCharts(container, root, labels = {}) {
    const win = root.ownerDocument.defaultView;
    const colors = tokens(root);
    const charts = [];

    for (const element of container.querySelectorAll('[data-ipx-lv-chart]')) {
        const canvas = element.querySelector('canvas');
        const table = element.parentElement.querySelector('[data-ipx-lv-chart-table]');

        try {
            const data = JSON.parse(element.getAttribute('data-ipx-lv-chart-data') || '{}');
            const type = element.getAttribute('data-ipx-lv-chart');
            const chart = type === 'trend'
                ? trendChart(canvas, data, colors, win)
                : levelsChart(canvas, { ...data, label: labels.entries }, colors, win);

            charts.push(chart);

            // The chart is drawn: the data table becomes an optional disclosure.
            if (table) {
                table.open = false;
            }
        } catch (error) {
            element.classList.add('ipx-log-viewer__chart--failed');

            if (table) {
                table.open = true;

                if (labels.chartsUnavailable && !table.previousElementSibling?.hasAttribute('data-ipx-lv-chart-note')) {
                    const note = root.ownerDocument.createElement('p');
                    note.className = 'ipx-log-viewer__hint';
                    note.setAttribute('data-ipx-lv-chart-note', '');
                    note.textContent = labels.chartsUnavailable;
                    table.before(note);
                }
            }

            console.error('InertiaPixel Log Viewer: a chart could not be rendered.', error);
        }
    }

    return charts;
}

export function destroyCharts(charts) {
    for (const chart of charts) {
        chart.destroy();
    }

    charts.length = 0;
}
