<x-filament::page class="rk-roadmap-page">

    <x-filament::card class="rk-rm-card">

        <div class="w-full lg:flex md:hidden sm:hidden hidden flex-col gap-5">
            <div class="w-full flex justify-between items-center">
                <form wire:submit.prevent="filter" class="flex items-center gap-2 min-w-[16rem]">
                    {{ $this->form }}
                    <button type="submit" class="rk-rm-search" aria-label="{{ __('Search') }}">
                        <x-heroicon-o-search class="w-5 h-5" wire:loading.remove />
                        <div wire:loading.flex>
                            <div class="lds-dual-ring w-4 h-4"></div>
                        </div>
                    </button>
                </form>
                <div class="flex items-center gap-2">
                    {{-- Nothing to attach an epic or a ticket to until a
                         project is selected. --}}
                    @if($project && auth()->user()->can('Create ticket'))
                        <button wire:click="createEpic" wire:loading.attr="disabled" class="rk-rm-btn">
                            <x-heroicon-o-plus class="w-4 h-4" /> {{ __('Epic') }}
                        </button>
                        <button wire:click="createTicket" wire:loading.attr="disabled" class="rk-rm-btn rk-rm-btn--success">
                            <x-heroicon-o-plus class="w-4 h-4" /> {{ __('Ticket') }}
                        </button>
                    @endif
                </div>
            </div>

            <div wire:init="filter" class="rk-gt" id="roadmap-chart" wire:ignore></div>
        </div>

        <div class="w-full 2xl:hidden xl:hidden lg:hidden md:flex sm:flex flex flex-col gap-2 text-center
                    items-center justify-center text-gray-500 font-medium">
            <x-heroicon-o-emoji-sad class="w-10 h-10" />
            <span>{{ __('Road Map chart is only available on large screen') }}</span>
        </div>
    </x-filament::card>

    @if($epic)
        <!-- Epic modal -->
        <div class="dialog-container">
            <div class="dialog dialog-lg">
                <div class="dialog-header">
                    {{ __($epic && $epic->id ? 'Update Epic' : 'Create Epic') }}
                </div>
                <div class="dialog-content">
                    @livewire('road-map.epic-form', ['epic' => $epic])
                </div>
            </div>
        </div>
    @endif

    @if($ticket)
        <!-- Epic modal -->
        <div class="dialog-container">
            <div class="dialog dialog-xl">
                <div class="dialog-header">
                    {{ __('Create ticket') }}
                </div>
                <div class="dialog-content">
                    @livewire('road-map.issue-form', ['project' => $project])
                </div>
            </div>
        </div>
    @endif

    @push('scripts')
        <script>
            // Road Map timeline (replaces the old table-based Gantt). Renders the
            // JSON of road-map.data; clicking an epic still emits `updateEpic`,
            // clicking a ticket still opens its share URL. Epics are bars from
            // their start/end date; tickets are bars from created -> due date
            // (or -> today / finish date) with arrows for "blocked by" and for
            // dependent epics. Every text goes through textContent.
            (() => {
                const root = document.getElementById('roadmap-chart');
                const locale = '{{ config('app.locale') }}';
                const T = @js([
                    'day' => __('Day'), 'week' => __('Week'), 'month' => __('Month'), 'today' => __('Today'),
                    'tickets' => __('Tickets'), 'epic' => __('Epic'), 'noEpic' => __('Without epic'),
                    'empty' => __('No epics yet. Use the Epic button to plan the first one.'),
                    'days' => __('days'), 'unscheduled' => __('Not scheduled yet'),
                ]);
                const DAY = 86400000;
                const LEFT = 340;
                const EPIC_H = 56;
                const TICKET_H = 44;
                const ZOOMS = { day: 34, week: 14, month: 4.4 };
                const COLORS = ['#3b82d6', '#72cc90', '#ee9a82', '#a98be8'];
                const state = { zoom: 'week', collapsed: new Set(), items: [], scrollTo: null, last: null, loadedAt: 0, first: true };
                const SVGNS = 'http://www.w3.org/2000/svg';

                const el = (tag, cls, text) => {
                    const node = document.createElement(tag);
                    if (cls) node.className = cls;
                    if (text !== undefined && text !== null) node.textContent = text;
                    return node;
                };
                const svg = (tag, attrs) => {
                    const node = document.createElementNS(SVGNS, tag);
                    Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));
                    return node;
                };
                const fmt = (date, opts) => new Intl.DateTimeFormat(locale, opts).format(date);
                const parse = (s) => {
                    const [y, m, d] = String(s).slice(0, 10).split('-').map(Number);
                    return new Date(y, m - 1, d);
                };
                const startOfWeek = (d) => {
                    const x = new Date(d);
                    x.setDate(x.getDate() - ((x.getDay() + 6) % 7));
                    return x;
                };
                const initials = (name) => name.split(' ').filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('');
                const safeUrl = (u) => (/^https?:\/\//i.test(u || '') ? u : null);

                // ---- model --------------------------------------------------------
                function build(items) {
                    const epics = items.filter((i) => i.pGroup === 1);
                    const tickets = items.filter((i) => i.pGroup !== 1).map((t) => ({
                        ...t,
                        start: parse(t.meta.start),
                        end: parse(t.meta.end),
                    }));
                    const groups = epics
                        .map((epic, index) => {
                            const children = tickets.filter((t) => String(t.pParent) === String(epic.pID));
                            const progress = children.length
                                ? Math.round(children.reduce((n, t) => n + (Number(t.pComp) || 0), 0) / children.length)
                                : 0;

                            return { epic, children, progress, color: COLORS[index % COLORS.length], start: parse(epic.pStart), end: parse(epic.pEnd) };
                        })
                        .sort((a, b) => a.start - b.start);
                    const orphans = tickets.filter((t) => !epics.some((e) => String(e.pID) === String(t.pParent)));

                    return { groups, orphans, tickets };
                }

                function range(groups, tickets) {
                    const today = new Date(new Date().setHours(0, 0, 0, 0));
                    const starts = groups.map((g) => g.start).concat(tickets.map((t) => t.start), today);
                    const ends = groups.map((g) => g.end).concat(tickets.map((t) => t.end), today);
                    const start = startOfWeek(new Date(Math.min(...starts) - 10 * DAY));
                    const end = new Date(Math.max(...ends) + 28 * DAY);

                    return { start, end, today };
                }

                // ---- rendering ----------------------------------------------------
                function render(keepScroll) {
                    const prev = root.querySelector('.rk-gt__scroll');
                    const saved = prev && keepScroll ? { left: prev.scrollLeft, top: prev.scrollTop } : null;
                    root.replaceChildren();
                    const { groups, orphans, tickets } = build(state.items);

                    const bar = el('div', 'rk-gt__toolbar');
                    const seg = el('div', 'rk-gt__seg');
                    Object.keys(ZOOMS).forEach((key) => {
                        const b = el('button', 'rk-gt__seg-btn' + (state.zoom === key ? ' is-active' : ''), T[key]);
                        b.type = 'button';
                        b.addEventListener('click', () => { state.zoom = key; render(false); });
                        seg.appendChild(b);
                    });
                    const todayBtn = el('button', 'rk-gt__today-btn', T.today);
                    todayBtn.type = 'button';
                    bar.append(seg, todayBtn);
                    root.appendChild(bar);

                    if (!groups.length && !orphans.length) {
                        root.appendChild(el('p', 'rk-gt__empty', T.empty));

                        return;
                    }

                    const { start, end, today } = range(groups, tickets);
                    const px = ZOOMS[state.zoom];
                    const width = Math.round(Math.ceil((end - start) / DAY) * px);
                    const x = (d) => Math.round(((d - start) / DAY) * px);
                    const xEnd = (d) => x(new Date(d.getTime() + DAY));

                    const scroller = el('div', 'rk-gt__scroll');
                    const inner = el('div', 'rk-gt__inner');
                    inner.style.width = (LEFT + width) + 'px';
                    inner.style.setProperty('--left', LEFT + 'px');

                    // header
                    const head = el('div', 'rk-gt__head');
                    const headLeft = el('div', 'rk-gt__cell-left rk-gt__head-left', T.epic + ' / ' + T.tickets);
                    const headRight = el('div', 'rk-gt__head-right');
                    headRight.style.width = width + 'px';
                    const top = el('div', 'rk-gt__head-top');
                    const bottom = el('div', 'rk-gt__head-bottom');
                    const lines = [];
                    const weekends = [];
                    const place = (parent, cls, text, from, to) => {
                        const c = el('div', cls, text);
                        c.style.left = from + 'px';
                        c.style.width = Math.max(0, to - from) + 'px';
                        parent.appendChild(c);
                    };

                    if (state.zoom === 'month') {
                        for (let y = start.getFullYear(); y <= end.getFullYear(); y++) {
                            place(top, 'rk-gt__top-cell', String(y), x(new Date(Math.max(start, new Date(y, 0, 1)))), x(new Date(Math.min(end, new Date(y + 1, 0, 1)))));
                        }
                        for (let d = new Date(start.getFullYear(), start.getMonth(), 1); d < end; d = new Date(d.getFullYear(), d.getMonth() + 1, 1)) {
                            place(bottom, 'rk-gt__tick', fmt(d, { month: 'short' }), x(d), x(new Date(d.getFullYear(), d.getMonth() + 1, 1)));
                            lines.push(x(d));
                        }
                    } else {
                        for (let d = new Date(start.getFullYear(), start.getMonth(), 1); d < end; d = new Date(d.getFullYear(), d.getMonth() + 1, 1)) {
                            place(top, 'rk-gt__top-cell', fmt(d, { month: 'long', year: 'numeric' }), x(new Date(Math.max(start, d))), x(new Date(Math.min(end, new Date(d.getFullYear(), d.getMonth() + 1, 1)))));
                        }
                        const stepDays = state.zoom === 'day' ? 1 : 7;
                        for (let d = new Date(start); d < end; d = new Date(d.getTime() + stepDays * DAY)) {
                            const a = x(d);
                            const weekend = state.zoom === 'day' && [0, 6].includes(d.getDay());
                            const label = state.zoom === 'day' ? fmt(d, { day: 'numeric' }) : fmt(d, { day: 'numeric', month: 'short' });
                            const cell = el('div', 'rk-gt__tick' + (weekend ? ' is-weekend' : ''), label);
                            cell.style.left = a + 'px';
                            cell.style.width = (stepDays * px) + 'px';
                            bottom.appendChild(cell);
                            lines.push(a);
                            if (weekend) weekends.push(a);
                        }
                    }
                    headRight.append(top, bottom);
                    head.append(headLeft, headRight);
                    inner.appendChild(head);

                    // body rows (+ anchors for the arrows)
                    const body = el('div', 'rk-gt__body');
                    const anchors = {};
                    let y = 0;
                    let barIndex = 0;
                    const addRow = (cls, height) => {
                        const row = el('div', 'rk-gt__row ' + cls);
                        body.appendChild(row);
                        const top0 = y;
                        y += height;

                        return { row, mid: top0 + height / 2 };
                    };
                    const openTicket = (t) => window.open('/tickets/share/' + t.meta.slug, '_blank');

                    const renderTicket = (t, group) => {
                        const { row, mid } = addRow('rk-gt__row--ticket', TICKET_H);
                        const left = el('div', 'rk-gt__cell-left');
                        const link = el('button', 'rk-gt__ticket-name', t.pName);
                        link.type = 'button';
                        link.title = t.pName;
                        link.addEventListener('click', () => openTicket(t));
                        left.appendChild(link);
                        const avatar = safeUrl(t.meta.avatar);
                        if (t.pRes && avatar) {
                            const img = el('img', 'rk-gt__avatar');
                            img.src = avatar;
                            img.alt = initials(t.pRes);
                            img.title = t.pRes;
                            img.loading = 'lazy';
                            left.appendChild(img);
                        } else if (t.pRes) {
                            left.appendChild(el('span', 'rk-gt__avatar rk-gt__avatar--text', initials(t.pRes)));
                        }
                        left.appendChild(el('span', 'rk-gt__pct' + (t.meta.done ? ' is-done' : ''), (Number(t.pComp) || 0) + '%'));
                        const right = el('div', 'rk-gt__cell-right');
                        right.style.width = width + 'px';

                        const color = group ? group.color : '#9aa3b2';
                        const x1 = x(t.start);
                        const x2 = Math.max(x1 + 10, xEnd(t.end));
                        const b = el('button', 'rk-gt__tbar' + (t.meta.scheduled || t.meta.done ? '' : ' is-open'));
                        b.type = 'button';
                        b.style.left = x1 + 'px';
                        b.style.width = (x2 - x1) + 'px';
                        b.style.setProperty('--c', color);
                        b.style.setProperty('--i', barIndex++);
                        const fill = el('span', 'rk-gt__bar-fill');
                        fill.style.width = Math.min(100, Number(t.pComp) || 0) + '%';
                        b.append(fill, el('span', 'rk-gt__bar-label', t.pName));
                        b.addEventListener('click', () => openTicket(t));
                        b.addEventListener('mousemove', (e) => showTip(e, t.pName, fmt(t.start, { day: 'numeric', month: 'long', year: 'numeric' }) + ' – ' + (t.meta.scheduled || t.meta.done ? fmt(t.end, { day: 'numeric', month: 'long', year: 'numeric' }) : T.unscheduled), (t.pRes || '') + (t.pRes ? ' · ' : '') + (Number(t.pComp) || 0) + '%'));
                        b.addEventListener('mouseleave', hideTip);
                        right.appendChild(b);
                        row.append(left, right);
                        anchors['t' + t.meta.id] = { x1, x2, y: mid, half: 11 };
                    };

                    groups.forEach((g) => {
                        const key = String(g.epic.pID);
                        const closed = state.collapsed.has(key);
                        const { row, mid } = addRow('rk-gt__row--epic', EPIC_H);
                        const left = el('div', 'rk-gt__cell-left');
                        const chev = el('button', 'rk-gt__chev' + (closed ? ' is-closed' : ''), '▾');
                        chev.type = 'button';
                        chev.setAttribute('aria-label', 'toggle');
                        chev.addEventListener('click', () => {
                            closed ? state.collapsed.delete(key) : state.collapsed.add(key);
                            render(true);
                        });
                        const dot = el('span', 'rk-gt__dot');
                        dot.style.background = g.color;
                        const name = el('button', 'rk-gt__epic-name', g.epic.pName);
                        name.type = 'button';
                        name.title = g.epic.pName;
                        name.addEventListener('click', () => Livewire.emit('updateEpic', g.epic.meta.id));
                        const dates = el('span', 'rk-gt__epic-range', fmt(g.start, { day: 'numeric', month: 'short' }) + ' – ' + fmt(g.end, { day: 'numeric', month: 'short' }));
                        const stack = el('span', 'rk-gt__epic-text');
                        stack.append(name, dates);
                        left.append(chev, dot, stack, el('span', 'rk-gt__count', String(g.children.length)));
                        const right = el('div', 'rk-gt__cell-right');
                        right.style.width = width + 'px';

                        const x1 = x(g.start);
                        const x2 = Math.max(x1 + 10, xEnd(g.end));
                        const b = el('button', 'rk-gt__bar');
                        b.type = 'button';
                        b.style.left = x1 + 'px';
                        b.style.width = (x2 - x1) + 'px';
                        b.style.setProperty('--c', g.color);
                        b.style.setProperty('--i', barIndex++);
                        const fill = el('span', 'rk-gt__bar-fill');
                        fill.style.width = g.progress + '%';
                        b.append(fill, el('span', 'rk-gt__bar-label', g.epic.pName));
                        b.addEventListener('click', () => Livewire.emit('updateEpic', g.epic.meta.id));
                        b.addEventListener('mousemove', (e) => showTip(e, g.epic.pName,
                            fmt(g.start, { day: 'numeric', month: 'long', year: 'numeric' }) + ' – ' + fmt(g.end, { day: 'numeric', month: 'long', year: 'numeric' }),
                            (Math.round((g.end - g.start) / DAY) + 1) + ' ' + T.days + ' · ' + g.children.length + ' ' + T.tickets + ' · ' + g.progress + '%'));
                        b.addEventListener('mouseleave', hideTip);
                        right.appendChild(b);
                        row.append(left, right);
                        anchors['e' + g.epic.meta.id] = { x1, x2, y: mid, half: 16 };

                        if (!closed) g.children.forEach((t) => renderTicket(t, g));
                    });

                    if (orphans.length) {
                        const { row } = addRow('rk-gt__row--epic', EPIC_H);
                        const left = el('div', 'rk-gt__cell-left');
                        left.append(el('span', 'rk-gt__dot rk-gt__dot--muted'), el('span', 'rk-gt__epic-text', T.noEpic), el('span', 'rk-gt__count', String(orphans.length)));
                        const right = el('div', 'rk-gt__cell-right');
                        right.style.width = width + 'px';
                        row.append(left, right);
                        orphans.forEach((t) => renderTicket(t, null));
                    }

                    // arrows: epic dependency (parent -> child) and "blocked by" (blocker -> blocked)
                    const links = [];
                    groups.forEach((g) => {
                        if (g.epic.pDepend) links.push(['e' + g.epic.pDepend, 'e' + g.epic.meta.id]);
                    });
                    tickets.forEach((t) => (t.meta.blocked_by || []).forEach((id) => links.push(['t' + id, 't' + t.meta.id])));
                    const arrows = svg('svg', { class: 'rk-gt__arrows', width, height: y });
                    const pending = [];
                    arrows.style.left = LEFT + 'px';
                    links.forEach(([from, to]) => {
                        const a = anchors[from];
                        const b = anchors[to];
                        if (!a || !b || a.y === b.y) return;
                        const dir = b.y > a.y ? 1 : -1;
                        const ye = b.y - dir * b.half;
                        const r = 6;
                        let d;
                        let xh;
                        if (b.x1 + 14 >= a.x2 + 12) {
                            // target starts after the source ends: right, then down into the target start
                            xh = b.x1 + 14;
                            d = `M ${a.x2} ${a.y} H ${xh - r} Q ${xh} ${a.y} ${xh} ${a.y + dir * r} V ${ye - dir * 5}`;
                        } else {
                            // target starts earlier: out to the right, across between the rows, then into its start
                            xh = b.x1 + 14;
                            const yc = ye - dir * 12;
                            const xo = a.x2 + 12;
                            d = `M ${a.x2} ${a.y} H ${xo - r} Q ${xo} ${a.y} ${xo} ${a.y + dir * r} V ${yc - dir * r} Q ${xo} ${yc} ${xo - r} ${yc} H ${xh + r} Q ${xh} ${yc} ${xh} ${yc + dir * r} V ${ye - dir * 5}`;
                        }
                        const path = svg('path', { d, class: 'rk-gt__arrow' });
                        arrows.appendChild(path);
                        pending.push(path);
                        arrows.appendChild(svg('path', {
                            d: `M ${xh - 4} ${ye - dir * 6} L ${xh + 4} ${ye - dir * 6} L ${xh} ${ye} Z`,
                            class: 'rk-gt__arrow-head',
                        }));
                    });
                    body.appendChild(arrows);
                    inner.appendChild(body);
                    // length of each connector for the "draw" animation (needs the DOM)
                    requestAnimationFrame(() => pending.forEach((p) => p.style.setProperty('--len', (p.getTotalLength?.() ?? 400) + 'px')));

                    // overlay: grid lines, weekend hatching, today
                    const overlay = el('div', 'rk-gt__overlay');
                    overlay.style.width = width + 'px';
                    weekends.forEach((a) => { const w = el('i', 'rk-gt__weekend'); w.style.left = a + 'px'; w.style.width = px + 'px'; overlay.appendChild(w); });
                    lines.forEach((a) => { const l = el('i', 'rk-gt__vline'); l.style.left = a + 'px'; overlay.appendChild(l); });
                    const tx = x(today) + Math.round(px / 2);
                    if (tx >= 0 && tx <= width) {
                        const line = el('i', 'rk-gt__today'); line.style.left = tx + 'px';
                        overlay.appendChild(line);
                        const pill = el('b', 'rk-gt__today-pill', T.today); pill.style.left = tx + 'px';
                        headRight.appendChild(pill);
                    }
                    inner.appendChild(overlay);
                    scroller.appendChild(inner);
                    root.appendChild(scroller);

                    const tip = el('div', 'rk-gt__tip'); tip.hidden = true;
                    root.appendChild(tip);

                    const jump = (date) => { scroller.scrollLeft = Math.max(0, x(date) - 40); };
                    todayBtn.addEventListener('click', () => jump(today));
                    if (saved) {
                        scroller.scrollLeft = saved.left;
                        scroller.scrollTop = saved.top;
                    } else {
                        jump(state.first ? (state.scrollTo || today) : today);
                    }
                    state.first = false;

                    function showTip(e, title, line1, line2) {
                        tip.replaceChildren(el('b', '', title), el('span', '', line1), el('span', '', line2));
                        const r = root.getBoundingClientRect();
                        tip.style.left = Math.min(e.clientX - r.left + 14, r.width - 260) + 'px';
                        tip.style.top = (e.clientY - r.top + 16) + 'px';
                        tip.hidden = false;
                    }
                    function hideTip() { tip.hidden = true; }
                }

                async function load(detail) {
                    state.last = detail;
                    state.scrollTo = detail.scroll_to ? parse(detail.scroll_to) : null;
                    try {
                        const response = await fetch(detail.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                        state.items = response.ok ? await response.json() : [];
                    } catch (e) {
                        state.items = [];
                    }
                    state.loadedAt = Date.now();
                    render(true);
                }

                window.addEventListener('projectChanged', (e) => load(e.detail));

                // Progress changes when hours are logged or a ticket reaches Done: pick
                // those up when the user comes back to this tab.
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible' && root.isConnected && state.last && Date.now() - state.loadedAt > 20000) {
                        load(state.last);
                    }
                });
            })();
        </script>
    @endpush

</x-filament::page>
