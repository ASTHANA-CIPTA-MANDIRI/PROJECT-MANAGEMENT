import 'flowbite';
import './echo';
import Swal from 'sweetalert2/dist/sweetalert2.esm.js';

// Click-to-inspect for the dashboard pie charts. Filament 2 JSON-encodes chart
// options, so callbacks cannot be passed from PHP; instead this is a global
// Chart.js plugin that only acts on charts whose PHP options set
// `plugins.rencanakanPieDetail.enabled` (texts arrive as plugin options, so
// they stay translatable). `window.Chart` is the instance Filament bundles.
// All text is written with textContent - labels are never parsed as HTML.
const PIE_DETAIL_ID = 'rencanakanPieDetail';
const PIE_DETAIL_OFFSET = 10;

function pieDetailOptions(chart) {
    const opts = chart.options?.plugins?.[PIE_DETAIL_ID];

    return opts && opts.enabled ? opts : null;
}

function pieDetailPanel(chart, opts) {
    const state = chart.$pieDetail;

    // Livewire may morph the wrapper and drop the node; recreate on demand.
    if (state.panel && state.panel.isConnected) {
        return state.panel;
    }

    const panel = document.createElement('div');
    panel.className = 'pie-detail-panel';
    panel.setAttribute('role', 'status');
    panel.setAttribute('aria-live', 'polite');
    chart.canvas.insertAdjacentElement('afterend', panel);
    state.panel = panel;

    return panel;
}

function pieDetailRow(label, value) {
    const row = document.createElement('div');
    row.className = 'pie-detail-row';
    const dt = document.createElement('span');
    dt.className = 'pie-detail-key';
    dt.textContent = label;
    const dd = document.createElement('span');
    dd.className = 'pie-detail-value';
    dd.textContent = value;
    row.append(dt, dd);

    return row;
}

function pieDetailRender(chart) {
    const opts = pieDetailOptions(chart);

    if (!opts) {
        return;
    }

    const state = chart.$pieDetail;
    const panel = pieDetailPanel(chart, opts);
    const dataset = chart.data.datasets[0];
    const index = state.index;

    panel.replaceChildren();

    if (index === null || !dataset || index >= chart.data.labels.length) {
        state.index = null;
        panel.classList.remove('is-active');
        const hint = document.createElement('p');
        hint.className = 'pie-detail-hint';
        hint.textContent = opts.hint || '';
        panel.append(hint);

        return;
    }

    const values = dataset.data.map((v) => Number(v) || 0);
    const total = values.reduce((a, b) => a + b, 0);
    const value = values[index];
    const percent = total > 0 ? (value / total) * 100 : 0;
    const swatch = Array.isArray(dataset.backgroundColor)
        ? dataset.backgroundColor[index % dataset.backgroundColor.length]
        : dataset.backgroundColor;

    const title = document.createElement('div');
    title.className = 'pie-detail-title';
    const dot = document.createElement('span');
    dot.className = 'pie-detail-dot';
    dot.style.backgroundColor = swatch || 'currentColor';
    const name = document.createElement('strong');
    name.textContent = String(chart.data.labels[index]);
    title.append(dot, name);

    const fmt = new Intl.NumberFormat(document.documentElement.lang || undefined, {
        maximumFractionDigits: 1,
    });

    panel.classList.add('is-active');
    panel.append(
        title,
        pieDetailRow(opts.countLabel || '', fmt.format(value)),
        pieDetailRow(opts.shareLabel || '', fmt.format(percent) + '%'),
    );
}

function pieDetailApplyOffset(chart) {
    const dataset = chart.data.datasets[0];

    if (dataset) {
        dataset.offset = chart.data.labels.map((_, i) =>
            i === chart.$pieDetail.index ? PIE_DETAIL_OFFSET : 0,
        );
    }
}

const pieDetailPlugin = {
    id: PIE_DETAIL_ID,
    afterInit(chart) {
        if (!pieDetailOptions(chart)) {
            return;
        }

        chart.$pieDetail = chart.$pieDetail || { index: null, panel: null };
        chart.canvas.style.cursor = 'pointer';
        pieDetailRender(chart);
    },
    beforeUpdate(chart) {
        // Livewire replaces chart.data wholesale; re-apply the highlight.
        if (chart.$pieDetail && pieDetailOptions(chart)) {
            pieDetailApplyOffset(chart);
        }
    },
    afterUpdate(chart) {
        if (chart.$pieDetail && pieDetailOptions(chart)) {
            pieDetailRender(chart);
        }
    },
    afterEvent(chart, args) {
        const event = args.event;

        if (!chart.$pieDetail || event.type !== 'click' || !pieDetailOptions(chart)) {
            return;
        }

        const hit = chart.getElementsAtEventForMode(event, 'nearest', { intersect: true }, true)[0];
        const next = hit ? hit.index : null;

        // Clicking outside the pie keeps the current selection; clicking the
        // selected slice again clears it.
        if (next === null) {
            return;
        }

        chart.$pieDetail.index = next === chart.$pieDetail.index ? null : next;
        chart.update();
    },
};

if (window.Chart) {
    window.Chart.register(pieDetailPlugin);

    // Charts created before this script ran miss afterInit; adopt them.
    Object.values(window.Chart.instances || {}).forEach((chart) => {
        if (pieDetailOptions(chart) && !chart.$pieDetail) {
            pieDetailPlugin.afterInit(chart);
        }
    });
}

// ---------------------------------------------------------------------------
// Analytics charts (ApexCharts-like look on the Chart.js Filament bundles):
// smooth gradient area lines with a dashed crosshair + dark tooltip, and a
// white-gapped doughnut with a centre label. Data and (translated) texts are
// handed over by resources/views/filament/pages/analytics.blade.php; nothing
// here is parsed as HTML (canvas only).
const RK_TOOLTIP = {
    backgroundColor: 'rgba(38, 38, 38, 0.92)',
    titleColor: '#ffffff',
    bodyColor: '#ffffff',
    padding: 12,
    cornerRadius: 10,
    boxPadding: 6,
    usePointStyle: true,
    titleFont: { size: 13, weight: '500' },
    bodyFont: { size: 13 },
};

const RK_LEGEND = {
    position: 'bottom',
    labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 10, boxHeight: 10, padding: 18, color: '#8a94a6' },
};

const rkCrosshair = {
    id: 'rkCrosshair',
    afterDatasetsDraw(chart) {
        const active = chart.tooltip?.getActiveElements?.() ?? [];

        if (!active.length) {
            return;
        }

        const { ctx, chartArea } = chart;
        const x = active[0].element.x;

        ctx.save();
        ctx.setLineDash([4, 4]);
        ctx.strokeStyle = '#9aa3b2';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x, chartArea.top);
        ctx.lineTo(x, chartArea.bottom);
        ctx.stroke();
        ctx.restore();
    },
};

const rkCenterText = {
    id: 'rkCenterText',
    afterDraw(chart) {
        const opts = chart.options.plugins.rkCenterText;

        if (!opts) {
            return;
        }

        const { ctx, chartArea } = chart;
        const cx = (chartArea.left + chartArea.right) / 2;
        const cy = (chartArea.top + chartArea.bottom) / 2;
        const family = window.Chart.defaults.font.family;

        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#111827';
        ctx.font = `700 28px ${family}`;
        ctx.fillText(String(opts.value), cx, cy - 8);
        ctx.fillStyle = '#8a94a6';
        ctx.font = `400 12px ${family}`;
        ctx.fillText(opts.caption || '', cx, cy + 16);
        ctx.restore();
    },
};

window.rkCharts = {
    prepare(canvas) {
        window.Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        window.Chart.getChart(canvas)?.destroy();
    },

    // cfg: { labels: string[], series: [{ label, data: number[], color }] }
    area(canvas, cfg) {
        this.prepare(canvas);

        const gradient = (color) => (context) => {
            const { ctx, chartArea } = context.chart;

            if (!chartArea) {
                return `${color}33`;
            }

            const g = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
            g.addColorStop(0, `${color}59`);
            g.addColorStop(1, `${color}05`);

            return g;
        };

        return new window.Chart(canvas, {
            type: 'line',
            data: {
                labels: cfg.labels,
                datasets: cfg.series.map((serie) => ({
                    label: serie.label,
                    data: serie.data,
                    borderColor: serie.color,
                    backgroundColor: gradient(serie.color),
                    fill: true,
                    cubicInterpolationMode: 'monotone',
                    borderWidth: 3,
                    pointRadius: 0,
                    pointHoverRadius: 7,
                    pointHoverBackgroundColor: serie.color,
                    pointHoverBorderColor: '#ffffff',
                    pointHoverBorderWidth: 3,
                })),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                animation: { duration: 1400, easing: 'easeOutQuart' },
                plugins: {
                    legend: {
                        ...RK_LEGEND,
                        labels: {
                            ...RK_LEGEND.labels,
                            // The datasets' fill is a gradient function; show the solid line colour.
                            generateLabels: (chart) => chart.data.datasets.map((dataset, index) => ({
                                text: dataset.label,
                                fillStyle: dataset.borderColor,
                                strokeStyle: dataset.borderColor,
                                lineWidth: 0,
                                hidden: !chart.isDatasetVisible(index),
                                datasetIndex: index,
                                pointStyle: 'circle',
                            })),
                        },
                    },
                    tooltip: {
                        ...RK_TOOLTIP,
                        callbacks: {
                            labelColor: (item) => ({
                                borderColor: item.dataset.borderColor,
                                backgroundColor: item.dataset.borderColor,
                                borderRadius: 6,
                            }),
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false, drawBorder: true, borderColor: '#d9dee6' }, ticks: { color: '#9aa3b2' } },
                    y: {
                        beginAtZero: true,
                        grid: { display: false, drawBorder: false },
                        ticks: { color: '#9aa3b2', precision: 0 },
                    },
                },
            },
            plugins: [rkCrosshair],
        });
    },

    // cfg: { label, labels: string[], data: number[], color, hover }  - rounded pill bars
    bar(canvas, cfg) {
        this.prepare(canvas);

        return new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: cfg.labels,
                datasets: [{
                    label: cfg.label,
                    data: cfg.data,
                    backgroundColor: cfg.color,
                    hoverBackgroundColor: cfg.hover || cfg.color,
                    // cfg.top: columns rounded only at the top (Spike growth chart); default: full pill.
                    borderRadius: cfg.top ? { topLeft: 10, topRight: 10 } : 100,
                    borderSkipped: cfg.top ? 'bottom' : false,
                    maxBarThickness: cfg.thickness || 34,
                    barPercentage: cfg.top ? 0.7 : 0.6,
                    categoryPercentage: 0.8,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                animation: { duration: 900, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    tooltip: RK_TOOLTIP,
                    rkHoverBand: { enabled: true },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#9aa3b2' } },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(148, 163, 184, 0.22)', drawBorder: false },
                        ticks: { color: '#9aa3b2', precision: 0 },
                    },
                },
            },
        });
    },

    // cfg: { labels: string[], data: number[], colors: string[], center: { value, caption } }
    doughnut(canvas, cfg) {
        this.prepare(canvas);

        return new window.Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: cfg.labels,
                datasets: [{
                    data: cfg.data,
                    backgroundColor: cfg.colors,
                    borderColor: '#ffffff',
                    borderWidth: 3,
                    hoverOffset: 10,
                    hoverBorderColor: '#ffffff',
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                animation: { animateRotate: true, animateScale: true, duration: 1300, easing: 'easeOutQuart' },
                plugins: {
                    legend: cfg.legend === false ? { display: false } : RK_LEGEND,
                    tooltip: RK_TOOLTIP,
                    rkCenterText: cfg.center,
                },
            },
            plugins: [rkCenterText],
        });
    },
};

// ---------------------------------------------------------------------------
// RencanakanAlert - the single SweetAlert2 entry point for the panel.
//
// SweetAlert is UX only: every confirmed action still goes through the same
// Livewire call, so Policies / server-side checks remain the real gate.
// Texts come from PHP (`window.RencanakanAlertTexts`, rendered with __() in
// AppServiceProvider) so they stay translatable; the styles are SweetAlert's
// own default theme plus a few CSS variables in filament.scss.
//
//   RencanakanAlert.confirm({ severity, title, text, confirmText, cancelText })
//       -> Promise<boolean>   (severity: warning | danger | info | success)
//   RencanakanAlert.toast({ severity, title, text, timer })
//       -> Promise            (severity: success | warning | danger | error | info)
//
// Opt-in for any Blade button / form (works with wire:click, x-on:click,
// wire:submit - the click is intercepted before Livewire/Alpine see it):
//   <button wire:click="delete" data-swal-confirm="Delete this?"
//           data-swal-title="..." data-swal-confirm-text="..."
//           data-swal-severity="danger">
//
// Automatic: Filament confirmation modals (requiresConfirmation(), Delete /
// Restore / Detach actions...) and Filament toast notifications.
// ---------------------------------------------------------------------------
const rkTexts = () => window.RencanakanAlertTexts || {};
const rkText = (key, fallback) => rkTexts()[key] || fallback;
const rkIsDanger = (severity) => severity === 'danger' || severity === 'error';
const rkIcon = (severity) => (rkIsDanger(severity) ? 'error' : severity || 'info');

function rkConfirm({
    severity = 'warning',
    title,
    text,
    confirmText,
    cancelText,
} = {}) {
    return Swal.fire({
        icon: rkIsDanger(severity) ? 'warning' : severity,
        title: title || rkText('title', 'Are you sure?'),
        text: text || undefined,
        showCancelButton: true,
        focusCancel: rkIsDanger(severity),
        confirmButtonText: confirmText || rkText('confirm', 'Yes, continue'),
        cancelButtonText: cancelText || rkText('cancel', 'Cancel'),
        reverseButtons: true,
        customClass: {
            popup: 'rk-swal-popup' + (rkIsDanger(severity) ? ' rk-swal-danger' : ''),
        },
    }).then((result) => result.isConfirmed);
}

const rkToastMixin = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    showCloseButton: true,
    timerProgressBar: true,
    customClass: { popup: 'rk-swal-popup' },
    didOpen: (toast) => {
        toast.addEventListener('mouseenter', Swal.stopTimer);
        toast.addEventListener('mouseleave', Swal.resumeTimer);
    },
});

const rkIsModalOpen = () =>
    Swal.isVisible() && !Swal.getPopup()?.classList.contains('swal2-toast');

// Toasts are shown one after another, and never on top of an open
// confirmation (Swal shows a single popup; firing would dismiss the dialog).
let rkToastChain = Promise.resolve();

function rkToast({ severity = 'success', title, text, timer = 5000 } = {}) {
    rkToastChain = rkToastChain
        .then(async () => {
            while (rkIsModalOpen()) {
                await new Promise((resolve) => setTimeout(resolve, 300));
            }

            return rkToastMixin.fire({
                icon: rkIcon(severity),
                title: title || undefined,
                text: text || undefined,
                timer,
            });
        })
        .catch(() => {});

    return rkToastChain;
}

window.RencanakanAlert = { confirm: rkConfirm, toast: rkToast, Swal };

// --- data-swal-confirm: opt-in confirmation for any button or form --------
// Buttons rendered by vendor views we do not edit (filament-breezy's 2FA
// page) are matched by selector instead; their text comes from PHP too.
const RK_VENDOR_CONFIRMS = [
    {
        selector: '[wire\\:click="disableTwoFactor"]',
        textKey: 'disableTwoFactor',
        severity: 'danger',
    },
];

function rkOptionsFromElement(el) {
    const vendor = RK_VENDOR_CONFIRMS.find((entry) => el.matches(entry.selector));

    return {
        severity: el.dataset.swalSeverity || vendor?.severity || 'warning',
        title: el.dataset.swalTitle,
        text: el.dataset.swalConfirm || (vendor ? rkText(vendor.textKey, '') : undefined),
        confirmText: el.dataset.swalConfirmText,
        cancelText: el.dataset.swalCancelText,
    };
}

// Capture phase on document: runs before the element's own wire:click /
// x-on:click / wire:submit listeners, so cancelling really cancels. Event
// delegation also means it survives Livewire morphing (no per-node setup).
document.addEventListener(
    'click',
    (event) => {
        const selector = ['[data-swal-confirm]', ...RK_VENDOR_CONFIRMS.map((entry) => entry.selector)].join(',');
        const el = event.target instanceof Element ? event.target.closest(selector) : null;

        if (!el || el.matches('form') || el.disabled || el.getAttribute('aria-disabled') === 'true') {
            return;
        }

        if (el.dataset.swalPassthrough === '1') {
            delete el.dataset.swalPassthrough;

            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        rkConfirm(rkOptionsFromElement(el)).then((confirmed) => {
            if (confirmed) {
                el.dataset.swalPassthrough = '1';
                el.click();
                delete el.dataset.swalPassthrough;
            }
        });
    },
    true,
);

document.addEventListener(
    'submit',
    (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-swal-confirm')) {
            return;
        }

        if (form.dataset.swalPassthrough === '1') {
            delete form.dataset.swalPassthrough;

            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        rkConfirm(rkOptionsFromElement(form)).then((confirmed) => {
            if (confirmed) {
                form.dataset.swalPassthrough = '1';
                form.requestSubmit();
            }
        });
    },
    true,
);

// --- Filament confirmation modals -> SweetAlert ---------------------------
// Filament opens a modal by dispatching `open-modal` on window after Livewire
// has already morphed the new action's markup in. If that modal is a pure
// confirmation (heading + submit button, no form fields / custom content) we
// stop Filament's own handler (so the modal never shows and its focus trap
// never engages), show SweetAlert instead, and then either click Filament's
// real submit button (the same Livewire call as before) or close() the modal,
// which unmounts the action server-side exactly like Filament's Cancel does.
// Anything else (forms, details, slide-overs) is left to Filament untouched.
function rkFindModal(id) {
    return Array.from(document.querySelectorAll('.filament-modal')).find((el) =>
        (el.getAttribute('x-on:open-modal.window') || '').includes("'" + id + "'"),
    );
}

function rkReadConfirmationModal(root) {
    const content = root.querySelector('.filament-modal-content');
    const actions = root.querySelector('.filament-modal-actions');
    const submit = actions?.querySelector('button[type="submit"]');
    const heading = content?.querySelector('.filament-modal-heading');

    if (!content || !submit || !heading || root.querySelector('.filament-modal-header')) {
        return null;
    }

    // A non-empty slot (form, custom modalContent) renders as `.space-y-4`.
    // Blade's slot->isNotEmpty() is true for whitespace-only slots, so Filament
    // renders an empty wrapper even for plain confirmations: only a wrapper
    // with real elements or text counts as custom content.
    const slot = content.querySelector(':scope > .space-y-4');

    if (slot && (slot.children.length > 0 || slot.textContent.trim() !== '')) {
        return null;
    }

    const cancel = Array.from(actions.querySelectorAll('button')).find((b) => b !== submit);

    // Filament's button also holds state-only texts - `wire:loading` ones and
    // `x-show` ones that are not negated ("Uploading file..." while a file is
    // uploading) - plus icons; only the default label may become the
    // SweetAlert button text.
    const labelOf = (button) => {
        if (!button) {
            return undefined;
        }

        const clone = button.cloneNode(true);
        clone.querySelectorAll('*').forEach((node) => {
            const conditional = node.hasAttribute('x-show') && !node.getAttribute('x-show').trim().startsWith('!');

            if (node.tagName === 'svg' || conditional || Array.from(node.attributes).some((a) => a.name.startsWith('wire:loading'))) {
                node.remove();
            }
        });

        return clone.textContent.replace(/\s+/g, ' ').trim();
    };

    return {
        submit,
        title: heading.textContent.trim(),
        text: content.querySelector('.filament-modal-subheading')?.textContent.trim(),
        confirmText: labelOf(submit),
        cancelText: labelOf(cancel),
        severity: /danger/.test(submit.className) ? 'danger' : 'warning',
    };
}

window.addEventListener(
    'open-modal',
    (event) => {
        const id = event.detail?.id;
        const root = id ? rkFindModal(id) : null;
        const modal = root ? rkReadConfirmationModal(root) : null;

        if (!modal || !window.Alpine) {
            return;
        }

        event.stopImmediatePropagation();

        rkConfirm(modal).then((confirmed) => {
            if (confirmed) {
                modal.submit.click();
            } else {
                window.Alpine.$data(root).close();
            }
        });
    },
    true,
);

// --- Filament notifications -> SweetAlert toasts --------------------------
// The Filament notification (title/body/color/duration) stays the source of
// truth: we read it from the rendered element and its serialized payload.
// Notifications with actions, a custom view or `persistent()` stay as
// Filament's own toast, since a toast cannot carry buttons/links.
const RK_NOTIFICATION = '.filament-notifications-notification';
const rkHandledNotifications = new Set();

function rkNotificationPayload(el) {
    const match = /JSON\.parse\('(.*)'\)/s.exec(el.getAttribute('x-data') || '');

    try {
        return match ? JSON.parse(JSON.parse('"' + match[1] + '"')) : null;
    } catch (e) {
        return null;
    }
}

function rkHandleNotification(el) {
    // The bell's notification list reuses this class for its rows; those are
    // panel content, never toasts, so leave them alone.
    if (el.closest('.filament-modal')) {
        return;
    }

    const key = el.getAttribute('wire:key') || '';

    if (rkHandledNotifications.has(key)) {
        // Livewire morphs may strip the class again; re-apply it. Only when it is
        // really missing: classList.add() on an existing token still queues an
        // attribute mutation, which would re-trigger the observer forever.
        if (!el.classList.contains('rk-swal-handled')) {
            el.classList.add('rk-swal-handled');
        }

        return;
    }

    const payload = rkNotificationPayload(el);

    if (
        !payload ||
        payload.view !== 'notifications::notification' ||
        (payload.actions || []).length > 0 ||
        typeof payload.duration !== 'number'
    ) {
        return;
    }

    const title = el.querySelector('.filament-notifications-title')?.textContent.trim();
    const text = el.querySelector('.filament-notifications-body')?.textContent.trim();

    if (!title && !text) {
        return;
    }

    rkHandledNotifications.add(key);
    el.classList.add('rk-swal-handled');

    rkToast({
        severity: { success: 'success', warning: 'warning', danger: 'error' }[payload.iconColor] || 'info',
        title,
        text,
        timer: payload.duration,
    });
}

function rkScanNotifications(root) {
    if (root.matches?.(RK_NOTIFICATION)) {
        rkHandleNotification(root);
    }

    root.querySelectorAll?.(RK_NOTIFICATION).forEach(rkHandleNotification);
}

function rkObserveNotifications() {
    rkScanNotifications(document);

    new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type === 'attributes') {
                rkScanNotifications(mutation.target);

                return;
            }

            mutation.addedNodes.forEach((node) => node instanceof Element && rkScanNotifications(node));
        });
    }).observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class'],
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', rkObserveNotifications);
} else {
    rkObserveNotifications();
}

// --- Page header card: breadcrumb inside the card -------------------------
// Filament 2 only hands breadcrumbs to the topbar, not to the page header
// component, and we do not edit vendor views. So the crumbs Filament already
// computed (getBreadcrumbs) are read from the topbar and mirrored into the
// header card as "Home • Group • Page". Pages whose breadcrumbs are empty
// (Dashboard, custom pages) fall back to the page heading as the last crumb.
// While a card carries the breadcrumb, `body.rk-header-crumbs` hides the
// topbar copy (CSS), so the user never sees it twice. Pages without a
// `.filament-header` (custom getHeader()) keep the topbar breadcrumb.
const RK_HEADER = '.filament-page > div > .filament-header';

function rkHeaderCrumbs(header) {
    const crumbs = Array.from(
        document.querySelectorAll('.filament-main-topbar .filament-breadcrumbs li > a'),
    )
        .map((a) => ({ text: a.textContent.trim(), href: a.getAttribute('href') }))
        .filter((crumb) => crumb.text !== '');

    // A resource's list page ends at the resource ("Home - Projects"); drop
    // Filament's trailing "List" crumb there. Other pages (Edit/Create/View)
    // keep theirs.
    if (crumbs.length > 1 && document.querySelector('.filament-resources-list-records-page')) {
        crumbs.pop();
    }

    if (crumbs.length === 0) {
        const heading = header.querySelector('.filament-header-heading')?.textContent.trim();

        if (heading) {
            crumbs.push({ text: heading, href: null });
        }
    }

    return crumbs;
}

function rkSyncHeaderBreadcrumb() {
    const header = document.querySelector(RK_HEADER);

    if (!header) {
        document.body.classList.remove('rk-header-crumbs');

        return;
    }

    if (header.querySelector(':scope > .rk-header-breadcrumb')) {
        document.body.classList.add('rk-header-crumbs');

        return;
    }

    const crumbs = rkHeaderCrumbs(header);

    if (crumbs.length === 0) {
        return;
    }

    const home = document.querySelector('.filament-sidebar-header a[href]')?.getAttribute('href') || '/';
    const items = [{ text: rkText('home', 'Home'), href: home }, ...crumbs];

    const nav = document.createElement('nav');
    nav.className = 'rk-header-breadcrumb';
    nav.setAttribute('aria-label', 'Breadcrumb');
    const list = document.createElement('ol');

    items.forEach((item, index) => {
        const li = document.createElement('li');
        const isLast = index === items.length - 1;

        if (!isLast && item.href && item.href !== '#') {
            const a = document.createElement('a');
            a.href = item.href;
            a.textContent = item.text;
            li.append(a);
        } else {
            const span = document.createElement('span');
            span.textContent = item.text;

            if (isLast) {
                span.setAttribute('aria-current', 'page');
            }

            li.append(span);
        }

        list.append(li);
    });

    nav.append(list);
    // Between the title block and the action buttons.
    header.firstElementChild?.insertAdjacentElement('afterend', nav);
    document.body.classList.add('rk-header-crumbs');
}

let rkHeaderSyncQueued = false;

function rkQueueHeaderSync() {
    if (rkHeaderSyncQueued) {
        return;
    }

    rkHeaderSyncQueued = true;
    requestAnimationFrame(() => {
        rkHeaderSyncQueued = false;
        rkSyncHeaderBreadcrumb();
    });
}

function rkObserveHeader() {
    rkSyncHeaderBreadcrumb();

    // Livewire morphs may drop the injected node; the sync is idempotent, so
    // re-running it on any DOM change inside the content area is safe.
    const content = document.querySelector('.filament-main-content');

    if (content) {
        new MutationObserver(rkQueueHeaderSync).observe(content, { childList: true, subtree: true });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', rkObserveHeader);
} else {
    rkObserveHeader();
}

// ---------------------------------------------------------------------------
// Project table: row "three dots" -> icon bar above the table.
//
// The real Filament row actions stay in the DOM (hidden by CSS), so Policies,
// visibility and confirmations are untouched. Clicking a row's dots marks the
// row active and mirrors its visible actions as round icon buttons in
// `.rk-row-actions-bar`; each mirror only calls .click() on the original.
// Click delegation + a Livewire hook (no MutationObserver, nothing that
// writes to the DOM from a DOM-change callback), so there is no feedback loop.
// ---------------------------------------------------------------------------
// A body class per top-level page (rk-path-<slug>) lets CSS target pages that
// have no resource/page class of their own (API tokens, Jira import, settings).
{
    const rkSegments = location.pathname.split('/').filter(Boolean);

    document.body.classList.add('rk-path-' + (rkSegments[0] || 'home'));

    // Two-level pages (platform/organizations) also get rk-path-platform-organizations,
    // so one of them can be styled without touching its siblings (platform/support-session).
    if (rkSegments.length > 1) {
        document.body.classList.add('rk-path-' + rkSegments.slice(0, 2).join('-'));
    }
}

// Resource list pages that share the dots-menu / pager / Create-button chrome.
const RK_PROJECTS = ':is(.filament-resources-list-records-page:is(.filament-resources-projects, .filament-resources-tickets, .filament-resources-activities, .filament-resources-ticket-statuses, .filament-resources-ticket-types, .filament-resources-ticket-priorities, .filament-resources-project-statuses, .filament-resources-labels, .filament-resources-ticket-hours, .filament-resources-users, .filament-resources-roles, .filament-resources-permissions, .filament-resources-platform-plans), .rk-path-api-tokens, .rk-path-organization, .rk-path-platform-organizations, .rk-path-platform-payments)';
let rkActiveRowKey = null;

function rkActionLabel(el) {
    return (
        el.getAttribute('title') ||
        el.getAttribute('aria-label') ||
        el.querySelector('.sr-only')?.textContent.trim() ||
        el.textContent.trim() ||
        ''
    );
}

function rkFindActiveRow(root) {
    if (!rkActiveRowKey) {
        return null;
    }

    return Array.from(root.querySelectorAll('tbody tr')).find((tr) => tr.getAttribute('wire:key') === rkActiveRowKey) || null;
}

function rkSelectedRows(root) {
    return Array.from(root.querySelectorAll('tbody tr')).filter((tr) => tr.querySelector('input.filament-tables-record-checkbox:checked'));
}

// Round icon button that only proxies a click to the real Filament control.
function rkMirrorButton(original) {
    const svg = original.querySelector('svg');

    if (!svg || original.disabled) {
        return null;
    }

    const label = rkActionLabel(original);
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.title = label;
    btn.setAttribute('aria-label', label);
    btn.append(svg.cloneNode(true));

    const tone = original.outerHTML.match(/text-(danger|success)-/);

    if (tone) {
        btn.classList.add(`is-${tone[1]}`);
    }

    // The original carries wire:click / href / x-on:click / confirmation, so
    // every server-side check (Policy, BulkDeleteAuthorizer) runs as before.
    btn.addEventListener('click', () => original.click());

    return btn;
}

function rkRowOriginals(row) {
    return Array.from(row.querySelectorAll('.filament-tables-actions-container > a, .filament-tables-actions-container > button'));
}

// Only one bar at a time. Selection wins over the dots-activated row: while
// any checkbox is ticked, the Filament "N selected" bar carries the icons
// (bulk actions; plus the row's own actions when exactly one row is ticked)
// and the dots bar is removed. Idempotent: DOM is only written on change.
function rkRenderRowActionsBar() {
    const root = document.querySelector(RK_PROJECTS);

    if (!root) {
        return;
    }

    const selected = rkSelectedRows(root);
    const indicator = root.querySelector('.filament-tables-selection-indicator');
    const mirrors = indicator?.querySelector(':scope > .rk-selection-actions') || null;
    const existing = root.querySelector('.rk-row-actions-bar');

    if (selected.length > 0) {
        rkActiveRowKey = null;
        root.querySelectorAll('tbody tr.rk-row-active').forEach((tr) => tr.classList.remove('rk-row-active'));
        existing?.remove();

        if (!indicator) {
            return;
        }

        const wrap = document.createElement('div');
        wrap.className = 'rk-selection-actions rk-row-actions-bar__icons';

        const originals = [
            ...(selected.length === 1 ? rkRowOriginals(selected[0]) : []),
            ...Array.from(root.querySelectorAll('.filament-tables-bulk-actions .filament-tables-bulk-action')),
        ];

        originals.forEach((original) => {
            const btn = rkMirrorButton(original);

            if (btn) {
                wrap.append(btn);
            }
        });

        if (mirrors && mirrors.innerHTML === wrap.innerHTML && selected.length === Number(mirrors.dataset.count)) {
            return;
        }

        wrap.dataset.count = String(selected.length);
        mirrors ? mirrors.replaceWith(wrap) : indicator.append(wrap);

        return;
    }

    mirrors?.remove();

    const row = rkFindActiveRow(root);

    root.querySelectorAll('tbody tr.rk-row-active').forEach((tr) => {
        if (tr !== row) {
            tr.classList.remove('rk-row-active');
        }
    });

    if (!row) {
        rkActiveRowKey = null;
        existing?.remove();

        return;
    }

    row.classList.add('rk-row-active');

    const bar = document.createElement('div');
    bar.className = 'rk-row-actions-bar';
    bar.setAttribute('role', 'toolbar');

    const title = document.createElement('div');
    title.className = 'rk-row-actions-bar__title';
    title.textContent =
        (row.querySelector('.filament-table-cell-name .rk-cell-title') || row.querySelector('.filament-table-cell-name'))?.textContent.trim() || '';

    const icons = document.createElement('div');
    icons.className = 'rk-row-actions-bar__icons';

    rkRowOriginals(row).forEach((original) => {
        const btn = rkMirrorButton(original);

        if (btn) {
            icons.append(btn);
        }
    });

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'rk-row-actions-bar__close';
    close.title = rkText('close', 'Close');
    close.setAttribute('aria-label', close.title);
    close.textContent = '×';
    close.addEventListener('click', () => {
        rkActiveRowKey = null;
        rkRenderRowActionsBar();
    });
    icons.append(close);

    bar.append(title, icons);

    // Idempotent: skip the DOM write when the bar is already identical.
    if (existing && existing.innerHTML === bar.innerHTML) {
        return;
    }

    if (existing) {
        existing.replaceWith(bar);
    } else {
        root.querySelector('.filament-tables-table-container')?.insertAdjacentElement('beforebegin', bar);
    }
}

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const root = event.target.closest(RK_PROJECTS);

    // Ticking checkboxes / "select all" / "deselect all" changes Alpine state
    // without a change event on every box; re-render right after it settles.
    if (root && event.target.closest('input[type="checkbox"], .filament-tables-selection-indicator button')) {
        requestAnimationFrame(rkRenderRowActionsBar);

        return;
    }

    // Rows only: a table's header actions share this class (e.g. "Add member").
    const dots = event.target.closest(`${RK_PROJECTS} tbody .filament-tables-actions-container`);

    // `.rk-pp` (Super Admin > Pembayaran) shows its three row actions as real icons, not dots.
    if (dots && !dots.closest('.rk-pp')) {
        // Selection wins: with ticked rows the dots do nothing.
        if (rkSelectedRows(root).length > 0) {
            return;
        }

        const key = dots.closest('tr')?.getAttribute('wire:key') || null;

        rkActiveRowKey = key && key !== rkActiveRowKey ? key : null;
        rkRenderRowActionsBar();

        return;
    }

    // Outside click (not the bar, not a modal/SweetAlert) closes it.
    if (rkActiveRowKey && !event.target.closest('.rk-row-actions-bar, .filament-modal, .swal2-container')) {
        rkActiveRowKey = null;
        rkRenderRowActionsBar();
    }
});

// --- Projects page chrome: Create button in the toolbar + custom pager ------
// Both mirror real Filament controls (hidden by CSS, not removed), so Policy /
// visibility / Livewire calls are untouched; renders are idempotent and are
// triggered by load, Livewire's message.processed and clicks only.
const RK_CHEVRON_DOWN = '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>';
const RK_CHEVRON_LEFT = '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.83 10l3.94 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clip-rule="evenodd"/></svg>';
const RK_CHEVRON_RIGHT = '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.17 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>';
const RK_PLUS = '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z"/></svg>';

function rkRenderCreateButton(root) {
    const original = root.querySelector('.filament-header-actions > a, .filament-header-actions > button, .filament-page-actions > a, .filament-page-actions > button');
    const slot = root.querySelector('.filament-tables-header-toolbar > div:not(:first-child)');
    const existing = slot?.querySelector(':scope > .rk-create-btn') || null;

    if (!original || !slot) {
        existing?.remove();

        return;
    }

    const label = original.textContent.trim();

    if (existing && existing.dataset.label === label) {
        return;
    }

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'rk-create-btn';
    btn.dataset.label = label;
    btn.title = label;
    btn.setAttribute('aria-label', label);
    btn.innerHTML = RK_PLUS;
    const span = document.createElement('span');
    span.textContent = label;
    btn.append(span);
    btn.addEventListener('click', () => original.click());

    existing ? existing.replaceWith(btn) : slot.append(btn);
}

function rkPagerNumber(text) {
    return Number(text.replace(/\D/g, ''));
}

let rkPagerPopup = null;

function rkClosePagerPopup() {
    rkPagerPopup?.remove();
    rkPagerPopup = null;
    document.querySelector('.rk-pager__value')?.classList.remove('is-open');
}

function rkOpenPagerPopup(trigger, select) {
    rkClosePagerPopup();

    const popup = document.createElement('ul');
    popup.className = 'rk-pager__popup';
    popup.setAttribute('role', 'listbox');

    Array.from(select.options).forEach((option) => {
        const li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.textContent = option.textContent.trim();
        li.className = option.value === select.value ? 'is-selected' : '';
        li.addEventListener('click', () => {
            rkClosePagerPopup();

            if (select.value !== option.value) {
                select.value = option.value;
                // wire:model on a <select> listens to `change`.
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        popup.append(li);
    });

    trigger.closest('.rk-pager').append(popup);

    // position: fixed escapes the card's overflow-hidden; flip up if no room.
    const rect = trigger.getBoundingClientRect();
    const height = popup.offsetHeight;
    const below = window.innerHeight - rect.bottom > height + 12;

    popup.style.left = `${Math.max(8, rect.left)}px`;
    popup.style.top = `${below ? rect.bottom + 6 : Math.max(8, rect.top - height - 6)}px`;
    trigger.classList.add('is-open');
    rkPagerPopup = popup;
}

function rkRenderPager(root) {
    const container = root.querySelector('.filament-tables-pagination-container');
    const nav = container?.querySelector('.filament-tables-pagination');
    const existing = container?.querySelector(':scope > .rk-pager') || null;

    if (!nav) {
        existing?.remove();

        return;
    }

    const select = nav.querySelector('select[wire\\:model="tableRecordsPerPage"]');
    const prev = nav.querySelector('[rel="prev"]');
    const next = nav.querySelector('[rel="next"]');

    // "Menampilkan 1 sampai 5 dari 12 hasil" / "Showing 1 to 5 of 12 results":
    // the three integers are first, last, total in every locale; the single
    // number form ("Showing 1 result") means first = last = total.
    const overview = nav.querySelector('.lg\\:grid > div:first-child > div')?.textContent || '';
    const nums = (overview.match(/\d[\d.,]*/g) || []).map(rkPagerNumber);
    let range = '';

    if (nums.length >= 3) {
        range = `${nums[0]}–${nums[1]} ${rkText('of', 'of')} ${nums[2]}`;
    } else if (nums.length === 1) {
        range = `${nums[0]}–${nums[0]} ${rkText('of', 'of')} ${nums[0]}`;
    }

    const selected = select ? Array.from(select.options).find((o) => o.value === select.value) : null;
    const sig = [range, selected?.textContent.trim() || '', prev ? 1 : 0, next ? 1 : 0].join('|');

    if (existing && existing.dataset.sig === sig) {
        return;
    }

    rkClosePagerPopup();

    const pager = document.createElement('div');
    pager.className = 'rk-pager';
    pager.dataset.sig = sig;

    if (select && selected) {
        const label = document.createElement('span');
        label.className = 'rk-pager__label';
        label.textContent = `${rkText('rowsPerPage', 'Rows per page')}:`;

        const value = document.createElement('button');
        value.type = 'button';
        value.className = 'rk-pager__value';
        value.setAttribute('aria-haspopup', 'listbox');
        value.innerHTML = `<span>${selected.textContent.trim()}</span>${RK_CHEVRON_DOWN}`;
        value.addEventListener('click', (event) => {
            event.stopPropagation();
            rkPagerPopup ? rkClosePagerPopup() : rkOpenPagerPopup(value, select);
        });

        pager.append(label, value);
    }

    if (range) {
        const text = document.createElement('span');
        text.className = 'rk-pager__range';
        text.textContent = range;
        pager.append(text);
    }

    [[prev, RK_CHEVRON_LEFT, 'prev'], [next, RK_CHEVRON_RIGHT, 'next']].forEach(([original, icon, kind]) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `rk-pager__arrow is-${kind}`;
        btn.innerHTML = icon;
        btn.setAttribute('aria-label', kind);

        if (original) {
            btn.setAttribute('aria-label', original.getAttribute('aria-label') || kind);
            btn.addEventListener('click', () => original.click());
        } else {
            btn.disabled = true;
        }

        pager.append(btn);
    });

    existing ? existing.replaceWith(pager) : container.prepend(pager);
}

function rkRenderProjectsChrome() {
    const root = document.querySelector(RK_PROJECTS);

    if (!root) {
        return;
    }

    rkRenderCreateButton(root);
    rkRenderPager(root);
    rkRenderRowActionsBar();
}

document.addEventListener('click', (event) => {
    if (rkPagerPopup && !(event.target instanceof Element && event.target.closest('.rk-pager__popup, .rk-pager__value'))) {
        rkClosePagerPopup();
    }
});
document.addEventListener('keydown', (event) => event.key === 'Escape' && rkClosePagerPopup());
window.addEventListener('resize', rkClosePagerPopup);
window.addEventListener('scroll', rkClosePagerPopup, true);

// Livewire 2 starts (and fires `livewire:load`) from an inline script BEFORE
// this deferred module runs, so a bare `livewire:load` listener never fires.
// Boot immediately when Livewire is already there, else wait for the event.
let rkProjectsChromeBooted = false;

function rkBootProjectsChrome() {
    if (rkProjectsChromeBooted || !window.Livewire) {
        return;
    }

    rkProjectsChromeBooted = true;
    rkRenderProjectsChrome();
    window.Livewire.hook('message.processed', () => {
        requestAnimationFrame(rkRenderProjectsChrome);
    });
}

document.addEventListener('livewire:load', rkBootProjectsChrome);
rkBootProjectsChrome();

// Label of the unread-count pill in the notifications popover ("8 baru"),
// translated in PHP (RencanakanAlertTexts) and consumed by CSS via a variable.
document.documentElement.style.setProperty('--rk-new-label', JSON.stringify(` ${rkText('newBadge', 'new')}`));


// --- Chart.js: light column behind the hovered bar group (timesheet charts) ---
// Opt-in per chart through options.plugins.rkHoverBand.enabled, so no other
// chart in the app is affected. Registered once; Filament bundles window.Chart.
if (window.Chart && !window.__rkHoverBand) {
    window.__rkHoverBand = true;

    window.Chart.register({
        id: 'rkHoverBand',
        beforeDatasetsDraw(chart, _args, options) {
            if (!options || !options.enabled) {
                return;
            }

            const active = chart.tooltip && chart.tooltip.getActiveElements ? chart.tooltip.getActiveElements() : [];

            if (!active.length) {
                return;
            }

            const { ctx, chartArea } = chart;
            const slots = Math.max(1, chart.data.labels.length);
            const width = chartArea.width / slots;

            ctx.save();
            ctx.fillStyle = options.color || 'rgba(148, 163, 184, 0.14)';
            ctx.fillRect(active[0].element.x - width / 2, chartArea.top, width, chartArea.bottom - chartArea.top);
            ctx.restore();
        },
    });
}

// Floating bars ([from, to]) show their length in the tooltip instead of "[from, to]".
// Every other chart keeps Chart.js's own default label.
if (window.Chart && !window.__rkFloatLabel) {
    window.__rkFloatLabel = true;

    const callbacks = window.Chart.defaults.plugins.tooltip.callbacks;
    const defaultLabel = callbacks.label;

    callbacks.label = function (context) {
        if (Array.isArray(context.raw) && context.raw.length === 2) {
            const hours = Math.round((context.raw[1] - context.raw[0]) * 100) / 100;

            return (context.dataset.label ? context.dataset.label + ': ' : '') + hours;
        }

        return defaultLabel.call(this, context);
    };
}
