        // An app, not a page: no pinch zoom (iOS ignores user-scalable=no) and no ctrl+wheel / ctrl+plus zoom.
        ['gesturestart', 'gesturechange', 'gestureend'].forEach((t) => document.addEventListener(t, (e) => e.preventDefault(), { passive: false }));
        document.addEventListener('touchmove', (e) => { if (e.touches.length > 1) e.preventDefault(); }, { passive: false });
        document.addEventListener('wheel', (e) => { if (e.ctrlKey) e.preventDefault(); }, { passive: false });
        document.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && ['+', '-', '=', '0'].includes(e.key)) e.preventDefault(); });

        // ---- toffee: everyone's channel, profiles and comment threads -----------------
        // Talks to api.php. Everything needs a profile: without one you only see the sign-in screen.
        // Each channel has two tabs: Public, and Private (seen only by the two people in it).
        const social = (() => {
            const API = 'api.php';
            const SVG = {
                people: '<svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8.5" r="3.2" stroke="currentColor" stroke-width="1.8" fill="none"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0M15.5 5.6a3 3 0 0 1 0 5.8M17 14.2a5 5 0 0 1 3.5 4.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" fill="none"/></svg>',
                reply: '<svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 8 5 12.5l5 4.5M5.5 12.5H14a5 5 0 0 1 5 5V19" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>',
                smile: '<svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M8.5 14.5a4.5 4.5 0 0 0 7 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/><circle cx="9" cy="10" r="1.2" fill="currentColor"/><circle cx="15" cy="10" r="1.2" fill="currentColor"/></svg>',
                keyboard: '<svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M7 10h.01M10.5 10h.01M14 10h.01M17.5 10h.01M8 14h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none" stroke-width="2"/></svg>',
                bell: '<svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2h-15z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M10 20.5a2 2 0 0 0 4 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>',
                phone: '<svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 3.5h2.8l1.4 4-2 1.3a11 11 0 0 0 6.4 6.4l1.3-2 4 1.4v2.8a2 2 0 0 1-2.2 2A17 17 0 0 1 4.6 5.7a2 2 0 0 1 2-2.2z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>',
                video: '<svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="7" width="12" height="10" rx="2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M15 10.5 21 7v10l-6-3.5z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>',
                lock: '<svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2" fill="#fff6cc" stroke="currentColor" stroke-width="1.8"/><path d="M8 11V8a4 4 0 0 1 8 0v3" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>',
                edit: '<svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20l1-4L16 5l3 3L8 19z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M14 7l3 3" stroke="currentColor" stroke-width="1.8"/></svg>',
                link: '<svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a4.5 4.5 0 0 0 6.4 0l3.3-3.3a4.5 4.5 0 0 0-6.4-6.4L12 5.6M14 10a4.5 4.5 0 0 0-6.4 0l-3.3 3.3a4.5 4.5 0 0 0 6.4 6.4l1.3-1.3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
                attach: '<svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M16.5 7.5 9 15a2.1 2.1 0 0 1-3-3l8-8a3.5 3.5 0 0 1 5 5l-8.5 8.5a5 5 0 0 1-7-7L11 3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
                mic: '<svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M6 11a6 6 0 0 0 12 0M12 17v4M9 21h6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
                send: '<svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 21 4l-6.5 17-3-7.5z" style="fill:var(--send-ink, var(--accent-dark));stroke:var(--send-ink, var(--accent-dark))" stroke-width="1.8" stroke-linejoin="round"/><path d="M11.5 13.5 21 4" style="stroke:var(--accent-soft)" stroke-width="1.4"/></svg>',
                del: '<svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M10 7V4h4v3M7 7l1 13h8l1-13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/></svg>',
            };
            // Icon button: drawn icon + optional text, name as tooltip and for screen readers.
            function iconBtn(label, icon, onclick, extraText = '', cls = '') {
                const b = el('button', { class: `sbtn icon-act ${cls}`, type: 'button', title: label, 'aria-label': label, onclick });
                b.innerHTML = icon;   // our own constant SVG, never user text
                if (extraText) b.append(el('span', { class: 'icon-count', text: extraText }));
                return b;
            }
            // Emoji set and reactions from the Torii messenger.
            const EMOJIS = "😀 😃 😄 😁 😆 😅 😂 🤣 🥲 🥹 ☺️ 😊 😇 🙂 🙃 😉 😌 😍 🥰 😘 😗 😙 😚 😋 😛 😝 😜 🤪 🤨 🧐 🤓 😎 🥸 🤩 🥳 😏 😒 😞 😔 😟 😕 🙁 ☹️ 😣 😖 😫 😩 🥺 😢 😭 😤 😠 😡 🤬 🤯 😳 🥵 🥶 😱 😨 😰 😥 😓 🤗 🤔 🫣 🤭 🫢 🫡 🤫 🫠 🤥 😶 🫥 😐 😑 😬 🙄 😯 😦 😧 😮 😲 🥱 😴 🤤 😪 😵 😵‍💫 🫨 🤐 🥴 🤢 🤮 🤧 😷 🤒 🤕 🤑 🤠 😈 👿 👹 👺 🤡 💩 👻 💀 ☠️ 👽 👾 🤖 🎃 😺 😸 😹 😻 😼 😽 🙀 😿 😾 🫶 👋 🤚 🖐️ ✋ 🖖 👌 🤌 🤏 ✌️ 🤞 🫰 🤟 🤘 🤙 👈 👉 👆 🖕 👇 ☝️ 👍 👎 ✊ 👊 🤛 🤜 👏 🫶 👐 🙌 🫶 🤝 🙏 ✍️ 💅 🤳 💪 🦵 🦶 👂 🦻 👃 🧠 🫀 🫁 🦷 🦴 👀 👁️ 👅 👄 🫦 💋 🩸".split(' ').filter(Boolean);
            const REACTIONS = ['❤️', '😂', '😮', '😢', '👍'];
            const overlay = document.querySelector('.social-overlay');
            const titleEl = document.getElementById('socialTitle');
            const backBtn = overlay.querySelector('.social-back');
            // 🏠: straight back to the home feed from anywhere, however many screens deep (‹ goes back one).
            const homeBtn = overlay.querySelector('.social-home');
            homeBtn.addEventListener('click', () => { history = []; go({ name: 'board' }, false); });
            const accountEl = overlay.querySelector('.social-dialog .social-account');
            const tabs = overlay.querySelector('.social-tabs');
            const tabRow = overlay.querySelector('.tab-row');
            const searchBtn = overlay.querySelector('.tab-search-btn');
            const searchRow = overlay.querySelector('.tab-search');
            const headBell = overlay.querySelector('.social-dialog .head-bell');   // 🔒 and 🔔, top right of the logo row
            const body = overlay.querySelector('.social-dialog .social-body');
            const foot = overlay.querySelector('.social-dialog .social-foot');   // the comment box, in a channel
            // Wide screens: two columns, the feed on the left (its own list, account bar and box).
            const feedPane = overlay.querySelector('.feed-pane');
            const feedPaneEls = { body: feedPane.querySelector('.feed-body'), account: feedPane.querySelector('.feed-account'), bell: feedPane.querySelector('.feed-bell') };
            const dialog = overlay.querySelector('.social-dialog');
            const wideQuery = window.matchMedia('(min-width: 1000px)');
            const isWide = () => wideQuery.matches && !!me;
            const channelEl = overlay.querySelector('.social-channel');   // whose channel this is: stays put above the tabs
            let csrf = '';
            let me = null;
            let view = { name: 'board' };
            let history = [];
            let pollTimer = null;
            let unread = 0;
            let highlightPost = null;   // scroll to and highlight this post after opening a thread
            const boardFilter = { text: '', mine: false };   // the channel list's search box and "Me" button

            // Loading: while toffee waits on the server, taps are blocked straight away (no double
            // sends), and after a moment a soft cover shows the logo and "loading…". Background
            // checks (new comments, the bell) stay silent.
            const busyEl = el('div', { class: 'tf-busy', hidden: true, 'aria-live': 'polite' },
                el('div', { class: 'tf-busy-card' }, el('img', { src: 'toffee-logo.png?v=2', alt: '' }), el('span', { text: 'loading…' })));
            document.body.append(busyEl);
            let busy = 0;
            let quiet = 0;   // > 0 while a background refresh runs
            let busyTimer = null;
            function setBusy(delta) {
                busy = Math.max(0, busy + delta);
                busyEl.hidden = busy === 0;
                clearTimeout(busyTimer);
                if (busy) busyTimer = setTimeout(() => busyEl.classList.add('show'), 300);
                else busyEl.classList.remove('show');
            }
            async function api(action, { method = 'GET', data, form, query = '' } = {}) {
                const silent = quiet > 0 || action === 'latest';
                if (!silent) setBusy(+1);
                try { return await apiCall(action, { method, data, form, query }); }
                finally { if (!silent) setBusy(-1); }
            }
            // toffee out of reach (a new version going live: the front door answers 502/503/504 for a
            // few minutes; or no connection): a calm bar says so, and toffee checks every 15 s and
            // carries on by itself when it's back (the view you're on is reloaded).
            const downBar = el('div', { class: 'down-bar', hidden: true, role: 'status', 'aria-live': 'polite' });
            document.body.append(downBar);
            let downTimer = null;
            function setDown(on) {
                if (on === !downBar.hidden) return;
                downBar.hidden = !on;
                clearInterval(downTimer);
                if (!on) { render(); return; }
                downBar.textContent = navigator.onLine === false
                    ? "No connection. toffee carries on by itself when you're back online."
                    : 'toffee is updating: back in about 3–5 minutes. It carries on by itself.';
                downTimer = setInterval(async () => {
                    try { const r = await fetch('/healthz', { cache: 'no-store' }); if (r.ok && (await r.text()).trim() === 'ok') setDown(false); } catch (e) { /* not yet */ }
                }, 15000);
            }
            window.addEventListener('online', () => { if (!downBar.hidden) fetch('/healthz', { cache: 'no-store' }).then((r) => r.ok && setDown(false)).catch(() => {}); });
            async function apiCall(action, { method, data, form, query }) {
                const opts = { method, headers: {}, credentials: 'same-origin' };
                if (method === 'POST') {
                    opts.headers['X-CSRF-Token'] = csrf;
                    if (form) {
                        opts.body = form;
                    } else {
                        opts.headers['Content-Type'] = 'application/json';
                        opts.body = JSON.stringify(data || {});
                    }
                }
                let res;
                try {
                    res = await fetch(`${API}?a=${action}${query}`, opts);
                } catch (e) {
                    setDown(true);
                    return { ok: false, down: true, error: 'No connection. Try again.' };
                }
                if (res.status >= 502 && res.status <= 504) { setDown(true); return { ok: false, down: true, error: 'toffee is updating: back in a few minutes.' }; }
                if (!downBar.hidden) setDown(false);
                try {
                    const r = await res.json();
                    if (r && r.csrf) csrf = r.csrf;   // signed in or out: a new token comes with the reply
                    return r;
                } catch (e) {
                    // Not JSON: toffee restarting (502/503/504 from the front door) counts as no connection.
                    return res.status >= 502 ? { ok: false, error: 'No connection. Try again.' } : { ok: false, error: 'Unexpected reply from the server.' };
                }
            }

            // Uploads report their progress (a 50 MB video on mobile data takes a while), which
            // fetch can't do: so XHR, with the same token and error handling as api().
            function uploadWithProgress(form, onProgress) {
                return new Promise((resolve) => {
                    const x = new XMLHttpRequest();
                    x.open('POST', `${API}?a=upload`);
                    x.setRequestHeader('X-CSRF-Token', csrf);
                    x.upload.onprogress = (e) => { if (e.lengthComputable) onProgress(Math.round((e.loaded / e.total) * 100)); };
                    x.onload = () => { try { const r = JSON.parse(x.responseText); if (r && r.csrf) csrf = r.csrf; resolve(r); } catch (e) { resolve({ ok: false, error: x.status === 413 ? 'That file is too big.' : 'Unexpected reply from the server.' }); } };
                    x.onerror = () => resolve({ ok: false, error: 'No connection. Try again.' });
                    x.send(form);
                });
            }

            let lastMeOffline = false;   // the last ?a=me didn't reach toffee at all
            let booted = false;          // the app has started (see boot())
            async function refreshMe() {
                const early = window.tfMe;   // index.php asked already, while app.js was loading
                window.tfMe = null;
                const r = (early && await early.catch(() => null)) || await api('me');
                if (r && r.csrf && early) csrf = r.csrf;
                lastMeOffline = !r.ok && r.error === 'No connection. Try again.';
                if (r.ok) {
                    csrf = r.csrf;
                    const was = me;
                    me = r.user;
                    if (me && !was && booted) refreshUnread();   // just signed in: the 🔔 count and 🔒 straight away (at start, open() asks)
                }
                renderAccount();
                return r.ok;
            }

            // Small helpers that never use innerHTML for user text.
            function el(tag, attrs = {}, ...kids) {
                const n = document.createElement(tag);
                for (const [k, v] of Object.entries(attrs)) {
                    if (k === 'class') n.className = v;
                    else if (k === 'text') n.textContent = v;
                    else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
                    else if (v !== undefined && v !== null && v !== false) n.setAttribute(k, v === true ? '' : v);
                }
                kids.flat().forEach((c) => c !== null && c !== undefined && n.append(c));
                return n;
            }
            // Picture viewer: pinch (or the mouse wheel) to zoom, drag to look around. A tap on a
            // zoomed picture brings it back to size; a tap on one at normal size closes it.
            function showPicture(src, caption) {
                const img = el('img', { src, alt: caption || '', draggable: 'false' });
                const lb = el('div', { class: 'lightbox', role: 'dialog', 'aria-label': caption || 'Picture' },
                    img, caption ? el('div', { class: 'cap', text: caption }) : null,
                    el('div', { class: 'cap', style: 'opacity:0.7', text: 'Pinch to zoom · tap to close' }));
                let scale = 1, x = 0, y = 0, moved = false, start = null;
                const pts = new Map();   // fingers (or the mouse) on the picture
                const show = () => { img.style.transform = scale === 1 ? '' : `translate(${x}px, ${y}px) scale(${scale})`; };
                const reset = () => { scale = 1; x = 0; y = 0; show(); };
                const begin = () => { start = { scale, x, y, pts: [...pts.values()].map((q) => ({ ...q })) }; };
                const mid = (a, b) => ({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });
                const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y) || 1;
                lb.addEventListener('pointerdown', (e) => {
                    if (!pts.size) moved = false;
                    pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
                    lb.setPointerCapture(e.pointerId);
                    begin();
                });
                lb.addEventListener('pointermove', (e) => {
                    if (!pts.has(e.pointerId)) return;
                    pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
                    const now = [...pts.values()];
                    const [a0, b0] = start.pts;
                    if (now.length >= 2 && b0) {            // pinch: zoom, and follow the fingers
                        scale = Math.min(5, Math.max(1, start.scale * dist(now[0], now[1]) / dist(a0, b0)));
                        const m0 = mid(a0, b0), m1 = mid(now[0], now[1]);
                        x = start.x + m1.x - m0.x;
                        y = start.y + m1.y - m0.y;
                        moved = true;
                    } else if (now.length === 1) {
                        const dx = now[0].x - a0.x, dy = now[0].y - a0.y;
                        if (Math.abs(dx) + Math.abs(dy) > 6) moved = true;
                        if (scale > 1) { x = start.x + dx; y = start.y + dy; }   // drag a zoomed picture
                    }
                    show();
                });
                const lift = (e) => { pts.delete(e.pointerId); begin(); if (scale < 1.05) reset(); };
                lb.addEventListener('pointerup', lift);
                lb.addEventListener('pointercancel', lift);
                lb.addEventListener('wheel', (e) => {
                    e.preventDefault();
                    scale = Math.min(5, Math.max(1, scale * (e.deltaY < 0 ? 1.15 : 1 / 1.15)));
                    if (scale < 1.05) reset(); else show();
                }, { passive: false });
                lb.addEventListener('click', () => {
                    if (moved) { moved = false; return; }   // the end of a pinch or drag, not a tap
                    if (scale > 1) return reset();
                    window.history.back();   // closes it (see popstate)
                });
                window.history.pushState({ tf: 'picture' }, '');
                document.body.append(lb);
            }
            // A picture made smaller on this phone (a JPEG this many pixels across).
            function smallCopy(file, px) {
                return new Promise((res) => {
                    const img = new Image();
                    img.onload = () => {
                        const k = Math.min(1, px / Math.max(img.width, img.height));
                        const cvs = el('canvas', { width: String(Math.round(img.width * k)), height: String(Math.round(img.height * k)) });
                        cvs.getContext('2d').drawImage(img, 0, 0, cvs.width, cvs.height);
                        URL.revokeObjectURL(img.src);
                        cvs.toBlob((b) => res(b), 'image/jpeg', 0.82);
                    };
                    img.onerror = () => res(null);
                    img.src = URL.createObjectURL(file);
                });
            }
            function avatar(user, big = false) {
                const a = el('span', { class: `avatar${big ? ' big' : ''}`, 'aria-hidden': 'true' });
                if (user && user.avatar) {
                    // its small copy (lists show it tiny); tapping it shows the full picture
                    a.append(el('img', { src: user.avatar.startsWith('api.php?a=file') ? `${user.avatar}&s=1` : user.avatar, alt: '', loading: 'lazy', decoding: 'async' }));
                    a.classList.add('has-pic');
                    a.removeAttribute('aria-hidden');
                    a.setAttribute('role', 'button');
                    a.setAttribute('title', `See ${user.display_name || user.username}'s picture`);
                    a.addEventListener('click', (e) => { e.stopPropagation(); showPicture(user.avatar, user.display_name || user.username); });
                }
                else a.textContent = ((user && (user.display_name || user.username)) || '?').trim().charAt(0).toUpperCase();
                return a;
            }
            // When something was posted, as each person chose (Edit profile -> Text -> Times):
            //   relative  "just now", "12 min ago", "2 h ago", "3 d ago"
            //   mixed     (default) relative for the first hour, then "14:05", "Yesterday 14:05",
            //             "Mon 14:05" (this week), "17 Oct 14:05", "17 Oct 2025 14:05"
            //   full      always the date and time
            // In the phone's own language and 12/24-hour clock.
            const clock = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' });
            const dayName = new Intl.DateTimeFormat(undefined, { weekday: 'short' });
            const dayMonth = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short' });
            const dayMonthYear = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
            const fullDate = new Intl.DateTimeFormat(undefined, { dateStyle: 'full', timeStyle: 'short' });
            function relative(s) {
                if (s < 60) return 'just now';
                if (s < 3600) return `${Math.floor(s / 60)} min ago`;
                if (s < 86400) return `${Math.floor(s / 3600)} h ago`;
                return `${Math.floor(s / 86400)} d ago`;
            }
            function dateTime(d, always) {
                const now = new Date();
                const days = Math.round((new Date(now.toDateString()) - new Date(d.toDateString())) / 86400000);
                if (!always && days === 0) return clock.format(d);
                if (!always && days === 1) return `Yesterday ${clock.format(d)}`;
                if (!always && days > 1 && days < 7) return `${dayName.format(d)} ${clock.format(d)}`;
                return `${(d.getFullYear() === now.getFullYear() ? dayMonth : dayMonthYear).format(d)} ${clock.format(d)}`;
            }
            function ago(t) {
                const s = Math.max(0, Math.floor(Date.now() / 1000) - t);
                const mode = textSettings().time;
                if (mode === 'relative') return relative(s);
                if (mode === 'full') return dateTime(new Date(t * 1000), true);
                return s < 3600 ? relative(s) : dateTime(new Date(t * 1000), false);
            }
            const fullTime = (t) => fullDate.format(new Date(t * 1000));   // for tooltips
            function sizeText(b) {
                return b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`;
            }

            // Buttons show drawn icons (SVG), never emoji: emoji are for what people write and react with.
            const icon = (svg) => { const s = el('span', { class: 'btn-icon' }); s.innerHTML = svg; return s; };   // (our own constant SVG)
            const bellBtn = () => el('button', { class: 'sbtn bell', type: 'button', title: 'Notifications', 'aria-label': `Notifications${unread ? `: ${unread} new` : ''}`,
                onclick: () => go({ name: 'notifications' }) }, icon(SVG.bell), unread ? el('span', { class: 'bell-count', text: String(unread) }) : null);
            // The account bar (you, Edit profile, Sign out, 🔒) is on home only: inside a channel the
            // room goes to the posts. 🔔 is in the logo row, on every screen.
            function renderAccount() {
                accountEl.replaceChildren();
                accountEl.hidden = (!!me && (view.name !== 'board' || isWide())) || (!me && view.name === 'auth');   // wide: it's in the feed column; sign-in page: the form says it all
                const bells = () => (me ? [bellBtn()] : []);
                headBell.replaceChildren(...bells());
                feedPaneEls.bell.replaceChildren(...bells());
                // You, Edit profile and Sign out (🔒 is above ＋, bottom right), on your banner if you have one.
                const banner = me && me.backgrounds && me.backgrounds.banner;
                const mine = () => [el('div', { class: `account-panel${banner ? ' has-banner' : ''}`, style: banner ? `--banner: url("${banner}")` : '' }, avatar(me),
                    el('span', { class: 'grow' }, el('b', { text: me.display_name }), el('span', { class: 'meta', text: ` @${me.username}` })),
                    el('button', { class: 'sbtn link', type: 'button', text: 'Edit profile', onclick: () => go({ name: 'profile' }) }),
                    el('button', { class: 'sbtn link', type: 'button', text: 'Sign out', onclick: signOut }))];
                accountEl.classList.toggle('split', !!me);
                feedPaneEls.account.classList.toggle('split', !!me);
                feedPaneEls.account.replaceChildren(...(me ? mine() : []));
                if (me) {
                    accountEl.append(...mine());
                } else {
                    accountEl.append(
                        el('span', { class: 'grow', text: 'Sign in, or create a profile to get your own channel.' }),
                        el('button', { class: 'sbtn primary', type: 'button', text: 'Create profile', onclick: () => go({ name: 'auth', mode: 'register' }) }),
                        el('button', { class: 'sbtn', type: 'button', text: 'Sign in', onclick: () => go({ name: 'auth', mode: 'login' }) })
                    );
                }
            }

            // Every screen's header shows the Toffee logo and name in caramel; the screen's own
            // name goes in the browser tab. (Sign in passes '': its big logo is in the middle.)
            function setTitle(t) {
                titleEl.replaceChildren(...(t ? [el('img', { class: 'brand-logo', src: 'toffee-logo-64.webp?v=2', alt: '' }), el('span', { class: 'brand', text: 'toffee' })] : []));
                document.title = !t || t === 'Toffee' ? 'toffee' : `${t} · toffee`;
                // No title and no back button: drop the empty header row.
                overlay.querySelector('.social-head').hidden = !t && backBtn.hidden && homeBtn.hidden;
            }
            // Picture behind the list: covers the window and stays put while the list scrolls.
            // The app is exactly as tall as what's visible above the keyboard.
            if (window.visualViewport) {
                const fit = () => {
                    document.documentElement.style.setProperty('--tf-vh', `${Math.round(window.visualViewport.height)}px`);
                    window.scrollTo(0, 0);   // never let the browser push the page up out of view
                };
                window.visualViewport.addEventListener('resize', fit);
                fit();
            }
            document.addEventListener('focusin', (e) => {
                if (e.target.matches('.social-foot textarea, .social-foot input')) setTimeout(() => e.target.scrollIntoView({ block: 'nearest' }), 250);
            });

            // ---- text: font and size, kept on this device ----
            // The phone's own font by default, as in WhatsApp, Signal or iMessage: Roboto on Android,
            // San Francisco on iPhones and Macs, Segoe UI on Windows. Nothing to download.
            const FONTS = {
                system: 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
                verdana: 'Verdana, Geneva, "DejaVu Sans", sans-serif',
                roboto: 'Roboto, "Helvetica Neue", Arial, sans-serif',
                georgia: 'Georgia, Gelasio, "Times New Roman", serif',
            };
            const SIZES = { small: 0.9, normal: 1, large: 1.15 };
            const TIMES = { relative: 'Relative', mixed: 'Mixed', full: 'Date & time' };
            function textSettings() {
                let t = {};
                try { t = JSON.parse(localStorage.getItem('tf_text') || '{}'); } catch (e) { /* defaults */ }
                // (Every phone has "verdana" saved from when it was the default: only a font someone picked counts.)
                const picked = !!t.picked && !!FONTS[t.font];
                return { font: picked ? t.font : 'system', picked, size: SIZES[t.size] ? t.size : 'normal', time: TIMES[t.time] ? t.time : 'mixed' };
            }
            function applyText(change) {
                const t = { ...textSettings(), ...(change || {}) };
                localStorage.setItem('tf_text', JSON.stringify(t));
                document.documentElement.style.setProperty('--tf-font', FONTS[t.font]);
                document.documentElement.style.setProperty('--tf-zoom', String(SIZES[t.size]));
                return t;
            }
            applyText();
            // Themes: the colours of the whole app, kept on this device (like the text settings).
            const THEMES = { toffee: ['Toffee', '#c0702a', '#fff'], night: ['Night', '#e2964c', '#1c1f24'], ocean: ['Ocean', '#1f78b4', '#fff'],
                forest: ['Forest', '#3b8a4e', '#fff'], berry: ['Berry', '#ad3a6c', '#fff'], slate: ['Slate', '#4b5d75', '#fff'],
                'ocean-night': ['Ocean Night', '#4fa8e6', '#1f2f3d'], 'forest-night': ['Forest Night', '#5cb874', '#1f2d22'], 'berry-night': ['Berry Night', '#e0679b', '#30202b'] };
            function applyTheme(t) {
                if (!THEMES[t]) t = 'toffee';
                localStorage.setItem('tf_theme', t);
                if (t === 'toffee') delete document.documentElement.dataset.theme; else document.documentElement.dataset.theme = t;
                const bar = getComputedStyle(document.documentElement).getPropertyValue('--card').trim() || '#fff';
                document.querySelector('meta[name="theme-color"]').setAttribute('content', bar);   // the phone's status bar
                return t;
            }
            applyTheme(localStorage.getItem('tf_theme') || 'toffee');
            function themePicker() {
                const btns = Object.entries(THEMES).map(([k, [name, accent, card]]) => el('button', { type: 'button', class: 'theme-swatch', 'data-theme-name': k,
                    title: name, onclick: () => show(applyTheme(k)) },
                    el('span', { class: 'swatch', style: `background:linear-gradient(135deg, ${card} 0 50%, ${accent} 50% 100%)` }), el('span', { text: name })));
                const show = (t) => btns.forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.themeName === t)));
                show(localStorage.getItem('tf_theme') || 'toffee');
                return el('div', { class: 'text-picker' }, el('b', { text: '🎨 Theme' }), el('div', { class: 'theme-grid' }, btns));
            }
            function textPicker() {
                const fontBtns = Object.keys(FONTS).map((k) => el('button', { type: 'button', style: `font-family:${FONTS[k]}`,
                    text: { system: 'Phone', verdana: 'Verdana', roboto: 'Roboto', georgia: 'Georgia' }[k], onclick: () => show(applyText({ font: k, picked: true })) }));
                const sizeBtns = Object.keys(SIZES).map((k) => el('button', { type: 'button',
                    text: { small: 'Small', normal: 'Normal', large: 'Large' }[k], onclick: () => show(applyText({ size: k })) }));
                const timeBtns = Object.keys(TIMES).map((k) => el('button', { type: 'button', text: TIMES[k], onclick: () => show(applyText({ time: k })) }));
                const example = el('span', { class: 'meta' });
                const show = (t) => {
                    Object.keys(FONTS).forEach((k, i) => fontBtns[i].setAttribute('aria-pressed', String(t.font === k)));
                    Object.keys(SIZES).forEach((k, i) => sizeBtns[i].setAttribute('aria-pressed', String(t.size === k)));
                    Object.keys(TIMES).forEach((k, i) => timeBtns[i].setAttribute('aria-pressed', String(t.time === k)));
                    const now = Math.floor(Date.now() / 1000);
                    example.textContent = `For example: ${[now - 600, now - 3 * 3600, now - 26 * 3600, now - 9 * 86400].map(ago).join(' · ')}`;
                };
                show(textSettings());
                return el('div', { class: 'text-picker' }, el('b', { text: 'Text' }),
                    el('span', { class: 'meta', text: 'Font' }), el('div', { class: 'seg' }, fontBtns),
                    el('span', { class: 'meta', text: 'Size of comments' }), el('div', { class: 'seg' }, sizeBtns),
                    el('span', { class: 'meta', text: 'Times' }), el('div', { class: 'seg' }, timeBtns), example);
            }

            function setBanner(url) {
                channelEl.style.backgroundImage = url ? `url("${url}")` : '';
                channelEl.classList.toggle('has-bg', !!url);
            }
            // 🔍 in the tab row: a search box that only takes space while you use it. It searches
            // what's listed here — people and their last message in your inbox, text and names in a
            // channel or conversation.
            let searching = '';
            const searchInput = el('input', { type: 'search', class: 'channel-search', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false',
                oninput: () => { searching = searchInput.value; applySearch(); } });
            const searchNone = el('div', { class: 'empty', text: 'Nothing here matches that.', hidden: true });
            searchRow.append(searchInput, el('button', { class: 'sbtn', type: 'button', text: '✕', 'aria-label': 'Close search', onclick: () => toggleSearch(false) }));
            function toggleSearch(on) {
                searchRow.hidden = !on;
                searchBtn.setAttribute('aria-pressed', String(on));
                if (on) { searchInput.focus(); } else if (searching) { searching = searchInput.value = ''; applySearch(); }
            }
            searchBtn.addEventListener('click', () => toggleSearch(searchRow.hidden));
            function applySearch() {
                const q = searching.trim().toLowerCase();
                const items = [...body.querySelectorAll('.thread-item, .conv-card')];
                let shown = 0;
                items.forEach((n) => {
                    const hit = !q || n.textContent.toLowerCase().includes(q);
                    n.hidden = !hit;
                    if (hit) shown++;
                });
                searchNone.hidden = !q || shown > 0;
                if (searchNone.hidden) searchNone.remove(); else body.append(searchNone);
            }
            function searchPlaceholder() {
                searchInput.placeholder = view.name !== 'thread' ? 'Search'
                    : me && me.username === view.user && view.tab === 'private' && !view.notes ? 'Search people and messages'
                    : view.tab === 'private' ? 'Search this conversation' : 'Search these posts';
            }

            function setTab(name) {
                tabs.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.tab === name)));
                showCallBtns();
            }

            function go(next, push = true) {
                if (push) { history.push(view); window.history.pushState({ tf: 'screen' }, ''); }
                view = next;
                render();
            }
            function back() {
                view = history.pop() || { name: 'board' };
                render();
            }

            // Two columns or one: the feed column shows on wide screens once you're signed in, with
            // the channel open beside it marked.
            async function quietly(fn) { quiet++; try { return await fn(); } finally { quiet--; } }
            // What you've seen, kept on this phone (the browser's cache storage, one copy per screen,
            // per person): opening home, a chat or your lists shows it at once, then the server's
            // newer copy replaces it a moment later. Wiped when you sign out.
            const KEPT = 'toffee-kept-v1';
            const keptKey = (k) => `/kept/${encodeURIComponent(me ? me.username : '-')}/${encodeURIComponent(k)}`;
            async function keptGet(k) {
                if (!me || !window.caches) return null;
                try { const res = await (await caches.open(KEPT)).match(keptKey(k)); return res ? await res.json() : null; } catch (e) { return null; }
            }
            function keptPut(k, data) {
                if (!me || !window.caches || !data || !data.ok) return;
                caches.open(KEPT).then((c) => c.put(keptKey(k), new Response(JSON.stringify(data), { headers: { 'Content-Type': 'application/json' } }))).catch(() => {});
            }
            function layout() {
                const wide = isWide();
                const was = !feedPane.hidden;
                feedPane.hidden = !wide;
                overlay.classList.toggle('is-wide', wide);
                // One comment box for both columns: on wide screens it sits under both, used by
                // whichever post you selected (in the feed or in the channel).
                if (wide && foot.parentNode !== overlay) overlay.append(foot);
                if (!wide && foot.parentNode !== dialog) dialog.append(foot);
                if (wide && !was) {
                    quietly(() => api('latest', { query: '&feed=1' })).then((l) => { if (l.ok) feedSig = l.feed_sig; });   // (asked before it loads)
                    renderBoard(false, true).then(markOpen);
                }
                markOpen();
            }
            function markOpen() {
                feedPaneEls.body.querySelectorAll('.board-row[data-user]').forEach((row) =>
                    row.classList.toggle('open-now', view.name === 'thread' && row.dataset.user.toLowerCase() === String(view.user).toLowerCase()));
            }
            let feedSoon = null;
            let feedSig = null;   // the feed's fingerprint when the feed column was last loaded
            function refreshFeed() {   // after something changed in a channel: the feed shows it too
                if (!isWide()) return;
                clearTimeout(feedSoon);
                feedSoon = setTimeout(async () => {
                    // Asked before the feed is loaded, so a change in between only means one extra reload.
                    const l = await quietly(() => api('latest', { query: '&feed=1' }));
                    if (l.ok && l.feed_sig === feedSig) return;
                    if (playingIn(feedPaneEls.body)) return;   // a video playing in the feed: redraw later, when it's done
                    feedSig = l.ok ? l.feed_sig : null;
                    renderBoard(true, true).then(markOpen);
                }, 400);
            }
            wideQuery.addEventListener('change', () => {
                // Narrowed to one column with nothing to go Back to: start from home.
                if (!wideQuery.matches && !history.length) view = { name: 'board' };
                render();
            });

            // Bottom right of home (on a wide screen, of the feed column), one above the other:
            // 🔒 straight to your private messages (only a shortcut: new ones are counted on 🔔), 👥 to
            // your groups, and ＋ straight to your channel's Public tab, with the box ready to write in.
            const openInbox = (groups = false) => go({ name: 'thread', user: me.username, display: me.display_name, tab: 'private', groups });
            const fabs = [dialog, feedPane].map((col) => {
                const b = el('button', { type: 'button', class: 'sbtn new-post-fab', title: 'New post', 'aria-label': 'New post', hidden: true, onclick: newPost });
                b.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>';   // our own constant SVG
                const lock = el('button', { type: 'button', class: 'sbtn new-post-fab inbox-fab', title: 'Private messages', 'aria-label': 'Private messages', hidden: true, onclick: () => openInbox() }, icon(SVG.lock));
                const groups = el('button', { type: 'button', class: 'sbtn new-post-fab groups-fab', title: 'Your groups', 'aria-label': 'Your groups', hidden: true, onclick: () => openInbox(true) }, icon(SVG.people));
                col.append(lock, groups, b);
                return [b, lock, groups];
            });
            function showFabs() {
                const inMyPrivate = view.name === 'thread' && view.tab === 'private' && me && String(view.user).toLowerCase() === me.username.toLowerCase();
                fabs[0].forEach((b) => { b.hidden = !me || isWide() || view.name !== 'board'; });
                fabs[1].forEach((b) => { b.hidden = !me || !isWide(); });
                fabs[1][1].hidden ||= inMyPrivate && !view.groups;   // (your Private tab open beside the feed: you're there already)
                fabs[1][2].hidden ||= inMyPrivate && !!view.groups;
            }
            function newPost() {
                const mine = { name: 'thread', user: me.username, display: me.display_name, tab: 'public' };
                if (isWide()) { history = []; view = mine; render(); } else go(mine);
                // The box: ready, once the channel has drawn it.
                const started = Date.now();
                const ready = () => {
                    const box = foot.querySelector('textarea');   // (wide: the box is under both columns)
                    if (box) { box.focus(); box.scrollIntoView({ block: 'nearest' }); }
                    else if (Date.now() - started < 3000) setTimeout(ready, 60);
                };
                setTimeout(ready, 60);
            }
            let dropRecording = null;   // a voice note being recorded (see the composer)
            async function render() {
                if (dropRecording) dropRecording();   // left the screen mid-recording: the microphone goes off
                showFabs();
                // Signed in: never show a sign-in form.
                if (view.name === 'auth' && me) {
                    while (history.length && history[history.length - 1].name === 'auth') history.pop();
                    view = history.pop() || { name: 'board' };
                }
                // Not signed in: only the sign-in / create-profile screen.
                if (!me && view.name !== 'auth') { history = []; view = { name: 'auth', mode: 'login' }; }
                // Wide: home is the feed column, so this column shows a channel: yours to start.
                if (isWide() && view.name === 'board') { history = []; view = { name: 'thread', user: me.username, display: me.display_name, tab: 'public' }; }
                layout();
                backBtn.hidden = history.length === 0 || view.name === 'board' || view.name === 'key';
                homeBtn.hidden = !me || isWide() || view.name === 'board' || view.name === 'key';   // wide: the feed is always beside it
                stopPolling();
                tabRow.hidden = view.name !== 'thread';   // Public | Private belong to a channel
                if (tabRow.hidden) toggleSearch(false);
                renderAccount();
                if (view.name !== 'thread') { foot.replaceChildren(); homeSel = null; channelEl.replaceChildren(); setBanner(null); }
                if (view.name === 'board') return renderBoard();
                if (view.name === 'thread') return renderThread();
                if (view.name === 'auth') return renderAuth();
                if (view.name === 'profile') return renderProfile();
                if (view.name === 'notifications') return renderNotifications();
                if (view.name === 'key') return renderKey();
            }

            // Home: react to and reply to each person's latest post right here (select it with the
            // bar on its left); reading the replies means opening the channel ("3 replies").
            let homeSel = null;   // id of the latest post selected on home
            let homeTop = null;   // where the feed was scrolled to before a post was selected
            let homeNote = '';    // a line shown once after replying from home
            // inFeed: draw it in the feed column (wide screens) instead of as the home screen.
            let feedRun = 0;
            // ---- calls: voice and video, phone to phone -----------------------------------
            // The call goes straight between the two browsers (WebRTC); toffee only passes on the
            // offer and the answer, each with all its network addresses (CALLS in api.php). One call
            // at a time; 📞 and 🎥 are on a private conversation.
            let call = null;              // the call on screen
            const callsDone = new Set();  // calls ended here (?a=latest may still say one is ringing)
            const CALL_SAYS = { declined: 'Declined', missed: 'No answer', busy: 'Busy', ended: 'Call ended' };
            // All our addresses go in one go: wait until STUN has found our address on the internet
            // (or a relay's), or everything is found, at most 2.5 s.
            function gathered(pc) {
                if (pc.iceGatheringState === 'complete') return Promise.resolve();
                return new Promise((done) => {
                    let t = setTimeout(done, 2500);
                    pc.addEventListener('icegatheringstatechange', () => { if (pc.iceGatheringState === 'complete') { clearTimeout(t); done(); } });
                    pc.addEventListener('icecandidate', (e) => {
                        if (e.candidate && / typ (srflx|relay) /.test(e.candidate.candidate)) { clearTimeout(t); t = setTimeout(done, 250); }   // a moment for the rest
                    });
                });
            }
            let callMediaError = null;
            async function callMedia(video) {
                callMediaError = null;
                try {
                    return await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
                        video: video ? { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } } : false });
                } catch (e) {
                    callMediaError = e;
                    if (!video) return null;
                    try { return await navigator.mediaDevices.getUserMedia({ audio: true }); } catch (e2) { callMediaError = e2; return null; }   // no camera: talk anyway
                }
            }
            // Sound: a page may only play it after you've tapped it, so the first tap anywhere in
            // toffee opens one shared sound channel; calls ring through it later.
            let callSound = null;
            const unlockSound = () => {
                try {
                    callSound = callSound || new (window.AudioContext || window.webkitAudioContext)();
                    if (callSound.state === 'suspended') callSound.resume();
                } catch (e) { /* no sound here */ }
            };
            ['pointerdown', 'keydown', 'touchend'].forEach((ev) => document.addEventListener(ev, unlockSound, { passive: true }));
            // tones: [frequencies, seconds on] repeated every `every` s. Ringing in: two soft rings and a
            // buzz; calling out: the ring-back tone you'd hear on a phone.
            function tones(freqs, on, every, buzz) {
                const play = () => {
                    if (buzz && navigator.vibrate) navigator.vibrate(buzz);
                    if (!callSound || callSound.state !== 'running') return;
                    const t = callSound.currentTime;
                    (Array.isArray(on) ? on : [[0, on]]).forEach(([from, len]) => freqs.forEach((f) => {
                        const o = callSound.createOscillator(), g = callSound.createGain();
                        o.frequency.value = f;
                        g.gain.setValueAtTime(0.0001, t + from);
                        g.gain.exponentialRampToValueAtTime(0.07, t + from + 0.03);
                        g.gain.setValueAtTime(0.07, t + from + len - 0.05);
                        g.gain.exponentialRampToValueAtTime(0.0001, t + from + len);
                        o.connect(g); g.connect(callSound.destination);
                        o.start(t + from); o.stop(t + from + len + 0.02);
                    }));
                };
                play();
                const timer = setInterval(play, every * 1000);
                return () => { clearInterval(timer); if (navigator.vibrate) navigator.vibrate(0); };
            }
            const ringer = () => tones([523, 659], [[0, 0.4], [0.55, 0.4]], 2.5, [400, 200, 400]);   // someone's calling you
            const ringback = () => tones([440, 480], 1.6, 4);                                           // you're calling: it's ringing there
            // Voice calls: the earpiece, like a phone call, where the browser lets a page choose
            // (Safari's audio session; Chrome's sound outputs). 🔊 switches to the loudspeaker.
            async function earpiece(on) {
                const c = call;
                if (!c) return false;
                try { if (navigator.audioSession) navigator.audioSession.type = 'play-and-record'; } catch (e) { /* not this browser */ }
                const v = c.ui.remote;
                if (!v.setSinkId || !navigator.mediaDevices.enumerateDevices) return !!navigator.audioSession;
                const outs = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'audiooutput');
                const ear = outs.find((d) => /earpiece|receiver|handset|phone/i.test(d.label));
                const loud = outs.find((d) => /speaker/i.test(d.label)) || outs.find((d) => d.deviceId === 'default');
                const pick = on ? ear : loud;
                if (!pick) return false;
                try { await v.setSinkId(pick.deviceId); return true; } catch (e) { return false; }
            }
            // The call screen, in toffee's own look: its logo row, the call in a card, and the
            // controls in one bar like the comment box's.
            const CALL_ICON = {
                mic: '<path d="M12 3a3 3 0 0 1 3 3v6a3 3 0 0 1-6 0V6a3 3 0 0 1 3-3z"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21M8.5 21h7"/>',
                micOff: '<path d="M15 10V6a3 3 0 0 0-5.8-1.1M9 9v3a3 3 0 0 0 4.6 2.5M18.5 11a6.5 6.5 0 0 1-1.2 3.8M5.5 11a6.5 6.5 0 0 0 9.8 5.6M12 17.5V21M8.5 21h7M3.5 3.5l17 17"/>',
                cam: '<rect x="2.5" y="6.5" width="13" height="11" rx="2"/><path d="M15.5 10.5l6-3.5v10l-6-3.5z"/>',
                camOff: '<path d="M15.5 13.5v2a2 2 0 0 1-2 2h-9a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h1M9 6.5h4.5a2 2 0 0 1 2 2v2l6-3.5v10M3 3l18 18"/>',
                flip: '<path d="M4.5 11a7.5 7.5 0 0 1 13.2-4.6M19.5 3.5V8H15M19.5 13a7.5 7.5 0 0 1-13.2 4.6M4.5 20.5V16H9"/>',
                speaker: '<path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="M15.5 9a4 4 0 0 1 0 6M18 6.5a7.5 7.5 0 0 1 0 11"/>',
                ear: '<path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="M15.5 10.5a2 2 0 0 1 0 3"/>',
                phone: '<path d="M6.6 3.5h3l1.6 4.2-2 1.4a11.5 11.5 0 0 0 5.7 5.7l1.4-2 4.2 1.6v3a2 2 0 0 1-2.1 2A16.6 16.6 0 0 1 4.6 5.6a2 2 0 0 1 2-2.1z"/>',
                add: '<circle cx="9.5" cy="8" r="3.5"/><path d="M3 20a6.5 6.5 0 0 1 13 0M19 8v6M16 11h6"/>',
            };
            const callIcon = (name, cls = '') => {
                const b = el('span', { class: `call-icon ${cls}`, 'aria-hidden': 'true' });
                b.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${CALL_ICON[name]}</svg>`;   // our own constant SVG
                return b;
            };
            const callBtn = (icon, label, cls, onclick, iconCls) => el('button', { type: 'button', class: `call-btn ${cls}`, title: label, 'aria-label': label, onclick },
                callIcon(icon, iconCls), el('span', { class: 'call-btn-label', text: label }));
            function callScreen(c) {
                const remote = el('video', { class: 'call-remote', autoplay: true, playsinline: true });
                const local = el('video', { class: 'call-local', autoplay: true, playsinline: true, muted: true, hidden: true });   // (shown once your camera is on)
                local.muted = true;   // (your own voice never plays back to you)
                const status = el('div', { class: 'call-status' });
                const btns = el('div', { class: 'call-btns seg-bar' });
                const node = el('div', { class: `call-view${c.video ? ' video' : ''}`, role: 'dialog', 'aria-label': `Call with ${c.with.display_name}` },
                    el('div', { class: 'social-head call-head' }, el('strong', {}, el('img', { class: 'brand-logo', src: 'toffee-logo-64.webp?v=2', alt: '' }), el('span', { class: 'brand', text: 'toffee' })),
                        el('span', { class: 'call-kind', text: c.video ? 'Video call' : 'Voice call' })),
                    el('div', { class: 'call-stage' }, remote, local,
                        el('div', { class: 'call-who' }, avatar(c.with, true), el('b', { text: c.with.display_name }), el('span', { class: 'meta', text: `@${c.with.username}` }), status)),
                    btns);
                document.body.append(node);
                return { node, remote, local, status, btns };
            }
            function callStatus(t) { if (call) call.ui.status.textContent = t; }
            function makePc(ice) {
                const pc = new RTCPeerConnection({ iceServers: ice });
                pc.ontrack = (e) => {
                    const v = call && call.ui.remote;
                    if (!v) return;
                    v.srcObject = e.streams[0] || new MediaStream([e.track]);
                    v.play().catch(() => {});
                };
                pc.onconnectionstatechange = () => {
                    if (!call || call.pc !== pc) return;
                    const s = pc.connectionState;
                    if (s === 'connected') { clearTimeout(call.lost); talking(); }
                    else if (s === 'disconnected') { callStatus('Reconnecting…'); clearTimeout(call.lost); call.lost = setTimeout(() => { if (call && call.pc === pc && pc.connectionState !== 'connected') endCall("The call dropped (the network went away)."); }, 10000); }
                    else if (s === 'failed') endCall(call.connected ? 'The call dropped (the network went away).'
                        : "Couldn't connect: one of your networks won't allow a direct call. Try Wi-Fi.");
                };
                return pc;
            }
            // The data channel: hanging up reaches the other side at once (the server is the backup).
            function wire(dc) {
                call.dc = dc;
                dc.onmessage = (e) => {
                    if (e.data === 'bye') endCall(CALL_SAYS.ended, { told: true });
                    else if (/^room:\d+$/.test(e.data) && call) moveToRoom(Number(e.data.slice(5)), call);   // someone is being added: on to a group call
                };
            }
            // Answered, but no connection after 20 s: one of the networks won't allow a direct call.
            function connectOrGiveUp(c) {
                clearTimeout(c.giveUp);
                c.giveUp = setTimeout(() => { if (call === c && !c.connected) endCall("Couldn't connect: one of your networks won't allow a direct call. Try Wi-Fi."); }, 20000);
            }
            function showLocal() {
                if (!call || !call.local) return;
                call.ui.local.srcObject = call.local;
                call.ui.local.hidden = !call.local.getVideoTracks().length;
            }
            // Answered and connected: the time, and mute / camera / switch camera / hang up.
            function talking() {
                if (!call || call.connected) return;
                call.connected = Date.now();
                call.ui.node.classList.add('live');
                if (!call.video) { const c = call; earpiece(true).then((ok) => { if (call === c) { c.canEar = ok; c.loud = false; callButtons(); } }); }
                const tick = () => { const s = Math.floor((Date.now() - call.connected) / 1000); callStatus(`${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`); };
                tick();
                call.clock = setInterval(() => call && tick(), 1000);
                // Still talking: tell the server now and then (a closed tab ends the call by itself).
                call.alive = setInterval(() => call && api('call_alive', { method: 'POST', data: { id: call.id } }).then((r) => { if (r.ok && r.state !== 'accepted') endCall(CALL_SAYS.ended, { told: true }); }), 15000);
                callButtons();
            }
            function callButtons() {
                const c = call;
                const mic = c.local && c.local.getAudioTracks()[0];
                const cam = c.local && c.local.getVideoTracks()[0];
                const parts = [];
                if (mic) parts.push(callBtn(mic.enabled ? 'mic' : 'micOff', mic.enabled ? 'Mute' : 'Unmute', `mute${mic.enabled ? '' : ' off'}`, () => { mic.enabled = !mic.enabled; callButtons(); }));
                if (cam) parts.push(callBtn(cam.enabled ? 'cam' : 'camOff', cam.enabled ? 'Camera off' : 'Camera on', `cam${cam.enabled ? '' : ' off'}`, () => { cam.enabled = !cam.enabled; c.ui.local.classList.toggle('off', !cam.enabled); callButtons(); }));
                if (cam) parts.push(callBtn('flip', 'Switch', 'flip', flipCamera));
                // Voice: earpiece or loudspeaker (only where the browser lets a page choose).
                if (!cam && c.canEar) parts.push(callBtn(c.loud ? 'speaker' : 'ear', c.loud ? 'Speaker' : 'Earpiece', `speaker${c.loud ? ' on' : ''}`, async () => { c.loud = !c.loud; await earpiece(!c.loud); callButtons(); }));
                if (c.connected) parts.push(callBtn('add', 'Add', 'add', () => pickPerson([c.with.username], (u) => addToCall(u))));
                parts.push(callBtn('phone', 'Hang up', 'hangup', () => endCall(CALL_SAYS.ended), 'down'));
                c.ui.btns.replaceChildren(...parts);
            }
            async function flipCamera() {
                const c = call, old = c.local.getVideoTracks()[0];
                c.facing = c.facing === 'environment' ? 'user' : 'environment';
                try {
                    const s = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { exact: c.facing } } });
                    const track = s.getVideoTracks()[0];
                    const sender = c.pc.getSenders().find((x) => x.track && x.track.kind === 'video');
                    if (sender) await sender.replaceTrack(track);
                    old.stop();
                    c.local.removeTrack(old); c.local.addTrack(track);
                    showLocal();
                } catch (e) { c.facing = c.facing === 'environment' ? 'user' : 'environment'; }   // only one camera
            }
            // Watch the call on the server: answered, declined, missed, or hung up (backup for the data channel).
            function watchCall(every) {
                clearInterval(call.watch);
                call.watch = setInterval(async () => {
                    const c = call;
                    if (!c || !c.id) return;
                    const r = await api('call_poll', { query: `&id=${c.id}` });
                    if (call !== c || !r.ok) return;
                    if (c.caller && r.state === 'accepted' && r.answer && !c.answered) {
                        c.answered = true;
                        if (c.stopRing) { c.stopRing(); c.stopRing = null; }
                        callStatus('Connecting…');
                        connectOrGiveUp(c);
                        await c.pc.setRemoteDescription({ type: 'answer', sdp: r.answer });
                        watchCall(4000);
                    } else if (r.state === 'moved' && r.room) {   // (backup for the data channel's word)
                        moveToRoom(r.room, c);
                    } else if (['declined', 'missed', 'ended'].includes(r.state)) {
                        endCall(CALL_SAYS[r.state], { told: r.state !== 'missed' || !c.caller });
                    }
                }, every);
            }
            async function startCall(user, display, video) {
                if (call || room) return;
                if (!window.RTCPeerConnection || !navigator.mediaDevices) return toast("This browser can't make calls.");
                call = { video, caller: true, with: { username: user, display_name: display } };
                call.ui = callScreen(call);
                const c = call;
                c.ui.btns.replaceChildren(callBtn('phone', 'Cancel', 'hangup', () => endCall('Cancelled'), 'down'));
                callStatus('Starting…');
                unlockSound();   // (you tapped 📞: sound may play now)
                const [cfg, local] = await Promise.all([api('call_config'), callMedia(video)]);
                if (call !== c) { if (local) local.getTracks().forEach((t) => t.stop()); return; }
                if (!local) return endCall(micProblem(callMediaError, video ? 'the microphone and camera' : 'the microphone'));
                if (!cfg.ok) return endCall(cfg.error);
                c.local = local;
                showLocal();
                c.pc = makePc(cfg.ice);
                wire(c.pc.createDataChannel('toffee'));
                local.getTracks().forEach((t) => c.pc.addTrack(t, local));
                await c.pc.setLocalDescription(await c.pc.createOffer());
                await gathered(c.pc);
                if (call !== c) return;
                const r = await api('call_start', { method: 'POST', data: { user, video, offer: c.pc.localDescription.sdp } });
                if (call !== c) return;
                if (!r.ok) return endCall(r.busy ? r.error : r.error || "Couldn't start the call.", { told: true });
                c.id = r.id;
                callStatus('Ringing…');
                c.stopRing = ringback();
                // Their phone rings again every 8 s while it rings here (they may not be in toffee).
                let rings = 0;
                c.rerings = setInterval(() => { if (call === c && !c.answered && ++rings <= 4) api('call_ring', { method: 'POST', data: { id: c.id } }); else clearInterval(c.rerings); }, 8000);
                watchCall(1000);
            }
            // Someone is calling (from ?a=latest, which a phone notification also wakes).
            function incomingCall(ring) {
                if (!ring || call || room || callsDone.has(ring.id) || !window.RTCPeerConnection) return;
                call = { id: ring.id, video: ring.video, caller: false, with: ring.from };
                call.ui = callScreen(call);
                const c = call;
                callStatus(ring.video ? 'Video call…' : 'Voice call…');
                c.stopRing = ringer();
                c.ui.btns.replaceChildren(callBtn('phone', 'Decline', 'hangup', () => endCall('Declined'), 'down'),
                    callBtn(ring.video ? 'cam' : 'phone', 'Answer', 'answer', acceptCall));
                watchCall(1500);
            }
            async function acceptCall() {
                const c = call;
                clearCallNotice(c.id);
                if (c.stopRing) { c.stopRing(); c.stopRing = null; }
                c.ui.btns.replaceChildren(callBtn('phone', 'Hang up', 'hangup', () => endCall(CALL_SAYS.ended), 'down'));
                callStatus('Connecting…');
                clearInterval(c.watch);
                const [cfg, r, local] = await Promise.all([api('call_config'), api('call_poll', { query: `&id=${c.id}` }), callMedia(c.video)]);
                if (call !== c) { if (local) local.getTracks().forEach((t) => t.stop()); return; }
                if (!r.ok || r.state !== 'ringing') return endCall(CALL_SAYS[r.state] || 'Call ended', { told: true });
                if (!local) return endCall(micProblem(callMediaError, c.video ? 'the microphone and camera' : 'the microphone'));
                c.local = local;
                showLocal();
                c.pc = makePc(cfg.ice);
                c.pc.ondatachannel = (e) => wire(e.channel);
                local.getTracks().forEach((t) => c.pc.addTrack(t, local));
                await c.pc.setRemoteDescription({ type: 'offer', sdp: r.offer });
                await c.pc.setLocalDescription(await c.pc.createAnswer());
                await gathered(c.pc);
                if (call !== c) return;
                const a = await api('call_answer', { method: 'POST', data: { id: c.id, answer: c.pc.localDescription.sdp } });
                if (call !== c) return;
                if (!a.ok) return endCall(a.error, { told: true });
                connectOrGiveUp(c);
                watchCall(4000);
            }
            // A call's ringing notification on this phone: gone once you've answered or declined here.
            function clearCallNotice(id) {
                if (!id || !navigator.serviceWorker) return;
                Promise.race([navigator.serviceWorker.ready, new Promise((r) => setTimeout(() => r(null), 3000))])
                    .then((reg) => reg && reg.getNotifications({ tag: String(id).startsWith('room-') ? `toffee-${id}` : `toffee-call-${id}` })).then((list) => (list || []).forEach((n) => n.close())).catch(() => {});
            }
            // Hang up, cancel, decline, or the other side ended it. told: the server knows already.
            function endCall(say, { told = false } = {}) {
                const c = call;
                if (!c) return;
                call = null;
                if (!c.caller) clearCallNotice(c.id);
                if (c.id) callsDone.add(c.id);
                if (c.stopRing) c.stopRing();
                clearInterval(c.watch); clearInterval(c.clock); clearInterval(c.alive); clearTimeout(c.lost); clearInterval(c.rerings); clearTimeout(c.giveUp);
                if (c.dc && c.dc.readyState === 'open') { try { c.dc.send('bye'); } catch (e) { /* closing anyway */ } }
                if (c.local) c.local.getTracks().forEach((t) => t.stop());
                if (c.pc) c.pc.close();
                const after = () => {   // the note in the conversation ("📞 Voice call · 2:31")
                    if (view.name === 'thread' && view.tab === 'private' && String(view.user).toLowerCase() === c.with.username.toLowerCase()) renderThread(true);
                };
                if (c.id && !told) api('call_end', { method: 'POST', data: { id: c.id } }).then(after);
                else setTimeout(after, 800);
                c.ui.node.classList.add('over');
                c.ui.status.textContent = say || CALL_SAYS.ended;
                c.ui.btns.replaceChildren();
                setTimeout(() => c.ui.node.remove(), 1800);
            }
            // A reaction: if it didn't go through (no connection...), say so instead of nothing.
            async function reactOrSay(opts) {
                const r = await quietly(() => api('react', opts));   // (no "loading…": it already shows)
                if (!r.ok) toast(r.error || "Your reaction didn't go through. Try again.");
                return r;
            }
            // A reaction shows at once (from what's on this phone), then goes to the server; if it
            // doesn't go through, it's undone. The next check brings everyone else's.
            // Your reaction on a post as it's shown here: the counts and which one is yours.
            function bump(post, emoji) {
                const counts = { ...(post.reactions || {}) };
                if (post.my_reaction) { counts[post.my_reaction] -= 1; if (counts[post.my_reaction] <= 0) delete counts[post.my_reaction]; }
                if (emoji) counts[emoji] = (counts[emoji] || 0) + 1;
                post.reactions = counts;
                post.my_reaction = emoji || null;
            }
            function reactNow(postId, emoji) {
                const post = threadCache && threadCache.r.posts.find((x) => x.id === postId);
                const before = post && { reactions: { ...post.reactions }, mine: post.my_reaction };
                if (post) bump(post, emoji);
                return reactOrSay({ method: 'POST', data: { id: postId, emoji } }).then((r) => {
                    if (!r.ok && post) { post.reactions = before.reactions; post.my_reaction = before.mine; if (view.name === 'thread') redrawThread(); }
                    return r;
                });
            }
            function toast(t) {
                const n = el('div', { class: 'call-toast', text: t, role: 'status' });
                document.body.append(n);
                setTimeout(() => n.remove(), 4000);
            }

            // ---- group calls: every phone to every other one ----------------------------------
            // A group's members call together (ROOMS in api.php). Whoever joins sends an offer to each
            // person already in; they answer. Same look as a call between two: the card, the bar of
            // buttons, the rings. Each person is a tile (their picture, or their video).
            let room = null;
            const roomsDone = new Set();
            function roomScreen(r) {
                const local = el('video', { class: 'call-local', autoplay: true, playsinline: true, muted: true, hidden: true });
                local.muted = true;
                const status = el('div', { class: 'call-status' });
                const grid = el('div', { class: 'room-grid' });
                const btns = el('div', { class: 'call-btns seg-bar' });
                const node = el('div', { class: `call-view room${r.video ? ' video' : ''}`, role: 'dialog', 'aria-label': 'Group call' },
                    el('div', { class: 'social-head call-head' }, el('strong', {}, el('img', { class: 'brand-logo', src: 'toffee-logo-64.webp?v=2', alt: '' }), el('span', { class: 'brand', text: 'toffee' })),
                        el('span', { class: 'call-kind', text: r.video ? 'Group video call' : 'Group voice call' })),
                    el('div', { class: 'call-stage room-stage' }, grid, local, el('div', { class: 'room-status' }, status)),
                    btns);
                document.body.append(node);
                return { node, grid, local, status, btns };
            }
            // A person's tile: their picture and name; their video on top once it comes (video calls).
            function roomTile(person) {
                const v = el('video', { class: 'room-video', autoplay: true, playsinline: true });
                const tile = el('div', { class: 'room-tile', 'data-user': String(person.id) }, v,
                    el('div', { class: 'room-who' }, avatar(person, true), el('b', { text: person.display_name })));
                return { tile, v };
            }
            function roomStatus(t) { if (room) room.ui.status.textContent = t; }
            function roomShowPeople(people) {
                const r = room;
                if (!r) return;
                const others = people.filter((p) => p.id !== r.me);
                r.people = others;
                // Everyone in the group: in the call (tile lit), still ringing, or not here.
                others.forEach((p) => {
                    let t = r.tiles.get(p.id);
                    if (!t) { t = roomTile(p); r.tiles.set(p.id, t); r.ui.grid.append(t.tile); }
                    t.tile.classList.toggle('in', p.state === 'in');
                    t.tile.classList.toggle('ringing', p.state === 'ringing');
                    t.tile.hidden = p.state === 'out' && !r.peers.has(p.id);
                });
                const n = others.filter((p) => p.state === 'in').length;
                r.ui.grid.dataset.n = String(Math.max(1, others.filter((p) => !r.tiles.get(p.id).tile.hidden).length));
                if (r.joined && !r.clock) roomStatus(n ? '' : 'Ringing…');
                return n;
            }
            function roomPc(uid, ice) {
                const r = room;
                const pc = new RTCPeerConnection({ iceServers: ice });
                r.local.getTracks().forEach((t) => pc.addTrack(t, r.local));
                pc.ontrack = (e) => {
                    const t = r.tiles.get(uid);
                    if (!t) return;
                    t.v.srcObject = e.streams[0] || new MediaStream([e.track]);
                    t.v.play().catch(() => {});
                    if (e.track.kind === 'video') t.tile.classList.add('has-video');
                };
                pc.onconnectionstatechange = () => {
                    if (room !== r) return;
                    const st = pc.connectionState;
                    const t = r.tiles.get(uid);
                    if (t) t.tile.classList.toggle('live', st === 'connected');
                    if (st === 'connected') roomTalking();
                    if (st === 'failed') roomDrop(uid);
                };
                const old = r.peers.get(uid);
                if (old) old.close();
                r.peers.set(uid, pc);
                return pc;
            }
            function roomDrop(uid) {
                const r = room;
                const pc = r && r.peers.get(uid);
                if (pc) { pc.close(); r.peers.delete(uid); }
                const t = r && r.tiles.get(uid);
                if (t) { t.v.srcObject = null; t.tile.classList.remove('live', 'has-video'); }
            }
            async function roomOffer(uid) {
                const r = room;
                const pc = roomPc(uid, r.ice);
                await pc.setLocalDescription(await pc.createOffer());
                await gathered(pc);
                if (room !== r) return;
                await api('room_offer', { method: 'POST', data: { id: r.id, to: uid, offer: pc.localDescription.sdp } });
            }
            async function roomAnswer(uid, offer) {
                const r = room;
                const pc = roomPc(uid, r.ice);
                await pc.setRemoteDescription({ type: 'offer', sdp: offer });
                await pc.setLocalDescription(await pc.createAnswer());
                await gathered(pc);
                if (room !== r) return;
                await api('room_answer', { method: 'POST', data: { id: r.id, from: uid, answer: pc.localDescription.sdp } });
            }
            // Every 1.5 s: who's in, offers to answer, answers to our offers, people who left.
            function roomWatch() {
                const r = room;
                clearInterval(r.watch);
                r.watch = setInterval(async () => {
                    if (room !== r || r.busy) return;
                    r.busy = true;
                    try {
                        const st = await api('room_state', { query: `&id=${r.id}` });
                        if (room !== r || !st.ok) return;
                        if (st.over) return endRoom(r.joined ? 'Call ended' : 'The call has ended');
                        r.me = st.me;
                        if (!st.group && !r.adhoc) { r.adhoc = true; if (r.joined) roomButtons(); }
                        const n = roomShowPeople(st.people);
                        if (!r.joined) return;
                        for (const o of st.offers) if (!r.answering.has(o.from)) { r.answering.add(o.from); roomAnswer(o.from, o.offer).finally(() => r.answering.delete(o.from)); }
                        // Ours to call (checked every time, so a missed or dropped line is tried again).
                        for (const uid of st.offer_to || []) if (!r.peers.has(uid) && !r.answering.has(uid)) roomOffer(uid);
                        for (const a of st.answers) {
                            const pc = r.peers.get(a.to);
                            if (pc && pc.signalingState === 'have-local-offer') pc.setRemoteDescription({ type: 'answer', sdp: a.answer }).catch(() => {});
                        }
                        st.people.forEach((p) => { if (p.id !== r.me && p.state !== 'in' && r.peers.has(p.id)) roomDrop(p.id); });
                        if (n) r.someone = true;
                        if (!n && r.someone) return endRoom('Everyone has left');
                        if (!n && !r.someone && Date.now() - r.started > 45000) return endRoom(CALL_SAYS.missed);
                    } finally { r.busy = false; }
                }, 1500);
            }
            function roomTalking() {
                const r = room;
                if (!r || r.clock) return;
                if (r.stopRing) { r.stopRing(); r.stopRing = null; }
                r.ui.node.classList.add('live');
                const t0 = Date.now();
                const tick = () => { const sec = Math.floor((Date.now() - t0) / 1000); roomStatus(`${Math.floor(sec / 60)}:${String(sec % 60).padStart(2, '0')}`); };
                tick();
                r.clock = setInterval(() => room === r && tick(), 1000);
            }
            function roomButtons() {
                const r = room;
                const mic = r.local && r.local.getAudioTracks()[0];
                const cam = r.local && r.local.getVideoTracks()[0];
                const parts = [];
                if (mic) parts.push(callBtn(mic.enabled ? 'mic' : 'micOff', mic.enabled ? 'Mute' : 'Unmute', `mute${mic.enabled ? '' : ' off'}`, () => { mic.enabled = !mic.enabled; roomButtons(); }));
                if (cam) parts.push(callBtn(cam.enabled ? 'cam' : 'camOff', cam.enabled ? 'Camera off' : 'Camera on', `cam${cam.enabled ? '' : ' off'}`, () => { cam.enabled = !cam.enabled; r.ui.local.classList.toggle('off', !cam.enabled); roomButtons(); }));
                if (cam) parts.push(callBtn('flip', 'Switch', 'flip', roomFlip));
                // A call that grew from one between two: add more people (a group's call rings its members).
                if (r.adhoc) parts.push(callBtn('add', 'Add', 'add', () => pickPerson((r.people || []).filter((p) => p.state !== 'out').map((p) => p.username), (u) => addToCall(u))));
                parts.push(callBtn('phone', 'Hang up', 'hangup', () => endRoom(CALL_SAYS.ended), 'down'));
                r.ui.btns.replaceChildren(...parts);
            }
            async function roomFlip() {
                const r = room, old = r.local.getVideoTracks()[0];
                r.facing = r.facing === 'environment' ? 'user' : 'environment';
                try {
                    const st = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { exact: r.facing } } });
                    const track = st.getVideoTracks()[0];
                    for (const pc of r.peers.values()) { const sender = pc.getSenders().find((x) => x.track && x.track.kind === 'video'); if (sender) await sender.replaceTrack(track); }
                    old.stop(); r.local.removeTrack(old); r.local.addTrack(track);
                    r.ui.local.srcObject = r.local;
                } catch (e) { r.facing = r.facing === 'environment' ? 'user' : 'environment'; }   // only one camera
            }
            // In: microphone (and camera), then an offer to each person already talking.
            async function roomEnter() {
                const r = room;
                if (r.stopRing) { r.stopRing(); r.stopRing = null; }
                if (r.ringCard) { r.ringCard.remove(); r.ringCard = null; r.ui.grid.hidden = false; }
                clearCallNotice(`room-${r.id}`);
                r.ui.btns.replaceChildren(callBtn('phone', 'Hang up', 'hangup', () => endRoom(CALL_SAYS.ended), 'down'));
                roomStatus('Connecting…');
                const [cfg, local] = await Promise.all([api('call_config'), r.local ? Promise.resolve(r.local) : callMedia(r.video)]);   // (moved over from a call: same microphone and camera)
                if (room !== r) { if (local) local.getTracks().forEach((t) => t.stop()); return; }
                if (!local) return endRoom(micProblem(callMediaError, r.video ? 'the microphone and camera' : 'the microphone'));
                if (!cfg.ok) return endRoom(cfg.error);
                r.ice = cfg.ice; r.local = local;
                r.ui.local.srcObject = local; r.ui.local.hidden = !local.getVideoTracks().length;
                const j = await api('room_join', { method: 'POST', data: { id: r.id } });
                if (room !== r) return;
                if (!j.ok) return endRoom(j.error, { told: true });
                r.joined = true;
                roomButtons();
                if (j.talking.length) j.talking.forEach((uid) => roomOffer(uid));
                else if (r.moved) roomStatus('Adding…');   // (the other side is on its way over: no ring)
                else { roomStatus('Ringing…'); r.stopRing = ringback(); }
                r.alive = setInterval(() => room === r && api('room_alive', { method: 'POST', data: { id: r.id } }), 15000);
                roomWatch();
            }
            // A call between two goes on as a room (someone is being added): the same microphone and
            // camera, no ringing; the two phones connect again (about a second of quiet).
            function moveToRoom(id, c) {
                if (!c || call !== c || room) return;
                call = null;
                callsDone.add(c.id);
                if (c.stopRing) c.stopRing();
                clearInterval(c.watch); clearInterval(c.clock); clearInterval(c.alive); clearTimeout(c.lost); clearInterval(c.rerings); clearTimeout(c.giveUp);
                if (c.pc) c.pc.close();
                c.ui.node.remove();
                room = { id, video: c.video, peers: new Map(), tiles: new Map(), answering: new Set(), started: Date.now(), joined: false, adhoc: true, moved: true, local: c.local };
                room.ui = roomScreen(room);
                roomEnter();
            }
            // Add someone: a call between two becomes a room first (the other side is told and follows).
            async function addToCall(u) {
                if (call && call.connected && !room) {
                    const c = call;
                    const r = await api('room_from_call', { method: 'POST', data: { call: c.id } });
                    if (!r.ok) return toast(r.error || "Couldn't add them.");
                    if (c.dc && c.dc.readyState === 'open') { try { c.dc.send(`room:${r.id}`); } catch (e) { /* their app follows by itself */ } }
                    await new Promise((res) => setTimeout(res, 150));   // (the word reaches them before the line closes)
                    moveToRoom(r.id, c);
                    for (let i = 0; i < 40 && room && !room.joined; i++) await new Promise((res) => setTimeout(res, 100));   // in first, then ring them
                }
                if (!room) return;
                const a = await api('room_add', { method: 'POST', data: { id: room.id, user: u.username } });
                toast(a.ok ? `Calling ${u.display_name}…` : a.error || "Couldn't add them.");
            }
            // Pick someone to add (search by name, like @): everyone but those in the call already.
            async function pickPerson(skip, onPick) {
                if (document.querySelector('.reacted-sheet')) return;
                if (!boardCache) { const r = await quietly(() => api('channels')); if (r.ok) boardCache = r; }
                const all = ((boardCache && boardCache.channels) || []).filter((p) => p.username !== me.username && !skip.includes(p.username));
                const close = () => sheet.remove();
                const list = el('div', { class: 'reacted-list' });
                const search = el('input', { type: 'search', placeholder: 'Search people', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false', class: 'pick-search' });
                const show = () => {
                    const q = search.value.trim().toLowerCase().replace(/^@/, '');
                    const starts = (u) => u.username.toLowerCase().startsWith(q) || (u.display_name || '').toLowerCase().startsWith(q);
                    const found = all.filter((u) => !q || u.username.toLowerCase().includes(q) || (u.display_name || '').toLowerCase().includes(q)).sort((a, b) => starts(b) - starts(a)).slice(0, 50);
                    list.replaceChildren(...found.map((u) => el('button', { type: 'button', class: 'reacted-row pick-row', onclick: () => { close(); onPick(u); } }, avatar(u),
                        el('span', { class: 'grow' }, el('b', { text: u.display_name }), el('span', { class: 'meta', text: ` @${u.username}` })))));
                    if (!found.length) list.replaceChildren(el('div', { class: 'meta', text: 'Nobody by that name.' }));
                };
                search.addEventListener('input', show);
                const sheet = el('div', { class: 'install-sheet reacted-sheet pick-sheet', role: 'dialog', 'aria-label': 'Add to the call', onclick: (e) => { if (e.target === sheet) close(); } },
                    el('div', { class: 'install-card reacted-card' },
                        el('div', { class: 'reacted-head' }, el('b', { text: 'Add to the call' }),
                            el('button', { type: 'button', class: 'compose-close', text: '✕', 'aria-label': 'Close', onclick: close })),
                        search, list));
                document.body.append(sheet);
                show();
                search.focus();
            }
            // Call your group (or join its call if one is on).
            async function startRoom(groupId, video) {
                if (call || room) return toast("You're in a call.");
                if (!window.RTCPeerConnection || !navigator.mediaDevices) return toast("This browser can't make calls.");
                unlockSound();
                const res = await api('room_start', { method: 'POST', data: { group: groupId, video } });
                if (!res.ok) return toast(res.error || "Couldn't start the call.");
                room = { id: res.id, video: res.video, peers: new Map(), tiles: new Map(), answering: new Set(), started: Date.now(), joined: false };
                room.ui = roomScreen(room);
                roomEnter();
            }
            // A group you're in is calling (from ?a=latest, which its phone notification also wakes).
            function incomingRoom(ring) {
                if (!ring || call || room || roomsDone.has(ring.room) || !window.RTCPeerConnection) return;
                room = { id: ring.room, video: ring.video, peers: new Map(), tiles: new Map(), answering: new Set(), started: Date.now(), joined: false };
                room.ui = roomScreen(room);
                const r = room;
                // Ringing: who's calling, big in the middle (as in a call between two).
                r.adhoc = !ring.group;
                r.ringCard = el('div', { class: 'call-who room-ring' }, avatar(ring.from, true), el('b', { text: ring.from.display_name }),
                    el('span', { class: 'meta', text: ring.group ? (ring.video ? 'is calling the group (video)' : 'is calling the group') : ring.video ? 'is adding you to a video call' : 'is adding you to a call' }));
                r.ui.grid.before(r.ringCard);
                r.ui.grid.hidden = true;
                r.stopRing = ringer();
                r.ui.btns.replaceChildren(callBtn('phone', 'Decline', 'hangup', () => endRoom('Declined', { decline: true }), 'down'),
                    callBtn(ring.video ? 'cam' : 'phone', 'Join', 'answer', roomEnter));
                roomWatch();
            }
            function endRoom(say, { told = false, decline = false } = {}) {
                const r = room;
                if (!r) return;
                room = null;
                roomsDone.add(r.id);
                clearCallNotice(`room-${r.id}`);
                if (r.stopRing) r.stopRing();
                clearInterval(r.watch); clearInterval(r.clock); clearInterval(r.alive);
                for (const pc of r.peers.values()) pc.close();
                if (r.local) r.local.getTracks().forEach((t) => t.stop());
                if (!told) api('room_leave', { method: 'POST', data: { id: r.id, decline: decline || !r.joined } }).then(() => { if (view.name === 'thread' && view.tab === 'private') renderThread(true); });
                r.ui.node.classList.add('over');
                r.ui.status.textContent = say || CALL_SAYS.ended;
                r.ui.btns.replaceChildren();
                setTimeout(() => r.ui.node.remove(), 1800);
            }
            // 📞 / 🎥: on a private conversation with someone else (not your own inbox or notes).
            function showCallBtns() {
                const on = !!me && view.name === 'thread' && view.tab === 'private' && String(view.user).toLowerCase() !== me.username.toLowerCase()
                    && !!window.RTCPeerConnection;
                overlay.querySelectorAll('.tab-call-btn').forEach((b) => { b.hidden = !on; });
            }
            overlay.querySelectorAll('.tab-call-btn').forEach((b) => b.addEventListener('click', () => startCall(view.user, view.display || view.user, b.dataset.video === '1')));

            // ---- today's posts, like a status ----------------------------------------------
            // On home, a channel's public posts from the last 24 hours are on its card: swipe it left
            // and right to go through them; scrolling up and down goes on to the next channel, as
            // before. Dots under the card: one per post, hollow until you've seen it, filled where you
            // are. What you've seen is kept on this device only (nothing is written on the server).
            const seenStatus = (() => {
                let m = {};
                try { m = JSON.parse(localStorage.getItem('tf_seen_status') || '{}') || {}; } catch (e) { m = {}; }
                const old = Date.now() - 2 * 86400000;
                for (const k of Object.keys(m)) if (m[k] < old) delete m[k];   // older than any of today's
                return m;
            })();
            const isSeen = (id) => !!seenStatus[id];
            const stripAt = {};   // which of today's posts each card is showing, kept when home redraws
            function markSeen(id) {
                if (seenStatus[id]) return false;
                seenStatus[id] = Date.now();
                try { localStorage.setItem('tf_seen_status', JSON.stringify(seenStatus)); } catch (e) { /* full: forget it */ }
                return true;
            }
            // Seen once at least a third of it has been on screen.
            const seenWatch = 'IntersectionObserver' in window ? new IntersectionObserver((entries) => {
                for (const e of entries) {
                    if (!e.isIntersecting) continue;
                    const slide = e.target;
                    seenWatch.unobserve(slide);
                    if (markSeen(Number(slide.dataset.post)) && slide.onSeen) slide.onSeen();
                }
            }, { threshold: 0.35 }) : null;
            let boardCache = null;   // home as last loaded: deselecting a post redraws from it at once
            async function renderBoard(keep = false, inFeed = false, local = false) {
                const body = inFeed ? feedPaneEls.body : overlay.querySelector('.social-dialog .social-body');
                if (!inFeed) { setTitle('Toffee'); setTab('public'); history = []; }
                const keepTop = body.scrollTop;
                if (!keep) {
                    if (!inFeed || homeSel) foot.replaceChildren();   // (in the feed column: only our own box)
                    homeSel = null;
                    homeTop = null;
                    body.classList.remove('home-focus');
                    body.replaceChildren(el('div', { class: 'empty', text: 'Loading…' }));
                }
                const shown = view;
                const run = ++feedRun;
                // First time this visit: the copy kept on this phone at once, then the server's.
                const kept = !local && !boardCache ? await keptGet('channels') : null;
                const r = (local && boardCache) || kept || (inFeed ? await quietly(() => api('channels')) : await api('channels'));
                if (inFeed ? run !== feedRun || !isWide() : view !== shown) return;   // moved on meanwhile
                if (r.ok) boardCache = r;
                if (kept) setTimeout(() => { if (inFeed ? isWide() : view === shown) quietly(() => renderBoard(true, inFeed)); }, 0);
                else if (!local) keptPut('channels', r);
                if (!r.ok && r.down && body.childElementCount) return;   // (toffee out of reach for a moment: keep what's shown; the bar says so)
                if (!r.ok) { if (inFeed) feedSig = null; return body.replaceChildren(el('div', { class: 'empty', text: r.error })); }
                if (!r.channels.length) {
                    return body.replaceChildren(el('div', { class: 'empty', text: 'No channels yet. Create a profile to open the first one.' }));
                }
                // toffee's own channels (Help & Feedback) pinned at the top, then newest post first;
                // people who haven't posted yet go last, A-Z.
                r.channels.sort((a, b) => (b.pinned - a.pinned)
                    || ((b.latest ? b.latest.at : 0) - (a.latest ? a.latest.at : 0))
                    || a.display_name.localeCompare(b.display_name, undefined, { sensitivity: 'base' })
                    || a.username.localeCompare(b.username, undefined, { sensitivity: 'base' }));
                // Search by name, @username or bio; "Me" shows just your own channel.
                // Searches everything shown: names, @usernames, bios and the posts themselves.
                const search = el('input', { type: 'search', class: 'channel-search', placeholder: 'Search feed',
                    'aria-label': 'Search feed', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false' });
                search.value = boardFilter.text;
                const meBtn = me ? el('button', { class: 'sbtn', type: 'button', text: 'Me', title: 'Show only your channel',
                    'aria-pressed': String(boardFilter.mine) }) : null;
                const none = el('div', { class: 'empty', text: 'Nobody matches that.', hidden: true });
                const openChannel = (p, withReplies = false, post = p.latest) => {
                    if (post) highlightPost = post.id;
                    // Wide: it opens beside the feed, replacing the channel there (no long Back trail).
                    go({ name: 'thread', user: p.username, display: p.display_name, tab: 'public', openRoot: withReplies && post ? post.id : null }, !inFeed);
                };
                // A post on a card in the shape the comment box expects.
                const asPost = (post) => ({ id: post.id, parent_id: null, private: false, author: post.author_user, body: post.text,
                    reactions: post.reactions, my_reaction: post.my_reaction });
                // local: nothing changed (just deselected), so home redraws at once from what's here.
                const done = (note, local = false) => {
                    homeSel = null; homeNote = note || ''; foot.replaceChildren();
                    if (inFeed && composer && view.name === 'thread') foot.replaceChildren(composer.node);   // the channel's box again
                    renderBoard(true, inFeed, local);
                };
                const select = (p, post) => {
                    if (homeSel === post.id) return done('', true);
                    homeSel = post.id;
                    // Wide: the shared box was the channel's; a post selected there lets go.
                    if (inFeed && selectedId) { selectedId = null; if (composer) composer.clearReply(); redrawThread(); }
                    const c = buildComposer({ user: p.username, tab: 'public', display: p.display_name, home: true,
                        onSent: (res) => done(res.private ? `🔒 Sent privately to ${post.author}: it's on the Private tab of ${p.display_name}'s channel.`
                            : `✓ Replied to ${post.author}.`),
                        onReact: (x, e) => { rememberReaction(e); bump(post, e); reactOrSay({ method: 'POST', data: { id: x.id, emoji: e } }); done('', true); },   // (at once)
                        onDeselect: () => done('', true) });
                    c.setPeople(post.author_user.username !== me.username ? [post.author_user] : []);
                    foot.replaceChildren(c.node);
                    c.replyTo(asPost(post), false);
                    // Only the selected post shows (as in a channel), until you're done with it.
                    if (homeTop === null) homeTop = body.scrollTop;
                    body.classList.add('home-focus');
                    body.scrollTop = 0;
                    rows.forEach((row) => {
                        row.classList.toggle('selected', !!row.querySelector(`.latest[data-post="${homeSel}"]`));
                        row.querySelectorAll('.select-bar').forEach((b) => {
                            const on = b.closest('.latest').dataset.post === String(homeSel);
                            b.setAttribute('aria-pressed', String(on));
                            b.querySelector('.sel-label').textContent = on ? 'selected' : 'select';
                            b.querySelector('.sel-tick').textContent = on ? '✓' : '';
                        });
                    });
                };
                const stop = (fn) => (e) => { e.stopPropagation(); fn(e); };
                const reactions = (post) => reactionsRow(post.id, post.reactions, post.my_reaction,
                    (e) => { if (e) rememberReaction(e); bump(post, e); reactOrSay({ method: 'POST', data: { id: post.id, emoji: e } }); done('', true); });   // (at once)
                // A post on the card, big and complete: text and photo (replies are in the channel).
                const postBlock = (p, post) => el('span', { class: 'latest', 'data-post': String(post.id) },
                    // The same toffee select bar as in a channel: react or reply from here.
                    el('button', { type: 'button', class: 'select-bar', 'aria-pressed': String(homeSel === post.id),
                        title: 'Select to react or reply', 'aria-label': `Select ${post.author}'s post to react or reply`,
                        onclick: stop(() => select(p, post)) },
                        el('span', { class: 'sel-label', text: homeSel === post.id ? 'selected' : 'select' }),
                        el('span', { class: 'sel-tick', text: homeSel === post.id ? '✓' : '' })),
                    el('span', { class: 'latest-main' },
                        // When, and "3 replies" (read them in the channel) on the same line.
                        el('span', { class: 'latest-top' },
                            el('span', { class: 'meta', text: `${ago(post.at)}${post.edited ? ' · edited' : ''}`, title: fullTime(post.at) }),
                            post.replies ? el('button', { type: 'button', class: 'replies-link',
                                text: `${post.replies} ${post.replies === 1 ? 'reply' : 'replies'}`, title: 'Read the replies in the channel',
                                onclick: stop(() => openChannel(p, true, post)) }) : null),
                        post.text ? el('div', { class: 'latest-text' }, renderMarkdown(post.text)) : null,   // same Markdown as in the channel
                        // Photos, videos, voice notes and documents, exactly as in the channel.
                        post.attachments.length ? el('div', { class: 'attachments' }, post.attachments.map(attachmentNode)) : null,
                        reactions(post)));
                // Today's posts (newest first, as the card opens on the latest), or just the latest.
                const slidesOf = (p) => (p.today && p.today.length > 1 && p.latest && p.today[0].id === p.latest.id ? p.today : null);
                // Several of today's posts: a strip you swipe sideways, with see-through ‹ › on it.
                const strip = (p, slides, row) => {
                    const track = el('div', { class: 'today-strip' }, slides.map((post, i) => {
                        const slide = el('div', { class: 'today-slide', 'data-post': String(post.id) }, postBlock(p, post));
                        slide.onSeen = () => { const d = row.querySelectorAll('.strip-dots span')[i]; if (d) d.classList.remove('new'); };
                        return slide;
                    }));
                    const at = () => Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
                    // Dots under the card: one per post from today, yours filled (as on any photo swipe).
                    const dots = el('div', { class: 'strip-dots', 'aria-hidden': 'true' }, slides.map((x) => el('span', { class: isSeen(x.id) ? '' : 'new' })));
                    const showDot = () => { const i = at(); [...dots.children].forEach((d, k) => d.classList.toggle('on', k === i)); };
                    // The card is as tall as the post it's showing (not the tallest of them).
                    const fit = () => {
                        const s = track.children[at()];
                        if (s) track.style.height = `${s.offsetHeight}px`;   // (the whole slide: nothing cut off under the post)
                    };
                    track.addEventListener('scroll', () => {
                        stripAt[p.username] = at();
                        fit();
                        showDot();
                    }, { passive: true });
                    // Pictures and players load later and change a post's height: each slide is watched
                    // (the strip's own height is set here, so watching it alone missed a video growing).
                    if ('ResizeObserver' in window) { const ro = new ResizeObserver(fit); [...track.children].forEach((x) => ro.observe(x)); }
                    track.addEventListener('load', fit, true);
                    requestAnimationFrame(() => {   // back on the post it was showing before home redrew
                        const i = Math.min(stripAt[p.username] || 0, slides.length - 1);
                        if (i) track.scrollLeft = i * track.clientWidth;
                        fit();
                        showDot();
                    });
                    // A mouse (no touch, no trackpad swipe): drag the card sideways.
                    let drag = null;
                    track.addEventListener('pointerdown', (e) => { if (e.pointerType === 'mouse' && e.button === 0) drag = { x: e.clientX, left: track.scrollLeft, moved: false }; });
                    track.addEventListener('pointermove', (e) => {
                        if (!drag) return;
                        const dx = e.clientX - drag.x;
                        if (Math.abs(dx) > 6 && !drag.moved) { drag.moved = true; track.classList.add('dragging'); }
                        if (drag.moved) track.scrollLeft = drag.left - dx;
                    });
                    const drop = () => {
                        if (!drag) return;
                        const moved = drag.moved;
                        drag = null;
                        track.classList.remove('dragging');
                        if (moved) track.scrollTo({ left: at() * track.clientWidth, behavior: 'smooth' });
                    };
                    track.addEventListener('pointerup', drop);
                    track.addEventListener('pointerleave', drop);
                    // A drag isn't a tap: don't open the channel (or select) when it ends.
                    track.addEventListener('click', (e) => { if (track.dataset.dragged) { e.stopPropagation(); e.preventDefault(); delete track.dataset.dragged; } }, true);
                    track.addEventListener('pointerup', () => { if (track.classList.contains('dragging')) track.dataset.dragged = '1'; }, true);
                    if (seenWatch) requestAnimationFrame(() => track.querySelectorAll('.today-slide').forEach((s) => seenWatch.observe(s)));
                    return el('div', { class: 'today-wrap' }, track, dots);
                };
                const rows = r.channels.map((p) => {
                    const slides = slidesOf(p);
                    const row = el('div', {
                        class: `board-row${p.is_me ? ' me' : ''}${p.pinned ? ' pinned' : ''}${p.latest && [p.latest, ...(slides || [])].some((x) => x.id === homeSel) ? ' selected' : ''}`,
                        role: 'button', tabindex: '0', 'data-user': p.username,
                        onclick: () => openChannel(p),
                        onkeydown: (e) => { if (e.target === e.currentTarget && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openChannel(p); } },
                    },
                        avatar(p),
                        el('span', { class: 'who' }, el('b', { text: p.display_name }), el('span', { class: 'meta', text: ` @${p.username}` }),
                            p.today && p.today.length ? el('span', { class: 'meta today-count', text: ` · ${p.today.length} today` }) : null,
                            p.bio ? el('span', { class: 'bio', text: p.bio }) : null,
                            p.latest ? null : el('span', { class: 'meta latest-none', text: 'No posts yet' })));
                    if (slides) row.append(strip(p, slides, row));
                    else if (p.latest) {
                        const one = postBlock(p, p.latest);
                        row.append(one);
                    }
                    return row;
                });
                const applyFilter = () => {
                    boardFilter.text = search.value;
                    const q = search.value.trim().toLowerCase().replace(/^@/, '');
                    let shown = 0;
                    rows.forEach((row, i) => {
                        const p = r.channels[i];
                        const hit = (!boardFilter.mine || p.is_me) && (!q
                            || [p.display_name, p.username, p.bio, p.latest && p.latest.text, ...(p.today || []).map((x) => x.text)].some((t) => (t || '').toLowerCase().includes(q)));
                        row.hidden = !hit;
                        if (hit) shown++;
                    });
                    none.hidden = shown > 0;
                };
                search.addEventListener('input', applyFilter);
                if (meBtn) meBtn.addEventListener('click', () => {
                    boardFilter.mine = !boardFilter.mine;
                    meBtn.setAttribute('aria-pressed', String(boardFilter.mine));
                    applyFilter();
                });
                const keyCard = me && !me.has_recovery ? el('button', { type: 'button', class: 'key-card', onclick: () => go({ name: 'profile' }) },
                    el('b', { text: '🔑 Protect your profile: get your recovery key' }),
                    el('span', { text: "Without it, a forgotten password can't be reset. It takes a moment." })) : null;
                const note = homeNote ? el('div', { class: 'home-note', role: 'status', text: homeNote }) : '';
                homeNote = '';
                body.replaceChildren(keyCard || '', notifyCard() || '', mediaCard() || '', note, el('div', { class: 'channel-filter' }, search, meBtn), ...rows, none);
                applyFilter();
                body.classList.toggle('home-focus', !!homeSel);
                // Done with a selected post: back to where you were in the feed.
                if (!homeSel && homeTop !== null) { body.scrollTop = homeTop; homeTop = null; }
                else if (keep) body.scrollTop = keepTop;
            }

            async function renderNotifications() {
                setTitle('Notifications');
                setTab('');
                if (!me) return go({ name: 'auth', mode: 'login' }, false);
                const shown = view;
                const r = await api('notifications');
                if (view !== shown) return;   // you've moved on meanwhile
                if (!r.ok) return body.replaceChildren(el('div', { class: 'empty', text: r.error }));
                const why = { private: 'sent you a private message', reply: 'replied to your comment', mention: 'mentioned you', board: 'commented on your channel', announcement: 'posted news 📣' };
                const markAll = el('button', { class: 'sbtn link', type: 'button', text: 'Mark all as read', onclick: async () => {
                    await api('notifications_read', { method: 'POST', data: {} }); refreshUnread(); renderNotifications(); } });
                // Notifications are a history you can wipe: the posts and messages themselves stay.
                const clearAll = el('button', { class: 'sbtn link', type: 'button', text: 'Clear all', onclick: async () => {
                    if (!confirm('Clear all your notifications? The posts and messages themselves stay.')) return;
                    await api('notifications_clear', { method: 'POST', data: {} }); refreshUnread(); renderNotifications(); } });
                if (!r.items.length) return body.replaceChildren(pushSwitch(), el('div', { class: 'empty', text: 'No notifications yet.' }));
                body.replaceChildren(pushSwitch(), el('div', { class: 'notif-tools' }, r.unread ? markAll : null, clearAll), ...r.items.map((n) => el('button', {
                    type: 'button', class: `board-row${n.unread ? ' unread' : ''}`,
                    onclick: async () => {
                        if (n.unread) await api('notifications_read', { method: 'POST', data: { id: n.id } });
                        refreshUnread();
                        highlightPost = n.post_id;
                        // A private message: the conversation with whoever sent it (their channel's Private tab).
                        const withWho = n.with || n.from;   // (a group's message: your conversation with whoever started it)
                        go(n.private ? { name: 'thread', user: withWho.username, display: withWho.display_name, tab: 'private' }
                            : { name: 'thread', user: n.board, display: n.board_display, tab: 'public' });
                    },
                },
                    avatar(n.from),
                    el('span', { class: 'who' },
                        el('b', { text: n.from.display_name }), ` ${n.group ? 'wrote in a group' : why[n.kind] || 'posted'}`,
                        // (a group's message: the group mark, so it's clear before you open it)
                        n.group ? el('span', { class: 'group-tag' }, icon(SVG.people)) : n.kind === 'private' ? el('span', { class: 'lock-tag', text: '🔒' }) : null, el('br'),
                        // (a private message is in your conversation with them, not "on" a channel)
                        el('span', { class: 'meta', text: n.private ? n.snippet : `${n.snippet} · on ${n.board_display}'s channel` })),
                    el('span', { class: 'meta', text: ago(n.at), title: fullTime(n.at) }))));
            }

            // ---- thread with replies and the composer ----
            let composer = null;
            const openReplies = new Set();
            const lastRootOf = {};
            function rootOfPost(p) { return lastRootOf[p.id] || p.id; }   // ids of top comments whose replies are shown
            let selectedId = null;            // the comment the bottom bar acts on (react / reply / private)
            let anchor = null;                // keep this comment still on screen through the next render
            let keepInView = null;            // after deselecting: scroll back to that comment (no flash)
            // After you post or react: show everything again and jump to it.
            function showMine(postId, parentPost) {
                selectedId = null;
                if (parentPost) openReplies.add(rootOfPost(parentPost));
                highlightPost = postId;
            }
            function quickReactions() {
                let mine = [];
                try { mine = JSON.parse(localStorage.getItem('bc_recent_reactions') || '[]'); } catch (e) { /* ignore */ }
                return [...new Set([...mine, ...REACTIONS])].slice(0, 5);
            }
            function rememberReaction(e) {
                if (!e) return;
                const list = [e, ...quickReactions().filter((x) => x !== e)].slice(0, 5);
                localStorage.setItem('bc_recent_reactions', JSON.stringify(list));
            }
            function reactAndShow(p, emoji) {
                rememberReaction(emoji);
                reactNow(p.id, emoji);
                if (composer) composer.clearReply();
                showMine(p.id, p.parent_id ? p : null);
                redrawThread();   // (at once: no wait for the server)
            }
            function selectPost(p) {
                if (homeSel) { homeSel = null; renderBoard(true, true).then(markOpen); }   // the feed's box gives way
                if (selectedId === p.id) return deselectPost();
                selectedId = p.id;
                composer.replyTo(p, p.private);
                highlightPost = p.id;   // keep the picked comment in view
                redrawThread();
            }
            function deselectPost() {
                keepInView = selectedId;   // everything comes back: stay at the comment you had picked
                selectedId = null;
                if (composer) composer.clearReply();
                redrawThread();
            }
            // Your own Private tab: your conversations, like the feed. One card per person (and one
            // for your notes), with the latest message from either of you; select it to react or
            // reply right here, tap the card for the whole conversation.
            let convSel = null;   // id of the latest message selected here
            async function renderConversations(keep = false) {
                setTitle(`${view.display}'s channel`);
                setTab('private');
                const shown = view;
                const keepTop = body.scrollTop;
                if (!keep) { convSel = null; groupSel = null; }
                // All your conversations, or only your one-off groups (to find one from a month ago).
                const groupsOnly = !!view.groups;
                const listAs = groupsOnly ? 'my_groups' : 'conversations';
                const kept = !keep ? await keptGet(listAs) : null;
                const r = kept || await api(listAs);
                if (view !== shown) return;
                if (kept) setTimeout(() => { if (view === shown) quietly(() => renderConversations(true)); }, 0);
                else keptPut(listAs, r);
                if (!r.ok && r.down && body.childElementCount) return;   // (out of reach for a moment: keep what's shown)
                if (!r.ok) return body.replaceChildren(el('div', { class: 'empty', text: r.error }));
                const filter = el('div', { class: 'seg conv-filter' },
                    el('button', { type: 'button', 'aria-pressed': String(!groupsOnly), text: 'All', onclick: () => { if (groupsOnly) { view.groups = false; renderConversations(); } } }),
                    el('button', { type: 'button', 'aria-pressed': String(groupsOnly), onclick: () => { if (!groupsOnly) { view.groups = true; renderConversations(); } } }, icon(SVG.people), ' Groups'));
                // Your picture, name and bio above the tabs, as on any channel.
                channelEl.replaceChildren(el('div', { class: 'post', style: 'border:none;padding-top:0;align-items:center' }, avatar(me, true),
                    el('div', { class: 'content' }, el('b', { text: me.display_name }), el('span', { class: 'meta', text: ` @${me.username}` }),
                        me.bio ? el('div', { class: 'meta', text: me.bio }) : null)));
                setBanner(me.backgrounds.banner);
                if (groupsOnly) return renderMyGroups(r.groups, filter, keep ? keepTop : null, shown);
                const openConv = (c) => go(c.notes ? { name: 'thread', user: me.username, display: me.display_name, tab: 'private', notes: true }
                    : { name: 'thread', user: c.with.username, display: c.with.display_name, tab: 'private' });
                const stop = (fn) => (e) => { e.stopPropagation(); fn(e); };
                const inboxBox = () => buildComposer({ user: view.user, tab: 'private', display: view.display, inbox: true });   // a list: no box until you select
                const done = () => { convSel = null; composer = inboxBox(); foot.replaceChildren(composer.node); quietly(() => renderConversations(true)); };   // (no "loading…")
                const select = (c) => {
                    if (convSel === c.latest.id) return done();
                    convSel = c.latest.id;
                    const other = c.notes ? me : c.with;
                    const box = buildComposer({ user: other.username, tab: 'private', display: other.display_name, home: true,
                        onSent: () => done(), onDeselect: () => done(),
                        onReact: async (post, e) => { rememberReaction(e); await reactOrSay({ method: 'POST', data: { id: post.id, emoji: e } }); done(); } });
                    box.setPeople([]);
                    foot.replaceChildren(box.node);
                    box.replyTo(c.latest, true);
                    renderConversations(true);
                };
                // My notes (to yourself) always first, even before you've written one.
                const convs = [...r.conversations.filter((c) => c.notes), ...r.conversations.filter((c) => !c.notes)];
                const notesCard = convs.some((c) => c.notes) ? null : el('div', { class: 'board-row conv-card notes-empty', role: 'button', tabindex: '0',
                    onclick: () => openConv({ notes: true }), onkeydown: (e) => { if (e.key === 'Enter') openConv({ notes: true }); } },
                    el('span', { class: 'avatar', text: '📝' }),
                    el('span', { class: 'who' }, el('b', { text: 'My notes' })),
                    el('span', { class: 'latest' }, el('span', { class: 'latest-main' },
                        el('span', { class: 'meta', text: 'Only you see these: notes, photos and voice notes to yourself. Tap to write one.' }))));
                const cards = convs.map((c) => {
                    const m = c.latest;
                    const on = convSel === m.id;
                    const reacts = reactionsRow(m.id, m.reactions, m.my_reaction,
                        async (e) => { if (e) rememberReaction(e); await reactOrSay({ method: 'POST', data: { id: m.id, emoji: e } }); done(); });
                    return el('div', { class: `board-row conv-card${c.unread ? ' unread' : ''}${on ? ' selected' : ''}`, role: 'button', tabindex: '0',
                        onclick: () => openConv(c), onkeydown: (e) => { if (e.target === e.currentTarget && e.key === 'Enter') openConv(c); } },
                        c.notes ? el('span', { class: 'avatar', text: '📝' }) : avatar(c.with),
                        el('span', { class: 'who' }, el('b', { text: c.notes ? 'My notes' : c.with.display_name }),
                            c.notes ? null : el('span', { class: 'meta', text: ` @${c.with.username}` }),
                            c.unread ? el('span', { class: 'conv-unread', text: String(c.unread), title: `${c.unread} new` }) : null),
                        el('span', { class: 'latest' },
                            el('button', { type: 'button', class: 'select-bar', 'aria-pressed': String(on), title: 'Select to react or reply',
                                'aria-label': `Select the latest message with ${c.notes ? 'yourself' : c.with.display_name}`, onclick: stop(() => select(c)) },
                                el('span', { class: 'sel-label', text: on ? 'selected' : 'select' }), el('span', { class: 'sel-tick', text: on ? '✓' : '' })),
                            el('span', { class: 'latest-main' },
                                el('span', { class: 'latest-top' }, el('span', { class: 'meta', text: `${m.mine && !c.notes ? 'You · ' : ''}${ago(m.at)}`, title: fullTime(m.at) })),
                                m.body ? el('div', { class: 'latest-text' }, renderMarkdown(m.body)) : null,
                                m.attachments.length ? el('div', { class: 'attachments' }, m.attachments.map(attachmentNode)) : null,
                                reacts)));
                });
                searchPlaceholder();
                body.replaceChildren(filter, ...(notesCard ? [notesCard] : []), ...(cards.some((x, i) => !convs[i].notes) ? cards : [...cards, el('div', { class: 'empty',
                    text: 'No private messages yet. Go to someone\'s channel and tap 🔒 Private to write to them: your conversations gather here, one per person.' })]));
                applySearch();
                if (keep) body.scrollTop = keepTop;
                if (!convSel) { composer = inboxBox(); foot.replaceChildren(composer.node); }
                // New messages show up here too.
                let seen = unread;
                startPolling(async () => {
                    const l = await quietly(() => api('latest'));
                    if (view !== shown || !l.ok) return;
                    setUnread(l.unread, l.call || l.room);
                    if (l.unread !== seen && !convSel) { seen = l.unread; renderConversations(true); }
                });
            }

            // Your groups, newest activity first: who's in each, how it started, when it was last busy.
            // Tap one: its conversation opens at its first message. Search finds them by name or words.
            let groupSel = null;   // the group selected on the Groups list (reply from there)
            let groupSelMsg = null;          // ... and which of its messages the reply answers
            const groupsOpen = new Set();    // groups whose thread is open on the list
            const groupThreads = {};         // their messages, as last loaded (group id -> {last, posts})
            function renderMyGroups(groups, filter, keepTop, shown) {
                const stop = (fn) => (e) => { e.stopPropagation(); fn(e); };
                const openGroup = (g) => { highlightPost = g.id; go({ name: 'thread', user: g.with.username, display: g.with.display_name, tab: 'private' }); };
                const listBox = () => buildComposer({ user: view.user, tab: 'private', display: view.display, inbox: true });
                const done = () => { groupSel = groupSelMsg = null; composer = listBox(); foot.replaceChildren(composer.node); quietly(() => renderConversations(true)); };   // (no "loading…")
                // Select a group (or one of its messages, in its open thread): reply right here.
                const select = (g, msg = null) => {
                    const target = msg || g.latest;
                    if (groupSel === g.id && groupSelMsg === target.id) return done();
                    groupSel = g.id;
                    groupSelMsg = target.id;
                    const box = buildComposer({ user: g.with.username, tab: 'private', display: g.with.display_name, home: true,
                        onSent: () => done(), onDeselect: () => done(),
                        onReact: async (post, e) => { rememberReaction(e); await reactOrSay({ method: 'POST', data: { id: post.id, emoji: e } }); done(); } });
                    box.setPeople(g.members.filter((m) => m.username !== me.username));
                    foot.replaceChildren(box.node);
                    box.replyTo({ id: target.id, parent_id: null, private: true, group: g.id, author: target.author, reactions: target.reactions || {}, my_reaction: target.my_reaction || null }, true);
                    renderConversations(true);
                };
                // A group's thread, open in its card: every message, oldest first; tap one to reply to it.
                const threadBox = (g) => {
                    const box = el('div', { class: 'replies-box group-thread', onclick: (e) => e.stopPropagation() }, el('div', { class: 'meta', text: 'Loading…' }));
                    // Each reply with its own select strip, as on posts (select one to reply to it).
                    const draw = (posts) => box.replaceChildren(...posts.map((m) => {
                        const on = groupSel === g.id && groupSelMsg === m.id;
                        return el('div', { class: `post reply group-msg${on ? ' selected' : ''}` },
                        el('button', { type: 'button', class: 'select-bar', 'aria-pressed': String(on), title: on ? 'Selected: write below to reply. Tap to deselect.' : 'Select to reply to this',
                            'aria-label': `Select ${m.author.display_name}'s message to reply`, onclick: () => select(g, m) },
                            el('span', { class: 'sel-label', text: on ? 'selected' : 'select' }), el('span', { class: 'sel-tick', text: on ? '✓' : '' })),
                        el('div', { class: 'content' },
                            el('div', { class: 'post-head' }, avatar(m.author), el('b', { text: m.mine ? 'You' : m.author.display_name }),
                                el('span', { class: 'meta', text: ` · ${ago(m.at)}${m.edited ? ' · edited' : ''}`, title: fullTime(m.at) })),
                            m.body ? renderMarkdown(m.body) : null,
                            m.attachments.length ? el('div', { class: 'attachments' }, m.attachments.map(attachmentNode)) : null,
                            reactionsRow(m.id, m.reactions, m.my_reaction, async (e) => {
                                if (e) rememberReaction(e);
                                await reactOrSay({ method: 'POST', data: { id: m.id, emoji: e } });
                                delete groupThreads[g.id];
                                renderConversations(true);
                            })));
                    }));
                    const have = groupThreads[g.id];
                    if (have && have.last === (g.latest && g.latest.id)) draw(have.posts);   // (nothing new: at once, no trip to the server)
                    else quietly(() => api('group_thread', { query: `&id=${g.id}` })).then((r) => {
                        if (!r.ok) return box.replaceChildren(el('div', { class: 'meta', text: r.error }));
                        groupThreads[g.id] = { last: g.latest && g.latest.id, posts: r.posts };
                        draw(r.posts);
                        box.scrollTop = box.scrollHeight;   // (newest at the bottom, like a chat)
                    });
                    return box;
                };
                const toggleThread = (g) => { groupsOpen.has(g.id) ? groupsOpen.delete(g.id) : groupsOpen.add(g.id); renderConversations(true); };
                const names = (list) => {
                    const n = list.filter((m) => m.username !== me.username).map((m) => m.display_name);
                    return n.length > 1 ? `${n.slice(0, -1).join(', ')} and ${n[n.length - 1]}` : n[0] || 'Just you';
                };
                const day = (t) => new Date(t * 1000).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: new Date(t * 1000).getFullYear() === new Date().getFullYear() ? undefined : 'numeric' });
                const cards = groups.map((g) => el('div', { class: `board-row conv-card group-card${g.unread ? ' unread' : ''}${groupSel === g.id ? ' selected' : ''}`, role: 'button', tabindex: '0',
                    onclick: () => openGroup(g), onkeydown: (e) => { if (e.target === e.currentTarget && e.key === 'Enter') openGroup(g); } },
                    el('span', { class: 'group-faces' }, g.members.filter((m) => m.username !== me.username).slice(0, 3).map((m) => avatar(m))),
                    el('span', { class: 'who' }, el('b', { text: names(g.members) }),
                        el('span', { class: 'group-tag' }, icon(SVG.people), ` ${g.members.length}`),
                        g.unread ? el('span', { class: 'conv-unread', text: String(g.unread), title: `${g.unread} new` }) : null),
                    el('span', { class: 'latest' },
                        g.latest && !groupsOpen.has(g.id) ? el('button', { type: 'button', class: 'select-bar', 'aria-pressed': String(groupSel === g.id), title: 'Select to reply here',
                            'aria-label': 'Select this group to reply', onclick: stop(() => select(g)) },
                            el('span', { class: 'sel-label', text: groupSel === g.id ? 'selected' : 'select' }), el('span', { class: 'sel-tick', text: groupSel === g.id ? '✓' : '' })) : null,
                        el('span', { class: 'latest-main' },
                            el('span', { class: 'latest-top' }, el('span', { class: 'meta', text: `Started by ${g.started_by} · ${day(g.at)}` }),
                                // "3 replies", as on any post: opens the thread here (tapping the card opens the chat)
                                g.messages > 1 ? el('button', { type: 'button', class: 'replies-link', 'aria-expanded': String(groupsOpen.has(g.id)), title: groupsOpen.has(g.id) ? 'Hide the replies' : 'Show the replies here',
                                    onclick: stop(() => toggleThread(g)), text: `${g.messages - 1} ${g.messages === 2 ? 'reply' : 'replies'}` }) : null),
                            el('div', { class: 'meta group-first', text: g.first }),
                            // the latest (what a reply here answers)
                            g.latest && g.latest.id !== g.id ? el('div', { class: 'latest-text' }, el('b', { text: g.latest.mine ? 'You' : g.latest.author.display_name }), `: ${g.latest.body}`) : null,
                            el('span', { class: 'meta', text: ago(g.last_at), title: fullTime(g.last_at) }),
                            groupsOpen.has(g.id) ? threadBox(g) : null))));
                searchPlaceholder();
                body.replaceChildren(filter, ...(cards.length ? cards : [el('div', { class: 'empty',
                    text: 'No groups yet. Write privately to someone and @name others: you all see it, and every reply.' })]));
                applySearch();
                if (keepTop !== null) body.scrollTop = keepTop;
                if (!groupSel) { composer = listBox(); foot.replaceChildren(composer.node); }
                let seen = unread;
                startPolling(async () => {
                    const l = await quietly(() => api('latest'));
                    if (view !== shown || !l.ok) return;
                    setUnread(l.unread, l.call || l.room);
                    if (l.unread !== seen && !groupSel) { seen = l.unread; renderConversations(true); }
                });
            }

            // The channel or conversation last loaded, so showing or hiding replies, selecting and the
            // like redraw at once from what's here, with no trip to the server (and no "loading…").
            let threadCache = null;
            const redrawThread = () => renderThread(true, true);
            async function renderThread(keepComposer = false, local = false) {
                // Your own Private tab: your conversations, one card per person (like the feed).
                if (view.tab === 'private' && me && me.username === view.user && !view.notes) return renderConversations(keepComposer);
                const first = !keepComposer;   // just opened: start at the newest (top of Public, bottom of Private)
                if (first) { openReplies.clear(); selectedId = null; if (view.openRoot) openReplies.add(view.openRoot); }
                // Through refreshes, the first post you can see stays where it is on screen (new
                // posts arrive above it); if you're at the very top, you stay there and see them.
                const atTop = body.scrollTop < 8;
                const atBottom = body.scrollHeight - body.scrollTop - body.clientHeight < 40;
                const pin = (() => {
                    const top = body.getBoundingClientRect().top;
                    const n = [...body.querySelectorAll('[data-post]')].find((x) => x.getBoundingClientRect().bottom > top);
                    return n ? { id: n.dataset.post, top: n.getBoundingClientRect().top } : null;
                })();
                const tab = view.tab === 'private' ? 'private' : 'public';
                setTitle(`${view.display}'s channel`);
                setTab(tab);
                const shown = view;
                // This tab only, newest top comments first: view.roots of them ("Show older posts"
                // adds more), and back far enough to reach a post we've been asked to show.
                const cached = local && threadCache && threadCache.view === view && threadCache.tab === tab ? threadCache.r : null;
                // Just opened: the copy kept on this phone at once (the first check, straight after, brings anything newer).
                const keptAs = `thread:${String(view.user).toLowerCase()}:${tab}`;
                const kept = !cached && !keepComposer && !highlightPost && !view.notes ? await keptGet(keptAs) : null;
                if (view !== shown) return;
                const r = cached || kept || await api('thread', { query: `&user=${encodeURIComponent(view.user)}&tab=${tab}`
                    + `&roots=${view.roots || 30}${highlightPost ? `&include=${highlightPost}` : ''}` });
                if (!cached && !kept && !view.notes) keptPut(keptAs, r);
                if (view !== shown) return;   // you've moved on meanwhile
                if (!r.ok && r.down && body.childElementCount) return;   // (out of reach for a moment: keep what's shown)
                if (!r.ok) return body.replaceChildren(el('div', { class: 'empty', text: r.error }));
                threadCache = { view, tab, r };
                threadGroups = r.groups || {};
                threadCalls = r.group_calls || {};
                view.roots = Math.max(view.roots || 30, r.roots);   // keep what's loaded through refreshes
                // Opened a conversation with new messages: they're read now (the 🔔 count goes down).
                if (r.unread && !cached) quietly(() => api('conversation_read', { method: 'POST', data: { user: view.user } })).then(refreshUnread);
                if (r.sig !== shown.lastSig) { if (shown.lastSig !== undefined) refreshFeed(); shown.lastSig = r.sig; }
                if (view.display !== r.player.display_name) { view.display = r.player.display_name; setTitle(`${view.display}'s channel`); }
                // Public reads like a feed: newest top comment first, replies folded under each (oldest
                // first). Private is a conversation, like messages: every message (replies too, from
                // both channels) on its own, oldest at the top and newest at the bottom.
                // Both tabs are threads: a top message with its replies under it ("2 replies"; replies
                // of replies too, oldest first). Public lists top posts newest first, like a feed;
                // Private, a conversation, oldest first like messages.
                const chat = !!r.conversation;
                const roots = r.posts.filter((p) => !p.parent_id && p.private === chat);
                if (!chat) roots.reverse();
                const byId = {};
                r.posts.forEach((p) => { byId[p.id] = p; });
                // Replies of replies are shown flat under their top comment.
                const rootOf = lastRootOf;
                Object.keys(rootOf).forEach((k) => delete rootOf[k]);
                r.posts.forEach((p) => { rootOf[p.id] = !p.parent_id ? p.id : (rootOf[p.parent_id] || p.parent_id); });
                const repliesOf = {};
                r.posts.forEach((p) => { if (p.parent_id && byId[rootOf[p.id]] && p.private === chat) (repliesOf[rootOf[p.id]] = repliesOf[rootOf[p.id]] || []).push(p); });
                // A reply's quote line is for answers to other replies (a direct reply's is obvious).
                r.posts.forEach((p) => { if (p.quote && p.parent_id === rootOf[p.id]) p.quote = null; });

                if (selectedId && !byId[selectedId]) selectedId = null;   // deleted meanwhile
                const focus = selectedId ? (rootOf[selectedId] || selectedId) : null;
                const list = el('div', { class: chat ? 'conversation' : '' });
                const own = me && me.username === view.user;
                if (!roots.length) list.append(el('div', { class: 'empty', text: tab === 'public' ? (own ? 'Write your first post 👇' : 'No posts yet. Say hello!')
                    : own ? 'Your notes to yourself: only you see them.'
                    : `No messages yet. Anything you send here is seen only by you and ${view.display}, whichever of your channels it's written in.` }));
                roots.forEach((p) => {
                    if (focus && p.id !== focus) return;
                    const replies = repliesOf[p.id] || [];
                    const open = openReplies.has(p.id) || (focus === p.id && selectedId !== p.id);
                    // "3 replies": plain lowercase text, on its own line at the far right of the comment.
                    let toggle = null;
                    if (replies.length) {
                        toggle = el('button', { type: 'button', class: 'replies-link', 'aria-expanded': String(open),
                            title: open ? 'Hide replies' : 'Show replies',
                            text: `${replies.length} ${replies.length === 1 ? 'reply' : 'replies'}`,
                            onclick: () => {
                                const node = body.querySelector(`[data-post="${p.id}"]`);
                                if (node) anchor = { id: p.id, top: node.getBoundingClientRect().top, fixed: true };
                                open ? openReplies.delete(p.id) : openReplies.add(p.id);
                                redrawThread();
                            } });
                    }
                    // One band per conversation (comment + its replies), alternating like table rows.
                    let shown = replies;
                    const sel = focus === p.id && selectedId !== p.id ? byId[selectedId] : null;
                    if (sel) {
                        // Show only the chain the selected reply belongs to: every reply it answers,
                        // back up to the top comment. Everything else in the thread is hidden.
                        const chain = new Set();
                        for (let c = sel; c && c.id !== p.id; c = byId[c.parent_id]) chain.add(c.id);
                        shown = replies.filter((c) => chain.has(c.id));
                        toggle = null;
                    }
                    // (In a conversation: yours in toffee, theirs in white.)
                    list.append(el('div', { class: `thread-item${p.private ? ' private' : ''}${chat ? (p.mine ? ' msg-mine' : ' msg-theirs') : ''}${p.group ? ' msg-group' : ''}` }, postNode(p, false, byId, toggle),
                        open ? el('div', { class: 'replies-box', 'data-root': String(p.id) }, shown.map((c) => postNode(c, true, byId))) : null));
                });
                // Older ones on request: below the feed, or above the messages.
                if (r.has_more && !focus) {
                    const older = el('button', { type: 'button', class: 'sbtn link older-link', text: chat ? 'Show older messages' : 'Show older posts',
                        onclick: () => { view.roots = (view.roots || 30) + 30; renderThread(true); } });
                    chat ? list.prepend(older) : list.append(older);
                }
                const head = el('div', { class: 'post', style: 'border:none;padding-top:0;align-items:center' },
                    avatar(r.player, true),
                    el('div', { class: 'content' }, el('b', { text: r.player.display_name }),
                        el('span', { class: 'meta', text: ` @${r.player.username}` }),
                        r.player.bio ? el('div', { class: 'meta', text: r.player.bio }) : null));
                if (!keepComposer || !composer || composer.tab !== tab) composer = buildComposer();
                if (selectedId && composer.setParent) composer.setParent(byId[selectedId]);
                // @ suggestions: only the people in this comment's thread (its top comment and replies).
                const people = [];
                if (focus && byId[focus]) {
                    [byId[focus], ...(repliesOf[focus] || [])].forEach((c) => {
                        if (c.author.username !== me.username && !people.some((x) => x.username === c.author.username)) people.push(c.author);
                    });
                }
                composer.setPeople(people);
                const keepTop = body.scrollTop;
                const innerTops = {};   // keep each open thread's own scroll position across refreshes
                body.querySelectorAll('.replies-box[data-root]').forEach((b) => { innerTops[b.dataset.root] = b.scrollTop; });
                channelEl.replaceChildren(head);
                setBanner(r.player.backgrounds.banner);
                searchPlaceholder();
                body.replaceChildren(list);
                applySearch();
                if (!homeSel && foot.firstChild !== composer.node) foot.replaceChildren(composer.node);   // (wide: unless the feed is using it)
                body.querySelectorAll('.replies-box[data-root]').forEach((b) => {
                    b.scrollTop = b.dataset.root in innerTops ? innerTops[b.dataset.root] : 0;   // newly opened: start at the first reply
                });
                const target = highlightPost || keepInView;
                if (target && byId[target]) {
                    const flash = !!highlightPost;
                    highlightPost = null;
                    keepInView = null;
                    const root = lastRootOf[target] || target;
                    if (root !== target && !openReplies.has(root)) { openReplies.add(root); if (flash) highlightPost = target; else keepInView = target; return redrawThread(); }
                    const node = body.querySelector(`[data-post="${target}"]`);
                    if (node) {
                        // The whole card lights up: a top post's (or message's) card, or the reply itself.
                        if (flash) (node.parentElement.classList.contains('thread-item') ? node.parentElement : node).classList.add('flash');
                        node.scrollIntoView({ block: 'center' });
                        return;
                    }
                }
                highlightPost = keepInView = null;
                const keep = anchor || pin;   // opening/closing replies: the tapped comment; otherwise the first one in view
                anchor = null;
                // Just opened, or you were already at the newest: stay with the newest (new ones arrive there).
                if (chat && (first || (atBottom && !keep?.fixed))) body.scrollTop = body.scrollHeight;
                else if (!chat && (first || (atTop && !keep?.fixed))) body.scrollTop = 0;
                else {
                    body.scrollTop = keepTop;
                    const node = keep && body.querySelector(`[data-post="${keep.id}"]`);
                    if (node) body.scrollTop += node.getBoundingClientRect().top - keep.top;
                }
                // Every 5 s: one small request for this channel's fingerprint and your 🔔 count;
                // the channel is reloaded only when its fingerprint changed.
                // (every 2 s in a chat: new messages show sooner; 4 s on a channel)
                startPolling(async () => {
                    quiet++;   // a background refresh: no loading cover
                    try {
                        const l = await api('latest', { query: `&${chat ? 'conv' : 'board'}=${encodeURIComponent(shown.user)}` });
                        if (view !== shown || !l.ok) return;
                        setUnread(l.unread, l.call || l.room);
                        // (Something is playing here: the redraw waits until it stops, so it isn't cut off.)
                        if (l.sig !== r.sig && !playingIn(body)) await renderThread(true);
                    } finally { quiet--; }
                }, chat ? 2000 : 4000);
                if (kept && pollFn) pollFn();   // shown from the phone's copy: check for newer straight away
            }

            // Basic Markdown for comments: **bold**, *italic* / _italic_, ~~strike~~,
            // `code`, ```code blocks```, "- " / "* " bullets, "1. " numbers, "> " quotes.
            // Builds DOM nodes from text only (never innerHTML), so it can't inject anything.
            // Links and pictures: https/http only, opened outside toffee without a referrer.
            const linkTo = (url, kids) => el('a', { href: url, target: '_blank', rel: 'noopener noreferrer nofollow ugc', onclick: (e) => e.stopPropagation() }, kids);
            // ![alt](url): toffee's own pictures show straight away. A picture on another site waits
            // for a tap, since loading it tells that site who's reading (and when).
            // What a link points to, for ![caption](link): a YouTube, Vimeo or TikTok video, a video or
            // audio file, or (anything else) a picture. Players come from the sites' own embed pages.
            function mediaOf(url) {
                let u;
                try { u = new URL(url); } catch (e) { return null; }
                const host = u.hostname.replace(/^(www|m)\./, '');
                const path = u.pathname;
                let m;
                if (host === 'youtu.be' && (m = /^\/([\w-]{6,})/.exec(path))) return { kind: 'youtube', id: m[1], host: u.host };
                if (host === 'youtube.com' || host === 'youtube-nocookie.com') {
                    const id = u.searchParams.get('v') || ((m = /^\/(?:shorts|embed|live)\/([\w-]{6,})/.exec(path)) && m[1]);
                    if (id) return { kind: 'youtube', id, tall: path.startsWith('/shorts/'), host: u.host };
                }
                if (host === 'vimeo.com' && (m = /^\/(\d+)/.exec(path))) return { kind: 'vimeo', id: m[1], host: u.host };
                if (host === 'tiktok.com' && (m = /\/video\/(\d+)/.exec(path))) return { kind: 'tiktok', id: m[1], tall: true, host: u.host };
                const ext = (path.split('.').pop() || '').toLowerCase();
                if (['mp4', 'webm', 'mov', 'm4v', 'ogv'].includes(ext)) return { kind: 'video', host: u.host };
                if (['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac'].includes(ext)) return { kind: 'audio', host: u.host };
                return { kind: 'image', host: u.host };
            }
            const MEDIA_LABEL = { youtube: '▶️ YouTube video', vimeo: '▶️ Vimeo video', tiktok: '▶️ TikTok video', video: '🎬 Video', audio: '🎵 Audio', image: '🖼 Picture' };
            // ![caption](link): the picture, player or video itself, shown straight away (players
            // don't start until you press play).
            // YouTube, Vimeo and TikTok: a cover the player's size with ▶ until you tap it (one tap
            // plays, as before). Nothing loads from those sites, or Google, until then; once tapped,
            // the player stays through redraws.
            const playersOn = new Set();
            const SITE_NAME = { youtube: 'YouTube', vimeo: 'Vimeo', tiktok: 'TikTok' };
            function mdPicture(url, alt) {
                const media = mediaOf(url);
                if (!media) return document.createTextNode(alt || url);
                const stop = (e) => e.stopPropagation();
                const dead = (e) => e.target.replaceWith(linkTo(url, alt || url));   // a link that doesn't work any more: just the link
                const player = (src, tall) => el('iframe', { class: `md-embed${tall ? ' tall' : ''}`, src, title: alt || MEDIA_LABEL[media.kind], loading: 'lazy',
                    allow: 'autoplay; encrypted-media; picture-in-picture; fullscreen', allowfullscreen: true, referrerpolicy: 'strict-origin-when-cross-origin' });
                const go = (play) => (play ? '&autoplay=1' : '');   // tapped just now: it starts at once
                const make = (play = false) => ({
                    youtube: () => player(`https://www.youtube-nocookie.com/embed/${encodeURIComponent(media.id)}?rel=0&playsinline=1${go(play)}`, media.tall),
                    vimeo: () => player(`https://player.vimeo.com/video/${encodeURIComponent(media.id)}?dnt=1${go(play)}`),
                    tiktok: () => player(`https://www.tiktok.com/player/v1/${encodeURIComponent(media.id)}?rel=0${go(play)}`, true),
                    video: () => videoCover({ url }),
                    audio: () => voicePlayer({ url }),
                    image: () => el('img', { class: 'md-img', src: url, alt, loading: 'lazy', referrerpolicy: 'no-referrer', role: 'button', onerror: dead,
                        title: alt || 'See full size', onclick: (e) => { stop(e); showPicture(url, alt); } }),
                })[media.kind]();
                const box = (node) => (alt && media.kind !== 'image' ? el('figure', { class: 'md-media' }, node, el('figcaption', { class: 'meta', text: alt })) : node);
                if (SITE_NAME[media.kind] && !playersOn.has(url)) {
                    const tall = media.kind === 'tiktok' || media.tall;
                    const cover = el('button', { type: 'button', class: `md-embed md-cover${tall ? ' tall' : ''}`, 'aria-label': `Play the ${SITE_NAME[media.kind]} video${alt ? `: ${alt}` : ''}`,
                        onclick: (e) => { stop(e); playersOn.add(url); cover.replaceWith(make(true)); } },
                        el('span', { class: 'md-cover-play', 'aria-hidden': 'true' }, '▶'),
                        el('span', { class: 'md-cover-site', text: SITE_NAME[media.kind] }));
                    return box(cover);
                }
                return box(make());
            }

            // 🔗 in the comment box: paste a link and toffee writes the code for it: a picture,
            // video, audio or YouTube/Vimeo/TikTok player (![caption](link)), or a plain link.
            function linkSheet(onAdd) {
                const close = () => sheet.remove();
                const input = el('input', { type: 'url', class: 'link-input', placeholder: 'Paste a link (https://…)', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false' });
                const caption = el('input', { type: 'text', class: 'link-input', placeholder: 'Caption (optional)', maxlength: '120' });
                const found = el('div', { class: 'link-found meta', text: 'Pictures, videos, audio, YouTube, Vimeo and TikTok play right in the post. Anything else becomes a link.' });
                const err = el('div', { class: 'social-error' });
                const asLink = el('label', { class: 'key-check link-as' }, el('input', { type: 'checkbox' }), el('span', { text: 'Just a link (not embedded)' }));
                const check = () => {
                    err.textContent = '';
                    const v = input.value.trim();
                    if (!v) { found.textContent = 'Pictures, videos, audio, YouTube, Vimeo and TikTok play right in the post. Anything else becomes a link.'; return null; }
                    if (!/^https?:\/\/\S+$/i.test(v)) { found.textContent = 'That doesn\'t look like a link (it should start with https://).'; return null; }
                    const m = mediaOf(v);
                    found.textContent = m ? `${MEDIA_LABEL[m.kind]} · from ${m.host}` : '🔗 Link';
                    return m;
                };
                input.addEventListener('input', check);
                const paste = navigator.clipboard && navigator.clipboard.readText ? el('button', { type: 'button', class: 'sbtn', text: 'Paste', onclick: async () => {
                    try { input.value = (await navigator.clipboard.readText()).trim(); check(); } catch (e) { err.textContent = 'Couldn\'t read the clipboard: press and hold the box and choose Paste.'; }
                } }) : null;
                const add = el('button', { type: 'button', class: 'sbtn primary', text: 'Add to my message', onclick: async () => {
                    let url = input.value.trim();
                    if (!check()) { err.textContent = err.textContent || 'Paste a link first.'; return; }
                    if (/^https:\/\/(vm|vt)\.tiktok\.com\/|^https:\/\/(www\.)?tiktok\.com\/t\//i.test(url)) {   // a TikTok short link: find the video it points to
                        const r = await api('resolve_link', { query: `&url=${encodeURIComponent(url)}` });
                        if (!r.ok) { err.textContent = r.error; return; }
                        url = r.url;
                    }
                    const cap = caption.value.trim().replace(/[\[\]\n]/g, ' ');
                    const plain = asLink.querySelector('input').checked;
                    onAdd(plain ? (cap ? `[${cap}](${url})` : url) : `![${cap}](${url})`);
                    close();
                } });
                const sheet = el('div', { class: 'install-sheet reacted-sheet', role: 'dialog', 'aria-label': 'Add a link', onclick: (e) => { if (e.target === sheet) close(); } },
                    el('div', { class: 'install-card reacted-card link-card' },
                        el('div', { class: 'reacted-head' }, el('b', { text: '🔗 Add a picture, video or link' }),
                            el('button', { type: 'button', class: 'compose-close', text: '✕', 'aria-label': 'Close', onclick: close })),
                        el('div', { class: 'link-row' }, input, paste), found, caption, asLink, err, add));
                document.body.append(sheet);
                input.focus();
            }
            const INLINE = [
                { re: /!\[([^\]\n]*)\]\((https?:\/\/[^\s)]+)\)/, make: (m) => mdPicture(m[2], m[1]) },        // ![alt](url)
                { re: /\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/, make: (m) => linkTo(m[2], m[1]) },  // [text](url)
                { re: /https?:\/\/[^\s<>"')\]]*[^\s<>"')\].,!?;:]/, make: (m) => linkTo(m[0], m[0]) },  // bare link
                { re: /`([^`\n]+)`/, tag: 'code', raw: true },
                { re: /\*\*(?=\S)([^*\n]*?\S)\*\*/, tag: 'strong' },
                { re: /~~(?=\S)([^~\n]*?\S)~~/, tag: 's' },
                { re: /(^|[^\w*])\*(?=\S)([^*\n]*?\S)\*(?![\w*])/, tag: 'em', lead: true },
                { re: /(^|[^\w])_(?=\S)([^_\n]*?\S)_(?!\w)/, tag: 'em', lead: true },
            ];
            function mdInline(text, depth = 0) {
                const out = [];
                while (text) {
                    let best = null;
                    for (const rule of INLINE) {
                        const m = rule.re.exec(text);
                        if (!m) continue;
                        const start = m.index + (rule.lead ? m[1].length : 0);
                        if (!best || start < best.start) best = { rule, m, start };
                    }
                    if (!best || depth > 4) { out.push(document.createTextNode(text)); break; }
                    const { rule, m, start } = best;
                    if (start > 0) out.push(document.createTextNode(text.slice(0, start)));
                    if (rule.make) {
                        out.push(rule.make(m));
                    } else {
                        const inner = rule.lead ? m[2] : m[1];
                        const node = document.createElement(rule.tag);
                        if (rule.raw) node.textContent = inner; else node.append(...mdInline(inner, depth + 1));
                        out.push(node);
                    }
                    text = text.slice(m.index + m[0].length);
                }
                return out;
            }
            function renderMarkdown(src) {
                const root = document.createElement('div');
                root.className = 'text md';
                const lines = String(src).replace(/\r\n?/g, '\n').split('\n');
                let i = 0;
                let para = null;
                const endPara = () => { para = null; };
                while (i < lines.length) {
                    const line = lines[i];
                    if (/^\s*```/.test(line)) {                       // code block
                        endPara();
                        const code = [];
                        i++;
                        while (i < lines.length && !/^\s*```/.test(lines[i])) code.push(lines[i++]);
                        i++;   // closing fence (or end of text)
                        const pre = document.createElement('pre');
                        pre.textContent = code.join('\n');
                        root.append(pre);
                        continue;
                    }
                    const bullet = /^\s*[-*]\s+(.*)$/.exec(line);
                    const number = /^\s*(\d{1,3})[.)]\s+(.*)$/.exec(line);
                    if (bullet || number) {                         // lists
                        endPara();
                        const ordered = !!number && !bullet;
                        const list = document.createElement(ordered ? 'ol' : 'ul');
                        if (ordered && number[1] !== '1') list.start = Number(number[1]);
                        while (i < lines.length) {
                            const b = /^\s*[-*]\s+(.*)$/.exec(lines[i]);
                            const n = /^\s*\d{1,3}[.)]\s+(.*)$/.exec(lines[i]);
                            const m = ordered ? (!b && n) : (b && !n ? b : null);
                            if (!m) break;
                            const li = document.createElement('li');
                            li.append(...mdInline(m[1]));
                            list.append(li);
                            i++;
                        }
                        root.append(list);
                        continue;
                    }
                    if (/^\s*>\s?/.test(line)) {                      // quote
                        endPara();
                        const q = document.createElement('blockquote');
                        const qs = [];
                        while (i < lines.length && /^\s*>\s?/.test(lines[i])) qs.push(lines[i++].replace(/^\s*>\s?/, ''));
                        qs.forEach((t, k) => { if (k) q.append(document.createElement('br')); q.append(...mdInline(t)); });
                        root.append(q);
                        continue;
                    }
                    if (!line.trim()) { endPara(); i++; continue; }    // blank line: new paragraph
                    if (!para) { para = document.createElement('p'); root.append(para); } else para.append(document.createElement('br'));
                    para.append(...mdInline(line));
                    i++;
                }
                return root;
            }

            function fileIcon(name) {
                const ext = String(name || '').split('.').pop().toLowerCase();
                return { pdf: '📕', doc: '📘', docx: '📘', odt: '📘', rtf: '📘', xls: '📗', xlsx: '📗', ods: '📗', csv: '📗', ppt: '📙', pptx: '📙', odp: '📙',
                    zip: '🗜️', rar: '🗜️', '7z': '🗜️', txt: '📝', md: '📝' }[ext] || '📄';
            }
            // Voice notes and videos load nothing until you press ▶ (a chat full of them used to fetch
            // every file at once, which slowed toffee for everyone). Their length comes with the post.
            const mmss = (s) => (s > 0 && isFinite(s) ? `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}` : '');
            const PLAY_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg>';
            const PAUSE_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z" fill="currentColor"/></svg>';
            const LOADING_ICON = '<svg class="spin" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="34 50"/></svg>';
            // One thing plays at a time: starting one pauses the others.
            const pauseOthers = (me1) => document.querySelectorAll('audio, video').forEach((m) => { if (m !== me1 && !m.muted && !m.paused) m.pause(); });
            // A voice note: ▶, a bar you can tap or drag to jump, its length, and 1× / 1.5× / 2×.
            let voiceRate = 1;
            function voicePlayer(att) {
                const stop = (e) => e.stopPropagation();
                let audio = null, pendingSeek = null;
                const btn = el('button', { type: 'button', class: 'vn-play', 'aria-label': 'Play voice note' });
                btn.innerHTML = PLAY_ICON;
                const fill = el('span', { class: 'vn-fill' });
                const bar = el('span', { class: 'vn-bar', role: 'slider', tabindex: '0', 'aria-label': 'Jump to', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': '0' }, fill);
                const time = el('span', { class: 'vn-time', text: mmss(att.duration) || '🎤' });
                const speed = el('button', { type: 'button', class: 'vn-speed', 'aria-label': 'Playback speed', text: `${voiceRate}×` });
                const box = el('div', { class: 'voice-note', onclick: stop }, btn, bar, time, speed);
                const length = () => (audio && isFinite(audio.duration) && audio.duration > 0 ? audio.duration : att.duration || 0);
                const show = () => {
                    const d = length(), t = audio ? audio.currentTime : 0;
                    const pct = d ? Math.min(100, (t / d) * 100) : 0;
                    fill.style.width = `${pct}%`;
                    bar.setAttribute('aria-valuenow', String(Math.round(pct)));
                    time.textContent = (audio && !audio.paused) || t > 0 ? mmss(t) || '0:00' : mmss(d) || '🎤';
                };
                // ▶, ⏸, or a spinner while it loads (taps then wait: a second tap used to pause it before it began).
                let loading = false;
                const setIcon = (state) => {
                    loading = state === 'loading';
                    btn.innerHTML = state === 'loading' ? LOADING_ICON : state ? PAUSE_ICON : PLAY_ICON;
                    btn.setAttribute('aria-label', state === 'loading' ? 'Loading' : state ? 'Pause' : 'Play voice note');
                };
                const ready = () => {
                    if (audio) return audio;
                    audio = el('audio', { preload: 'auto', src: att.url });
                    audio.playbackRate = voiceRate;
                    audio.addEventListener('timeupdate', show);
                    audio.addEventListener('loadedmetadata', () => { if (pendingSeek !== null && length()) { audio.currentTime = pendingSeek * length(); pendingSeek = null; } show(); });
                    audio.addEventListener('playing', () => setIcon(true));
                    audio.addEventListener('waiting', () => { if (!audio.paused) setIcon('loading'); });
                    audio.addEventListener('pause', () => { setIcon(false); show(); });
                    audio.addEventListener('ended', () => { setIcon(false); audio.currentTime = 0; show(); });
                    audio.addEventListener('error', () => { setIcon(false); time.textContent = "Can't play"; });
                    box.append(audio);
                    return audio;
                };
                btn.addEventListener('click', (e) => {
                    stop(e);
                    const a = ready();
                    if (loading) return;
                    if (a.paused) { pauseOthers(a); setIcon('loading'); a.play().catch(() => setIcon(false)); } else a.pause();
                });
                const seekTo = (x) => {
                    const r = bar.getBoundingClientRect();
                    const f = Math.max(0, Math.min(1, (x - r.left) / r.width));
                    const a = ready();
                    if (a.readyState >= 1 && length()) a.currentTime = f * length(); else pendingSeek = f;
                    fill.style.width = `${f * 100}%`;
                };
                bar.addEventListener('pointerdown', (e) => {
                    stop(e); bar.setPointerCapture(e.pointerId); seekTo(e.clientX);
                    const move = (ev) => seekTo(ev.clientX);
                    bar.addEventListener('pointermove', move);
                    bar.addEventListener('pointerup', () => bar.removeEventListener('pointermove', move), { once: true });
                });
                bar.addEventListener('keydown', (e) => {
                    if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
                    e.preventDefault(); const a = ready();
                    if (a.readyState >= 1) a.currentTime = Math.max(0, Math.min(length(), a.currentTime + (e.key === 'ArrowRight' ? 5 : -5)));
                });
                speed.addEventListener('click', (e) => {
                    stop(e);
                    voiceRate = voiceRate === 1 ? 1.5 : voiceRate === 1.5 ? 2 : 1;   // (kept for the next voice note too)
                    speed.textContent = `${voiceRate}×`;
                    if (audio) audio.playbackRate = voiceRate;
                });
                return box;
            }
            // A video: its cover picture (or a dark box) with ▶ and its length; tap and it plays there.
            // Its own shape: a wide video spans the post, an upright phone video stays upright.
            const fitVideo = (node, w, h) => {
                if (!w || !h) return;
                node.style.aspectRatio = `${w} / ${h}`;
                node.style.width = `min(100%, ${Math.round(420 * w / h)}px)`;
            };
            function videoPlayer(att, play) {
                const v = el('video', { class: 'attach-video', src: att.url, controls: true, playsinline: true, preload: play ? 'auto' : 'none',
                    onclick: (e) => e.stopPropagation(), onloadedmetadata: (e) => fitVideo(e.currentTarget, e.currentTarget.videoWidth, e.currentTarget.videoHeight) });
                if (att.poster) v.poster = att.poster;
                if (play) { v.autoplay = true; v.addEventListener('play', () => pauseOthers(v)); }
                return v;
            }
            function videoCover(att) {
                if (playersOn.has(att.url)) return videoPlayer(att, false);   // tapped before: the player stays through redraws
                // Sent before covers were saved (2026-10-25): its first frame, as before (only its first bytes load).
                if (!att.poster) {
                    const v = el('video', { class: 'attach-video', src: `${att.url}#t=0.1`, controls: true, playsinline: true, preload: 'metadata',
                        onclick: (e) => e.stopPropagation(), onloadedmetadata: (e) => fitVideo(e.currentTarget, e.currentTarget.videoWidth, e.currentTarget.videoHeight) });
                    v.addEventListener('play', () => pauseOthers(v));
                    return v;
                }
                const cover = el('button', { type: 'button', class: 'attach-video vid-cover', 'aria-label': `Play video${att.duration ? ` (${mmss(att.duration)})` : ''}`,
                    onclick: (e) => {
                        e.stopPropagation();
                        playersOn.add(att.url);
                        const v = videoPlayer(att, true);
                        if (cover.style.aspectRatio) { v.style.aspectRatio = cover.style.aspectRatio; v.style.width = cover.style.width; }
                        cover.replaceWith(v);
                    } });
                if (att.poster) cover.append(el('img', { class: 'vid-poster', src: att.poster, alt: '', loading: 'lazy', decoding: 'async',
                    onload: (e) => fitVideo(cover, e.currentTarget.naturalWidth, e.currentTarget.naturalHeight) }));
                const play = el('span', { class: 'md-cover-play', 'aria-hidden': 'true' });
                play.innerHTML = PLAY_ICON;
                cover.append(play);
                if (att.duration) cover.append(el('span', { class: 'vid-time', text: mmss(att.duration) }));
                return cover;
            }
            // A GIF that's really a video: loads and plays only while it's on screen (a chat full of
            // them used to download and play all of them at once).
            const gifSeen = 'IntersectionObserver' in window ? new IntersectionObserver((list) => list.forEach((e) => {
                const v = e.target;
                if (e.isIntersecting) { if (!v.src) v.src = v.dataset.src; v.play().catch(() => {}); } else if (!v.paused) v.pause();
            }), { rootMargin: '200px' }) : null;
            const gifsWatched = new Set();
            function gifVideo(url) {
                const v = el('video', { class: 'attach-gif', loop: true, muted: true, playsinline: true, preload: 'none' });
                v.muted = true;
                if (!gifSeen) { v.src = url; v.autoplay = true; return v; }
                // (ones no longer on the page, after a redraw, stop being watched: nothing kept for nothing)
                gifsWatched.forEach((x) => { if (!x.isConnected) { gifSeen.unobserve(x); gifsWatched.delete(x); } });
                v.dataset.src = url;
                gifSeen.observe(v);
                gifsWatched.add(v);
                return v;
            }
            function attachmentNode(att) {
                let attNode = null;
                if (att && att.kind === 'sticker') {
                    attNode = el('img', { class: 'attach-sticker', src: att.url, alt: 'sticker', loading: 'lazy', decoding: 'async' });
                } else if (att && att.kind === 'gif') {
                    attNode = att.mime === 'image/gif'
                        ? el('img', { class: 'attach-gif', src: att.url, alt: 'GIF', loading: 'lazy', decoding: 'async' })
                        : gifVideo(att.url);
                } else if (att && att.kind === 'image') {
                    // Same picture viewer as profile pictures: an overlay in the app, tap to close.
                    attNode = el('img', { class: 'attach-img', src: att.url, alt: att.name || 'image', loading: 'lazy', role: 'button', title: 'See full size',
                        onclick: (e) => { e.stopPropagation(); showPicture(att.url, ''); } });
                } else if (att && att.kind === 'audio') {
                    attNode = voicePlayer(att);
                } else if (att && att.kind === 'video') {
                    attNode = videoCover(att);
                } else if (att) {
                    // Documents and other files: a download, with an icon for what it is.
                    attNode = el('a', { class: 'attach-file', href: att.url, download: att.name || 'file', onclick: (e) => e.stopPropagation(),
                        text: `${fileIcon(att.name)} ${att.name || 'file'} (${sizeText(att.size)})` });
                }
                return attNode;
            }

            // A post's reactions: tap one to add or remove yours; "Who reacted" (or press and hold a
            // reaction, on a phone) shows who reacted with what. onToggle(emoji) does the reacting.
            // The most-used first; past 6, a "+N" chip opens the pop-up with all of them (a post
            // with 18 kinds of reaction would otherwise fill the screen).
            const REACT_CHIPS = 6;
            function reactionsRow(postId, reactions, mine, onToggle) {
                const entries = Object.entries(reactions || {}).sort((a, b) => b[1] - a[1] || (b[0] === mine) - (a[0] === mine));
                if (!entries.length) return null;
                const row = el('div', { class: 'reacts' });
                // Yours always shows, so you can tap it off.
                const shown = entries.slice(0, REACT_CHIPS);
                if (mine && !shown.some(([e]) => e === mine)) shown[REACT_CHIPS - 1] = entries.find(([e]) => e === mine);
                const more = entries.filter((x) => !shown.includes(x)).reduce((t, [, n]) => t + n, 0);
                shown.forEach(([e, n]) => {
                    let held = null, longPress = false;
                    const chip = el('button', { type: 'button', class: `react-chip${mine === e ? ' mine' : ''}`, text: `${e} ${n}`,
                        title: `${mine === e ? 'Remove your reaction' : 'React'} (hold to see who reacted)`,
                        onclick: (ev) => { ev.stopPropagation(); if (longPress) { longPress = false; return; } onToggle(mine === e ? '' : e); },
                        oncontextmenu: (ev) => { ev.preventDefault(); ev.stopPropagation(); whoReacted(postId); },   // right-click / long-press menu
                        onpointerdown: () => { held = setTimeout(() => { longPress = true; whoReacted(postId); }, 500); },
                        onpointerup: () => clearTimeout(held), onpointerleave: () => clearTimeout(held), onpointercancel: () => clearTimeout(held) });
                    row.append(chip);
                });
                if (more) row.append(el('button', { type: 'button', class: 'react-chip react-more-chip', text: `+${more}`, title: 'See all the reactions',
                    onclick: (ev) => { ev.stopPropagation(); whoReacted(postId); } }));
                row.append(el('button', { type: 'button', class: 'who-reacted', text: 'Who reacted',
                    onclick: (ev) => { ev.stopPropagation(); whoReacted(postId); } }));
                return row;
            }
            // One-off groups (a private message that @named others): who's in one, like "Who reacted".
            let threadGroups = {};   // group id -> its members, from the conversation last loaded
            let threadCalls = {};    // group id -> its call that's on now (Join)
            function showMembers(gid) {
                if (document.querySelector('.reacted-sheet')) return;
                const people = threadGroups[gid] || [];
                const close = () => sheet.remove();
                const sheet = el('div', { class: 'install-sheet reacted-sheet', role: 'dialog', 'aria-label': 'Members', onclick: (e) => { if (e.target === sheet) close(); } },
                    el('div', { class: 'install-card reacted-card' },
                        el('div', { class: 'reacted-head' }, el('b', { text: `Group (${people.length})` }),
                            el('button', { type: 'button', class: 'compose-close', text: '✕', 'aria-label': 'Close', onclick: close })),
                        el('div', { class: 'meta', text: 'Everyone here sees this thread and every reply. @name someone in a reply to bring them in.' }),
                        window.RTCPeerConnection ? el('div', { class: 'room-start seg-bar' },
                            el('button', { type: 'button', class: 'sbtn', onclick: () => { close(); startRoom(gid, false); } }, icon(SVG.phone), threadCalls[gid] ? ' Join the call' : ' Voice call everyone'),
                            el('button', { type: 'button', class: 'sbtn', onclick: () => { close(); startRoom(gid, true); } }, icon(SVG.video), threadCalls[gid] ? ' Join with video' : ' Video call everyone')) : null,
                        el('div', { class: 'reacted-list' }, people.map((x) => el('div', { class: 'reacted-row' }, avatar(x),
                            el('span', { class: 'grow' }, el('b', { text: x.username === me.username ? 'You' : x.display_name }), el('span', { class: 'meta', text: ` @${x.username}` })))))));
                document.body.append(sheet);
            }
            async function whoReacted(postId) {
                if (document.querySelector('.reacted-sheet')) return;
                const r = await api('reactions', { query: `&id=${postId}` });
                if (!r.ok) return;
                const close = () => sheet.remove();
                // One list: the most popular emoji first, then by name.
                const counts = {};
                r.reactions.forEach((x) => { counts[x.emoji] = (counts[x.emoji] || 0) + 1; });
                const people = r.reactions.slice().sort((a, b) => counts[b.emoji] - counts[a.emoji] || (a.emoji < b.emoji ? -1 : a.emoji > b.emoji ? 1 : 0));
                const sheet = el('div', { class: 'install-sheet reacted-sheet', role: 'dialog', 'aria-label': 'Who reacted', onclick: (e) => { if (e.target === sheet) close(); } },
                    el('div', { class: 'install-card reacted-card' },
                        el('div', { class: 'reacted-head' }, el('b', { text: `Reactions (${r.reactions.length})` }),
                            el('button', { type: 'button', class: 'compose-close', text: '✕', 'aria-label': 'Close', onclick: close })),
                        el('div', { class: 'reacted-list' }, people.map((x) => el('div', { class: 'reacted-row' },
                            el('span', { class: 'reacted-emoji', text: x.emoji }), avatar(x),
                            el('span', { class: 'grow' }, el('b', { text: x.me ? 'You' : x.display_name }), el('span', { class: 'meta', text: ` @${x.username}` })))))));
                document.body.append(sheet);
            }

            // Tap a reply's quote line: scroll to the message it answers and light it up (loading back
            // far enough first if it's older than what's shown).
            function showMessage(id) {
                const node = body.querySelector(`[data-post="${id}"]`);
                if (!node) { highlightPost = id; renderThread(true); return; }
                const card = node.parentElement.classList.contains('thread-item') ? node.parentElement : node;
                card.classList.remove('flash');
                void card.offsetWidth;   // restart the animation if it's already lit
                card.classList.add('flash');
                node.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
            function postNode(p, isReply, byId = {}, extra = null) {
                const list = p.attachments || (p.attachment ? [p.attachment] : []);
                const attNode = list.length ? el('div', { class: 'attachments' }, list.map(attachmentNode)) : null;
                const actions = el('div', { class: 'actions' });
                // Reactions: counts, tap one to add/remove yours.
                const reacts = reactionsRow(p.id, p.reactions, p.my_reaction, (e) => react(p, e));
                if (me) {
                    const editable = p.mine && !p.pending && Date.now() / 1000 - p.at < 300;   // 5 minutes, then permanent
                    if (editable && p.body) {
                        actions.append(iconBtn('Edit (within 5 minutes)', SVG.edit, () => editInPlace(p, textNode)));
                    }
                    if (editable) {
                        actions.append(iconBtn('Delete (within 5 minutes)', SVG.del, async () => {
                            if (!confirm('Delete this comment?')) return;
                            const r = await quietly(() => api('delete_post', { method: 'POST', data: { id: p.id } }));
                            if (!r.ok) alert(r.error);
                            quietly(() => renderThread(true));
                        }));
                    }
                }
                const textNode = p.body ? renderMarkdown(p.body) : null;
                // In a conversation: a reply says what it answers; a private copy of a public post
                // says it's about that post.
                const about = p.copied ? el('div', { class: 'msg-quote about', text: `↩ ${p.copied} replied privately to this public post:` })
                    : p.quote ? el('button', { type: 'button', class: 'msg-quote', title: 'Show the message this answers',
                        onclick: (e) => { e.stopPropagation(); showMessage(p.parent_id); } }, '↩ ', el('b', { text: p.quote.author }), `: ${p.quote.text}`) : null;
                if (textNode && p.edited) textNode.append(el('span', { class: 'meta', text: ' (edited)' }));
                const selected = selectedId === p.id;
                // Full-height bar on the right: tap to select this comment for the bottom bar.
                const selectBar = me && !p.pending ? el('button', {
                    type: 'button', class: 'select-bar', 'aria-pressed': String(selected),
                    title: selected ? 'Selected: use the buttons below to react or reply. Tap to deselect.' : 'Select to react or reply',
                    'aria-label': selected ? 'Deselect comment' : `Select ${p.author.display_name}'s comment to react or reply`,
                    onclick: () => selectPost(p),
                // Sideways "select" / "selected" when the comment is tall enough; otherwise just ✓ when selected.
                }, el('span', { class: 'sel-label', text: selected ? 'selected' : 'select' }), el('span', { class: 'sel-tick', text: selected ? '✓' : '' })) : null;
                return el('div', { class: `post${isReply ? ' reply' : ''}${p.private ? ' private' : ''}${selected ? ' selected' : ''}${p.pending ? ' pending' : ''}`, 'data-post': String(p.id) },
                    selectBar,   // on the left: the right edge is for the scroll bar
                    // As in the feed: picture, name and time on one line, then the post across the
                    // full width (no column of empty space under the picture).
                    el('div', { class: 'content' },
                        el('div', { class: 'post-head' }, avatar(p.author), el('b', { text: p.author.display_name }),
                            el('span', { class: 'meta', text: ` @${p.author.username} · ${p.pending ? 'sending…' : ago(p.at)} `, title: fullTime(p.at) }),
                            p.group && p.group === p.id && threadGroups[p.group] ? el('button', { type: 'button', class: 'group-tag', title: 'Who is in this group',
                                onclick: (e) => { e.stopPropagation(); showMembers(p.group); } }, icon(SVG.people), ` ${threadGroups[p.group].length}`) : null,
                            // Its call is on: join it straight from here.
                            p.group && p.group === p.id && threadCalls[p.group] ? el('button', { type: 'button', class: 'group-tag room-join', title: 'Join the group call',
                                onclick: (e) => { e.stopPropagation(); startRoom(p.group, threadCalls[p.group].video); } }, icon(threadCalls[p.group].video ? SVG.video : SVG.phone), ` Join (${threadCalls[p.group].in})`) : null),
                        about, textNode,
                        attNode, reacts, actions, extra ? el('div', { class: 'replies-line' }, extra) : null));
            }

            // PicMo emoji picker (search, categories, skin tones, recents), pinned
            // and self-hosted in vendor/ with its emoji data: no CDN calls.
            let picmoLoad = null;
            function loadPicmo() {
                if (!picmoLoad) {
                    picmoLoad = Promise.all([
                        import('./vendor/picmo-5.8.5/index.js'),
                        fetch('vendor/emojibase-data-17.0.0/en/data.json').then((r) => r.json()),
                        fetch('vendor/emojibase-data-17.0.0/en/messages.json').then((r) => r.json()),
                    ]).catch((e) => { picmoLoad = null; throw e; });
                }
                return picmoLoad;
            }
            // Mount a picker in root; onPick(emoji). Returns false if PicMo can't load.
            async function mountPicmo(root, onPick) {
                try {
                    const [mod, emojiData, messages] = await loadPicmo();
                    root.replaceChildren();
                    const picker = mod.createPicker({
                        rootElement: root, emojiData, messages, locale: 'en', theme: mod.lightTheme,
                        showPreview: false, emojisPerRow: 8, visibleRows: 4, emojiSize: '1.5rem',
                    });
                    picker.addEventListener('emoji:select', (sel) => onPick(sel.emoji));
                    return true;
                } catch (e) {
                    console.error('Emoji picker failed to load; using the simple grid.', e);
                    return false;
                }
            }

            function recentEmojis() {
                try { return JSON.parse(localStorage.getItem('bc_recent_emojis') || '[]'); } catch (e) { return []; }
            }
            // Same logic as the messenger's addToRecentEmojis.
            function addToRecentEmojis(str) {
                const found = (str || '').match(/\p{Emoji_Presentation}/gu);
                if (!found) return;
                let recent = recentEmojis();
                found.forEach((e) => { recent = recent.filter((x) => x !== e); recent.unshift(e); });
                localStorage.setItem('bc_recent_emojis', JSON.stringify(recent.slice(0, 24)));
            }

            // Stickers and GIFs, as in the messenger: your own collection, made
            // from a picture (cropped square) or a GIF / short video (15 s max).
            // Kept on the server, so it follows you to every device.
            async function myLibrary() {
                const r = await api('library');
                return r.ok ? r.items : [];
            }
            function pickFile(accept) {
                return new Promise((res) => {
                    const inp = el('input', { type: 'file', accept });
                    inp.addEventListener('change', () => res(inp.files[0] || null));
                    inp.click();
                });
            }
            async function createSticker() {
                const f = await pickFile('image/*');
                if (!f) return null;
                const img = await new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = rej; i.src = URL.createObjectURL(f); });
                const cvs = el('canvas', { width: '512', height: '512' });
                const side = Math.min(img.width, img.height);
                cvs.getContext('2d').drawImage(img, (img.width - side) / 2, (img.height - side) / 2, side, side, 0, 0, 512, 512);
                const blob = await new Promise((res) => cvs.toBlob(res, 'image/png'));
                const fd = new FormData();
                fd.append('file', blob, 'sticker.png');
                fd.append('purpose', 'sticker');
                return api('upload', { method: 'POST', form: fd });
            }
            // A video's length and first frame (a small JPEG), read on this phone before uploading.
            // Not every phone can read every video (an iPhone's HEVC on some Androids): then neither.
            function videoInfo(file) {
                return new Promise((res) => {
                    const url = URL.createObjectURL(file);
                    const v = el('video', { muted: true, playsinline: true, preload: 'auto' });
                    let duration = 0, done = false;
                    const finish = (poster) => { if (done) return; done = true; URL.revokeObjectURL(url); v.removeAttribute('src'); v.load(); res({ duration, poster }); };
                    setTimeout(() => finish(null), 8000);
                    v.onerror = () => finish(null);
                    v.onloadedmetadata = () => { duration = isFinite(v.duration) ? Math.round(v.duration * 10) / 10 : 0; v.currentTime = Math.min(0.5, duration / 2 || 0); };
                    v.onseeked = () => {
                        const w = v.videoWidth, h = v.videoHeight;
                        if (!w || !h) return finish(null);
                        const k = Math.min(1, 720 / Math.max(w, h));
                        const cvs = el('canvas', { width: String(Math.round(w * k)), height: String(Math.round(h * k)) });
                        try { cvs.getContext('2d').drawImage(v, 0, 0, cvs.width, cvs.height); } catch (e) { return finish(null); }
                        cvs.toBlob((b) => finish(b), 'image/jpeg', 0.75);
                    };
                    v.src = url;
                });
            }
            async function createGif() {
                const f = await pickFile('video/*,image/gif');
                if (!f) return null;
                if (f.type.startsWith('video/')) {
                    const dur = await new Promise((res) => { const v = el('video', { preload: 'metadata' }); v.onloadedmetadata = () => res(v.duration); v.onerror = () => res(0); v.src = URL.createObjectURL(f); });
                    if (dur > 15) return { ok: false, error: 'GIF videos must be 15 seconds or less.' };
                }
                const fd = new FormData();
                fd.append('file', f, f.name);
                fd.append('purpose', 'gif');
                return api('upload', { method: 'POST', form: fd });
            }

            function react(p, emoji) {
                reactNow(p.id, emoji);
                redrawThread();   // (at once: no wait for the server)
            }
            function editInPlace(p, textNode) {
                if (!textNode) return;
                const box = el('textarea', { maxlength: '2000', style: 'width:100%;font-size:16px;padding:6px;border:1px solid #999;border-radius:6px' });
                box.value = p.body;
                const err = el('div', { class: 'social-error' });
                const wrap = el('div', {}, box, el('div', { class: 'actions' },
                    el('button', { class: 'sbtn primary', type: 'button', text: 'Save', onclick: async () => {
                        const r = await quietly(() => api('edit_post', { method: 'POST', data: { id: p.id, body: box.value } }));
                        if (!r.ok) { err.textContent = r.error; return; }
                        quietly(() => renderThread(true));
                    } }),
                    el('button', { class: 'sbtn link', type: 'button', text: 'Cancel', onclick: () => redrawThread() })), err);
                stopPolling();
                textNode.replaceWith(wrap);
                box.focus();
            }

            // The comment box. ctx says where it posts: a channel (default: the open one) or, from
            // home, the channel of a selected post, with onSent / onReact / onDeselect to come back to.
            // It stays folded away until it's needed: a post selected, or a "New post" button tapped.
            function buildComposer(ctx = { user: view.user, tab: view.tab, display: view.display }) {
                if (!me) {
                    const node = el('div', { class: 'composer' },
                        el('div', { class: 'meta', style: 'margin-bottom:6px', text: 'Want to join in? It only takes a name and a password.' }),
                        el('div', { class: 'guest-actions' },
                            el('button', { class: 'sbtn primary', type: 'button', text: 'Create profile to comment', onclick: () => go({ name: 'auth', mode: 'register' }) }),
                            el('button', { class: 'sbtn', type: 'button', text: 'Sign in to comment', onclick: () => go({ name: 'auth', mode: 'login' }) })));
                    return { node, replyTo: () => {}, clearReply: () => {}, tab: view.tab === 'private' ? 'private' : 'public' };
                }
                const onPrivateTab = ctx.tab === 'private';
                const react = ctx.onReact || reactAndShow;
                let parent = null;
                let uploads = [];   // up to 4 attachments for this comment
                let recorder = null;
                let recTimer = null;
                let recUploaded = null;   // settles once the stopped voice note is attached
                const text = el('textarea', { placeholder: 'text…', maxlength: '2000', 'aria-label': 'Message' });
                // Typing @ shows the people in this thread; tap one to tag them.
                let people = [];
                let matches = [];
                let pick = 0;
                const mentionList = el('div', { class: 'mention-list', role: 'listbox', hidden: true });
                function mentionQuery() {
                    const m = /(^|\s)@([A-Za-z0-9_-]*)$/.exec(text.value.slice(0, text.selectionStart));
                    return m ? m[2].toLowerCase() : null;
                }
                function closeMentions() { mentionList.hidden = true; matches = []; }
                // Privately, @name brings people in: a new message starts a group, a group's reply adds
                // them. (In a conversation between two, a reply's @name adds nobody: it stays between two.)
                const privateNow = () => privBtn.getAttribute('aria-pressed') === 'true';
                // (a private reply to a public post can start one too: the post becomes its first message)
                const canGroup = () => privateNow() && (parent ? !!parent.group || !parent.private : ctx.user !== me.username);
                const everyone = () => ((boardCache && boardCache.channels) || []).map((c) => ({ username: c.username, display_name: c.display_name, avatar: c.avatar }))
                    .filter((u) => u.username !== me.username && !(privateNow() && u.username.toLowerCase() === String(ctx.user).toLowerCase()));   // (privately: not who you're writing to, they're in it)
                function showGroupHint() {
                    if (canGroup() && !boardCache && /@\w/.test(text.value)) {   // (who's who comes with home: fetched once if this page was opened directly)
                        quietly(() => api('channels')).then((r) => { if (r.ok && !boardCache) { boardCache = r; showGroupHint(); } });
                        return;
                    }
                    const known = new Map(everyone().map((u) => [u.username.toLowerCase(), u]));
                    const named = canGroup() ? [...new Set((text.value.match(/@([A-Za-z0-9_-]{3,20})/g) || []).map((x) => x.slice(1).toLowerCase()))].filter((n) => known.has(n)) : [];
                    groupHint.hidden = !named.length;
                    if (named.length) groupHint.textContent = parent && !parent.group
                        ? `This starts a group on this post: ${named.map((n) => '@' + known.get(n).username).join(', ')} and ${parent.author.display_name} will all see it and every reply.`
                        : parent
                        ? `${named.map((n) => '@' + known.get(n).username).join(', ')} will join this group and see all of it.`
                        : `This starts a group: ${named.map((n) => '@' + known.get(n).username).join(', ')} and ${ctx.display || ctx.user} will all see it and every reply.`;
                }
                function showMentions() {
                    const q = mentionQuery();
                    if (q === null || (privateNow() && !canGroup())) return closeMentions();
                    if (!boardCache) quietly(() => api('channels')).then((r) => { if (r.ok && !boardCache) { boardCache = r; showMentions(); } });
                    // @ lists people (typing narrows it: @h, everyone whose name has an h): in public, the
                    // people in this thread first, then everyone else.
                    const source = privateNow() ? everyone() : [...people, ...everyone().filter((u) => !people.some((x) => x.username === u.username))];
                    const starts = (u) => u.username.toLowerCase().startsWith(q) || (u.display_name || '').toLowerCase().startsWith(q);   // @h: names starting with h first
                    matches = source.filter((u) => u.username.toLowerCase().includes(q) || (u.display_name || '').toLowerCase().includes(q))
                        .sort((a, b) => starts(b) - starts(a)).slice(0, 50);   // the list scrolls
                    if (!matches.length) return closeMentions();
                    pick = Math.min(pick, matches.length - 1);
                    mentionList.replaceChildren(el('div', { class: 'meta mention-head', text: privateNow() ? (parent && parent.group ? 'Bring someone into this group' : 'Start a group: they all see this and every reply') : 'People' }),
                        ...matches.map((u, i) => el('button', { type: 'button', class: `mention-item${i === pick ? ' on' : ''}`, role: 'option',
                            'aria-selected': String(i === pick),
                            onmousedown: (e) => e.preventDefault(),   // keep the keyboard open on phones
                            onclick: () => insertMention(u) },
                        avatar(u), el('b', { text: u.display_name || u.username }), el('span', { class: 'meta', text: ` @${u.username}` }))));
                    mentionList.hidden = false;
                }
                function insertMention(u) {
                    const at = text.selectionStart;
                    const before = text.value.slice(0, at).replace(/@[A-Za-z0-9_-]*$/, `@${u.username} `);
                    text.value = before + text.value.slice(at).replace(/^\S*\s?/, '');
                    text.focus();
                    text.setSelectionRange(before.length, before.length);
                    closeMentions();
                    showGroupHint();
                }
                // The box grows with what you write, up to about 7 lines, then scrolls (no dragging its corner).
                const grow = () => { text.style.height = 'auto'; text.style.height = `${Math.min(text.scrollHeight + 2, 180)}px`; };
                text.addEventListener('input', () => { pick = 0; showMentions(); showGroupHint(); grow(); });
                text.addEventListener('click', showMentions);
                text.addEventListener('blur', () => setTimeout(closeMentions, 150));
                text.addEventListener('keydown', (e) => {
                    if (mentionList.hidden || !matches.length) return;
                    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                        e.preventDefault();
                        pick = (pick + (e.key === 'ArrowDown' ? 1 : matches.length - 1)) % matches.length;
                        showMentions();
                    } else if (e.key === 'Enter' || e.key === 'Tab') {
                        e.preventDefault();
                        insertMention(matches[pick]);
                    } else if (e.key === 'Escape') {
                        e.stopPropagation();
                        closeMentions();
                    }
                });
                const replyChip = el('div');
                const attachChip = el('div');
                const err = el('div', { class: 'social-error' });
                const fileInput = el('input', { type: 'file', hidden: true });   // photos, videos, documents: anything but programs
                const privBtn = iconBtn('Private: only you and the other person can see it', SVG.lock, null, '', 'composer-btn');
                privBtn.setAttribute('aria-pressed', 'false');
                const micBtn = iconBtn('Record a voice note (up to 60 s)', SVG.mic, null, '', 'composer-btn');
                const emojiPanel = el('div', { class: 'emoji-panel', hidden: true });
                const drawerBody = el('div', { class: 'emoji-drawer' });
                let drawerTab = 'emoji';
                let picmoBox = null;
                const tabBtns = ['emoji', 'sticker', 'gif'].map((t) => el('button', {
                    type: 'button', class: 'emoji-tab', text: { emoji: 'Emoji', sticker: 'Stickers', gif: 'GIFs' }[t],
                    onclick: () => { drawerTab = t; fillDrawer(); } }));
                const textSection = el('div', {}, el('div', { class: 'emoji-tabs' }, tabBtns), drawerBody);
                emojiPanel.append(textSection);
                // Adds the emoji where the cursor was, without bringing up the phone's keyboard, so
                // you can pick several in a row (see emojiMode).
                function insertEmoji(e) {
                    const at = text.selectionStart ?? text.value.length;
                    text.value = text.value.slice(0, at) + e + text.value.slice(text.selectionEnd ?? at);
                    text.setSelectionRange(at + e.length, at + e.length);
                    text.dispatchEvent(new Event('input'));
                }
                // With a comment selected, 😊 starts with "React to …": quick reactions + any emoji.
                const reactRow = el('div', { class: 'react-row', hidden: true });   // shown only while a comment is selected
                const reactAll = el('div', { class: 'react-all picmo-host', hidden: true });
                function closeReactPicker() {
                    reactAll.hidden = true;
                    const b = reactRow.querySelector('.react-more');
                    if (b) { b.textContent = '＋'; b.title = 'React with any emoji'; b.setAttribute('aria-label', b.title); }
                }
                // (shown in the comment box itself whenever a comment is selected)
                function fillReactRow() {
                    reactAll.hidden = true;
                    if (!parent) { reactRow.hidden = true; return; }
                    reactRow.hidden = false;
                    const mine = parent.my_reaction;
                    reactRow.title = `React to ${parent.author.display_name}`;
                    reactRow.replaceChildren(
                        ...quickReactions().map((e) => el('button', { type: 'button', class: `emoji-item${mine === e ? ' active' : ''}`, text: e,
                            'aria-label': `React ${e}`, onclick: () => { react(parent, mine === e ? '' : e); } })),
                        el('button', { type: 'button', class: 'emoji-item react-more', text: '＋', title: 'React with any emoji', 'aria-label': 'React with any emoji', onclick: async (ev) => {
                            // ＋ opens the full set for reacting (and closes the text emoji drawer); ✕ closes it.
                            reactAll.hidden = !reactAll.hidden;
                            if (!reactAll.hidden) emojiMode(false);
                            const b = ev.currentTarget;
                            b.textContent = reactAll.hidden ? '＋' : '✕';
                            b.title = reactAll.hidden ? 'React with any emoji' : 'Close';
                            b.setAttribute('aria-label', b.title);
                            if (!reactAll.hidden && !reactAll.dataset.ready) {
                                reactAll.replaceChildren(el('div', { class: 'emoji-label', text: 'Loading emoji…' }));
                                if (await mountPicmo(reactAll, (e) => { addToRecentEmojis(e); react(parent, e); })) reactAll.dataset.ready = '1';
                            }
                        } }));
                }
                async function fillDrawer() {
                    tabBtns.forEach((b, i) => b.classList.toggle('active', ['emoji', 'sticker', 'gif'][i] === drawerTab));
                    drawerBody.className = `emoji-drawer ${drawerTab}-grid`;
                    if (drawerTab === 'emoji') {
                        if (!picmoBox) {
                            picmoBox = el('div', { class: 'picmo-box' }, el('div', { class: 'emoji-label', text: 'Loading emoji…' }));
                            drawerBody.className = 'emoji-drawer picmo-host';
                            drawerBody.replaceChildren(picmoBox);
                            if (await mountPicmo(picmoBox, (e) => { insertEmoji(e); addToRecentEmojis(e); })) return;
                            picmoBox = null;   // fall back to the messenger grid below
                        } else {
                            drawerBody.className = 'emoji-drawer picmo-host';
                            drawerBody.replaceChildren(picmoBox);
                            return;
                        }
                        drawerBody.className = 'emoji-drawer emoji-grid';
                        const item = (e) => el('button', { type: 'button', class: 'emoji-item', text: e, onclick: () => insertEmoji(e) });
                        const recent = recentEmojis();
                        drawerBody.replaceChildren(
                            recent.length ? el('div', { class: 'emoji-label', text: 'Recent' }) : null,
                            ...recent.map(item), recent.length ? el('div', { class: 'emoji-sep' }) : null,
                            ...EMOJIS.map(item));
                        return;
                    }
                    const kind = drawerTab;
                    drawerBody.replaceChildren(el('div', { class: 'emoji-label', text: 'Loading…' }));
                    const items = (await myLibrary()).filter((x) => x.kind === kind);
                    const add = el('button', { type: 'button', class: 'sticker-item sticker-add-btn', text: '+',
                        title: kind === 'sticker' ? 'Make a sticker from a picture' : 'Add a GIF (or a video up to 15 s)',
                        onclick: async () => {
                            const r = kind === 'sticker' ? await createSticker() : await createGif();
                            if (r && !r.ok) err.textContent = r.error;
                            fillDrawer();
                        } });
                    drawerBody.replaceChildren(add, ...items.map((x) => {
                        const send = () => sendQuick(x.id);
                        return kind === 'gif'
                            ? el('video', { class: 'sticker-item', src: x.url, autoplay: true, loop: true, muted: true, playsinline: true, onclick: send })
                            : el('img', { class: 'sticker-item', src: x.url, alt: 'sticker', onclick: send });
                    }), items.length ? null : el('div', { class: 'emoji-label',
                        text: kind === 'sticker' ? 'Tap + to make a sticker from any picture.' : 'Tap + to add a GIF or a short video.' }));
                }
                // The emoji panel and the phone's keyboard take turns: while the panel is open the
                // keyboard stays down (inputmode none) and 😊 becomes ⌨️, which, like tapping the
                // text box, closes the panel and brings the keyboard back.
                const emojiBtn = el('button', { class: 'sbtn icon-act composer-btn emoji-btn', type: 'button',
                    onclick: () => {
                        if (emojiPanel.hidden) { emojiMode(true); fillDrawer(); closeReactPicker(); }
                        else { emojiMode(false); text.focus(); }
                    } });
                function emojiMode(on) {
                    emojiPanel.hidden = !on;
                    if (on) { text.setAttribute('inputmode', 'none'); text.blur(); } else text.removeAttribute('inputmode');
                    emojiBtn.innerHTML = on ? SVG.keyboard : SVG.smile;
                    const label = on ? 'Back to the keyboard' : 'Emoji, stickers and GIFs for your message';
                    emojiBtn.title = label;
                    emojiBtn.setAttribute('aria-label', label);
                }
                emojiMode(false);
                text.addEventListener('pointerdown', () => { if (!emojiPanel.hidden) emojiMode(false); });   // tap the box: keyboard back
                const sendBtn = iconBtn('Send', SVG.send, null, '', 'composer-btn send-btn');
                privBtn.addEventListener('click', () => {
                    if (onPrivateTab || (parent && parent.private)) return; // the Private tab, and private threads, stay private
                    privBtn.setAttribute('aria-pressed', String(privBtn.getAttribute('aria-pressed') !== 'true'));
                    updateSendState();
                });
                // Public posts on a channel are its owner's. Everyone else replies to them (or reacts),
                // or writes privately: the Private tab, or 🔒.
                const ownChannel = me.username === ctx.user;
                const hint = el('div', { class: 'meta compose-hint', hidden: true, text: 'Select a post to reply, or tap 🔒 to write privately.' });
                const groupHint = el('div', { class: 'meta group-hint', hidden: true, 'aria-live': 'polite' });
                function updateSendState() {
                    const allowed = ownChannel || onPrivateTab || !!parent || privBtn.getAttribute('aria-pressed') === 'true';
                    sendBtn.disabled = !allowed;
                    hint.hidden = allowed;
                }
                fileInput.multiple = true;
                fileInput.addEventListener('change', async () => {
                    const files = [...fileInput.files];
                    fileInput.value = '';
                    for (const f of files) await attach(f);
                });
                function renderChips() {
                    attachChip.replaceChildren(...uploads.map((u) => el('span', { class: 'chip' }, u.label,
                        el('button', { type: 'button', 'aria-label': `Remove ${u.label}`, text: '✕',
                            onclick: () => { uploads = uploads.filter((x) => x !== u); renderChips(); } }))));
                }
                // Photos are shrunk on the device (max 1600 px) before uploading: quicker on
                // mobile data, kinder to the Pis, still sharp. GIFs and small images are left alone.
                async function shrinkPhoto(blob, name) {
                    if (!/^image\/(jpeg|png|webp)$/.test(blob.type || '')) return [blob, name];
                    let img;
                    try { img = await createImageBitmap(blob); } catch (e) { return [blob, name]; }
                    const scale = Math.min(1, 1600 / Math.max(img.width, img.height));
                    if (scale === 1 && blob.size < 900 * 1024) return [blob, name];
                    const c = el('canvas', { width: String(Math.round(img.width * scale)), height: String(Math.round(img.height * scale)) });
                    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                    const out = await new Promise((res) => c.toBlob(res, 'image/jpeg', 0.85));
                    return out && out.size < blob.size ? [out, (name || 'photo').replace(/\.\w+$/, '') + '.jpg'] : [blob, name];
                }

                // Size caps, checked here first so nobody waits for a big upload to be refused
                // (the server checks again: these match UPLOAD_KINDS in api.php).
                const MB = 1024 * 1024;
                const capFor = (type) => /^image\//.test(type) ? 10 * MB : /^video\//.test(type) ? 50 * MB : /^audio\//.test(type) ? 10 * MB : 20 * MB;
                const capName = (type) => /^video\//.test(type) ? 'Videos' : /^image\//.test(type) ? 'Photos' : /^audio\//.test(type) ? 'Audio' : 'Documents';
                const uploading = new Set();   // attachments still on their way (Send waits for them)
                function attach(...args) {
                    const p = attachNow(...args);
                    uploading.add(p);
                    return p.finally(() => uploading.delete(p));
                }
                async function attachNow(blob, name, purpose = '', extra = {}) {
                    err.textContent = '';
                    if (uploads.length >= 4) { err.textContent = 'Up to 4 attachments per comment.'; return; }
                    name = name || blob.name || 'file';
                    [blob, name] = await shrinkPhoto(blob, name);
                    const type = blob.type || '';
                    if (blob.size > capFor(type)) { err.textContent = `${capName(type)} can be up to ${capFor(type) / MB} MB (this one is ${sizeText(blob.size)}).`; return; }
                    const pending = el('span', { class: 'chip', text: 'Uploading…' });
                    attachChip.append(pending);
                    // A video: its first frame goes up first, as its cover, with its length.
                    if (type.startsWith('video/') && !purpose) {
                        pending.textContent = 'Preparing video…';
                        const { duration, poster } = await videoInfo(blob);
                        if (duration) extra.duration = duration;
                        if (poster) {
                            const pf = new FormData();
                            pf.append('file', poster, 'cover.jpg');
                            const pr = await api('upload', { method: 'POST', form: pf });
                            if (pr.ok) extra.poster_id = pr.id;
                        }
                    }
                    const fd = new FormData();
                    fd.append('file', blob, name);
                    if (purpose) fd.append('purpose', purpose);
                    Object.entries(extra).forEach(([k, v]) => fd.append(k, String(v)));
                    const r = await uploadWithProgress(fd, (pct) => { pending.textContent = `Uploading ${name.slice(0, 24)}… ${pct}%`; });
                    pending.remove();
                    if (!r.ok) { err.textContent = r.error; return; }
                    const label = r.kind === 'audio' ? '🎤 Voice note' : r.kind === 'image' ? '🖼 Photo' : r.kind === 'video' ? '🎬 Video' : `${fileIcon(name)} ${name}`;
                    uploads.push({ id: r.id, kind: r.kind, label });
                    renderChips();
                }

                micBtn.addEventListener('click', async () => {
                    if (recorder) { recorder.stop(); return; }
                    if (!navigator.mediaDevices || !window.MediaRecorder) { err.textContent = 'Voice notes need a newer browser.'; return; }
                    let stream;
                    try {
                        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    } catch (e) {
                        err.textContent = micProblem(e);
                        return;
                    }
                    // MP4 (AAC) first: every phone and browser plays it. Chrome used to pick WebM, which older iPhones can't play.
                    const type = ['audio/mp4;codecs=mp4a.40.2', 'audio/mp4', 'audio/webm;codecs=opus', 'audio/webm', 'audio/ogg'].find((t) => MediaRecorder.isTypeSupported(t)) || '';
                    const chunks = [];
                    recorder = new MediaRecorder(stream, type ? { mimeType: type } : undefined);
                    recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data);
                    let uploaded;
                    recUploaded = new Promise((res) => { uploaded = res; });
                    let dropped = false;
                    // Left this screen while recording: the microphone is let go (and the note dropped).
                    dropRecording = () => { dropped = true; if (recorder && recorder.state !== 'inactive') recorder.stop(); else stream.getTracks().forEach((t) => t.stop()); };
                    recorder.onstop = async () => {
                        clearInterval(recTimer);
                        stream.getTracks().forEach((t) => t.stop());
                        dropRecording = null;
                        micBtn.innerHTML = SVG.mic;
                        if (dropped) { recorder = null; chunks.length = 0; uploaded(); return; }
                        const rec = recorder;
                        const secs = Math.round((Date.now() - started) / 100) / 10;
                        // (iPhones' Safari can hand over the last of the sound just after "stopped")
                        await new Promise((r) => setTimeout(r, 150));
                        const mime = rec.mimeType || type || 'audio/webm';
                        const ext = mime.includes('mp4') ? 'm4a' : mime.includes('ogg') ? 'ogg' : 'webm';
                        const blob = new Blob(chunks, { type: mime });
                        recorder = null;
                        if (blob.size) await attach(blob, `voice-note.${ext}`, 'voice', { duration: secs });
                        else err.textContent = "That voice note didn't record. Try again.";
                        uploaded();
                    };
                    const started = Date.now();
                    recorder.start(1000);   // (the sound comes in a piece every second, not all at the end)
                    micBtn.replaceChildren(el('span', { class: 'icon-count rec', text: '■ 0:00' }));
                    recTimer = setInterval(() => {
                        const s = Math.floor((Date.now() - started) / 1000);
                        micBtn.firstChild.textContent = `■ 0:${String(s).padStart(2, '0')}`;
                        if (s >= 60 && recorder) recorder.stop();
                    }, 250);
                });

                async function sendQuick(uploadId) {
                    err.textContent = '';
                    const r = await api('post', { method: 'POST', data: {
                        board: ctx.user, body: '', parent_id: parent ? parent.id : null,
                        private: privBtn.getAttribute('aria-pressed') === 'true', upload_id: uploadId,
                    } });
                    if (!r.ok) { err.textContent = r.error; return; }
                    emojiMode(false);
                    afterSend(r);
                }
                // Sent privately from the Public tab: it lands on the Private tab, so go there.
                function afterSend(r) {
                    if (ctx.onSent) { const was = parent; clearReply(); return ctx.onSent(r, was); }
                    if (r.private && !onPrivateTab) {
                        highlightPost = r.id;
                        view = { ...view, tab: 'private', roots: 0 };
                        render();
                        return;
                    }
                    showMine(r.id, parent);
                    clearReply();
                    quietly(() => renderThread(true));   // (no "loading…" over what you just sent)
                }
                sendBtn.addEventListener('click', async () => {
                    err.textContent = '';
                    sendBtn.disabled = true;
                    // Still recording: Send stops the voice note and sends it.
                    if (recorder) recorder.stop();
                    // Everything still on its way goes with this message (a voice note just stopped, a
                    // photo...): it used to be sent without it, and it went with the next message.
                    if (recUploaded || uploading.size) sendBtn.classList.add('waiting');
                    await recUploaded;
                    await Promise.all([...uploading]);
                    sendBtn.classList.remove('waiting');
                    const data = { board: ctx.user, body: text.value, parent_id: parent ? parent.id : null,
                        private: privBtn.getAttribute('aria-pressed') === 'true', upload_ids: uploads.map((u) => u.id) };
                    // In a channel or chat: your message shows at once ("sending…"), and the box is ready
                    // for the next; if it doesn't go through, it comes back into the box to try again.
                    const showNow = !ctx.onSent && !(data.private && !onPrivateTab) && threadCache && threadCache.view === view && (data.body.trim() || data.upload_ids.length);
                    let temp = null, keptText = '', keptUploads = [];
                    if (showNow) {
                        temp = { id: -Date.now(), parent_id: data.parent_id, private: data.private, pending: true, mine: true, at: Math.floor(Date.now() / 1000),
                            author: { username: me.username, display_name: me.display_name, avatar: me.avatar },
                            body: data.body.trim() || uploads.map((u) => u.label).join(' '), edited: false, reactions: {}, my_reaction: null,
                            attachments: [], group: parent ? parent.group || null : null };
                        threadCache.r.posts.push(temp);
                        keptText = text.value; keptUploads = uploads;
                        text.value = ''; grow(); groupHint.hidden = true; uploads = []; attachChip.replaceChildren();
                        showMine(temp.id, parent);
                        clearReply();
                        redrawThread();
                    }
                    const r = await quietly(() => api('post', { method: 'POST', data }));
                    sendBtn.disabled = false;
                    if (!r.ok && temp) {   // not sent: out of the list, back into the box
                        threadCache.r.posts = threadCache.r.posts.filter((x) => x !== temp);
                        text.value = keptText; grow(); uploads = keptUploads; renderChips();
                        redrawThread();
                        toast(r.error || 'Not sent. Tap send to try again.');
                        err.textContent = r.error;
                        return;
                    }
                    if (!r.ok) { err.textContent = r.error; return; }
                    if (temp) {   // sent: the server's copy takes its place
                        addToRecentEmojis(data.body);
                        emojiMode(false);
                        highlightPost = r.id;
                        quietly(() => renderThread(true));
                        return;
                    }
                    addToRecentEmojis(text.value);
                    emojiMode(false);
                    text.value = '';
                    grow();
                    groupHint.hidden = true;
                    uploads = [];
                    attachChip.replaceChildren();
                    afterSend(r);
                });

                function clearReply() {
                    parent = null;
                    fillReactRow();
                    replyChip.replaceChildren();
                    privBtn.setAttribute('aria-pressed', String(onPrivateTab));
                    privBtn.disabled = onPrivateTab;
                    updateSendState();
                    fold();
                }
                function replyTo(p, priv) {
                    parent = p;
                    fillReactRow();
                    privBtn.setAttribute('aria-pressed', String(!!priv || onPrivateTab));
                    privBtn.disabled = !!p.private || onPrivateTab;
                    updateSendState();
                    // (in a group: everyone in it sees the reply)
                    unfold(p.group ? (p.author.username === me.username ? 'Reply in the group' : `Reply to ${p.author.display_name} in the group`)
                        : `Reply to ${p.author.display_name}${p.private ? ' (private)' : ''}`, SVG.reply);

                    // Replying to a reply: start with @name, since that thread is shared.
                    // Replying to a top comment: no @ (the reply is in its own thread; the author is notified anyway).
                    const rest = text.value.replace(/^@\S+\s*/, '');
                    text.value = p.parent_id && p.author.username !== me.username ? `@${p.author.username} ${rest}` : rest;
                    // No keyboard yet: you may only want to react, and on a phone it would cover
                    // the post. Tap the box to write.
                    emojiMode(false);
                }

                // Folded: a slim bar with what you may start here. Unfolded: the box, saying what you're
                // writing, and ✕ to fold it again (or deselect the post).
                const closeBtn = el('button', { type: 'button', class: 'compose-close', text: '✕', title: 'Close', 'aria-label': 'Close',
                    onclick: () => { if (parent) (ctx.onDeselect || deselectPost)(); else fold(); } });
                // What you're writing ("🔒 Message to Carol", "↩ Reply to Alice") is the box's own
                // placeholder: no title line above it, so the box gets the room. ✕ (stop replying) is at
                // the start of the reactions row.
                const full = el('div', { class: 'compose-full' },
                    el('div', { class: 'react-line' }, closeBtn, reactRow), reactAll, replyChip, mentionList, hint, el('div', { class: 'compose-text' }, text), groupHint, attachChip,
                    // One bar like the Public | Private tabs: equal segments, icons only.
                    el('div', { class: 'tools seg-bar' },
                        emojiBtn,
                        iconBtn('Attach a picture or file', SVG.attach, () => fileInput.click(), '', 'composer-btn'),
                        iconBtn('Add a picture, video or link from the web', SVG.link, () => linkSheet((code) => {
                                const at = text.selectionStart ?? text.value.length;
                                const before = text.value.slice(0, at), after = text.value.slice(text.selectionEnd ?? at);
                                const add = `${before && !/\s$/.test(before) ? ' ' : ''}${code}${after && !/^\s/.test(after) ? ' ' : ''}`;
                                text.value = before + add + after;
                                text.setSelectionRange(before.length + add.length, before.length + add.length);
                                text.dispatchEvent(new Event('input'));
                            }), '', 'composer-btn link-btn'),
                        micBtn, privBtn, sendBtn),
                    emojiPanel, err);
                // Where you can simply write (a private conversation, your own Public tab), the box
                // is always there, like a messenger; selecting a message turns it into a reply, ✕
                // back. Elsewhere (someone's Public tab, home, your inbox) it shows only for a
                // selected post. It never brings up the keyboard by itself: tap the box for that.
                const own = me.username === ctx.user;
                const write = ctx.home || ctx.inbox ? null : onPrivateTab
                    ? { priv: true, label: own ? 'Note to yourself (only you see it)' : `Message to ${ctx.display || ctx.user}`, icon: own ? SVG.edit : SVG.lock }
                    : own ? { priv: false, label: 'New post', icon: SVG.edit } : null;
                const bar = el('div', { class: 'compose-bar' },
                    el('span', { class: 'meta compose-tip', text: `Select a ${onPrivateTab ? 'message' : 'post'} to react or reply` }));
                // Its placeholder says what you're writing, with a drawn icon beside it (no emoji).
                const phIcon = el('span', { class: 'ph-icon', 'aria-hidden': 'true' });
                function unfold(label, svg = '') {
                    text.placeholder = label; text.setAttribute('aria-label', label);
                    phIcon.innerHTML = svg;   // (our own constant SVG)
                    if (svg) text.after(phIcon); else phIcon.remove();
                    full.hidden = false; bar.hidden = true; closeBtn.hidden = !parent;
                }
                function fold() {
                    emojiMode(false); closeMentions(); closeReactPicker();
                    if (write) {   // back to writing a new one
                        privBtn.setAttribute('aria-pressed', String(write.priv));
                        privBtn.disabled = true;
                        updateSendState();
                        unfold(write.label, write.icon);
                        return;
                    }
                    full.hidden = true; bar.hidden = !!ctx.home;
                }
                const node = el('div', { class: 'composer' }, bar, full, fileInput);
                function setParent(p) { if (parent && p && parent.id === p.id) { parent = p; if (reactAll.hidden) fillReactRow(); } }
                function setPeople(list) { people = list; if (!mentionList.hidden) showMentions(); }
                clearReply();
                return { node, replyTo, clearReply, setParent, setPeople, tab: onPrivateTab ? 'private' : 'public' };
            }

            // Home: a nudge towards phone notifications for people who'd otherwise never find the
            // switch. On an iPhone in Safari it points at Add to Home Screen (the only way Apple
            // allows them); elsewhere, where they're possible but not asked for yet, at Turn on.
            // ✕ hides it for good on this device.
            function notifyCard() {
                if (localStorage.getItem('tf_notify_card') === 'hidden') return null;
                const iphoneSafari = isIOS && !installed && !(iosVersion && iosVersion < 16.04);
                const notAsked = pushSupported && Notification.permission === 'default';
                if (!iphoneSafari && !notAsked) return null;
                const card = el('div', { class: 'key-card notify-card', role: 'button', tabindex: '0', onclick: () => go({ name: 'notifications' }) },
                    el('b', { text: iphoneSafari ? '📱 Get notifications on your iPhone' : '📱 Turn on notifications' }),
                    el('span', { text: iphoneSafari ? 'Add toffee to your Home Screen first (Apple only allows notifications there). Tap to see how.'
                        : "So you don't miss replies and private messages, even when toffee is closed. Tap to turn on." }),
                    el('button', { type: 'button', class: 'card-close', 'aria-label': 'Hide this', title: 'Hide this', text: '✕',
                        onclick: (e) => { e.stopPropagation(); localStorage.setItem('tf_notify_card', 'hidden'); card.remove(); } }));
                return card;
            }

            // 🎙️ Microphone and camera: a web app may only ask when you tap something, so home has a
            // card that asks (like an app would), and calls then connect without stopping to ask.
            // Only while they haven't been allowed; hidden for good with ✕.
            // Ask (the browser shows its question); true if the microphone may be used now.
            async function askMedia() {
                let s = null;
                try { s = await navigator.mediaDevices.getUserMedia({ audio: true, video: true }); }
                catch (err) { try { s = await navigator.mediaDevices.getUserMedia({ audio: true }); } catch (err2) { s = null; } }
                if (s) s.getTracks().forEach((t) => t.stop());   // only asking: nothing is recorded
                return s ? (s.getVideoTracks().length ? 'both' : 'mic') : null;
            }
            // 'granted', 'denied', or 'prompt' (not asked yet, or the browser can't tell).
            async function mediaState() {
                if (!navigator.permissions || !navigator.permissions.query) return 'prompt';
                const [mic, cam] = await Promise.all(['microphone', 'camera'].map((name) => navigator.permissions.query({ name }).then((p) => p.state, () => 'prompt')));
                return mic === 'denied' ? 'denied' : mic === 'granted' && cam !== 'prompt' ? 'granted' : 'prompt';
            }
            // How to allow the microphone and camera again, for this kind of phone or computer.
            const isAndroid = /Android/i.test(navigator.userAgent);
            const mediaBlockedText = () => (isIOS
                ? (installed ? 'iPhone Settings › Apps › Safari › Microphone (and Camera) › Ask or Allow. Then close toffee fully (swipe it away) and open it again.'
                    : 'In Safari, tap ᴀA (left of the address) › Website Settings › Microphone (and Camera) › Allow. Then reload.')
                : isAndroid
                    ? (installed ? 'Press and hold the toffee icon › App info › Permissions › Microphone (and Camera) › Allow. Then open toffee again.'
                        : 'In Chrome, tap the icon left of the address › Permissions › Microphone (and Camera) › Allow. Then reload.')
                    : 'Click the icon left of the web address › Microphone (and Camera) › Allow. Then reload.');
            // What went wrong when the microphone wouldn't start, in plain words.
            function micProblem(e, what = 'the microphone') {
                const n = (e && e.name) || '';
                if (n === 'NotAllowedError' || n === 'SecurityError') return `toffee isn't allowed to use ${what}. ${mediaBlockedText()}`;
                if (n === 'NotFoundError' || n === 'OverconstrainedError') return `No ${what === 'the microphone' ? 'microphone' : 'microphone or camera'} found on this device.`;
                if (n === 'NotReadableError' || n === 'AbortError') return `${what[0].toUpperCase()}${what.slice(1)} is busy: another app (a phone call, WhatsApp, a recorder) is using it. Close that and try again.`;
                return `Couldn't start ${what}${n ? ` (${n})` : ''}. Try again.`;
            }
            function mediaCard() {
                if (localStorage.getItem('tf_media_card') === 'hidden' || !navigator.mediaDevices || !window.RTCPeerConnection) return null;
                const title = el('b', { text: '🎙️ Get ready for calls' });
                const text = el('span', { text: 'Let toffee use your microphone and camera, for voice notes and calls. Your browser asks you once.' });
                const allow = el('button', { type: 'button', class: 'sbtn primary', text: 'Allow', onclick: async (e) => {
                    e.stopPropagation();
                    const got = await askMedia();
                    if (got) {
                        title.textContent = '✓ Ready for calls';
                        text.textContent = 'toffee can use your microphone' + (got === 'both' ? ' and camera.' : '.');
                        allow.remove();
                        setTimeout(() => card.remove(), 2500);
                    } else blocked();
                } });
                const blocked = () => {
                    title.textContent = '🎙️ Microphone blocked';
                    text.textContent = `To make calls: ${mediaBlockedText()}`;
                    allow.remove();
                };
                const card = el('div', { class: 'key-card notify-card media-card' }, title, text, allow,
                    el('button', { type: 'button', class: 'card-close', 'aria-label': 'Hide this', title: 'Hide this', text: '✕',
                        onclick: (e) => { e.stopPropagation(); localStorage.setItem('tf_media_card', 'hidden'); card.remove(); } }));
                // Allowed already: no card. (Browsers that can't tell show it, and Allow just confirms.)
                mediaState().then((st) => { if (st === 'granted') card.remove(); else if (st === 'denied') blocked(); });
                return card;
            }

            // ---- sign up / sign in / forgot password / profile ----
            function renderAuth() {
                const mode = ['register', 'recover'].includes(view.mode) ? view.mode : 'login';
                const reg = mode === 'register', rec = mode === 'recover';
                setTitle('');   // the big logo and name in the middle are the title here
                setTab('');
                const err = el('div', { class: 'social-error' });
                const user = el('input', { autocomplete: 'username', maxlength: '20', placeholder: 'e.g. sam_7', autocapitalize: 'off', spellcheck: 'false' });
                const pass = el('input', { type: 'password', autocomplete: reg || rec ? 'new-password' : 'current-password' });
                const disp = el('input', { maxlength: '20', placeholder: 'Shown on your channel' });
                const key = el('input', { placeholder: 'TOFF-XXXX-XXXX-XXXX-XXXX', autocomplete: 'off', autocapitalize: 'characters', spellcheck: 'false', class: 'key-input' });
                const submit = el('button', { class: 'sbtn primary', type: 'submit', text: reg ? 'Create profile' : rec ? 'Set new password' : 'Sign in' });
                // Forgot password: open the key file you saved (or paste what you shared) and the username
                // and key fill themselves in. The file is read on this phone; it's never uploaded.
                const keyNote = el('div', { class: 'meta', 'aria-live': 'polite' });
                const fillFrom = (txt) => {
                    const k = String(txt).match(/TOFF[-\s]?([0-9A-Z]{4})[-\s]?([0-9A-Z]{4})[-\s]?([0-9A-Z]{4})[-\s]?([0-9A-Z]{4})/i);
                    if (!k) return false;
                    const u = String(txt).match(/@([A-Za-z0-9_-]{3,20})/);
                    key.value = `TOFF-${k.slice(1).join('-').toUpperCase()}`;
                    if (u) user.value = u[1];
                    keyNote.textContent = u ? `Filled in for @${u[1]}. Now choose a new password.` : 'Key filled in.';
                    (user.value ? pass : user).focus();
                    return true;
                };
                key.addEventListener('input', () => { if (/\s|@/.test(key.value)) fillFrom(key.value); });   // the whole saved text pasted in
                const keyFile = el('input', { type: 'file', accept: '.txt,text/plain', hidden: true, onchange: async () => {
                    const f = keyFile.files[0];
                    keyFile.value = '';
                    if (!f) return;
                    if (!fillFrom(await f.text())) keyNote.textContent = "That file has no recovery key in it. It's called toffee-recovery-<username>.txt.";
                } });
                const openKey = el('button', { class: 'sbtn', type: 'button', text: 'Open your saved key file', onclick: () => keyFile.click() });
                const link = (text, m) => el('button', { class: 'sbtn link', type: 'button', text, onclick: () => go({ name: 'auth', mode: m }, false) });
                const form = el('form', { class: 'social-form' },
                    el('div', { class: 'auth-brand' }, el('img', { src: 'toffee-logo.png?v=2', alt: '' }), el('span', { class: 'brand', text: 'toffee' })),
                    view.note ? el('div', { class: 'home-note', text: view.note }) : null,
                    rec ? el('div', { class: 'key-note' }, el('b', { text: 'Forgot your password?' }),
                        ' Open the key file you saved, or type your username and recovery key.') : null,
                    rec ? el('div', { class: 'key-open' }, openKey, keyFile, el('span', { class: 'meta', text: 'It fills in both for you.' })) : null,
                    el('label', {}, 'Username', user),
                    reg ? el('label', {}, 'Display name (optional)', disp) : null,
                    rec ? el('label', {}, 'Recovery key', key) : null,
                    rec ? keyNote : null,
                    el('label', {}, reg ? 'Password (at least 8 characters)' : rec ? 'New password (at least 8 characters)' : 'Password', pass),
                    reg ? el('div', { class: 'meta', text: 'No email or phone number needed. Next you get a recovery key: the only way to reset a forgotten password, so keep it safe.' }) : null,
                    err, submit,
                    rec ? el('div', { class: 'meta', text: 'Lost the key too? Then nobody can get that profile back (toffee knows no email or phone to check it\'s you). You can make a new profile.' }) : null,
                    reg ? link('I already have a profile: sign in', 'login') : link('New here? Create a profile', 'register'),
                    mode === 'login' ? link('Forgot password?', 'recover') : rec ? link('Back to sign in', 'login') : null);
                form.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    submit.disabled = true;
                    const r = await api(mode, { method: 'POST',
                        data: { username: user.value, password: pass.value, display_name: disp.value, key: key.value } });
                    submit.disabled = false;
                    if (!r.ok) { err.textContent = r.error; return; }
                    await refreshMe();
                    composer = null;
                    // Back to where you were, skipping any sign-in / sign-up screens; a new
                    // recovery key (new profile, first sign-in since keys, reset) is shown first.
                    while (history.length && history[history.length - 1].name === 'auth') history.pop();
                    if (r.recovery_key) go({ name: 'key', key: r.recovery_key, reason: mode }, false);
                    else history.length ? back() : go({ name: 'board' }, false);
                });
                body.replaceChildren(form);
                user.focus();
            }

            // "Save your recovery key": shown once, right after it's made. You can't move on
            // until you've said it's saved, because nobody can reset your password without it.
            function renderKey() {
                setTitle('Recovery key');
                setTab('');
                const k = view.key;
                const status = el('div', { class: 'meta key-status', 'aria-live': 'polite' });
                const done = el('button', { class: 'sbtn primary', type: 'button', text: 'Continue', disabled: true, onclick: async () => {
                    await refreshMe();
                    const then = view.then;
                    if (then) go(then, false); else history.length ? back() : go({ name: 'board' }, false);
                } });
                const saved = el('input', { type: 'checkbox', onchange: () => { done.disabled = !saved.checked; } });
                const copy = el('button', { class: 'sbtn', type: 'button', text: 'Copy', onclick: async () => {
                    try { await navigator.clipboard.writeText(k); status.textContent = 'Copied. Paste it into your password manager or notes.'; }
                    catch (e) { status.textContent = 'Copying is blocked here: select the key and copy it, or take a screenshot.'; }
                } });
                const file = el('button', { class: 'sbtn', type: 'button', text: 'Save as file', onclick: () => {
                    const text = `toffee recovery key for @${me.username}\n\n${k}\n\nWith your username and this key you can set a new password if you forget yours.\nKeep it private: anyone with it can take over your profile.\n`;
                    const a = el('a', { href: URL.createObjectURL(new Blob([text], { type: 'text/plain' })), download: `toffee-recovery-${me.username}.txt` });
                    document.body.append(a); a.click(); a.remove();
                    status.textContent = 'Saved to your downloads.';
                } });
                const share = navigator.share ? el('button', { class: 'sbtn', type: 'button', text: 'Share', onclick: () => {
                    navigator.share({ title: 'toffee recovery key', text: `toffee recovery key for @${me.username}: ${k}` }).catch(() => {});
                } }) : null;
                const intro = { register: 'Your profile is ready. One last, important step:', recover: 'Your new password is set and every other device is signed out. Your old key no longer works; here is your new one:',
                    login: 'toffee now has a way to get back in if you ever forget your password. Here is your key:', profile: 'Here is your new key. The old one no longer works.' }[view.reason] || '';
                body.replaceChildren(el('div', { class: 'social-form key-screen' },
                    el('div', { class: 'meta', text: intro }),
                    el('h2', { text: '🔑 Save your recovery key' }),
                    el('div', { class: 'key-box', text: k, 'aria-label': 'Your recovery key' }),
                    el('div', { class: 'key-actions' }, copy, file, share), status,
                    el('div', { class: 'key-note' },
                        el('p', {}, el('b', { text: 'This key is the only way back into your profile if you forget your password.' })),
                        el('p', { text: 'toffee never asks for your email or phone number, so there is no reset email. Nobody, not even the people who run toffee, can get you back in without this key.' }),
                        el('ul', {}, el('li', { text: 'Keep it in your password manager or notes app, or write it down, or take a screenshot.' }),
                            el('li', { text: 'Keep it private: anyone with your username and this key can take over your profile.' }),
                            el('li', { text: 'Lost it? Make a new one any time in Edit profile (you need your password).' }))),
                    el('label', { class: 'key-check' }, saved, el('span', { text: "I've saved my recovery key somewhere safe" })),
                    done));
            }

            // Edit profile: your recovery key. Making a new one needs your password.
            // Edit profile, at the very bottom: delete your profile and everything in it, for good.
            function deleteSection() {
                if (me.pinned) return null;   // toffee's own channels can't be deleted
                const err = el('div', { class: 'social-error' });
                const pass = el('input', { type: 'password', autocomplete: 'current-password', placeholder: 'Your password' });
                const del = el('button', { class: 'sbtn danger', type: 'button', text: 'Delete my profile', onclick: async () => {
                    err.textContent = '';
                    if (!pass.value) { err.textContent = 'Type your password first.'; return; }
                    if (!confirm('Delete your profile and everything in it? This can\'t be undone.')) return;
                    const r = await api('delete_account', { method: 'POST', data: { password: pass.value } });
                    if (!r.ok) { err.textContent = r.error; return; }
                    localStorage.removeItem('tf_push');
                    pushSubscription().then((sub) => sub && sub.unsubscribe()).catch(() => {});
                    await refreshMe();
                    composer = null;
                    history = [];
                    go({ name: 'auth', mode: 'login', note: 'Your profile and everything in it has been deleted.' }, false);
                } });
                return el('div', { class: 'push-switch delete-section' },
                    el('b', { text: '🗑️ Delete my profile' }),
                    el('span', { class: 'meta', text: 'Deletes your profile and everything in it, for good: your posts, replies and private messages '
                        + '(and the replies others left on your posts), your reactions, and every photo, video, voice note and file you shared. '
                        + 'Conversations with you disappear for the other person too. This can\'t be undone.' }),
                    pass, del, err);
            }

            function recoverySection() {
                const err = el('div', { class: 'social-error' });
                const pass = el('input', { type: 'password', autocomplete: 'current-password', placeholder: 'Your password' });
                const make = el('button', { class: 'sbtn', type: 'button', text: me.has_recovery ? 'Make a new key' : 'Get my key', onclick: async () => {
                    err.textContent = '';
                    const r = await api('recovery_new', { method: 'POST', data: { password: pass.value } });
                    if (!r.ok) { err.textContent = r.error; return; }
                    go({ name: 'key', key: r.recovery_key, reason: 'profile', then: { name: 'profile' } }, false);
                } });
                return el('div', { class: `push-switch key-section${me.has_recovery ? '' : ' missing'}` },
                    el('b', { text: '🔑 Recovery key' }),
                    el('span', { class: 'meta', text: me.has_recovery
                        ? 'You have one: it resets your password if you forget it. Lost it? Make a new one (the old one stops working).'
                        : "You don't have one yet. Without it, a forgotten password can't be reset." }),
                    pass, make, err);
            }

            function renderProfile() {
                setTitle('Your profile');
                setTab('');
                const err = el('div', { class: 'social-error' });
                const disp = el('input', { maxlength: '20', value: me.display_name });
                const bio = el('textarea', { maxlength: '160', rows: '2', placeholder: 'A line about you (optional)' });
                bio.value = me.bio || '';
                // Profile picture: same Choose / Remove buttons as the backgrounds, saved straight away.
                const preview = el('span', {}, avatar(me, true));
                const setPicture = async (id) => {
                    err.textContent = '';
                    const r = await api('profile', { method: 'POST', data: { avatar_id: id } });
                    if (!r.ok) { err.textContent = r.error; return; }
                    await refreshMe();
                    preview.replaceChildren(avatar(me, true));
                };
                const choosePic = el('button', { class: 'sbtn', type: 'button', text: 'Choose', onclick: async () => {
                    const picked = await pickFile('image/*');
                    const f = picked && await cropPicture(picked, 'avatar');   // the part for the circle
                    if (!f) return;
                    // A small copy too (160 px), for lists: they show it at 28-40 px (the big one is
                    // for when it's tapped). Uploaded first; the picture points to it.
                    const small = await smallCopy(f, 160);
                    const sr = small ? await api('upload', { method: 'POST', form: (() => { const x = new FormData(); x.append('file', small, 'avatar-small.jpg'); return x; })() }) : null;
                    const fd = new FormData();
                    fd.append('file', f);
                    if (sr && sr.ok) fd.append('poster_id', String(sr.id));
                    const r = await api('upload', { method: 'POST', form: fd });
                    if (!r.ok || r.kind !== 'image') { err.textContent = r.error || 'Choose an image.'; return; }
                    setPicture(r.id);
                } });
                const removePic = el('button', { class: 'sbtn link', type: 'button', text: 'Remove', onclick: () => setPicture('none') });
                const save = el('button', { class: 'sbtn primary', type: 'submit', text: 'Save' });
                const form = el('form', { class: 'social-form' },
                    el('div', { class: 'bg-row' }, preview,
                        el('span', { class: 'grow' }, el('b', { text: 'Profile picture' }), el('br'),
                            el('span', { class: 'meta', text: 'Others can tap it to see it full size, react and reply' })),
                        choosePic, removePic),
                    backgroundPicker(),
                    el('label', {}, 'Display name', disp),
                    el('label', {}, 'Bio', bio), err, save,
                    recoverySection(), themePicker(), textPicker(), pushSwitch(), mediaSwitch(), deleteSection());
                form.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const r = await api('profile', { method: 'POST', data: { display_name: disp.value, bio: bio.value } });
                    if (!r.ok) { err.textContent = r.error; return; }
                    await refreshMe();
                    back();
                });
                body.replaceChildren(form);
            }

            // ---- cropping a profile picture or banner before it's uploaded ---------------------
            // A frame in the exact shape it will show (a circle; the banner's strip, with dashed lines
            // for the thinner band a laptop shows). Drag the picture under it; pinch, the slider or the
            // mouse wheel zooms. Only the framed part is uploaded. Resolves to a JPEG Blob, or null.
            function cropPicture(file, kind) {
                return new Promise((done) => {
                    if (!/^image\/(jpeg|png|webp|heic|heif)$/i.test(file.type || '')) return done(file);   // (a GIF keeps its animation: no crop)
                    const banner = kind === 'banner';
                    const url = URL.createObjectURL(file);
                    const img = el('img', { class: 'crop-img', alt: '', draggable: 'false' });
                    const frame = el('div', { class: `crop-frame${banner ? ' banner' : ' circle'}` },
                        banner ? el('div', { class: 'crop-guide' }) : null);
                    const stage = el('div', { class: `crop-stage${banner ? ' banner' : ''}` }, img, frame);
                    const zoom = el('input', { type: 'range', class: 'crop-zoom', min: '1', max: '4', step: '0.01', value: '1', 'aria-label': 'Zoom' });
                    const close = (out) => { URL.revokeObjectURL(url); sheet.remove(); done(out); };
                    const sheet = el('div', { class: 'install-sheet crop-sheet', role: 'dialog', 'aria-label': banner ? 'Choose the part for your banner' : 'Choose the part for your picture' },
                        el('div', { class: 'install-card crop-card' },
                            el('b', { text: banner ? 'Your banner' : 'Your profile picture' }),
                            el('span', { class: 'meta', text: banner ? 'Drag the picture to choose what shows. Between the dashed lines: what a laptop shows.'
                                : 'Drag the picture to choose what shows in the circle.' }),
                            stage,
                            el('label', { class: 'crop-zoom-row' }, el('span', { text: '−' }), zoom, el('span', { text: '+' })),
                            el('div', { class: 'install-actions' },
                                el('button', { type: 'button', class: 'sbtn', text: 'Cancel', onclick: () => close(null) }),
                                el('button', { type: 'button', class: 'sbtn primary', text: 'Use this', onclick: () => cut() }))));
                    document.body.append(sheet);
                    // Where the picture sits: x, y (its top left in the stage) and its scale.
                    let base = 1, z = 1, x = 0, y = 0;
                    const box = () => ({ s: stage.getBoundingClientRect(), f: frame.getBoundingClientRect() });
                    const keepCovered = () => {   // the frame is never empty: the picture always covers it
                        const { s, f } = box(), w = img.naturalWidth * base * z, h = img.naturalHeight * base * z;
                        const fx = f.left - s.left, fy = f.top - s.top;
                        x = Math.min(fx, Math.max(fx + f.width - w, x));
                        y = Math.min(fy, Math.max(fy + f.height - h, y));
                        img.style.width = `${w}px`; img.style.height = `${h}px`;
                        img.style.transform = `translate(${x}px, ${y}px)`;
                    };
                    const zoomTo = (nz, cx, cy) => {   // zoom around a point (the middle, or under your fingers)
                        nz = Math.min(4, Math.max(1, nz));
                        const { s } = box();
                        cx = cx === undefined ? s.width / 2 : cx; cy = cy === undefined ? s.height / 2 : cy;
                        x = cx - (cx - x) * (nz / z); y = cy - (cy - y) * (nz / z); z = nz;
                        zoom.value = String(z);
                        keepCovered();
                    };
                    img.onload = () => {
                        const { s, f } = box();
                        base = Math.max(f.width / img.naturalWidth, f.height / img.naturalHeight);   // smallest size that fills the frame
                        x = (s.width - img.naturalWidth * base) / 2; y = (s.height - img.naturalHeight * base) / 2;
                        keepCovered();
                    };
                    img.onerror = () => close(file);   // can't show it (an odd format): upload it as it is
                    img.src = url;
                    zoom.addEventListener('input', () => zoomTo(Number(zoom.value)));
                    stage.addEventListener('wheel', (e) => { e.preventDefault(); const { s } = box(); zoomTo(z * (e.deltaY < 0 ? 1.08 : 1 / 1.08), e.clientX - s.left, e.clientY - s.top); }, { passive: false });
                    // Drag with one finger or the mouse; pinch with two.
                    const pts = new Map();
                    let last = null;
                    stage.addEventListener('pointerdown', (e) => { stage.setPointerCapture(e.pointerId); pts.set(e.pointerId, e); last = null; });
                    stage.addEventListener('pointermove', (e) => {
                        if (!pts.has(e.pointerId)) return;
                        const prev = pts.get(e.pointerId);
                        pts.set(e.pointerId, e);
                        if (pts.size === 1) { x += e.clientX - prev.clientX; y += e.clientY - prev.clientY; keepCovered(); return; }
                        const [a, b] = [...pts.values()];
                        const d = Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
                        const { s } = box();
                        if (last) zoomTo(z * (d / last), (a.clientX + b.clientX) / 2 - s.left, (a.clientY + b.clientY) / 2 - s.top);
                        last = d;
                    });
                    const up = (e) => { pts.delete(e.pointerId); last = null; };
                    stage.addEventListener('pointerup', up);
                    stage.addEventListener('pointercancel', up);
                    // The framed part, as a JPEG: a 640 px square, or a 1600 x 320 banner.
                    function cut() {
                        const { s, f } = box(), k = base * z;
                        const sx = (f.left - s.left - x) / k, sy = (f.top - s.top - y) / k, sw = f.width / k, sh = f.height / k;
                        const [ow, oh] = banner ? [1600, 320] : [640, 640];
                        const c = el('canvas', { width: String(ow), height: String(oh) });
                        const g = c.getContext('2d');
                        g.imageSmoothingQuality = 'high';
                        g.drawImage(img, sx, sy, sw, sh, 0, 0, ow, oh);
                        c.toBlob((b) => close(b ? new File([b], banner ? 'banner.jpg' : 'picture.jpg', { type: 'image/jpeg' }) : file), 'image/jpeg', 0.88);
                    }
                });
            }

            // Banner picture behind your picture, name and bio at the top of your channel.
            // Saved as soon as you pick one.
            function backgroundPicker() {
                const err = el('div', { class: 'social-error' });
                const row = (which, title, hint) => {
                    const thumb = el('span', { class: 'bg-thumb' });
                    const show = () => {
                        const url = me.backgrounds[which];
                        thumb.style.backgroundImage = url ? `url("${url}")` : '';
                        thumb.textContent = url ? '' : 'none';
                    };
                    const save = async (id) => {
                        err.textContent = '';
                        const r = await api('background', { method: 'POST', data: { which, upload_id: id } });
                        if (!r.ok) { err.textContent = r.error; return; }
                        await refreshMe();
                        show();
                    };
                    const choose = el('button', { class: 'sbtn', type: 'button', text: 'Choose', onclick: async () => {
                        const picked = await pickFile('image/*');
                        const f = picked && await cropPicture(picked, which === 'banner' ? 'banner' : 'avatar');   // the part that will show
                        if (!f) return;
                        const fd = new FormData();
                        fd.append('file', f);
                        const r = await api('upload', { method: 'POST', form: fd });
                        if (!r.ok || r.kind !== 'image') { err.textContent = r.error || 'Choose an image.'; return; }
                        save(r.id);
                    } });
                    show();
                    return el('div', { class: 'bg-row' }, thumb,
                        el('span', { class: 'grow' }, el('b', { text: title }), el('br'), el('span', { class: 'meta', text: hint })),
                        choose, el('button', { class: 'sbtn link', type: 'button', text: 'Remove', onclick: () => save(null) }));
                };
                return el('div', { class: 'bg-picker' },
                    row('banner', 'Channel banner', 'Behind your picture, name and bio at the top of your channel'),
                    err);
            }

            async function signOut() {
                await pushOff().catch(() => {});
                if (window.caches) await caches.delete(KEPT).catch(() => {});   // (nothing of yours stays on this phone)
                await api('logout', { method: 'POST' });
                await refreshMe();
                composer = null;
                go({ name: 'board' }, false);
            }

            // ---- polling (only while the window is open) and the "new" dot ----
            let pollFn = null;   // what the open view polls with, kept while the app is hidden so it can resume
            // A video or voice note playing in el, or a player (YouTube...) you've tapped into.
            function playingIn(root) {
                // (GIFs are looping, muted videos: always "playing", never cut off by a refresh.)
                if ([...root.querySelectorAll('video, audio')].some((m) => !m.paused && !m.ended && !m.loop && !m.muted)) return true;
                const a = document.activeElement;
                return !!(a && a.tagName === 'IFRAME' && root.contains(a));
            }
            // One check at a time: on a busy Pi a slow answer isn't overtaken by the next check.
            const oneAtATime = (fn) => { let busy = false; return async () => { if (busy) return; busy = true; try { await fn(); } finally { busy = false; } }; };
            let pollEvery = 5000;
            function startPolling(fn, every = 5000) { stopPolling(); pollEvery = every; pollFn = oneAtATime(fn); if (!document.hidden) pollTimer = setInterval(pollFn, every); }
            function stopPolling() { clearInterval(pollTimer); pollTimer = null; pollFn = null; }
            let heardUnread = null;   // unread count last time, to chime only for new ones
            function chime() {
                try {
                    const ac = new (window.AudioContext || window.webkitAudioContext)();
                    [880, 1320].forEach((f, i) => {
                        const o = ac.createOscillator(), g = ac.createGain(), t = ac.currentTime + i * 0.12;
                        o.frequency.value = f;
                        g.gain.setValueAtTime(0.0001, t);
                        g.gain.exponentialRampToValueAtTime(0.2, t + 0.02);
                        g.gain.exponentialRampToValueAtTime(0.0001, t + 0.3);
                        o.connect(g).connect(ac.destination);
                        o.start(t);
                        o.stop(t + 0.32);
                    });
                    setTimeout(() => ac.close(), 800);
                } catch (e) { /* no sound available */ }
            }
            function setUnread(n, ring = null) {
                if (ring) ring.room ? incomingRoom(ring) : incomingCall(ring);
                const before = unread;
                unread = me ? n : 0;
                // The same number on the app's icon (installed toffee on iPhone and Android).
                if (navigator.setAppBadge) (unread ? navigator.setAppBadge(unread) : navigator.clearAppBadge()).catch(() => {});
                if (heardUnread !== null && unread > heardUnread) chime();
                heardUnread = me ? unread : null;
                if (unread !== before || !headBell.childElementCount) renderAccount();   // (only when the number changes, not on every check)
            }
            async function refreshUnread() {
                if (!me) return setUnread(0);
                const r = await api('latest');
                if (r.ok) setUnread(r.unread, r.call || r.room);
            }
            const updateDot = refreshUnread;

            function open(start = { name: 'board' }) {
                history = [];
                view = start;
                overlay.hidden = false;
                renderAccount();
                refreshUnread();   // redraws the 🔔 count once it arrives
                render();
            }
            // ‹ Back and the phone's Back button do the same thing.
            backBtn.addEventListener('click', () => {
                if (window.history.state && window.history.state.tf) window.history.back();
                else back();
            });
            window.addEventListener('popstate', () => {
                const lb = document.querySelector('.lightbox');
                if (lb) { lb.remove(); return; }            // Back closes an open picture first
                // The recovery key screen stays until you've said the key is saved.
                if (view.name === 'key') { window.history.pushState({ tf: 'screen' }, ''); return; }
                if (history.length) back();
                else if (view.name !== 'board' && me) go({ name: 'board' }, false);
            });
            // Public | Private: in a channel they switch its tab; on the home screen they
            // show every channel, or every private conversation you're in.
            tabs.addEventListener('click', (e) => {
                const t = e.target.closest('button');
                if (!t) return;
                if (view.name === 'thread') {
                    // Private again, inside a conversation with someone: out to all your conversations (as 🔒 does).
                    const inConversation = me && view.tab === 'private' && String(view.user).toLowerCase() !== me.username.toLowerCase();
                    if (inConversation && t.dataset.tab === 'private') { composer = null; go({ name: 'thread', user: me.username, display: me.display_name, tab: 'private' }); }
                    else if ((view.tab || 'public') !== t.dataset.tab) { composer = null; go({ ...view, tab: t.dataset.tab, roots: 0 }, false); }
                } else {
                    go({ name: 'board' }, false);
                }
            });

            function openLink(href) {
                const q = new URL(href, location.href).searchParams;
                if (!me || !q.get('c')) return false;
                highlightPost = Number(q.get('post')) || null;
                history = [{ name: 'board' }];
                composer = null;
                view = { name: 'thread', user: q.get('c'), display: q.get('c'), tab: q.get('tab') === 'private' ? 'private' : 'public' };
                overlay.hidden = false;
                renderAccount();
                refreshUnread();   // the 🔔 count straight away, not at the first poll
                render();
                window.history.replaceState(null, '', location.pathname);
                return true;
            }

            // ---- installable app (PWA) and background notifications (Web Push) ----
            const pushSupported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
            // iPhone and iPad (iPads say they're Macs, but Macs have no touch screen).
            const isIOS = /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            const iosVersion = (() => { const m = /OS (\d+)_(\d+)/.exec(navigator.userAgent); return m ? Number(m[1]) + Number(m[2]) / 100 : 0; })();
            const installed = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('sw.js').catch((e) => console.error('Service worker failed', e));
                navigator.serviceWorker.addEventListener('message', (e) => {
                    if (e.data && e.data.type === 'open') openLink(e.data.url);
                    if (e.data && e.data.type === 'call') refreshUnread();   // a call's notification came in: ring now
                });
            }
            function keyBytes(b64) {
                const raw = atob(b64.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - b64.length % 4) % 4));
                return Uint8Array.from(raw, (c) => c.charCodeAt(0));
            }
            async function pushSubscription() {
                if (!pushSupported) return null;
                // (A service worker that never starts, e.g. in a private window, mustn't hang anything.)
                const reg = await Promise.race([navigator.serviceWorker.ready, new Promise((r) => setTimeout(() => r(null), 4000))]);
                return reg ? reg.pushManager.getSubscription() : null;
            }
            // Subscribe this device and tell toffee. ask: show the browser's "Allow notifications?"
            // (only from a tap); without it, only re-subscribes where permission was already given.
            async function pushOn(ask = true) {
                if (ask && (await Notification.requestPermission()) !== 'granted') {
                    return isIOS ? 'Notifications are off for toffee: iPhone Settings → Notifications → toffee.'
                        : 'Notifications are blocked for this site in your browser settings.';
                }
                const k = await api('push_key');
                if (!k.ok) return k.error;
                const reg = await navigator.serviceWorker.ready;
                let sub;
                try {
                    sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(k.key) });
                } catch (e) {
                    return 'This browser could not turn on notifications.';
                }
                const r = await api('push_subscribe', { method: 'POST', data: sub.toJSON() });
                if (r.ok) localStorage.setItem('tf_push', 'on');   // keep it on for this device
                return r.ok ? '' : r.error;
            }
            async function pushOff() {
                localStorage.removeItem('tf_push');
                const sub = await pushSubscription();
                if (!sub) return;
                await api('push_unsubscribe', { method: 'POST', data: { endpoint: sub.endpoint } });
                await sub.unsubscribe();
            }
            // "Phone notifications" (notifications page and profile): on / off, a test, and what
            // to do where they can't work yet (an iPhone needs toffee on its Home Screen first).
            function pushSwitch() {
                const msg = el('span', { class: 'meta' });
                const btn = el('button', { class: 'sbtn', type: 'button', text: '…', disabled: true });
                const test = el('button', { class: 'sbtn', type: 'button', text: 'Send me a test', hidden: true });
                const steps = el('ol', { class: 'push-steps', hidden: true });
                const howTo = (lines) => { steps.replaceChildren(...lines.map((t) => el('li', { text: t }))); steps.hidden = false; };
                const show = async () => {
                    steps.hidden = true;
                    test.hidden = true;
                    btn.hidden = false;
                    if (isIOS && iosVersion && iosVersion < 16.04) {
                        btn.hidden = true;
                        msg.textContent = `Notifications need iOS 16.4 or newer (this iPhone has iOS ${Math.floor(iosVersion)}). Update in Settings → General → Software Update.`;
                        return;
                    }
                    if (isIOS && !installed) {
                        btn.hidden = true;
                        msg.textContent = 'On iPhone, notifications only work in the toffee app on your Home Screen:';
                        howTo(['Open toffee in Safari.', 'Tap the Share button (the square with an arrow up).', 'Tap "Add to Home Screen", then "Add".',
                            'Open toffee from your Home Screen and sign in (once: the app keeps its own sign-in).', 'Come back here and tap "Turn on".']);
                        return;
                    }
                    if (!pushSupported) { btn.hidden = true; msg.textContent = 'This browser can\'t show notifications when toffee is closed. On Android, use Chrome; on iPhone, Safari.'; return; }
                    if (Notification.permission === 'denied') {
                        btn.hidden = true;
                        msg.textContent = isIOS ? 'Notifications are off for toffee. Turn them on in iPhone Settings → Notifications → toffee.'
                            : 'Notifications are blocked for toffee: tap the lock or ⓘ next to the address, then Notifications → Allow.';
                        return;
                    }
                    const on = !!(await pushSubscription());
                    btn.disabled = false;
                    btn.textContent = on ? 'Turn off' : 'Turn on';
                    btn.classList.toggle('primary', !on);
                    test.hidden = !on;
                    msg.textContent = on ? 'On: you get notified even when toffee is closed.' : 'Get notified even when toffee is closed.';
                };
                btn.addEventListener('click', async () => {
                    btn.disabled = true;
                    const on = !!(await pushSubscription());
                    const problem = on ? (await pushOff(), '') : await pushOn();
                    await show();
                    if (problem) msg.textContent = problem;
                });
                test.addEventListener('click', async () => {
                    test.disabled = true;
                    const r = await api('push_test', { method: 'POST' });
                    test.disabled = false;
                    msg.textContent = !r.ok ? r.error : r.sent ? `Sent to ${r.sent} ${r.sent === 1 ? 'device' : 'devices'}: it should show in a few seconds.`
                        : 'It could not be delivered. Turn notifications off and on again here.';
                });
                show();
                return el('div', { class: 'push-switch' }, el('b', { text: '📱 Phone notifications' }), msg, btn, test, steps);
            }

            // Edit profile: 🎙️ Microphone and camera, next to the notifications switch.
            function mediaSwitch() {
                const msg = el('span', { class: 'meta' });
                const btn = el('button', { class: 'sbtn primary', type: 'button', text: 'Allow', hidden: true });
                const show = async () => {
                    if (!navigator.mediaDevices || !window.RTCPeerConnection) { msg.textContent = "This browser can't make calls or record voice notes."; return; }
                    const st = await mediaState();
                    btn.hidden = st !== 'prompt';
                    msg.textContent = st === 'granted' ? 'On: toffee can use them for calls and voice notes (only while you use them).'
                        : st === 'denied' ? `Blocked. To allow them: ${mediaBlockedText()}`
                        : 'For calls and voice notes. Allow now, so calls connect without stopping to ask.';
                };
                btn.addEventListener('click', async () => {
                    btn.disabled = true;
                    const got = await askMedia();
                    btn.disabled = false;
                    if (got) { btn.hidden = true; msg.textContent = `On: toffee can use your microphone${got === 'both' ? ' and camera' : ''} for calls and voice notes.`; }
                    else await show().then(() => { if (!btn.hidden) msg.textContent = `Not allowed. To allow them: ${mediaBlockedText()}`; });
                });
                show();
                return el('div', { class: 'push-switch' }, el('b', { text: '🎙️ Microphone and camera' }), msg, btn);
            }

            // "Install toffee": opened in a browser rather than the installed app, a pop-up offers to
            // install it. Android / Chrome: the browser's own Install. iPhone: Safari has no such
            // button, so the steps. "Not now" waits a week.
            let installEvent = null;
            window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); installEvent = e; setTimeout(offerInstall, 1500); });
            window.addEventListener('appinstalled', () => { installEvent = null; document.querySelector('.install-sheet')?.remove(); });
            function offerInstall() {
                if (installed || document.querySelector('.install-sheet') || view.name === 'key') return;
                if (Number(localStorage.getItem('tf_install_later') || 0) > Date.now()) return;
                const iphone = isIOS && !installEvent;
                if (!installEvent && !iphone) return;   // a browser that can't install apps
                const close = () => sheet.remove();
                const later = el('button', { class: 'sbtn link', type: 'button', text: 'Not now', onclick: () => {
                    localStorage.setItem('tf_install_later', String(Date.now() + 7 * 86400 * 1000));
                    close();
                } });
                const install = installEvent ? el('button', { class: 'sbtn primary', type: 'button', text: 'Install toffee', onclick: async () => {
                    installEvent.prompt();
                    const choice = await installEvent.userChoice.catch(() => null);
                    installEvent = null;
                    if (!choice || choice.outcome !== 'accepted') localStorage.setItem('tf_install_later', String(Date.now() + 7 * 86400 * 1000));
                    close();
                } }) : null;
                const sheet = el('div', { class: 'install-sheet', role: 'dialog', 'aria-label': 'Install toffee' },
                    el('div', { class: 'install-card' },
                        el('img', { src: 'toffee-logo.png?v=2', alt: '' }),
                        el('b', { class: 'install-title', text: 'Get the toffee app' }),
                        el('p', { text: 'Install toffee on your phone: it opens like an app from your Home Screen, keeps you signed in, and can tell you about replies and private messages even when it\'s closed.' }),
                        iphone ? el('ol', { class: 'push-steps' },
                            el('li', { text: 'In Safari, tap the Share button (the square with an arrow up).' }),
                            el('li', { text: 'Scroll down and tap "Add to Home Screen", then "Add".' }),
                            el('li', { text: 'Open toffee from your Home Screen and sign in there.' })) : null,
                        el('div', { class: 'install-actions' }, install, later)));
                document.body.append(sheet);
            }

            // Start: who's signed in. No connection (a blip, or the Pi restarting): say so and try again,
            // rather than showing the sign-in page to someone who is signed in.
            async function boot() {
                const ok = await refreshMe();
                if (!ok && lastMeOffline) {
                    overlay.hidden = false;
                    body.replaceChildren(el('div', { class: 'empty', text: '📡 No connection to toffee. Trying again…' }));
                    return setTimeout(boot, 4000);
                }
                booted = true;
                started();
            }
            boot();
            function started() {
                if (!openLink(location.href)) open();
                // This device's notifications follow whoever is signed in on it. If the browser
                // dropped the subscription (it happens), quietly make a new one where they were on.
                (async () => {
                    const sub = me ? await pushSubscription() : null;
                    if (sub) { localStorage.setItem('tf_push', 'on'); api('push_subscribe', { method: 'POST', data: sub.toJSON() }); }
                    else if (me && pushSupported && Notification.permission === 'granted' && localStorage.getItem('tf_push') === 'on') pushOn(false);
                })();
                setTimeout(offerInstall, 1500);   // iPhone (no install event): offer from here
                // Fetch the emoji data once in the background, so the service worker has it ready
                // for the first 😊 (skipped when the phone asks to save data).
                if (navigator.serviceWorker?.controller && !navigator.connection?.saveData) {
                    setTimeout(() => ['vendor/picmo-5.8.5/index.js', 'vendor/emojibase-data-17.0.0/en/data.json', 'vendor/emojibase-data-17.0.0/en/messages.json']
                        .forEach((u) => fetch(u).catch(() => {})), 8000);
                }
            }
            let backgroundTimers = [];
            function startBackgroundPolling() {
                backgroundTimers = [
                    setInterval(() => { if (!pollFn) updateDot(); }, 10000),   // a channel's own polling already brings it (and calls ring within 10 s)
                    setInterval(refreshFeed, 30000),   // wide screens: the feed column keeps up with everyone's posts
                ];
            }
            startBackgroundPolling();
            // Hidden (another app, a locked phone): no polling at all; phone notifications still arrive by push.
            // Back: one check straight away, then polling as before.
            document.addEventListener('visibilitychange', () => {
                clearInterval(pollTimer);
                pollTimer = null;
                backgroundTimers.forEach(clearInterval);
                backgroundTimers = [];
                if (document.hidden) return;
                if (pollFn) { pollFn(); pollTimer = setInterval(pollFn, pollEvery); } else updateDot();
                refreshFeed();
                startBackgroundPolling();
            });

            return { open };
        })();
