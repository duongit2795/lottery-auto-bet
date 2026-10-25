/* Telegram Mini App — dùng chung dữ liệu với bot qua app/api.php */
(function () {
    'use strict';
    const tg = window.Telegram ? window.Telegram.WebApp : null;
    const $view = document.getElementById('view');
    const state = { user: null, config: {}, tab: 'home', stack: [], timer: null, services: {} };

    if (tg) {
        tg.ready();
        tg.expand();
        tg.BackButton.onClick(back);
    }

    // ------------------------------------------------------------ helpers
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const money = (n) => Number(n || 0).toLocaleString('vi-VN') + 'đ';
    const fmtTime = (s) => { if (!s) return ''; const d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? s : d.toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' }); };
    const statusText = { pending: 'Đang chờ', success: 'Thành công', refunded: 'Đã hoàn tiền', cancelled: 'Đã hủy' };
    const haptic = (t) => { try { tg && tg.HapticFeedback.notificationOccurred(t); } catch (e) {} };

    function toast(msg) {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.classList.add('show');
        clearTimeout(toast._t);
        toast._t = setTimeout(() => t.classList.remove('show'), 2400);
    }
    function alertBox(msg) { tg && tg.showAlert ? tg.showAlert(msg) : alert(msg); }
    function confirmBox(msg) {
        return new Promise((res) => (tg && tg.showConfirm ? tg.showConfirm(msg, res) : res(confirm(msg))));
    }
    function copy(text) {
        const done = () => { toast('Đã sao chép: ' + text); haptic('success'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, () => fallback());
        } else fallback();
        function fallback() {
            const a = document.createElement('textarea');
            a.value = text; document.body.appendChild(a); a.select();
            try { document.execCommand('copy'); done(); } catch (e) { alertBox(text); }
            a.remove();
        }
    }

    async function api(action, data = {}) {
        const res = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Init-Data': tg ? tg.initData : '' },
            body: JSON.stringify(Object.assign({ action }, data)),
        });
        let json;
        try { json = await res.json(); } catch (e) { json = { ok: false, message: 'Lỗi máy chủ (' + res.status + ')' }; }
        return json;
    }
    function setBalance(b) {
        if (state.user && b !== undefined) state.user.balance = b;
        document.getElementById('topBalance').textContent = state.user ? money(state.user.balance) : '—';
    }
    async function refreshUser() {
        const r = await api('init');
        if (r.ok) { state.user = r.user; setBalance(); }
    }

    // ------------------------------------------------------------ navigation
    function stopTimer() { if (state.timer) { clearInterval(state.timer); state.timer = null; } }
    function go(tab) {
        stopTimer();
        state.tab = tab;
        state.stack = [];
        document.querySelectorAll('#tabs button').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
        tg && tg.BackButton.hide();
        window.scrollTo(0, 0);
        views[tab]();
    }
    function open(fn, ...args) {
        stopTimer();
        state.stack.push([fn, args]);
        tg && tg.BackButton.show();
        window.scrollTo(0, 0);
        fn(...args);
    }
    function back() {
        stopTimer();
        state.stack.pop();
        const prev = state.stack[state.stack.length - 1];
        if (prev) prev[0](...prev[1]);
        else go(state.tab);
        if (!state.stack.length) tg && tg.BackButton.hide();
    }
    document.querySelectorAll('#tabs button').forEach((b) => b.addEventListener('click', () => go(b.dataset.tab)));
    function render(html) { $view.innerHTML = html; }
    function on(sel, ev, fn) { $view.querySelectorAll(sel).forEach((el) => el.addEventListener(ev, fn)); }

    // ------------------------------------------------------------ views
    const views = {};

    views.home = async function () {
        const u = state.user;
        render(`
            <div class="card hero">
                <div class="muted small">Xin chào ${esc(u.name || '')}</div>
                <div class="big">${money(u.balance)}</div>
                <div class="muted small">Lượt gói tin: ${u.credits}</div>
            </div>
            <div class="grid4">
                <button data-go="rent"><span>📱</span>Thuê sim</button>
                <button data-go="sms"><span>✉️</span>Gửi tin</button>
                <button data-go="deposit"><span>💰</span>Nạp tiền</button>
                <button data-go="packages"><span>📦</span>Gói tin</button>
            </div>
            <div class="gap"></div>
            <div class="card"><h3>Đơn gần đây</h3><div id="recent" class="list"><div class="muted small">Đang tải...</div></div></div>
        `);
        on('[data-go]', 'click', (e) => {
            const t = e.currentTarget.dataset.go;
            t === 'packages' ? open(views.packages) : go(t);
        });
        const r = await api('orders');
        const box = document.getElementById('recent');
        if (!box) return;
        box.innerHTML = r.ok && r.orders.length ? r.orders.slice(0, 8).map(orderRow).join('') : '<div class="muted small">Chưa có đơn nào.</div>';
        bindOrderRows();
    };

    function orderRow(o) {
        return `<div class="item" data-order="${o.id}">
            <div class="grow">
                <div class="name">${esc(o.service_name)} ${o.kind === 'sms' ? '<span class="badge">gửi tin</span>' : ''}</div>
                <div class="small muted">${esc(o.phone)} · ${fmtTime(o.created_at)}${o.otp ? ' · OTP <b>' + esc(o.otp) + '</b>' : ''}${o.sms_sent ? ' · đã gửi ' + o.sms_sent + ' lần' : ''}</div>
            </div>
            <span class="badge ${o.status}">${statusText[o.status] || o.status}</span>
        </div>`;
    }
    function bindOrderRows() { on('[data-order]', 'click', (e) => open(views.order, Number(e.currentTarget.dataset.order))); }

    // ---- danh sách dịch vụ (dùng cho thuê sim và gửi tin)
    async function serviceList(kind) {
        const key = kind || 'otp';
        if (!state.services[key]) {
            const r = await api('services', { kind });
            if (!r.ok) { render(`<div class="card center">${esc(r.message)}</div>`); return null; }
            state.services[key] = r.services;
        }
        return state.services[key];
    }

    views.rent = async function () {
        render('<div class="center muted">Đang tải dịch vụ...</div>');
        const list = await serviceList('');
        if (!list) return;
        render(`
            <div class="search"><input id="q" placeholder="🔍 Tìm dịch vụ (facebook, zalo...)" autocomplete="off"></div>
            <div class="card list" id="svcList"></div>
        `);
        const draw = (q) => {
            q = (q || '').toLowerCase();
            const rows = list.filter((s) => s.name.toLowerCase().includes(q));
            document.getElementById('svcList').innerHTML = rows.length ? rows.map((s) => `
                <div class="item" data-svc="${s.id}">
                    <div class="grow"><div class="name">${esc(s.name)}</div>${Object.keys(s.features).length ? `<div class="feat">${Object.values(s.features).map(esc).join(' · ')}</div>` : ''}</div>
                    <div class="price">${money(s.price)}</div>
                </div>`).join('') : '<div class="muted small">Không tìm thấy dịch vụ.</div>';
            on('[data-svc]', 'click', (e) => open(views.confirmRent, Number(e.currentTarget.dataset.svc), ''));
        };
        draw('');
        document.getElementById('q').addEventListener('input', (e) => draw(e.target.value));
    };

    views.confirmRent = async function (serviceId, kind) {
        const list = await serviceList(kind);
        const s = list && list.find((x) => x.id === serviceId);
        if (!s) { render('<div class="card center">Dịch vụ không tồn tại.</div>'); return; }
        const sms = kind === 'sms';
        const useCredit = sms && s.credits > 0;
        const cfg = state.config;
        let network = '';
        render(`
            <div class="card">
                <h3>${sms ? '✉️ Thuê số gửi tin' : '🛒 Thuê sim nhận OTP'}</h3>
                <div class="row between"><span class="muted">Dịch vụ</span><b>${esc(s.name)}</b></div>
                <div class="row between"><span class="muted">Giá</span><b class="price">${useCredit ? '1 lượt gói (còn ' + s.credits + ')' : money(s.price)}</b></div>
                <div class="row between"><span class="muted">Số dư</span><b>${money(state.user.balance)}</b></div>
                <div class="row between"><span class="muted">Thời gian</span><b>${Math.round(cfg.order_timeout / 60)} phút</b></div>
                ${Object.keys(s.features).length ? `<div class="feat" style="margin-top:6px">Hỗ trợ: ${Object.values(s.features).map(esc).join(', ')}</div>` : ''}
                ${cfg.allow_network && !sms ? `<label>Nhà mạng</label><div class="chips" id="nets"><button class="chip active" data-net="">Bất kỳ</button>${cfg.networks.map((n) => `<button class="chip" data-net="${n}">${n}</button>`).join('')}</div>` : ''}
                <div class="gap"></div>
                <div class="small muted">${sms ? 'Có số rồi bạn nhập số nhận + nội dung để gửi. 1 số gửi được nhiều tin tới khi hệ thống báo hết. Chưa gửi được tin nào sẽ tự hoàn tiền.' : 'Không nhận được OTP sẽ tự động hoàn tiền 100%.'}</div>
            </div>
            ${!useCredit && state.user.balance < s.price
                ? `<button class="btn" id="toDeposit">💰 Số dư không đủ — Nạp tiền</button>`
                : `<button class="btn" id="doRent">${sms ? '✅ Thuê số' : '✅ Xác nhận thuê'}</button>`}
        `);
        on('[data-net]', 'click', (e) => {
            network = e.currentTarget.dataset.net;
            $view.querySelectorAll('[data-net]').forEach((c) => c.classList.toggle('active', c.dataset.net === network));
        });
        on('#toDeposit', 'click', () => go('deposit'));
        on('#doRent', 'click', async (e) => {
            e.target.disabled = true;
            e.target.textContent = '⏳ Đang lấy số...';
            const r = await api('rent', { service_id: serviceId, kind, network });
            if (!r.ok) { haptic('error'); alertBox(r.message); e.target.disabled = false; e.target.textContent = 'Thử lại'; return; }
            haptic('success');
            state.services = {}; // cập nhật lượt gói
            await refreshUser();
            state.stack.pop();
            open(views.order, r.order.id);
        });
    };

    views.order = async function (id) {
        if (!$view.querySelector('#orderBox')) render('<div id="orderBox" class="center muted">Đang tải...</div>');
        const r = await api('order', { id });
        const box = document.getElementById('orderBox');
        if (!box) return;
        if (!r.ok) { box.innerHTML = esc(r.message); return; }
        setBalance(r.balance);
        drawOrder(r.order);
        stopTimer();
        if (r.order.status === 'pending') {
            let left = r.order.seconds_left;
            let tick = 0;
            state.timer = setInterval(async () => {
                left = Math.max(0, left - 1);
                const c = document.getElementById('countdown');
                if (c) c.textContent = Math.floor(left / 60) + ' phút ' + (left % 60) + ' giây';
                if (++tick % 5 === 0) {
                    const rr = await api('order', { id });
                    if (rr.ok && document.getElementById('orderBox')) {
                        const before = document.getElementById('orderBox').dataset.sig;
                        const sig = JSON.stringify([rr.order.status, rr.order.otp, rr.order.sms, rr.order.sms_sent, rr.order.sms_closed, rr.order.call_logs.length]);
                        if (sig !== before) {
                            if (rr.order.otp && !document.querySelector('.otp')) haptic('success');
                            drawOrder(rr.order);
                            setBalance(rr.balance);
                        }
                        left = rr.order.seconds_left;
                        if (rr.order.status !== 'pending') stopTimer();
                    }
                }
            }, 1000);
        }
    };

    function drawOrder(o) {
        const box = document.getElementById('orderBox');
        if (!box) return;
        box.className = '';
        box.dataset.sig = JSON.stringify([o.status, o.otp, o.sms, o.sms_sent, o.sms_closed, o.call_logs.length]);
        const sms = o.kind === 'sms';
        const formHtml = o.actions.length ? `
            <div class="card">
                <h3>📤 Gửi từ số này</h3>
                ${o.actions.length > 1 ? `<div class="chips" id="sendTypes">${o.actions.map((a, i) => `<button class="chip ${i === 0 ? 'active' : ''}" data-type="${a}">${{ custom: '✉️ SMS tùy chọn', fixed: '📤 SMS cố định', forward: '📞 Chuyển cuộc gọi' }[a]}</button>`).join('')}</div>` : ''}
                <div id="sendForm"></div>
            </div>` : '';
        box.innerHTML = `
            <div class="card">
                <div class="row between"><b>${esc(o.service_name)}</b><span class="badge ${o.status}">${statusText[o.status] || o.status}</span></div>
                <div class="copy"><div><div class="small muted">Số điện thoại</div><b>${esc(o.phone)}</b></div><button class="btn sm" data-copy="${esc(o.phone)}">Sao chép</button></div>
                <div class="row between small muted"><span>Đơn #${o.id}${o.network ? ' · ' + esc(o.network) : ''}</span><span>${o.by_package ? '1 lượt gói' : money(o.price)}</span></div>
                ${o.status === 'pending' ? `<div class="small muted" style="margin-top:6px">⏱ Còn lại: <b id="countdown">${Math.floor(o.seconds_left / 60)} phút ${o.seconds_left % 60} giây</b></div>` : ''}
                ${o.otp ? `<div class="otp"><div class="small">Mã OTP</div><div class="code">${esc(o.otp)}</div><button class="btn sm ok" data-copy="${esc(o.otp)}">Sao chép OTP</button></div>` : ''}
                ${o.sms ? `<div class="small" style="margin-top:6px">💬 ${esc(o.sms)}</div>` : ''}
                ${!sms && o.status === 'pending' && !o.otp ? '<div class="small muted center" style="padding:10px 0">⏳ Đang chờ OTP... tự cập nhật mỗi 5 giây</div>' : ''}
                ${o.call_logs.length ? `<div class="small" style="margin-top:8px"><b>📞 Cuộc gọi đến</b>${o.call_logs.map((c) => `<div>${esc(c.number)} · ${Number(c.duration || 0)}s · ${fmtTime(c.time)}</div>`).join('')}</div>` : ''}
                ${o.sms_sent ? `<div class="small" style="margin-top:8px">📤 Đã gửi <b>${o.sms_sent}</b> lần${o.sms_closed ? ' · <span style="color:var(--danger)">số đã hết lượt gửi</span>' : ''}</div>` : ''}
            </div>
            ${formHtml}
            ${o.sends.length ? `<div class="card"><h3>Lịch sử gửi</h3><div class="sendlog">${o.sends.map((x) => `<div>${x.ok == 1 ? '✅' : '❌'} ${fmtTime(x.created_at)} → ${esc(x.receiver || 'SMS cố định')} ${x.content ? '"' + esc(x.content) + '"' : ''}${x.error ? ' <span style="color:var(--danger)">' + esc(x.error) + '</span>' : ''}</div>`).join('')}</div></div>` : ''}
            <div class="btns">
                ${o.can_finish ? '<button class="btn ok" id="finish">✅ Xong, kết thúc</button>' : ''}
                ${o.can_cancel ? '<button class="btn danger" id="cancel">❌ Hủy & hoàn tiền</button>' : ''}
                ${o.status !== 'pending' ? `<button class="btn light" id="again">${sms ? '✉️ Thuê số mới' : '🔁 Thuê lại dịch vụ'}</button>` : ''}
            </div>
        `;
        on('[data-copy]', 'click', (e) => copy(e.currentTarget.dataset.copy));
        on('#again', 'click', () => open(views.confirmRent, o.service_id, sms ? 'sms' : ''));
        on('#cancel', 'click', async () => {
            if (!(await confirmBox('Hủy đơn và hoàn tiền?'))) return;
            const r = await api('cancel', { id: o.id });
            if (!r.ok) return alertBox(r.message);
            await refreshUser(); drawOrder(r.order); stopTimer();
        });
        on('#finish', 'click', async () => {
            if (!(await confirmBox('Kết thúc đơn này? Số sẽ không gửi thêm được nữa.'))) return;
            const r = await api('finish', { id: o.id });
            if (r.ok) { drawOrder(r.order); stopTimer(); }
        });
        if (o.actions.length) {
            let type = o.actions[0];
            const drawForm = () => {
                const f = document.getElementById('sendForm');
                f.innerHTML = type === 'fixed'
                    ? '<div class="small muted">Hệ thống sẽ gửi tin nhắn cố định của dịch vụ từ số này.</div><div class="gap"></div><button class="btn" id="send">📤 Gửi SMS</button>'
                    : `<label>${type === 'forward' ? 'Số nhận cuộc gọi chuyển tiếp' : 'Số điện thoại nhận tin'}</label><input id="sNum" type="tel" inputmode="tel" placeholder="0987654321">
                       ${type === 'custom' ? `<label>Nội dung <span id="cnt" class="muted"></span></label><textarea id="sText" maxlength="${state.config.sms_max_length || 300}" placeholder="Nhập nội dung tin nhắn"></textarea>` : ''}
                       <div class="gap"></div><button class="btn" id="send">${type === 'forward' ? '📞 Chuyển tiếp' : '✉️ Gửi tin'}</button>`;
                const t = document.getElementById('sText');
                if (t) t.addEventListener('input', () => { document.getElementById('cnt').textContent = '(' + t.value.length + '/' + (state.config.sms_max_length || 300) + ')'; });
                document.getElementById('send').addEventListener('click', async (e) => {
                    const num = (document.getElementById('sNum') || {}).value || '';
                    const text = (document.getElementById('sText') || {}).value || '';
                    if (type !== 'fixed' && !num.trim()) return alertBox('Vui lòng nhập số điện thoại.');
                    if (type === 'custom' && !text.trim()) return alertBox('Vui lòng nhập nội dung.');
                    e.target.disabled = true;
                    e.target.textContent = '⏳ Đang gửi...';
                    const r = await api('send', { id: o.id, type, number: num, content: text });
                    haptic(r.ok ? 'success' : 'error');
                    r.ok ? toast('✅ ' + r.message) : alertBox(r.message);
                    if (r.order) drawOrder(r.order);
                    if (r.order && r.order.status !== 'pending') { stopTimer(); refreshUser(); }
                });
            };
            on('[data-type]', 'click', (e) => {
                type = e.currentTarget.dataset.type;
                $view.querySelectorAll('[data-type]').forEach((c) => c.classList.toggle('active', c.dataset.type === type));
                drawForm();
            });
            drawForm();
        }
    }

    views.sms = async function () {
        if (!state.config.sms_enabled) { render('<div class="card center">Chức năng gửi tin nhắn đang tạm tắt.</div>'); return; }
        render('<div class="center muted">Đang tải...</div>');
        const list = await serviceList('sms');
        if (!list) return;
        render(`
            <div class="card">
                <h3>✉️ Gửi tin nhắn</h3>
                <div class="small">1️⃣ Chọn dịch vụ → thuê 1 số<br>2️⃣ Nhập số nhận + nội dung → gửi từ số đã thuê<br>3️⃣ Gửi tiếp nhiều lần tới khi hệ thống báo hết, xong bấm <b>Kết thúc</b></div>
                <div class="small muted" style="margin-top:6px">Lượt gói còn: <b>${state.user.credits}</b> · <a href="#" id="toPkg">Mua gói tin</a></div>
            </div>
            <div class="card list">${list.length ? list.map((s) => `
                <div class="item" data-svc="${s.id}">
                    <div class="grow"><div class="name">${esc(s.name)}</div><div class="feat">${Object.values(s.features).map(esc).join(' · ')}</div></div>
                    <div class="price">${s.credits > 0 ? '1 lượt gói' : money(s.price)}</div>
                </div>`).join('') : '<div class="muted small">Chưa có dịch vụ gửi tin.</div>'}</div>
            <button class="btn light" id="smsHist">📜 Lịch sử gửi tin</button>
        `);
        on('[data-svc]', 'click', (e) => open(views.confirmRent, Number(e.currentTarget.dataset.svc), 'sms'));
        on('#toPkg', 'click', (e) => { e.preventDefault(); open(views.packages); });
        on('#smsHist', 'click', () => open(views.history, 'sms'));
    };

    views.deposit = async function () {
        const cfg = state.config;
        if (!cfg.bank_ready) { render('<div class="card center">Chức năng nạp tiền chưa được cấu hình. Liên hệ ' + esc(cfg.support) + '</div>'); return; }
        const amounts = [20000, 50000, 100000, 200000, 500000, 1000000].filter((a) => a >= cfg.min_deposit);
        render(`
            <div class="card">
                <h3>💰 Nạp tiền</h3>
                <div class="small muted">Tối thiểu ${money(cfg.min_deposit)}${cfg.deposit_bonus > 0 ? ' · 🎉 Khuyến mãi +' + cfg.deposit_bonus + '%' : ''}</div>
                <label>Chọn số tiền</label>
                <div class="chips">${amounts.map((a) => `<button class="chip" data-amt="${a}">${money(a)}</button>`).join('')}</div>
                <label>Hoặc nhập số khác</label>
                <input id="amt" type="number" inputmode="numeric" placeholder="Nhập số tiền">
                <div class="gap"></div>
                <button class="btn" id="getQr">Tạo mã QR</button>
            </div>
            <div id="qrBox"></div>
            <div class="card"><h3>Lịch sử nạp</h3><div id="depHist" class="list small muted">Đang tải...</div></div>
        `);
        on('[data-amt]', 'click', (e) => { document.getElementById('amt').value = e.currentTarget.dataset.amt; showQr(); });
        on('#getQr', 'click', showQr);
        async function showQr() {
            const amount = parseInt(document.getElementById('amt').value || '0', 10);
            if (amount < cfg.min_deposit) return alertBox('Số tiền tối thiểu là ' + money(cfg.min_deposit));
            const r = await api('deposit', { amount });
            if (!r.ok) return alertBox(r.message);
            const i = r.info;
            document.getElementById('qrBox').innerHTML = `
                <div class="card">
                    <img class="qr" src="${esc(i.qr_data || i.qr)}" alt="QR" onerror="this.style.display='none'">
                    <div class="copy"><div><div class="small muted">Ngân hàng</div><b>${esc(i.bank_name)}</b></div></div>
                    <div class="copy"><div><div class="small muted">Số tài khoản</div><b>${esc(i.bank_account)}</b></div><button class="btn sm" data-copy="${esc(i.bank_account)}">Sao chép</button></div>
                    <div class="copy"><div><div class="small muted">Chủ tài khoản</div><b>${esc(i.bank_owner)}</b></div></div>
                    <div class="copy"><div><div class="small muted">Số tiền</div><b>${money(i.amount)}</b></div><button class="btn sm" data-copy="${i.amount}">Sao chép</button></div>
                    <div class="copy"><div><div class="small muted">Nội dung chuyển khoản</div><b style="color:var(--danger)">${esc(i.memo)}</b></div><button class="btn sm" data-copy="${esc(i.memo)}">Sao chép</button></div>
                    <div class="small muted">⚠️ Ghi <b>đúng nội dung</b> để được cộng tiền tự động trong 1–2 phút.</div>
                    <div class="gap"></div>
                    <button class="btn light" id="claim">✅ Tôi đã chuyển khoản</button>
                </div>`;
            on('[data-copy]', 'click', (e) => copy(e.currentTarget.dataset.copy));
            on('#claim', 'click', async () => {
                const rr = await api('deposit_claim', { amount: i.amount });
                alertBox(rr.message);
            });
            document.getElementById('qrBox').scrollIntoView({ behavior: 'smooth' });
        }
        const h = await api('deposits');
        const el = document.getElementById('depHist');
        if (el) el.innerHTML = h.ok && h.deposits.length ? h.deposits.map((d) => `<div class="item"><div class="grow">#${d.id} · ${fmtTime(d.created_at)}</div><b>${money(d.amount)}</b> <span class="badge ${d.status === 'approved' ? 'success' : d.status === 'pending' ? 'pending' : 'refunded'}">${{ approved: 'Thành công', pending: 'Chờ duyệt', rejected: 'Từ chối' }[d.status] || d.status}</span></div>`).join('') : 'Chưa có giao dịch nạp.';
    };

    views.packages = async function () {
        render('<div class="center muted">Đang tải...</div>');
        const r = await api('packages');
        if (!r.ok) { render(`<div class="card center">${esc(r.message)}</div>`); return; }
        render(`
            ${r.mine.length ? `<div class="card"><h3>📦 Gói của bạn</h3><div class="list">${r.mine.map((m) => `<div class="item"><div class="grow"><div class="name">${esc(m.name)}</div><div class="small muted">${m.expires_at ? 'HSD ' + fmtTime(m.expires_at) : 'Vĩnh viễn'}</div></div><b>${m.credits_left}/${m.credits_total}</b></div>`).join('')}</div></div>` : ''}
            <div class="card"><h3>Mua gói tin nhắn</h3><div class="small muted">Mỗi lượt = 1 lần thuê số gửi tin, dùng trước số dư.</div>
            <div class="list">${r.packages.length ? r.packages.map((p) => `
                <div class="item">
                    <div class="grow"><div class="name">${esc(p.name)}</div><div class="small muted">${p.credits} lượt · ${Number(p.days_valid) > 0 ? p.days_valid + ' ngày' : 'Vĩnh viễn'} · ${esc(p.service_name || 'Mọi dịch vụ gửi tin')}${p.description ? '<br>' + esc(p.description) : ''}</div></div>
                    <button class="btn sm" data-buy="${p.id}" data-name="${esc(p.name)}" data-price="${p.price}">${money(p.price)}</button>
                </div>`).join('') : '<div class="muted small">Chưa có gói nào.</div>'}</div></div>
        `);
        on('[data-buy]', 'click', async (e) => {
            const b = e.currentTarget.dataset;
            if (Number(b.price) > state.user.balance) { alertBox('Số dư không đủ, vui lòng nạp thêm.'); return go('deposit'); }
            if (!(await confirmBox('Mua ' + b.name + ' giá ' + money(b.price) + '?'))) return;
            const rr = await api('buy_package', { id: Number(b.buy) });
            haptic(rr.ok ? 'success' : 'error');
            alertBox(rr.message);
            if (rr.user) { state.user = rr.user; setBalance(); }
            state.services = {};
            views.packages();
        });
    };

    views.history = async function (kind) {
        render('<div class="center muted">Đang tải...</div>');
        const r = await api('orders', { kind });
        render(`<div class="card"><h3>${kind === 'sms' ? '📜 Lịch sử gửi tin' : '📜 Lịch sử đơn'}</h3><div class="list">${r.ok && r.orders.length ? r.orders.map(orderRow).join('') : '<div class="muted small">Chưa có đơn nào.</div>'}</div></div>`);
        bindOrderRows();
    };

    views.transactions = async function () {
        render('<div class="center muted">Đang tải...</div>');
        const r = await api('transactions');
        render(`<div class="card"><h3>💳 Biến động số dư</h3><div class="list">${r.ok && r.transactions.length ? r.transactions.map((t) => `
            <div class="item"><div class="grow"><div class="small">${esc(t.note)}</div><div class="small muted">${fmtTime(t.created_at)}</div></div>
            <b style="color:${Number(t.amount) >= 0 ? 'var(--ok)' : 'var(--danger)'}">${Number(t.amount) >= 0 ? '+' : ''}${money(t.amount)}</b></div>`).join('') : '<div class="muted small">Chưa có giao dịch.</div>'}</div></div>`);
    };

    views.account = async function () {
        await refreshUser();
        const u = state.user;
        const cfg = state.config;
        render(`
            <div class="card">
                <div class="row"><div class="grow"><b>${esc(u.name)}</b>${u.username ? ' <span class="muted">@' + esc(u.username) + '</span>' : ''}<div class="small muted">ID: ${esc(u.tg_id)}</div></div></div>
                <div class="gap"></div>
                <div class="row between"><span class="muted">Số dư</span><b class="price">${money(u.balance)}</b></div>
                <div class="row between"><span class="muted">Tổng nạp</span><b>${money(u.total_deposit)}</b></div>
                <div class="row between"><span class="muted">Tổng chi</span><b>${money(u.total_spent)}</b></div>
                <div class="row between"><span class="muted">Lượt gói tin</span><b>${u.credits}</b></div>
            </div>
            <div class="card list">
                <div class="item" data-open="history"><div class="grow">📜 Lịch sử đơn</div>›</div>
                <div class="item" data-open="transactions"><div class="grow">💳 Biến động số dư</div>›</div>
                <div class="item" data-open="packages"><div class="grow">📦 Gói tin nhắn</div>›</div>
            </div>
            ${u.ref_link ? `<div class="card"><h3>🎁 Giới thiệu bạn bè</h3>
                <div class="small muted">Nhận ${cfg.referral_percent}% giá trị mỗi đơn thành công của người được giới thiệu. Đã giới thiệu: ${u.ref_count} · Hoa hồng: ${money(u.ref_earned)}</div>
                <div class="copy"><b class="small">${esc(u.ref_link)}</b><button class="btn sm" data-copy="${esc(u.ref_link)}">Sao chép</button></div>
                <button class="btn light" id="share">📤 Chia sẻ link</button></div>` : ''}
            <div class="card small">🆘 Hỗ trợ: <b>${esc(cfg.support)}</b>${cfg.channel ? ` · <a href="#" id="channel">Kênh thông báo</a>` : ''}</div>
        `);
        on('[data-open]', 'click', (e) => open(views[e.currentTarget.dataset.open]));
        on('[data-copy]', 'click', (e) => copy(e.currentTarget.dataset.copy));
        on('#share', 'click', () => {
            const url = 'https://t.me/share/url?url=' + encodeURIComponent(u.ref_link) + '&text=' + encodeURIComponent('Thuê sim nhận OTP tự động 24/7 tại ' + (cfg.shop_name || ''));
            tg ? tg.openTelegramLink(url) : window.open(url);
        });
        on('#channel', 'click', (e) => { e.preventDefault(); tg ? tg.openLink(cfg.channel) : window.open(cfg.channel); });
    };

    // ------------------------------------------------------------ start
    (async function start() {
        if (!tg || !tg.initData) {
            render('<div class="card center">Vui lòng mở ứng dụng từ trong Telegram (nút <b>📲 Mở ứng dụng</b> của bot).</div>');
            return;
        }
        const r = await api('init');
        if (!r.ok) { render(`<div class="card center">${esc(r.message)}</div>`); return; }
        state.user = r.user;
        state.config = r.config;
        document.getElementById('shopName').textContent = r.config.shop_name || 'SIM OTP';
        if (!r.config.sms_enabled) document.getElementById('tabSms').style.display = 'none';
        setBalance();
        go('home');
    })();
})();
