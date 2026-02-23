(() => {
  const PATHNAME = window.location.pathname;
  const ROOT_PATH = (() => {
    if (Object.prototype.hasOwnProperty.call(window, '__QILING_ROOT_PATH__')) {
      const v = String(window.__QILING_ROOT_PATH__ || '');
      return v === '/' ? '' : v.replace(/\/+$/, '');
    }
    return PATHNAME.includes('/customer') ? PATHNAME.split('/customer')[0] : '';
  })();
  const API_PREFIX = `${ROOT_PATH}/api/v1`;
  const TOKEN_KEY = 'qiling_customer_portal_token';
  const PAYMENT_DRAFT_KEY = 'qiling_customer_payment_draft';
  const AUTO_REFRESH_MS = 8000;
  const AUTO_REFRESH_IDLE_MS = 10000;

  const el = {
    entryScreen: document.getElementById('entryScreen'),
    entryForm: document.getElementById('entryForm'),
    entryToken: document.getElementById('entryToken'),
    entryBtn: document.getElementById('entryBtn'),
    appScreen: document.getElementById('appScreen'),
    metaLine: document.getElementById('metaLine'),
    btnReload: document.getElementById('btnReload'),
    btnLogout: document.getElementById('btnLogout'),
    kpiGrid: document.getElementById('kpiGrid'),
    profilePanel: document.getElementById('profilePanel'),
    memberCardTable: document.getElementById('memberCardTable'),
    couponTable: document.getElementById('couponTable'),
    consumeTable: document.getElementById('consumeTable'),
    appointmentPanel: document.getElementById('appointmentPanel'),
    orderTable: document.getElementById('orderTable'),
    paymentPanel: document.getElementById('paymentPanel'),
    toastContainer: document.getElementById('toastContainer'),
  };

  const state = {
    token: localStorage.getItem(TOKEN_KEY) || '',
    tokenVisible: false,
    payload: null,
    paymentDraft: loadPaymentDraft(),
    appointmentDraft: {
      service_id: 0,
      start_at: '',
      duration_minutes: 60,
      notes: '',
    },
    highlightedPaymentNo: '',
    autoRefreshTimer: 0,
    autoRefreshBusy: false,
    pendingCount: 0,
  };

  function loadPaymentDraft() {
    try {
      const raw = String(localStorage.getItem(PAYMENT_DRAFT_KEY) || '').trim();
      if (raw === '') {
        return { order_id: 0, channel: '', scene: '', openid: '' };
      }
      const parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== 'object') {
        return { order_id: 0, channel: '', scene: '', openid: '' };
      }
      return {
        order_id: Number.isFinite(Number(parsed.order_id)) ? Math.trunc(Number(parsed.order_id)) : 0,
        channel: String(parsed.channel || '').trim(),
        scene: String(parsed.scene || '').trim(),
        openid: String(parsed.openid || '').trim(),
      };
    } catch (_err) {
      return { order_id: 0, channel: '', scene: '', openid: '' };
    }
  }

  function persistPaymentDraft() {
    localStorage.setItem(PAYMENT_DRAFT_KEY, JSON.stringify(state.paymentDraft || {}));
  }

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

  function toInt(v, fallback = 0) {
    const n = Number(v);
    return Number.isFinite(n) ? Math.trunc(n) : fallback;
  }

  function maskToken(token) {
    const raw = String(token || '').trim();
    if (raw === '') return '-';
    if (raw.length <= 10) {
      return `${raw.slice(0, 2)}****${raw.slice(-2)}`;
    }
    return `${raw.slice(0, 6)}****${raw.slice(-4)}`;
  }

  function buildPortalUrl(token) {
    const value = String(token || '').trim();
    if (value === '') return '';
    return `${window.location.origin}${ROOT_PATH}/customer/?token=${encodeURIComponent(value)}`;
  }

  async function copyText(text) {
    const value = String(text || '').trim();
    if (value === '') {
      throw new Error('没有可复制内容');
    }

    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(value);
      return;
    }

    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', 'readonly');
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    const success = document.execCommand('copy');
    document.body.removeChild(textarea);
    if (!success) {
      throw new Error('复制失败，请手动复制');
    }
  }

  function showEntry() {
    el.entryScreen.classList.remove('hidden');
    el.appScreen.classList.add('hidden');
  }

  function showApp() {
    el.entryScreen.classList.add('hidden');
    el.appScreen.classList.remove('hidden');
  }

  function normalizeErrorMessage(rawMessage) {
    const raw = String(rawMessage || '').trim();
    if (raw === '') return '操作失败，请稍后重试';

    const exact = {
      'portal token is required': '请使用门店二维码重新进入',
      'portal token invalid or expired': '入口已失效，请联系门店重新获取二维码',
      'customer account disabled': '账户状态不可用，请联系门店',
      'order_id is required': '请选择要支付的订单',
      'order not found': '订单不存在或不属于当前客户',
      'order already fully paid': '该订单已支付完成',
      'payment_no is required': '支付单号不能为空',
      'payment not found': '未找到对应支付单',
      'online payment is not enabled': '门店暂未开启在线支付',
      'payment channel unavailable': '当前支付方式不可用，请切换其他方式',
      'payment scene unavailable': '当前支付场景不可用，请切换后重试',
      'openid is required for selected payment scene': '当前支付场景需要 openid，请联系门店处理',
      'alipay not enabled': '支付宝支付未开启',
      'wechat not enabled': '微信支付未开启',
      'order status does not allow online payment': '当前订单状态不支持在线支付',
      'order does not belong to token store': '订单不属于当前门店，无法支付',
      'service_id is required': '请选择预约项目',
      'start_at is required': '请选择预约开始时间',
      'invalid start_at or end_at': '预约时间格式不正确，请重新选择',
      'service not found or online booking disabled': '该项目暂不支持在线预约',
      'customer appointment time conflict': '该时段你已有预约，请更换时间',
      'appointment time must be in the future': '预约时间需晚于当前时间',
      'create customer portal appointment failed': '创建预约失败，请稍后再试',
      'new_token must be 4-6 digits': '新口令必须为4-6位数字',
      'token must be 4-6 digits': '口令必须为4-6位数字',
      'new_token must be different from current token': '新口令不能与当前口令相同',
      'token already exists': '该口令已被占用，请换一个',
    };
    if (Object.prototype.hasOwnProperty.call(exact, raw)) {
      return exact[raw];
    }

    const lower = raw.toLowerCase();
    if (lower.includes('jsapi') && lower.includes('openid')) return '该支付场景需 openid，请联系门店处理';
    if (lower.includes('required')) return '缺少必要参数，请检查后重试';
    if (lower.includes('not found')) return '数据不存在，请刷新后重试';
    if (lower.includes('invalid')) return '参数不正确，请检查后重试';
    if (lower.includes('failed')) return '请求处理失败，请稍后重试';
    return raw;
  }

  async function requestJson(path, options = {}) {
    const method = String(options.method || 'GET').toUpperCase();
    const url = new URL(`${API_PREFIX}${path}`, window.location.origin);

    const query = options.query && typeof options.query === 'object' ? options.query : null;
    if (query) {
      Object.entries(query).forEach(([key, value]) => {
        const text = String(value ?? '').trim();
        if (text !== '') {
          url.searchParams.set(key, text);
        }
      });
    }

    const headers = {};
    const fetchOptions = { method, headers, cache: 'no-store' };
    if (method !== 'GET' && method !== 'HEAD') {
      headers['Content-Type'] = 'application/json';
      fetchOptions.body = JSON.stringify(options.body || {});
    }

    const resp = await fetch(url.toString(), fetchOptions);

    let payload = {};
    try {
      payload = await resp.json();
    } catch (_e) {
      payload = {};
    }

    if (!resp.ok) {
      throw new Error(normalizeErrorMessage(payload.message || `HTTP ${resp.status}`));
    }

    return payload;
  }

  async function requestOverview() {
    const token = String(state.token || '').trim();
    if (!token) {
      throw new Error('缺少访问口令');
    }

    return requestJson('/customer-portal/overview', {
      method: 'GET',
      query: { token },
    });
  }

  async function createPortalPayment(payload) {
    return requestJson('/customer-portal/payments/create', {
      method: 'POST',
      body: payload,
    });
  }

  async function syncPortalPayment(payload) {
    return requestJson('/customer-portal/payments/sync', {
      method: 'POST',
      body: payload,
    });
  }

  async function syncPortalPendingPayments(limit = 8) {
    return requestJson('/customer-portal/payments/sync-pending', {
      method: 'POST',
      body: {
        token: state.token,
        limit: toInt(limit, 8),
      },
    });
  }

  async function createPortalAppointment(payload) {
    return requestJson('/customer-portal/appointments/create', {
      method: 'POST',
      body: payload,
    });
  }

  async function rotatePortalToken(payload) {
    return requestJson('/customer-portal/token/rotate', {
      method: 'POST',
      body: payload,
    });
  }

  function renderTable(columns, rows, emptyText = '暂无数据') {
    if (!Array.isArray(rows) || rows.length === 0) {
      return `<div class="empty">${escapeHtml(emptyText)}</div>`;
    }

    const head = columns.map((c) => `<th>${escapeHtml(c.label)}</th>`).join('');
    const body = rows.map((row) => {
      const cells = columns.map((c) => {
        const raw = typeof c.get === 'function' ? c.get(row) : row[c.key];
        return `<td>${escapeHtml(raw === null || typeof raw === 'undefined' ? '-' : String(raw))}</td>`;
      }).join('');
      return `<tr>${cells}</tr>`;
    }).join('');

    return `<div class="table-wrap"><table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`;
  }

  function paymentStatusLabel(status) {
    const map = {
      pending: '待支付',
      success: '支付成功',
      closed: '已关闭',
      failed: '失败',
      refunded: '已退款',
    };
    return map[String(status || '').trim()] || String(status || '-');
  }

  function orderStatusLabel(status, payableAmount, paidAmount) {
    const payable = Number(payableAmount || 0);
    const paid = Number(paidAmount || 0);
    const total = Number.isFinite(payable) ? payable : 0;
    const done = Number.isFinite(paid) ? paid : 0;

    if (total > 0 && done >= total - 0.001) return '已支付';
    if (done > 0.001) return '部分支付';

    const map = {
      pending: '待支付',
      partially_paid: '部分支付',
      paid: '已支付',
      refunded: '已退款',
      cancelled: '已取消',
      completed: '已完成',
    };
    return map[String(status || '').trim()] || String(status || '-');
  }

  function channelLabel(code) {
    const map = {
      alipay: '支付宝',
      wechat: '微信',
    };
    return map[String(code || '').trim()] || String(code || '-');
  }

  function sceneLabel(code) {
    const map = {
      f2f: '当面付二维码',
      page: '网页支付',
      web: '网页支付',
      wap: 'H5支付',
      h5: 'H5支付',
      app: 'App支付',
      native: '扫码支付',
      jsapi: '公众号支付',
    };
    return map[String(code || '').trim()] || String(code || '-');
  }

  function appointmentStatusLabel(code) {
    const map = {
      booked: '已预约',
      completed: '已完成',
      cancelled: '已取消',
      no_show: '未到店',
    };
    return map[String(code || '').trim()] || String(code || '-');
  }

  function toDatetimeLocalValue(value) {
    const raw = String(value || '').trim();
    if (raw === '') return '';
    if (raw.includes('T')) return raw.slice(0, 16);
    if (raw.includes(' ')) return raw.replace(' ', 'T').slice(0, 16);
    return '';
  }

  function nextDefaultAppointmentTime() {
    const now = new Date();
    now.setMinutes(0, 0, 0);
    now.setHours(now.getHours() + 1);
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hour = String(now.getHours()).padStart(2, '0');
    return `${year}-${month}-${day}T${hour}:00`;
  }

  function buildQrImageUrl(content) {
    const text = String(content || '').trim();
    if (text === '') return '';
    if (text.startsWith('data:image/')) return text;
    if (text.startsWith('http://') || text.startsWith('https://')) {
      return `https://quickchart.io/qr?size=320&margin=1&text=${encodeURIComponent(text)}`;
    }
    return `https://quickchart.io/qr?size=320&margin=1&text=${encodeURIComponent(text)}`;
  }

  function renderAppointmentPanel(payload) {
    if (!el.appointmentPanel) {
      return;
    }

    const services = Array.isArray(payload.available_services) ? payload.available_services : [];
    const appointments = Array.isArray(payload.appointments) ? payload.appointments : [];

    if (toInt(state.appointmentDraft.service_id, 0) <= 0 && services.length > 0) {
      state.appointmentDraft.service_id = toInt(services[0].id, 0);
    }
    if (!state.appointmentDraft.start_at) {
      state.appointmentDraft.start_at = nextDefaultAppointmentTime();
    }

    const serviceOptions = services.map((row) => {
      const id = toInt(row.id, 0);
      const name = String(row.service_name || `服务#${id}`);
      const category = String(row.category || '').trim();
      const duration = toInt(row.duration_minutes, 60);
      const suffix = category ? ` · ${category}` : '';
      return `<option value="${escapeHtml(id)}">${escapeHtml(`${name}${suffix}（${duration}分钟）`)}</option>`;
    }).join('');

    const rowsHtml = appointments.length === 0
      ? '<div class="empty">暂无预约记录</div>'
      : appointments.map((row) => {
        const serviceName = String(row.service_name || `服务#${toInt(row.service_id, 0)}`);
        const staffName = String(row.staff_name || '').trim();
        return `
          <article class="appointment-record">
            <div class="appointment-record-top">
              <b>${escapeHtml(String(row.appointment_no || '-'))}</b>
              <span class="chip-status ${escapeHtml(String(row.status || 'booked'))}">${escapeHtml(appointmentStatusLabel(row.status || ''))}</span>
            </div>
            <p>项目：${escapeHtml(serviceName)}${String(row.category || '').trim() ? ` · ${escapeHtml(String(row.category || ''))}` : ''}</p>
            <p>预约时间：${escapeHtml(String(row.start_at || '-'))} - ${escapeHtml(String(row.end_at || '-'))}</p>
            <p>服务人员：${escapeHtml(staffName || '待安排')}</p>
          </article>
        `;
      }).join('');

    const formHtml = services.length === 0
      ? '<div class="empty">门店暂未开放可在线预约项目，请联系门店客服。</div>'
      : `
        <form id="portalAppointmentForm" class="pay-form">
          <div class="pay-row">
            <label>
              <span>预约项目</span>
              <select name="service_id">${serviceOptions}</select>
            </label>
            <label>
              <span>开始时间</span>
              <input type="datetime-local" name="start_at" value="${escapeHtml(toDatetimeLocalValue(state.appointmentDraft.start_at))}" />
            </label>
            <label>
              <span>时长（分钟）</span>
              <input type="number" name="duration_minutes" min="15" max="300" step="5" value="${escapeHtml(toInt(state.appointmentDraft.duration_minutes, 60))}" />
            </label>
          </div>
          <div class="pay-row">
            <label>
              <span>预约备注（可选）</span>
              <input name="notes" placeholder="如：希望安排女老师" value="${escapeHtml(String(state.appointmentDraft.notes || ''))}" />
            </label>
          </div>
          <div class="pay-row">
            <button class="btn primary" type="submit">提交预约</button>
          </div>
        </form>
      `;

    el.appointmentPanel.innerHTML = `
      <section class="pay-section">
        <h4>提交预约</h4>
        ${formHtml}
      </section>
      <section class="pay-section">
        <h4>我的预约记录</h4>
        <div class="appointment-record-list">${rowsHtml}</div>
      </section>
    `;

    const form = document.getElementById('portalAppointmentForm');
    if (form) {
      const serviceField = form.querySelector('[name="service_id"]');
      const startField = form.querySelector('[name="start_at"]');
      const durationField = form.querySelector('[name="duration_minutes"]');
      const notesField = form.querySelector('[name="notes"]');

      if (serviceField) {
        serviceField.value = String(toInt(state.appointmentDraft.service_id, toInt(services[0].id, 0)));
        serviceField.addEventListener('change', () => {
          state.appointmentDraft.service_id = toInt(serviceField.value, 0);
        });
      }
      if (startField) {
        startField.addEventListener('change', () => {
          state.appointmentDraft.start_at = String(startField.value || '').trim();
        });
      }
      if (durationField) {
        durationField.addEventListener('change', () => {
          state.appointmentDraft.duration_minutes = toInt(durationField.value, 60);
        });
      }
      if (notesField) {
        notesField.addEventListener('change', () => {
          state.appointmentDraft.notes = String(notesField.value || '').trim();
        });
      }

      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const serviceId = toInt(serviceField ? serviceField.value : 0, 0);
        const startAt = String(startField ? startField.value : '').trim();
        const durationMinutes = toInt(durationField ? durationField.value : 60, 60);
        const notes = String(notesField ? notesField.value : '').trim();

        if (serviceId <= 0) {
          toast('请选择预约项目', 'error');
          return;
        }
        if (startAt === '') {
          toast('请选择预约开始时间', 'error');
          return;
        }

        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;
        try {
          await createPortalAppointment({
            token: state.token,
            service_id: serviceId,
            start_at: startAt,
            duration_minutes: Math.max(15, durationMinutes),
            notes,
          });
          state.appointmentDraft.service_id = serviceId;
          state.appointmentDraft.start_at = startAt;
          state.appointmentDraft.duration_minutes = Math.max(15, durationMinutes);
          state.appointmentDraft.notes = notes;
          await loadAndRender();
          toast('预约已提交，门店会尽快确认', 'ok');
        } catch (err) {
          toast(err.message || '预约失败', 'error');
        } finally {
          if (submitBtn) submitBtn.disabled = false;
        }
      });
    }
  }

  function findPaymentByNo(paymentNo) {
    const rows = Array.isArray(state.payload && state.payload.online_payments)
      ? state.payload.online_payments
      : [];
    return rows.find((x) => String(x.payment_no || '') === String(paymentNo || '')) || null;
  }

  function pendingPaymentCount(payload) {
    const rows = Array.isArray(payload && payload.online_payments) ? payload.online_payments : [];
    return rows.filter((x) => String(x.status || '') === 'pending').length;
  }

  function buildOnlinePaymentList(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
      return '<div class="empty">暂无在线支付单</div>';
    }

    return rows.map((row) => {
      const paymentNo = String(row.payment_no || '');
      const orderNo = String(row.order_no || `#${toInt(row.order_id, 0)}`);
      const payUrl = String(row.pay_url || '').trim();
      const qrCode = String(row.qr_code || '').trim();
      const actions = [];
      if (payUrl !== '') {
        actions.push(`<a class="btn light" href="${escapeHtml(payUrl)}" target="_blank" rel="noopener">立即支付</a>`);
      }
      if (qrCode !== '') {
        actions.push(`<button class="btn light" type="button" data-payment-qr="${escapeHtml(paymentNo)}">查看二维码</button>`);
      }
      if (String(row.status || '') !== 'success') {
        actions.push(`<button class="btn light" type="button" data-payment-sync="${escapeHtml(paymentNo)}">补偿查单</button>`);
      }

      return `
        <article class="pay-record">
          <div class="pay-record-top">
            <b>${escapeHtml(paymentNo || '-')}</b>
            <span class="chip-status ${escapeHtml(String(row.status || 'pending'))}">${escapeHtml(paymentStatusLabel(row.status || ''))}</span>
          </div>
          <p>订单：${escapeHtml(orderNo)} · 渠道：${escapeHtml(channelLabel(row.channel || ''))} · 场景：${escapeHtml(sceneLabel(row.scene || ''))}</p>
          <p>金额：¥${escapeHtml(formatMoney(row.amount || 0))} · 创建：${escapeHtml(row.created_at || '-')}</p>
          <div class="pay-record-actions">${actions.join('')}</div>
        </article>
      `;
    }).join('');
  }

  function renderPaymentPanel(payload) {
    if (!el.paymentPanel) {
      return;
    }

    const orders = Array.isArray(payload.orders) ? payload.orders : [];
    const onlinePayments = Array.isArray(payload.online_payments) ? payload.online_payments : [];
    const channels = Array.isArray(payload.payment_channels) ? payload.payment_channels : [];

    const unpaidOrders = orders
      .map((row) => {
        const payable = Number(row.payable_amount || 0);
        const paid = Number(row.paid_amount || 0);
        const outstanding = Math.max(0, (Number.isFinite(payable) ? payable : 0) - (Number.isFinite(paid) ? paid : 0));
        return { ...row, outstanding };
      })
      .filter((row) => row.outstanding > 0.001 && !['cancelled', 'refunded'].includes(String(row.status || '')));

    const orderOptions = unpaidOrders
      .map((row) => `<option value="${escapeHtml(row.id)}">${escapeHtml(row.order_no || `订单#${row.id}`)}（待付 ¥${escapeHtml(formatMoney(row.outstanding || 0))}）</option>`)
      .join('');

    const unpaidOrderIds = new Set(unpaidOrders.map((row) => toInt(row.id, 0)));
    if (!unpaidOrderIds.has(toInt(state.paymentDraft.order_id, 0))) {
      state.paymentDraft.order_id = unpaidOrders.length > 0 ? toInt(unpaidOrders[0].id, 0) : 0;
    }

    const selectedOrderId = toInt(state.paymentDraft.order_id, 0);

    const channelMap = new Map();
    channels.forEach((row) => {
      if (!row || typeof row !== 'object') return;
      const code = String(row.code || '').trim();
      if (code === '') return;
      channelMap.set(code, row);
    });

    if (!channelMap.has(state.paymentDraft.channel)) {
      state.paymentDraft.channel = channels.length > 0 ? String(channels[0].code || '') : '';
    }

    const selectedChannel = channelMap.get(state.paymentDraft.channel);
    const scenes = selectedChannel && Array.isArray(selectedChannel.scenes) ? selectedChannel.scenes : [];
    const sceneMap = new Map();
    scenes.forEach((row) => {
      const code = String((row && row.code) || '').trim();
      if (code === '') return;
      sceneMap.set(code, row);
    });

    if (!sceneMap.has(state.paymentDraft.scene)) {
      const defaultScene = String((selectedChannel && selectedChannel.default_scene) || '').trim();
      state.paymentDraft.scene = sceneMap.has(defaultScene) ? defaultScene : (scenes[0] ? String(scenes[0].code || '') : '');
    }

    const selectedScene = sceneMap.get(state.paymentDraft.scene);
    const requiresOpenid = toInt(selectedScene && selectedScene.requires_openid, 0) === 1;

    const sceneOptions = scenes
      .map((row) => `<option value="${escapeHtml(row.code)}">${escapeHtml(row.name || sceneLabel(row.code))}</option>`)
      .join('');

    const channelOptions = channels
      .map((row) => `<option value="${escapeHtml(row.code)}">${escapeHtml(row.name || channelLabel(row.code))}</option>`)
      .join('');

    const activePaymentNo = String(state.highlightedPaymentNo || '').trim();
    const activePayment = activePaymentNo !== '' ? findPaymentByNo(activePaymentNo) : null;
    const activeQrSource = activePayment ? String(activePayment.qr_code || '').trim() : '';
    const activeQrImage = buildQrImageUrl(activeQrSource);

    const selectedOrderPayments = selectedOrderId > 0
      ? onlinePayments.filter((x) => toInt(x.order_id, 0) === selectedOrderId)
      : onlinePayments;

    persistPaymentDraft();

    const noConfigText = channels.length === 0
      ? '<div class="empty">门店暂未开启在线支付，请联系门店工作人员。</div>'
      : '';

    const createBlock = unpaidOrders.length === 0
      ? '<div class="empty">当前暂无待支付订单。</div>'
      : channels.length === 0
        ? '<div class="empty">门店暂未开放在线支付方式，请联系门店处理。</div>'
      : `
        <form id="portalPayCreateForm" class="pay-form">
          <div class="pay-row">
            <label>
              <span>待支付订单</span>
              <select name="order_id">${orderOptions}</select>
            </label>
            <label>
              <span>支付方式</span>
              <select name="channel">${channelOptions}</select>
            </label>
            <label>
              <span>支付场景</span>
              <select name="scene">${sceneOptions}</select>
            </label>
          </div>
          <div class="pay-row${requiresOpenid ? '' : ' hidden'}" id="portalOpenidRow">
            <label>
              <span>openid</span>
              <input name="openid" placeholder="公众号支付需要 openid" value="${escapeHtml(state.paymentDraft.openid || '')}" />
            </label>
          </div>
          <div class="pay-row">
            <button class="btn primary" type="submit">创建支付单并拉起支付</button>
          </div>
          <p class="hint">创建后可直接点击支付链接，或展示二维码由支付宝/微信扫码支付；支付成功将自动回调并更新状态。</p>
        </form>
      `;

    const qrPreview = activePayment
      ? `
        <div class="pay-preview">
          <h4>支付单 ${escapeHtml(activePayment.payment_no || '-')}</h4>
          <p>状态：${escapeHtml(paymentStatusLabel(activePayment.status || ''))} · 金额：¥${escapeHtml(formatMoney(activePayment.amount || 0))}</p>
          ${activeQrImage ? `<img src="${escapeHtml(activeQrImage)}" alt="支付二维码" />` : '<p class="hint">该支付单当前无二维码，可使用支付链接完成支付。</p>'}
          <div class="pay-preview-actions">
            ${String(activePayment.pay_url || '').trim() !== '' ? `<a class="btn primary" href="${escapeHtml(activePayment.pay_url)}" target="_blank" rel="noopener">立即支付</a>` : ''}
            ${String(activePayment.status || '') !== 'success' ? `<button class="btn light" type="button" data-payment-sync="${escapeHtml(activePayment.payment_no || '')}">补偿查单</button>` : ''}
          </div>
        </div>
      `
      : '<div class="empty">可在下方支付单记录中点击“查看二维码”。</div>';

    el.paymentPanel.innerHTML = `
      ${noConfigText}
      <section class="pay-section">
        <h4>发起在线支付</h4>
        ${createBlock}
      </section>
      <section class="pay-section">
        <h4>支付单记录${selectedOrderId > 0 ? `（订单 #${escapeHtml(selectedOrderId)}）` : ''}</h4>
        <div class="pay-record-list">${buildOnlinePaymentList(selectedOrderPayments)}</div>
      </section>
      <section class="pay-section">
        <h4>二维码与支付链接</h4>
        ${qrPreview}
      </section>
    `;

    bindPaymentPanelEvents();

    const form = document.getElementById('portalPayCreateForm');
    if (form) {
      const orderField = form.querySelector('[name="order_id"]');
      const channelField = form.querySelector('[name="channel"]');
      const sceneField = form.querySelector('[name="scene"]');
      const openidField = form.querySelector('[name="openid"]');

      if (orderField) orderField.value = String(selectedOrderId || '');
      if (channelField) channelField.value = String(state.paymentDraft.channel || '');
      if (sceneField) sceneField.value = String(state.paymentDraft.scene || '');
      if (openidField) openidField.value = String(state.paymentDraft.openid || '');
    }
  }

  function bindPaymentPanelEvents() {
    if (!el.paymentPanel) return;

    const form = document.getElementById('portalPayCreateForm');
    if (form) {
      const orderField = form.querySelector('[name="order_id"]');
      const channelField = form.querySelector('[name="channel"]');
      const sceneField = form.querySelector('[name="scene"]');
      const openidField = form.querySelector('[name="openid"]');

      if (orderField) {
        orderField.addEventListener('change', () => {
          state.paymentDraft.order_id = toInt(orderField.value, 0);
          persistPaymentDraft();
          renderPaymentPanel(state.payload || {});
        });
      }

      if (channelField) {
        channelField.addEventListener('change', () => {
          state.paymentDraft.channel = String(channelField.value || '').trim();
          state.paymentDraft.scene = '';
          persistPaymentDraft();
          renderPaymentPanel(state.payload || {});
        });
      }

      if (sceneField) {
        sceneField.addEventListener('change', () => {
          state.paymentDraft.scene = String(sceneField.value || '').trim();
          persistPaymentDraft();
          renderPaymentPanel(state.payload || {});
        });
      }

      if (openidField) {
        openidField.addEventListener('change', () => {
          state.paymentDraft.openid = String(openidField.value || '').trim();
          persistPaymentDraft();
        });
      }

      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const orderId = toInt(orderField ? orderField.value : 0, 0);
        const channel = String(channelField ? channelField.value : '').trim();
        const scene = String(sceneField ? sceneField.value : '').trim();
        const openid = String(openidField ? openidField.value : '').trim();

        if (orderId <= 0) {
          toast('请选择要支付的订单', 'error');
          return;
        }
        if (channel === '') {
          toast('请选择支付方式', 'error');
          return;
        }

        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        try {
          const res = await createPortalPayment({
            token: state.token,
            order_id: orderId,
            channel,
            scene,
            openid,
          });
          const payment = (res && res.payment) || {};
          state.highlightedPaymentNo = String(payment.payment_no || '').trim();
          state.paymentDraft.order_id = orderId;
          state.paymentDraft.channel = channel;
          state.paymentDraft.scene = scene;
          state.paymentDraft.openid = openid;
          persistPaymentDraft();
          const payUrl = String(payment.pay_url || '').trim();

          await loadAndRender();
          if (payUrl !== '') {
            window.open(payUrl, '_blank', 'noopener');
          }
          toast('支付单已创建，请继续支付', 'ok');
        } catch (err) {
          toast(err.message || '创建支付单失败', 'error');
        } finally {
          if (submitBtn) submitBtn.disabled = false;
        }
      });
    }

    el.paymentPanel.querySelectorAll('[data-payment-qr]').forEach((btn) => {
      btn.addEventListener('click', () => {
        state.highlightedPaymentNo = String(btn.getAttribute('data-payment-qr') || '').trim();
        renderPaymentPanel(state.payload || {});
      });
    });

    el.paymentPanel.querySelectorAll('[data-payment-sync]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const paymentNo = String(btn.getAttribute('data-payment-sync') || '').trim();
        if (paymentNo === '') return;

        btn.disabled = true;
        try {
          await syncPortalPayment({
            token: state.token,
            payment_no: paymentNo,
          });
          state.highlightedPaymentNo = paymentNo;
          await loadAndRender();
          toast('支付状态已刷新', 'ok');
        } catch (err) {
          toast(err.message || '刷新支付状态失败', 'error');
        } finally {
          btn.disabled = false;
        }
      });
    });
  }

  function bindProfilePanelEvents() {
    const tokenText = document.getElementById('portalTokenText');
    const btnToggle = document.getElementById('btnPortalTokenToggle');
    const btnCopyToken = document.getElementById('btnPortalTokenCopy');
    const btnCopyLink = document.getElementById('btnPortalLinkCopy');
    const tokenForm = document.getElementById('portalTokenRotateForm');
    const tokenInput = tokenForm ? tokenForm.querySelector('input[name="new_token"]') : null;
    const btnGenerate = document.getElementById('btnPortalTokenGenerate');

    const refreshTokenDisplay = () => {
      if (!tokenText) return;
      tokenText.textContent = state.tokenVisible ? String(state.token || '-') : maskToken(state.token);
      if (btnToggle) {
        btnToggle.textContent = state.tokenVisible ? '隐藏口令' : '显示口令';
      }
    };

    refreshTokenDisplay();

    if (btnToggle) {
      btnToggle.addEventListener('click', () => {
        state.tokenVisible = !state.tokenVisible;
        refreshTokenDisplay();
      });
    }

    if (btnCopyToken) {
      btnCopyToken.addEventListener('click', async () => {
        try {
          await copyText(state.token);
          toast('口令已复制', 'ok');
        } catch (err) {
          toast(err.message || '复制失败', 'error');
        }
      });
    }

    if (btnCopyLink) {
      btnCopyLink.addEventListener('click', async () => {
        try {
          await copyText(buildPortalUrl(state.token));
          toast('入口链接已复制', 'ok');
        } catch (err) {
          toast(err.message || '复制失败', 'error');
        }
      });
    }

    if (btnGenerate && tokenInput) {
      btnGenerate.addEventListener('click', () => {
        tokenInput.value = '';
        tokenInput.focus();
        toast('留空提交将自动生成新口令', 'info');
      });
    }

    if (tokenForm) {
      tokenForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const newToken = String(tokenInput ? tokenInput.value : '').trim();
        if (newToken && !/^\d{4,6}$/.test(newToken)) {
          toast('新口令必须为4-6位数字', 'error');
          return;
        }
        const submitBtn = tokenForm.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        try {
          const res = await rotatePortalToken({
            token: state.token,
            new_token: newToken,
          });
          const nextToken = String((res && res.token) || '').trim();
          if (nextToken === '') {
            throw new Error('口令更新成功，但未返回新口令');
          }
          saveToken(nextToken);
          state.tokenVisible = true;
          if (tokenInput) tokenInput.value = '';
          if (el.entryToken) el.entryToken.value = nextToken;
          await loadAndRender();
          toast('访问口令已更新', 'ok');
        } catch (err) {
          toast(err.message || '更新口令失败', 'error');
        } finally {
          if (submitBtn) submitBtn.disabled = false;
        }
      });
    }
  }

  function render() {
    const payload = state.payload || {};
    const profile = payload.profile || {};
    const wallet = payload.wallet || {};
    const cards = Array.isArray(payload.member_cards) ? payload.member_cards : [];
    const coupons = Array.isArray(payload.coupons) ? payload.coupons : [];
    const consumeRecords = Array.isArray(payload.consume_records) ? payload.consume_records : [];
    const orders = Array.isArray(payload.orders) ? payload.orders : [];

    el.metaLine.textContent = `${profile.name || '-'} · ${profile.mobile || '-'} · ${profile.store_name || `门店#${profile.store_id || 0}`}`;

    el.kpiGrid.innerHTML = `
      <article class="kpi"><span>钱包余额</span><b>${escapeHtml(formatMoney(wallet.balance || 0))}</b></article>
      <article class="kpi"><span>累计消费</span><b>${escapeHtml(formatMoney(profile.total_spent || 0))}</b></article>
      <article class="kpi"><span>到店次数</span><b>${escapeHtml(String(profile.visit_count || 0))}</b></article>
      <article class="kpi"><span>会员卡数量</span><b>${escapeHtml(String(cards.length))}</b></article>
    `;

    const portalUrl = buildPortalUrl(state.token);
    const portalQrImage = buildQrImageUrl(portalUrl);

    el.profilePanel.innerHTML = `
      <h3>我的资料</h3>
      <div class="profile-grid">
        <article class="meta-item"><span>会员编号</span><b>${escapeHtml(profile.customer_no || '-')}</b></article>
        <article class="meta-item"><span>姓名</span><b>${escapeHtml(profile.name || '-')}</b></article>
        <article class="meta-item"><span>手机号</span><b>${escapeHtml(profile.mobile || '-')}</b></article>
        <article class="meta-item"><span>性别</span><b>${escapeHtml(profile.gender || '-')}</b></article>
        <article class="meta-item"><span>生日</span><b>${escapeHtml(profile.birthday || '-')}</b></article>
        <article class="meta-item"><span>来源渠道</span><b>${escapeHtml(profile.source_channel || '-')}</b></article>
        <article class="meta-item"><span>最近到店</span><b>${escapeHtml(profile.last_visit_at || '-')}</b></article>
        <article class="meta-item"><span>建档时间</span><b>${escapeHtml(profile.created_at || '-')}</b></article>
        <article class="meta-item"><span>当前状态</span><b>${escapeHtml(profile.status || '-')}</b></article>
      </div>
      <section class="token-panel">
        <h4>访问口令管理</h4>
        <p class="hint">用于进入你的会员中心（4-6位数字）。二维码建议首次进入时使用，后续可直接输入口令。</p>
        <div class="token-row">
          <span class="token-label">当前口令</span>
          <code id="portalTokenText" class="token-code">${escapeHtml(maskToken(state.token))}</code>
          <div class="token-actions">
            <button id="btnPortalTokenToggle" type="button" class="btn light">显示口令</button>
            <button id="btnPortalTokenCopy" type="button" class="btn light">复制口令</button>
            <button id="btnPortalLinkCopy" type="button" class="btn light">复制入口链接</button>
          </div>
        </div>
        <form id="portalTokenRotateForm" class="token-form">
          <label>
            <span>新口令（4-6位数字）</span>
            <input name="new_token" placeholder="留空则自动生成新口令" maxlength="6" inputmode="numeric" pattern="\\d{4,6}" />
          </label>
          <div class="token-actions">
            <button class="btn primary" type="submit">更新访问口令</button>
            <button id="btnPortalTokenGenerate" class="btn light" type="button">自动生成</button>
          </div>
        </form>
        <div class="token-link-row">
          <span>当前入口</span>
          <a href="${escapeHtml(portalUrl)}" target="_blank" rel="noopener">${escapeHtml(portalUrl || '-')}</a>
        </div>
        ${portalQrImage ? `<div class="token-qr"><img src="${escapeHtml(portalQrImage)}" alt="会员中心入口二维码" /></div>` : ''}
      </section>
    `;
    bindProfilePanelEvents();

    el.memberCardTable.innerHTML = renderTable([
      { label: '卡号', key: 'card_no' },
      { label: '卡项', key: 'package_name' },
      { label: '总次数', key: 'total_sessions' },
      { label: '剩余次数', key: 'remaining_sessions' },
      { label: '状态', key: 'status' },
      { label: '到期时间', key: 'expire_at' },
    ], cards, '暂无会员卡');

    el.couponTable.innerHTML = renderTable([
      { label: '券码', key: 'coupon_code' },
      { label: '券名', key: 'coupon_name' },
      { label: '面额', get: (r) => formatMoney(r.face_value || 0) },
      { label: '门槛', get: (r) => formatMoney(r.min_spend || 0) },
      { label: '剩余张数', key: 'remain_count' },
      { label: '状态', key: 'status' },
      { label: '到期时间', key: 'expire_at' },
    ], coupons, '暂无优惠券');

    el.consumeTable.innerHTML = renderTable([
      { label: '消费单号', key: 'consume_no' },
      { label: '消费金额', get: (r) => formatMoney(r.consume_amount || 0) },
      { label: '扣余额', get: (r) => formatMoney(r.deduct_balance_amount || 0) },
      { label: '扣优惠券', get: (r) => formatMoney(r.deduct_coupon_amount || 0) },
      { label: '扣次', key: 'deduct_member_card_sessions' },
      { label: '时间', key: 'created_at' },
    ], consumeRecords, '暂无消费记录');

    renderAppointmentPanel(payload);

    el.orderTable.innerHTML = renderTable([
      { label: '订单号', key: 'order_no' },
      { label: '状态', get: (r) => orderStatusLabel(r.status, r.payable_amount, r.paid_amount) },
      { label: '应付', get: (r) => formatMoney(r.payable_amount || 0) },
      { label: '已付', get: (r) => formatMoney(r.paid_amount || 0) },
      { label: '支付时间', key: 'paid_at' },
      { label: '下单时间', key: 'created_at' },
    ], orders, '暂无订单记录');

    renderPaymentPanel(payload);
  }

  function clearAutoRefreshTimer() {
    if (state.autoRefreshTimer) {
      window.clearTimeout(state.autoRefreshTimer);
      state.autoRefreshTimer = 0;
    }
  }

  function scheduleAutoRefresh() {
    clearAutoRefreshTimer();
    if (!state.token || !state.payload) return;
    state.pendingCount = pendingPaymentCount(state.payload);
    const interval = state.pendingCount > 0 ? AUTO_REFRESH_MS : AUTO_REFRESH_IDLE_MS;

    state.autoRefreshTimer = window.setTimeout(async () => {
      if (state.autoRefreshBusy) {
        scheduleAutoRefresh();
        return;
      }
      state.autoRefreshBusy = true;
      const before = state.pendingCount;
      try {
        if (before > 0) {
          await syncPortalPendingPayments(8);
        }
        const payload = await requestOverview();
        state.payload = payload;
        state.pendingCount = pendingPaymentCount(payload);
        render();
        if (state.pendingCount < before) {
          toast('支付结果已自动更新', 'ok');
        } else if (before === 0 && state.pendingCount > 0) {
          toast('门店已发起新的待支付订单', 'info');
        }
      } catch (_err) {
        // keep silent to avoid disturbing customers during network jitter
      } finally {
        state.autoRefreshBusy = false;
        scheduleAutoRefresh();
      }
    }, interval);
  }

  async function loadAndRender() {
    const payload = await requestOverview();
    state.payload = payload;
    render();
    showApp();
    scheduleAutoRefresh();
  }

  function saveToken(token) {
    state.token = String(token || '').trim();
    if (state.token) {
      localStorage.setItem(TOKEN_KEY, state.token);
    }
  }

  function clearToken() {
    clearAutoRefreshTimer();
    state.token = '';
    state.tokenVisible = false;
    state.payload = null;
    state.appointmentDraft = {
      service_id: 0,
      start_at: '',
      duration_minutes: 60,
      notes: '',
    };
    state.highlightedPaymentNo = '';
    state.pendingCount = 0;
    localStorage.removeItem(TOKEN_KEY);
  }

  function readTokenFromUrl() {
    const url = new URL(window.location.href);
    const token = String(url.searchParams.get('token') || '').trim();
    if (!token) return '';

    url.searchParams.delete('token');
    window.history.replaceState({}, '', url.toString());
    return token;
  }

  function bindEvents() {
    el.entryForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const token = String(el.entryToken.value || '').trim();
      if (!token) {
        toast('请输入访问口令', 'error');
        return;
      }

      try {
        el.entryBtn.disabled = true;
        saveToken(token);
        await loadAndRender();
        toast('已进入会员中心', 'ok');
      } catch (err) {
        clearToken();
        showEntry();
        toast(err.message || '进入失败', 'error');
      } finally {
        el.entryBtn.disabled = false;
      }
    });

    el.btnReload.addEventListener('click', async () => {
      try {
        await loadAndRender();
        toast('已刷新', 'ok');
      } catch (err) {
        clearToken();
        showEntry();
        toast(err.message || '刷新失败', 'error');
      }
    });

    el.btnLogout.addEventListener('click', () => {
      clearToken();
      el.entryToken.value = '';
      showEntry();
      toast('已退出', 'info');
    });
  }

  async function bootstrap() {
    bindEvents();

    const tokenInUrl = readTokenFromUrl();
    if (tokenInUrl) {
      saveToken(tokenInUrl);
      el.entryToken.value = tokenInUrl;
    }

    if (!state.token) {
      showEntry();
      return;
    }

    try {
      await loadAndRender();
    } catch (err) {
      clearToken();
      showEntry();
      toast(err.message || '登录失效，请重新扫码', 'error');
    }
  }

  bootstrap();
})();
