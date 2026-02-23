(() => {
  const ROOT_PATH = (() => {
    if (Object.prototype.hasOwnProperty.call(window, '__QILING_ROOT_PATH__')) {
      const v = String(window.__QILING_ROOT_PATH__ || '');
      return v === '/' ? '' : v.replace(/\/+$/, '');
    }
    const path = String(window.location.pathname || '');
    return path.includes('/pay') ? path.split('/pay')[0] : '';
  })();
  const API_PREFIX = `${ROOT_PATH}/api/v1`;
  const TICKET_KEY = 'qiling_pay_wall_tickets';
  const REFRESH_PENDING_MS = 6000;
  const REFRESH_IDLE_MS = 12000;

  const el = {
    ticketForm: document.getElementById('ticketForm'),
    ticketInput: document.getElementById('ticketInput'),
    payList: document.getElementById('payList'),
    btnRefresh: document.getElementById('btnRefresh'),
    toastContainer: document.getElementById('toastContainer'),
  };

  const state = {
    tickets: [],
    rows: [],
    pendingCount: 0,
    timer: 0,
    loading: false,
    lastSuccessMap: {},
  };

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  function toast(message, type = 'info') {
    const node = document.createElement('div');
    node.className = `toast ${type}`;
    node.textContent = message;
    el.toastContainer.appendChild(node);
    window.setTimeout(() => node.remove(), 2600);
  }

  function formatMoney(v) {
    const n = Number(v || 0);
    const value = Number.isFinite(n) ? n : 0;
    return value.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function statusLabel(status) {
    const map = {
      pending: '待支付',
      success: '支付成功',
      closed: '已关闭',
      failed: '失败',
      refunded: '已退款',
    };
    return map[String(status || '').trim()] || String(status || '-');
  }

  function channelLabel(code) {
    const map = {
      alipay: '支付宝',
      wechat: '微信支付',
    };
    return map[String(code || '').trim()] || String(code || '-');
  }

  function sceneLabel(code) {
    const map = {
      f2f: '当面付',
      page: '网页付',
      web: '网页付',
      wap: 'H5',
      h5: 'H5',
      app: 'APP',
      native: '扫码',
      jsapi: '公众号',
    };
    return map[String(code || '').trim()] || String(code || '-');
  }

  function qrImageUrl(source) {
    const text = String(source || '').trim();
    if (text === '') return '';
    if (text.startsWith('data:image/')) return text;
    return `https://quickchart.io/qr?size=320&margin=1&text=${encodeURIComponent(text)}`;
  }

  function normalizeTickets(input) {
    const raw = Array.isArray(input) ? input.join('\n') : String(input || '');
    const parts = raw.split(/[\n,\s]+/).map((x) => x.trim()).filter(Boolean);
    const uniq = [];
    const seen = {};
    parts.forEach((t) => {
      if (seen[t]) return;
      seen[t] = true;
      uniq.push(t);
    });
    return uniq.slice(0, 40);
  }

  function loadTicketsFromUrlOrStorage() {
    const url = new URL(window.location.href);
    const ticket = String(url.searchParams.get('ticket') || '').trim();
    const tickets = String(url.searchParams.get('tickets') || '').trim();
    const fromUrl = normalizeTickets(`${ticket}\n${tickets}`);
    if (fromUrl.length > 0) {
      localStorage.setItem(TICKET_KEY, JSON.stringify(fromUrl));
      return fromUrl;
    }

    try {
      const raw = String(localStorage.getItem(TICKET_KEY) || '').trim();
      if (raw === '') return [];
      const parsed = JSON.parse(raw);
      return normalizeTickets(Array.isArray(parsed) ? parsed : []);
    } catch (_err) {
      return [];
    }
  }

  function syncUrlTickets(tickets) {
    const list = normalizeTickets(tickets);
    const url = new URL(window.location.href);
    if (list.length === 0) {
      url.searchParams.delete('ticket');
      url.searchParams.delete('tickets');
    } else if (list.length === 1) {
      url.searchParams.set('ticket', list[0]);
      url.searchParams.delete('tickets');
    } else {
      url.searchParams.delete('ticket');
      url.searchParams.set('tickets', list.join(','));
    }
    window.history.replaceState({}, '', url.toString());
  }

  async function requestStatuses(syncPending = true) {
    if (state.tickets.length === 0) {
      return { data: [], errors: [] };
    }

    const resp = await fetch(`${API_PREFIX}/payments/public/statuses`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      cache: 'no-store',
      body: JSON.stringify({
        tickets: state.tickets,
        sync_pending: syncPending ? 1 : 0,
      }),
    });

    let payload = {};
    try {
      payload = await resp.json();
    } catch (_e) {
      payload = {};
    }

    if (!resp.ok) {
      throw new Error(String(payload.message || `HTTP ${resp.status}`));
    }
    return payload;
  }

  function renderRows() {
    if (!Array.isArray(state.rows) || state.rows.length === 0) {
      el.payList.innerHTML = '<div class="empty">暂无支付单。请先在后台创建在线支付并打开支付链接。</div>';
      return;
    }

    const sorted = [...state.rows].sort((a, b) => String(b.created_at || '').localeCompare(String(a.created_at || '')));
    el.payList.innerHTML = `<div class="card-grid">${sorted.map((row) => {
      const paymentNo = String(row.payment_no || '');
      const status = String(row.status || 'pending');
      const qrSource = String(row.qr_code || row.pay_url || '').trim();
      const img = qrImageUrl(qrSource);
      const payUrl = String(row.pay_url || '').trim();
      return `
        <article class="pay-card">
          <div class="pay-card-top">
            <b>${escapeHtml(paymentNo || '-')}</b>
            <span class="chip ${escapeHtml(status)}">${escapeHtml(statusLabel(status))}</span>
          </div>
          <div class="pay-lines">
            <p>订单号：${escapeHtml(row.order_no || '-')} · 门店#${escapeHtml(row.store_id || 0)}</p>
            <p>应付：¥${escapeHtml(formatMoney(row.payable_amount || row.amount || 0))} · 已付：¥${escapeHtml(formatMoney(row.paid_amount || 0))}</p>
            <p>渠道：${escapeHtml(channelLabel(row.channel || ''))} · 场景：${escapeHtml(sceneLabel(row.scene || ''))}</p>
            <p>支付单状态：${escapeHtml(statusLabel(status))} · 订单状态：${escapeHtml(row.order_status || '-')}</p>
          </div>
          <div class="qr-box">
            ${img ? `<img src="${escapeHtml(img)}" alt="支付二维码" />` : '<div class="empty">当前无二维码</div>'}
            ${payUrl ? `<a class="btn light" href="${escapeHtml(payUrl)}" target="_blank" rel="noopener">打开支付页</a>` : ''}
          </div>
        </article>
      `;
    }).join('')}</div>`;
  }

  function clearTimer() {
    if (state.timer) {
      window.clearTimeout(state.timer);
      state.timer = 0;
    }
  }

  function scheduleNext() {
    clearTimer();
    if (state.tickets.length === 0) return;
    const interval = state.pendingCount > 0 ? REFRESH_PENDING_MS : REFRESH_IDLE_MS;
    state.timer = window.setTimeout(() => {
      refresh(false);
    }, interval);
  }

  async function refresh(forceToast = false) {
    if (state.loading) return;
    state.loading = true;
    if (el.btnRefresh) el.btnRefresh.disabled = true;

    try {
      const res = await requestStatuses(true);
      const rows = Array.isArray(res.data) ? res.data : [];
      const errors = Array.isArray(res.errors) ? res.errors : [];
      const before = state.pendingCount;

      state.rows = rows;
      state.pendingCount = rows.filter((x) => String(x.status || '') === 'pending').length;

      rows.forEach((row) => {
        const no = String(row.payment_no || '');
        if (!no) return;
        const prev = String(state.lastSuccessMap[no] || '');
        const now = String(row.status || '');
        if (prev !== 'success' && now === 'success') {
          toast(`支付成功：${no}`, 'ok');
        }
        state.lastSuccessMap[no] = now;
      });

      renderRows();

      if (forceToast) {
        toast('已刷新支付状态', 'ok');
      } else if (before > state.pendingCount) {
        toast('支付状态已自动更新', 'ok');
      }

      if (errors.length > 0) {
        const bad = errors[0];
        toast(`部分支付单读取失败：${bad.message || '未知错误'}`, 'info');
      }
    } catch (err) {
      toast(err && err.message ? err.message : '刷新失败', 'error');
    } finally {
      state.loading = false;
      if (el.btnRefresh) el.btnRefresh.disabled = false;
      scheduleNext();
    }
  }

  function bindEvents() {
    el.ticketForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const tickets = normalizeTickets(el.ticketInput.value || '');
      state.tickets = tickets;
      localStorage.setItem(TICKET_KEY, JSON.stringify(tickets));
      syncUrlTickets(tickets);
      state.rows = [];
      state.pendingCount = 0;
      renderRows();
      if (tickets.length === 0) {
        toast('请输入至少一个 ticket', 'error');
        clearTimer();
        return;
      }
      refresh(true);
    });

    if (el.btnRefresh) {
      el.btnRefresh.addEventListener('click', () => {
        refresh(true);
      });
    }
  }

  function bootstrap() {
    bindEvents();
    state.tickets = loadTicketsFromUrlOrStorage();
    el.ticketInput.value = state.tickets.join('\n');
    syncUrlTickets(state.tickets);
    renderRows();
    if (state.tickets.length > 0) {
      refresh(false);
    }
  }

  bootstrap();
})();
