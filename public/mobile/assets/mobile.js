(() => {
  const PATHNAME = window.location.pathname;
  const ROOT_PATH = (() => {
    if (Object.prototype.hasOwnProperty.call(window, '__QILING_ROOT_PATH__')) {
      const v = String(window.__QILING_ROOT_PATH__ || '');
      return v === '/' ? '' : v.replace(/\/+$/, '');
    }
    return PATHNAME.includes('/mobile') ? PATHNAME.split('/mobile')[0] : '';
  })();
  const API_PREFIX = `${ROOT_PATH}/api/v1`;
  const TOKEN_KEY = 'qiling_mobile_token';

  const el = {
    loginScreen: document.getElementById('loginScreen'),
    workScreen: document.getElementById('workScreen'),
    loginForm: document.getElementById('loginForm'),
    loginBtn: document.getElementById('loginBtn'),
    loginUsername: document.getElementById('loginUsername'),
    loginPassword: document.getElementById('loginPassword'),
    userMeta: document.getElementById('userMeta'),
    logoutBtn: document.getElementById('logoutBtn'),
    customerKeyword: document.getElementById('customerKeyword'),
    btnCustomerSearch: document.getElementById('btnCustomerSearch'),
    customerResult: document.getElementById('customerResult'),
    currentCustomer: document.getElementById('currentCustomer'),
    tabContent: document.getElementById('tabContent'),
    navButtons: Array.from(document.querySelectorAll('.nav-btn')),
    toastContainer: document.getElementById('toastContainer'),
  };

  const state = {
    token: localStorage.getItem(TOKEN_KEY) || '',
    user: null,
    tab: 'onboard',
    subTabs: {
      onboard: 'onboard_form',
      agent: 'consume',
      records: 'assets',
    },
    customer: null,
    orders: [],
    mobileMenu: {
      tabs: ['onboard', 'agent', 'records'],
      subtabs: {
        onboard: ['onboard_form', 'onboard_help'],
        agent: ['consume', 'wallet', 'card', 'coupon'],
        records: ['assets', 'consume', 'orders'],
      },
    },
    storeOptions: [],
  };

  const SOURCE_CHANNEL_OPTIONS = [
    '抖音',
    '小红书',
    '美团',
    '大众点评',
    '微信视频号',
    '微信公众号',
    '企业微信',
    '朋友圈广告',
    '百度地图',
    '高德地图',
    '自然到店',
    '老客转介绍',
    '线下地推',
    '异业合作',
    '门店活动',
    '电话咨询',
    '其他',
  ];

  const SOURCE_CHANNEL_ALIAS = {
    douyin: '抖音',
    抖音团购: '抖音',
    xiaohongshu: '小红书',
    meituan: '美团',
    dianping: '大众点评',
    dazhongdianping: '大众点评',
    视频号: '微信视频号',
    weixinshipinhao: '微信视频号',
    qiyeweixin: '企业微信',
    企微: '企业微信',
    laodaixin: '老客转介绍',
    zhuanjieshao: '老客转介绍',
  };

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  function renderSourceChannelOptionTags(includeEmpty = true) {
    const empty = includeEmpty ? '<option value="">快捷选择来源渠道（可选）</option>' : '';
    const options = SOURCE_CHANNEL_OPTIONS
      .map((item) => `<option value="${escapeHtml(item)}">${escapeHtml(item)}</option>`)
      .join('');
    return `${empty}${options}`;
  }

  function renderSourceChannelDatalist(id = 'qilingMobileSourceChannelList') {
    const options = SOURCE_CHANNEL_OPTIONS
      .map((item) => `<option value="${escapeHtml(item)}"></option>`)
      .join('');
    return `<datalist id="${escapeHtml(id)}">${options}</datalist>`;
  }

  function normalizeSourceChannel(value) {
    const raw = String(value || '').trim();
    if (raw === '') return '';
    const compact = raw.toLowerCase().replace(/[\s\-_/]/g, '');
    if (Object.prototype.hasOwnProperty.call(SOURCE_CHANNEL_ALIAS, compact)) {
      return SOURCE_CHANNEL_ALIAS[compact];
    }
    for (const item of SOURCE_CHANNEL_OPTIONS) {
      if (item.toLowerCase() === raw.toLowerCase()) return item;
    }
    return raw;
  }

  function normalizeMobileValue(value) {
    let raw = String(value || '').trim().replace(/\s+/g, '');
    if (raw.startsWith('+86')) raw = raw.slice(3);
    if (raw.startsWith('86') && raw.length === 13) raw = raw.slice(2);
    return raw;
  }

  function bindSourceChannelAssist(form, inputName = 'source_channel', presetName = 'source_channel_preset', memoryKey = 'qiling_mobile_last_source_channel') {
    if (!form) return;
    const input = form.querySelector(`[name="${inputName}"]`);
    if (!input) return;
    const preset = form.querySelector(`[name="${presetName}"]`);

    const remembered = String(localStorage.getItem(memoryKey) || '').trim();
    if (!input.value && remembered) {
      input.value = remembered;
    }

    if (preset) {
      preset.addEventListener('change', () => {
        const chosen = String(preset.value || '').trim();
        if (chosen) {
          input.value = chosen;
          input.focus();
        }
      });
    }

    const persist = () => {
      const normalized = normalizeSourceChannel(input.value);
      input.value = normalized;
      if (normalized) {
        localStorage.setItem(memoryKey, normalized);
      }
    };
    input.addEventListener('blur', persist);
    input.addEventListener('change', persist);
  }

  function applyStoreDefaultToForm(form, inputName = 'store_id') {
    if (!form) return;
    const input = form.querySelector(`[name="${inputName}"]`);
    if (!input) return;
    const storeId = toInt(state.user && state.user.staff_store_id, 0);
    if (storeId <= 0) return;
    const raw = String(input.value || '').trim();
    if (raw === '' || raw === '0') {
      input.value = String(storeId);
    }
    const placeholder = String(input.getAttribute('placeholder') || '');
    if (placeholder !== '' && !placeholder.includes('默认门店')) {
      input.setAttribute('placeholder', `${placeholder}（默认门店#${storeId}）`);
    }
  }

  function storeOptionLabel(store) {
    const id = toInt(store && store.id, 0);
    const name = String((store && (store.store_name || store.name)) || '').trim() || `门店#${id || '-'}`;
    const code = String((store && store.store_code) || '').trim();
    return code ? `${name} (#${id}) · ${code}` : `${name} (#${id})`;
  }

  function renderStoreOptionTags(stores, includeEmpty = true) {
    const rows = Array.isArray(stores) ? stores : [];
    const empty = includeEmpty ? '<option value="">快捷选择门店（可选）</option>' : '';
    const options = rows.map((s) => {
      const id = toInt(s && s.id, 0);
      if (id <= 0) return '';
      return `<option value="${id}">${escapeHtml(storeOptionLabel(s))}</option>`;
    }).join('');
    return `${empty}${options}`;
  }

  function renderStoreDatalist(stores, id = 'qilingMobileStoreList') {
    const rows = Array.isArray(stores) ? stores : [];
    const options = rows.map((s) => {
      const sid = toInt(s && s.id, 0);
      if (sid <= 0) return '';
      return `<option value="${escapeHtml(storeOptionLabel(s))}"></option>`;
    }).join('');
    return `<datalist id="${escapeHtml(id)}">${options}</datalist>`;
  }

  function normalizeStoreId(value, stores = []) {
    const raw = String(value || '').trim();
    if (raw === '') return '';
    if (/^\d+$/.test(raw)) return raw;

    const idMatch = raw.match(/#\s*(\d+)/);
    if (idMatch && idMatch[1]) return idMatch[1];

    const rows = Array.isArray(stores) ? stores : [];
    const lower = raw.toLowerCase();
    const byCode = rows.find((s) => String((s && s.store_code) || '').trim().toLowerCase() === lower);
    if (byCode && toInt(byCode.id, 0) > 0) return String(toInt(byCode.id, 0));

    const byName = rows.find((s) => String((s && (s.store_name || s.name)) || '').trim().toLowerCase() === lower);
    if (byName && toInt(byName.id, 0) > 0) return String(toInt(byName.id, 0));

    const fuzzy = rows.filter((s) => String((s && (s.store_name || s.name)) || '').trim().toLowerCase().includes(lower));
    if (fuzzy.length === 1 && toInt(fuzzy[0].id, 0) > 0) return String(toInt(fuzzy[0].id, 0));

    return raw;
  }

  function bindStoreAssist(form, stores, inputName = 'store_id', presetName = 'store_id_preset', memoryKey = 'qiling_mobile_last_store_id') {
    if (!form) return;
    const input = form.querySelector(`[name="${inputName}"]`);
    if (!input) return;
    const preset = form.querySelector(`[name="${presetName}"]`);

    const remembered = String(localStorage.getItem(memoryKey) || '').trim();
    const defaultStoreId = toInt(state.user && state.user.staff_store_id, 0);
    if (!input.value) {
      if (remembered !== '') {
        input.value = remembered;
      } else if (defaultStoreId > 0) {
        input.value = String(defaultStoreId);
      }
    }

    if (preset) {
      preset.addEventListener('change', () => {
        const chosen = String(preset.value || '').trim();
        if (chosen !== '') {
          input.value = chosen;
          input.focus();
        }
      });
    }

    const persist = () => {
      const normalized = normalizeStoreId(input.value, stores);
      input.value = normalized;
      if (/^\d+$/.test(normalized)) {
        localStorage.setItem(memoryKey, normalized);
      }
    };
    input.addEventListener('blur', persist);
    input.addEventListener('change', persist);
  }

  function toast(message, type = 'info') {
    const node = document.createElement('div');
    node.className = `toast ${type}`;
    node.textContent = message;
    el.toastContainer.appendChild(node);
    window.setTimeout(() => node.remove(), 2200);
  }

  function normalizeError(message) {
    const raw = String(message || '').trim();
    if (raw === '') return '请求失败';

    const map = [
      [/invalid credentials/i, '账号或密码错误'],
      [/account disabled/i, '账号已禁用'],
      [/customer not found/i, '未找到客户'],
      [/member card not found/i, '未找到会员卡'],
      [/coupon not found/i, '未找到优惠券'],
      [/keyword is required/i, '请输入搜索关键词'],
      [/consume record not found/i, '未找到消费记录'],
      [/amount cannot be zero/i, '金额不能为 0'],
      [/payment/i, '支付操作失败'],
      [/token/i, '登录已过期，请重新登录'],
      [/APP_KEY is missing/i, '系统密钥未配置，请联系管理员'],
    ];

    for (const [re, text] of map) {
      if (re.test(raw)) return text;
    }

    return raw;
  }

  function zhRole(value) {
    const v = String(value || '').trim();
    const map = {
      admin: '管理员',
      manager: '店长',
      consultant: '顾问',
      operator: '店员',
      finance: '财务',
      subscriber: '会员',
    };
    return map[v] || v || '-';
  }

  function userMetaText() {
    const username = String(state.user?.username || '-');
    const role = zhRole(state.user?.role_key);
    const storeId = toInt(state.user?.staff_store_id, 0);
    const storeText = storeId > 0 ? `门店#${storeId}` : '总部/未绑定门店';
    return `${username} · ${role} · ${storeText}`;
  }

  function toInt(value, fallback = 0) {
    const n = Number.parseInt(String(value ?? ''), 10);
    return Number.isFinite(n) ? n : fallback;
  }

  function toFloat(value, fallback = 0) {
    const n = Number.parseFloat(String(value ?? ''));
    return Number.isFinite(n) ? n : fallback;
  }

  function getAllowedTabs() {
    const tabs = Array.isArray(state.mobileMenu?.tabs) ? state.mobileMenu.tabs : [];
    const valid = tabs.filter((x) => ['onboard', 'agent', 'records'].includes(String(x)));
    return valid.length > 0 ? valid : ['onboard', 'agent', 'records'];
  }

  function getAllowedSubtabs(view) {
    const options = {
      onboard: ['onboard_form', 'onboard_help'],
      agent: ['consume', 'wallet', 'card', 'coupon'],
      records: ['assets', 'consume', 'orders'],
    };
    const fallback = options[view] || [];
    const list = Array.isArray(state.mobileMenu?.subtabs?.[view]) ? state.mobileMenu.subtabs[view] : [];
    const valid = list.filter((x) => fallback.includes(String(x)));
    return valid.length > 0 ? valid : fallback;
  }

  function ensureMenuAccess() {
    const allowedTabs = getAllowedTabs();
    if (!allowedTabs.includes(state.tab)) {
      state.tab = allowedTabs[0];
    }

    ['onboard', 'agent', 'records'].forEach((view) => {
      const allowed = getAllowedSubtabs(view);
      if (!allowed.includes(state.subTabs[view])) {
        state.subTabs[view] = allowed[0] || '';
      }
    });
  }

  function applyMainNavVisibility() {
    const allowedTabs = getAllowedTabs();
    el.navButtons.forEach((btn) => {
      const t = String(btn.getAttribute('data-tab') || '');
      if (allowedTabs.includes(t)) {
        btn.classList.remove('hidden');
      } else {
        btn.classList.add('hidden');
      }
    });

    const nav = document.querySelector('.bottom-nav');
    if (nav) {
      nav.style.gridTemplateColumns = `repeat(${Math.max(1, allowedTabs.length)}, 1fr)`;
    }
  }

  async function request(method, path, options = {}) {
    const query = options.query || {};
    const body = options.body;
    const params = new URLSearchParams();

    Object.entries(query).forEach(([k, v]) => {
      if (v === '' || v === null || typeof v === 'undefined') return;
      params.set(k, String(v));
    });

    const url = `${API_PREFIX}${path}${params.toString() ? `?${params.toString()}` : ''}`;
    const headers = { 'Content-Type': 'application/json' };
    if (state.token) headers.Authorization = `Bearer ${state.token}`;

    const res = await fetch(url, {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined,
    });

    let data = {};
    try {
      data = await res.json();
    } catch (_e) {
      data = {};
    }

    if (!res.ok) {
      const msg = normalizeError(data.message || `${res.status} ${res.statusText}`);
      throw new Error(msg);
    }

    return data;
  }

  async function loadMobileMenu() {
    try {
      const res = await request('GET', '/mobile/menu');
      const tabs = Array.isArray(res.tabs) ? res.tabs : [];
      const subtabs = (res && typeof res.subtabs === 'object' && res.subtabs) ? res.subtabs : {};
      state.mobileMenu = { tabs, subtabs };
    } catch (_e) {
      state.mobileMenu = {
        tabs: ['onboard', 'agent', 'records'],
        subtabs: {
          onboard: ['onboard_form', 'onboard_help'],
          agent: ['consume', 'wallet', 'card', 'coupon'],
          records: ['assets', 'consume', 'orders'],
        },
      };
    }

    ensureMenuAccess();
    applyMainNavVisibility();
  }

  async function loadStoreOptions() {
    try {
      const res = await request('GET', '/stores');
      const rows = Array.isArray(res?.data) ? res.data : [];
      state.storeOptions = rows;
      if (rows.length > 0) return;
    } catch (_e) {
      // ignore
    }

    const storeId = toInt(state.user && state.user.staff_store_id, 0);
    if (storeId > 0) {
      state.storeOptions = [{ id: storeId, store_name: `门店#${storeId}`, store_code: '' }];
    } else {
      state.storeOptions = [];
    }
  }

  function setAuthView(authed) {
    if (authed) {
      el.loginScreen.classList.add('hidden');
      el.workScreen.classList.remove('hidden');
      return;
    }
    el.loginScreen.classList.remove('hidden');
    el.workScreen.classList.add('hidden');
  }

  function customerStoreText(customer) {
    const storeName = customer.store_name || `门店#${customer.store_id || 0}`;
    return `${storeName}`;
  }

  function renderCustomerSummary() {
    const c = state.customer;
    if (!c) {
      el.currentCustomer.innerHTML = '<div class="empty">未选择客户</div>';
      return;
    }

    const wallet = c.wallet || {};
    const cardCount = Array.isArray(c.member_cards) ? c.member_cards.length : 0;
    const couponCount = Array.isArray(c.coupons) ? c.coupons.length : 0;
    const consumeCount = Array.isArray(c.consume_records) ? c.consume_records.length : 0;

    el.currentCustomer.innerHTML = `
      <div class="current-head">
        <b>${escapeHtml(c.name || '-')}&nbsp;(${escapeHtml(c.mobile || '-')})</b>
        <span>${escapeHtml(c.customer_no || '-')}</span>
      </div>
      <div class="current-meta">
        <span>归属：${escapeHtml(customerStoreText(c))}</span>
        <span>余额：${escapeHtml(wallet.balance || '0.00')} 元</span>
        <span>次卡：${cardCount} 张，优惠券：${couponCount} 张，消费记录：${consumeCount} 条</span>
      </div>
    `;
  }

  function renderSearchResult(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
      el.customerResult.innerHTML = '<div class="empty">未找到匹配客户</div>';
      return;
    }

    el.customerResult.innerHTML = rows.map((row) => {
      const wallet = row.wallet || {};
      const cardCount = Array.isArray(row.member_cards) ? row.member_cards.length : 0;
      return `
        <article class="customer-item">
          <div><b>${escapeHtml(row.name || '-')}</b> ${escapeHtml(row.mobile || '-')}</div>
          <div class="meta">${escapeHtml(row.customer_no || '-')} · ${escapeHtml(customerStoreText(row))}</div>
          <div class="meta">余额 ${escapeHtml(wallet.balance || '0.00')} · 次卡 ${cardCount}</div>
          <button type="button" class="btn light" data-customer-id="${row.id}">选择此客户</button>
        </article>
      `;
    }).join('');

    el.customerResult.querySelectorAll('[data-customer-id]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = toInt(btn.getAttribute('data-customer-id'), 0);
        const customer = rows.find((x) => toInt(x.id, 0) === id) || null;
        state.customer = customer;
        state.orders = [];
        renderCustomerSummary();
        renderTabContent();
        toast('已切换客户', 'ok');
      });
    });
  }

  async function searchCustomer() {
    const keyword = String(el.customerKeyword.value || '').trim();
    if (keyword === '') {
      toast('请输入关键词后再搜索', 'error');
      return;
    }

    try {
      const res = await request('GET', '/admin/customers/search', {
        query: { keyword, limit: 20 },
      });
      const rows = Array.isArray(res.data) ? res.data : [];
      renderSearchResult(rows);
    } catch (e) {
      toast(e.message || '搜索失败', 'error');
    }
  }

  async function refreshCurrentCustomer() {
    if (!state.customer) return;

    const keyword = state.customer.customer_no || state.customer.mobile || String(state.customer.id || '');
    if (!keyword) return;

    try {
      const res = await request('GET', '/admin/customers/search', {
        query: { keyword, limit: 50 },
      });
      const rows = Array.isArray(res.data) ? res.data : [];
      const hit = rows.find((x) => toInt(x.id, 0) === toInt(state.customer.id, 0));
      if (hit) state.customer = hit;
      renderCustomerSummary();
      if (state.tab === 'records' && (state.subTabs.records || 'assets') === 'orders') {
        await loadCustomerOrders();
      }
    } catch (_e) {
      // keep current snapshot when refresh fails
    }
  }

  function tabButtonClass(tab) {
    return tab === state.tab ? 'nav-btn active' : 'nav-btn';
  }

  function subButtonClass(view, tab) {
    return state.subTabs[view] === tab ? 'sub-menu-btn active' : 'sub-menu-btn';
  }

  function renderSubMenu(view, items) {
    return `
      <div class="sub-menu" data-submenu-view="${escapeHtml(view)}">
        ${items.map((item) => `<button type="button" class="${subButtonClass(view, item.id)}" data-subtab="${escapeHtml(item.id)}">${escapeHtml(item.title)}</button>`).join('')}
      </div>
    `;
  }

  function bindSubMenu(view, rerender) {
    const box = el.tabContent.querySelector(`.sub-menu[data-submenu-view="${view}"]`);
    if (!box) return;
    box.querySelectorAll('[data-subtab]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const tab = String(btn.getAttribute('data-subtab') || '').trim();
        if (!tab) return;
        state.subTabs[view] = tab;
        rerender();
      });
    });
  }

  function renderOnboardTab() {
    const allowedSubTabs = getAllowedSubtabs('onboard');
    const menuItems = [
      { id: 'onboard_form', title: '建档表单' },
      { id: 'onboard_help', title: '操作说明' },
    ].filter((x) => allowedSubTabs.includes(x.id));
    const safeMenuItems = menuItems.length > 0 ? menuItems : [{ id: 'onboard_form', title: '建档表单' }];
    if (!allowedSubTabs.includes(state.subTabs.onboard)) {
      state.subTabs.onboard = safeMenuItems[0].id;
    }
    const sub = state.subTabs.onboard || safeMenuItems[0].id;
    const menu = renderSubMenu('onboard', safeMenuItems);
    const stores = Array.isArray(state.storeOptions) ? state.storeOptions : [];

    if (sub === 'onboard_help') {
      el.tabContent.innerHTML = `
        ${menu}
        <section class="panel">
          <h3 class="section-title">建档说明</h3>
          <div class="list">
            <article class="record">
              <div class="line">1. 建议优先填写：姓名、手机号、来源渠道。</div>
              <div class="line">2. 来源渠道支持快捷下拉，也可以手动输入自定义渠道。</div>
              <div class="line">3. 已存在手机号会自动更新档案，不会重复建客户。</div>
              <div class="line">4. 可在建档时直接赠送余额，方便首次到店体验。</div>
            </article>
          </div>
          <div class="form-grid two" style="margin-top:8px;">
            <button class="btn light" type="button" data-switch-tab="agent">去代客操作</button>
            <button class="btn light" type="button" data-switch-tab="records">去看消费记录</button>
          </div>
        </section>
      `;

      bindSubMenu('onboard', renderOnboardTab);
      el.tabContent.querySelectorAll('[data-switch-tab]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const tab = String(btn.getAttribute('data-switch-tab') || '').trim();
          if (!tab) return;
          state.tab = tab;
          renderTabContent();
        });
      });
      return;
    }

    el.tabContent.innerHTML = `
      ${menu}
      <section class="panel">
        <h3 class="section-title">客户建档</h3>
        <p class="hint">支持新建客户；若手机号已存在会更新档案并补充赠送权益。</p>
        <form id="formOnboard" class="form-grid">
          <input name="name" placeholder="客户姓名（新建必填）" />
          <input name="mobile" placeholder="手机号（必填）" required />
          <div class="form-grid two">
            <select name="gender">
              <option value="unknown">性别：未知</option>
              <option value="female">女</option>
              <option value="male">男</option>
            </select>
            <select name="store_id_preset">
              ${renderStoreOptionTags(stores, true)}
            </select>
          </div>
          <input name="store_id" list="qilingMobileStoreList" placeholder="门店ID（可搜索门店名，也可手动输入）" />
          ${renderStoreDatalist(stores, 'qilingMobileStoreList')}
          <div class="form-grid two">
            <select name="source_channel_preset">
              ${renderSourceChannelOptionTags(true)}
            </select>
            <input name="source_channel" list="qilingMobileSourceChannelList" placeholder="来源渠道（可手动填写）" />
          </div>
          ${renderSourceChannelDatalist('qilingMobileSourceChannelList')}
          <input name="skin_type" placeholder="肤质/体质（可空）" />
          <input name="allergies" placeholder="过敏史（可空）" />
          <textarea name="notes" placeholder="备注（可空）"></textarea>
          <input name="gift_balance" placeholder="赠送余额（元，可空）" />
          <button class="btn primary" type="submit">提交建档</button>
        </form>
      </section>
    `;

    bindSubMenu('onboard', renderOnboardTab);

    const form = document.getElementById('formOnboard');
    applyStoreDefaultToForm(form, 'store_id');
    bindStoreAssist(form, stores, 'store_id', 'store_id_preset', 'qiling_mobile_last_store_id');
    bindSourceChannelAssist(form, 'source_channel', 'source_channel_preset', 'qiling_mobile_last_source_channel');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      const mobile = normalizeMobileValue(fd.get('mobile'));
      const payload = {
        customer: {
          name: String(fd.get('name') || '').trim(),
          mobile,
          gender: String(fd.get('gender') || 'unknown').trim(),
          source_channel: normalizeSourceChannel(fd.get('source_channel')),
          skin_type: String(fd.get('skin_type') || '').trim(),
          allergies: String(fd.get('allergies') || '').trim(),
          notes: String(fd.get('notes') || '').trim(),
        },
        gift_balance: toFloat(fd.get('gift_balance'), 0),
      };

      const storeId = toInt(normalizeStoreId(fd.get('store_id'), stores), 0);
      if (storeId > 0) payload.customer.store_id = storeId;

      try {
        const res = await request('POST', '/admin/customers/onboard', { body: payload });
        toast(`建档成功，客户ID #${res.customer_id || '-'}`, 'ok');
        await searchCustomerByMobile(payload.customer.mobile);
      } catch (err) {
        toast(err.message || '建档失败', 'error');
      }
    });
  }

  function renderAgentTab() {
    if (!state.customer) {
      el.tabContent.innerHTML = '<section class="panel"><div class="empty">请先在上方搜索并选择客户，再进行代客操作。</div></section>';
      return;
    }

    const allowedSubTabs = getAllowedSubtabs('agent');
    const menuItems = [
      { id: 'consume', title: '登记消费' },
      { id: 'wallet', title: '余额调整' },
      { id: 'card', title: '次卡调整' },
      { id: 'coupon', title: '发放优惠券' },
    ].filter((x) => allowedSubTabs.includes(x.id));
    const safeMenuItems = menuItems.length > 0 ? menuItems : [{ id: 'consume', title: '登记消费' }];
    if (!allowedSubTabs.includes(state.subTabs.agent)) {
      state.subTabs.agent = safeMenuItems[0].id;
    }
    const sub = state.subTabs.agent || safeMenuItems[0].id;
    const menu = renderSubMenu('agent', safeMenuItems);

    let body = '';
    if (sub === 'wallet') {
      body = `
        <section class="panel">
          <h3 class="section-title">余额调整</h3>
          <form id="formWallet" class="form-grid">
            <div class="form-grid two">
              <select name="mode">
                <option value="delta">增减模式</option>
                <option value="set_balance">直接设余额</option>
              </select>
              <select name="change_type">
                <option value="adjust">调整</option>
                <option value="gift">赠送</option>
                <option value="recharge">充值</option>
                <option value="deduct">扣减</option>
              </select>
            </div>
            <input name="amount" placeholder="金额" required />
            <input name="note" placeholder="备注" value="移动端代客调整余额" />
            <button class="btn primary" type="submit">提交余额调整</button>
          </form>
        </section>
      `;
    } else if (sub === 'card') {
      body = `
        <section class="panel">
          <h3 class="section-title">次卡调整</h3>
          <form id="formCardAdjust" class="form-grid">
            <input name="card_no" placeholder="会员卡号（必填）" required />
            <div class="form-grid two">
              <select name="mode">
                <option value="set_remaining">设剩余次数</option>
                <option value="delta_sessions">增减次数</option>
              </select>
              <input name="value" placeholder="数值" required />
            </div>
            <input name="note" placeholder="备注" value="移动端代客调整次卡" />
            <button class="btn primary" type="submit">提交次卡调整</button>
          </form>
        </section>
      `;
    } else if (sub === 'coupon') {
      body = `
        <section class="panel">
          <h3 class="section-title">发放优惠券</h3>
          <form id="formCouponGrant" class="form-grid">
            <input name="coupon_name" placeholder="券名称（如 到店立减券）" required />
            <div class="form-grid two">
              <input name="face_value" placeholder="面额" />
              <input name="count" placeholder="张数（默认1）" />
            </div>
            <input name="min_spend" placeholder="最低消费门槛（可空）" />
            <input name="expire_at" placeholder="过期时间（YYYY-MM-DD HH:MM:SS，可空）" />
            <input name="note" placeholder="备注" value="移动端代客发券" />
            <button class="btn primary" type="submit">发放优惠券</button>
          </form>
        </section>
      `;
    } else {
      body = `
        <section class="panel">
          <h3 class="section-title">登记消费</h3>
          <form id="formConsume" class="form-grid">
            <div class="form-grid two">
              <input name="consume_amount" placeholder="消费金额（可空）" />
              <input name="deduct_balance_amount" placeholder="扣余额（可空）" />
            </div>
            <div class="form-grid two">
              <input name="coupon_code" placeholder="优惠券码（可空）" />
              <input name="coupon_use_count" placeholder="券使用数量（默认1）" />
            </div>
            <div class="form-grid two">
              <input name="card_no" placeholder="次卡卡号（可空）" />
              <input name="consume_sessions" placeholder="扣次（默认1）" />
            </div>
            <input name="note" placeholder="备注" value="移动端代客登记消费" />
            <button class="btn primary" type="submit">提交消费</button>
          </form>
        </section>
      `;
    }

    el.tabContent.innerHTML = `${menu}${body}`;
    bindSubMenu('agent', renderAgentTab);
    bindAgentForms();
  }

  function renderRecordsTab() {
    if (!state.customer) {
      el.tabContent.innerHTML = '<section class="panel"><div class="empty">请先在上方搜索并选择客户，再查看记录。</div></section>';
      return;
    }

    const allowedSubTabs = getAllowedSubtabs('records');
    const menuItems = [
      { id: 'assets', title: '资产总览' },
      { id: 'consume', title: '消费记录' },
      { id: 'orders', title: '订单记录' },
    ].filter((x) => allowedSubTabs.includes(x.id));
    const safeMenuItems = menuItems.length > 0 ? menuItems : [{ id: 'assets', title: '资产总览' }];
    if (!allowedSubTabs.includes(state.subTabs.records)) {
      state.subTabs.records = safeMenuItems[0].id;
    }
    const sub = state.subTabs.records || safeMenuItems[0].id;
    const menu = renderSubMenu('records', safeMenuItems);

    const c = state.customer;
    const wallet = c.wallet || {};
    const cards = Array.isArray(c.member_cards) ? c.member_cards : [];
    const coupons = Array.isArray(c.coupons) ? c.coupons : [];
    const records = Array.isArray(c.consume_records) ? c.consume_records : [];

    if (sub === 'consume') {
      el.tabContent.innerHTML = `
        ${menu}
        <section class="panel">
          <h3 class="section-title">消费记录</h3>
          <div class="list">
            ${records.length ? records.map((x) => `<article class="record"><div class="title">${escapeHtml(x.consume_no || '-')}</div><div class="line">消费 ${escapeHtml(x.consume_amount || 0)}，扣余额 ${escapeHtml(x.deduct_balance_amount || 0)}，扣券 ${escapeHtml(x.deduct_coupon_amount || 0)}，扣次 ${escapeHtml(x.deduct_member_card_sessions || 0)}</div><div class="sub">${escapeHtml(x.created_at || '-')} · ${escapeHtml(x.note || '')}</div></article>`).join('') : '<div class="empty">暂无消费记录</div>'}
          </div>
        </section>
      `;
      bindSubMenu('records', renderRecordsTab);
      return;
    }

    if (sub === 'orders') {
      el.tabContent.innerHTML = `
        ${menu}
        <section class="panel">
          <div class="current-head">
            <h3 class="section-title">订单记录</h3>
            <button id="btnReloadOrders" type="button" class="btn light">刷新订单</button>
          </div>
          <div id="orderList" class="list"><div class="empty">加载中...</div></div>
        </section>
      `;
      bindSubMenu('records', renderRecordsTab);
      const btnReload = document.getElementById('btnReloadOrders');
      if (btnReload) btnReload.addEventListener('click', () => loadCustomerOrders(true));
      loadCustomerOrders(false);
      return;
    }

    el.tabContent.innerHTML = `
      ${menu}
      <section class="panel">
        <h3 class="section-title">余额与资产</h3>
        <div class="list">
          <article class="record"><div class="line">钱包余额：${escapeHtml(wallet.balance || '0.00')} 元</div><div class="sub">累计充值 ${escapeHtml(wallet.total_recharge || '0.00')} · 累计赠送 ${escapeHtml(wallet.total_gift || '0.00')}</div></article>
        </div>
      </section>

      <section class="panel">
        <h3 class="section-title">次卡</h3>
        <div class="list">
          ${cards.length ? cards.map((x) => `<article class="record"><div class="title">${escapeHtml(x.card_no || '-')}</div><div class="line">剩余 ${escapeHtml(x.remaining_sessions || 0)} / ${escapeHtml(x.total_sessions || 0)} 次</div><div class="sub">状态 ${escapeHtml(x.status || '-')} · 到期 ${escapeHtml(x.expire_at || '-')}</div></article>`).join('') : '<div class="empty">暂无次卡</div>'}
        </div>
      </section>

      <section class="panel">
        <h3 class="section-title">优惠券</h3>
        <div class="list">
          ${coupons.length ? coupons.map((x) => `<article class="record"><div class="title">${escapeHtml(x.coupon_name || '-')} (${escapeHtml(x.coupon_code || '-')})</div><div class="line">剩余 ${escapeHtml(x.remain_count || 0)} 张 · 面额 ${escapeHtml(x.face_value || 0)}</div><div class="sub">状态 ${escapeHtml(x.status || '-')} · 到期 ${escapeHtml(x.expire_at || '-')}</div></article>`).join('') : '<div class="empty">暂无优惠券</div>'}
        </div>
      </section>
    `;
    bindSubMenu('records', renderRecordsTab);
  }

  async function loadCustomerOrders(showToast = false) {
    if (!state.customer) return;
    const box = document.getElementById('orderList');
    if (box) box.innerHTML = '<div class="empty">加载中...</div>';

    try {
      const res = await request('GET', '/orders', {
        query: {
          customer_id: state.customer.id,
          limit: 50,
        },
      });
      state.orders = Array.isArray(res.data) ? res.data : [];
      if (box) {
        box.innerHTML = state.orders.length
          ? state.orders.map((x) => `<article class="record"><div class="title">${escapeHtml(x.order_no || '-')}</div><div class="line">应付 ${escapeHtml(x.payable_amount || 0)} · 已付 ${escapeHtml(x.paid_amount || 0)} · 状态 ${escapeHtml(x.status || '-')}</div><div class="sub">${escapeHtml(x.created_at || '-')}</div></article>`).join('')
          : '<div class="empty">暂无订单记录</div>';
      }
      if (showToast) toast('订单已刷新', 'ok');
    } catch (e) {
      if (box) box.innerHTML = `<div class="empty">${escapeHtml(e.message || '加载失败')}</div>`;
      if (showToast) toast(e.message || '加载失败', 'error');
    }
  }

  async function searchCustomerByMobile(mobile) {
    const keyword = String(mobile || '').trim();
    if (!keyword) return;

    el.customerKeyword.value = keyword;
    try {
      const res = await request('GET', '/admin/customers/search', {
        query: { keyword, limit: 20 },
      });
      const rows = Array.isArray(res.data) ? res.data : [];
      renderSearchResult(rows);
      if (rows.length > 0) {
        state.customer = rows[0];
        renderCustomerSummary();
      }
    } catch (_e) {
      // ignore
    }
  }

  function bindAgentForms() {
    const formConsume = document.getElementById('formConsume');
    const formWallet = document.getElementById('formWallet');
    const formCardAdjust = document.getElementById('formCardAdjust');
    const formCouponGrant = document.getElementById('formCouponGrant');

    if (formConsume) {
      formConsume.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(formConsume);
        const couponCode = String(fd.get('coupon_code') || '').trim();
        const cardNo = String(fd.get('card_no') || '').trim();

        const payload = {
          customer_id: state.customer.id,
          consume_amount: toFloat(fd.get('consume_amount'), 0),
          deduct_balance_amount: toFloat(fd.get('deduct_balance_amount'), 0),
          note: String(fd.get('note') || '').trim() || '移动端代客登记消费',
        };

        if (couponCode) {
          payload.coupon_usages = [{
            coupon_code: couponCode,
            use_count: Math.max(1, toInt(fd.get('coupon_use_count'), 1)),
          }];
        }

        if (cardNo) {
          payload.member_card_usages = [{
            card_no: cardNo,
            consume_sessions: Math.max(1, toInt(fd.get('consume_sessions'), 1)),
          }];
        }

        try {
          const res = await request('POST', '/admin/customers/consume-record', { body: payload });
          toast(`消费登记成功：${res.consume_no || '-'}`, 'ok');
          await refreshCurrentCustomer();
          renderTabContent();
        } catch (err) {
          toast(err.message || '消费登记失败', 'error');
        }
      });
    }

    if (formWallet) {
      formWallet.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(formWallet);

        const payload = {
          customer_id: state.customer.id,
          mode: String(fd.get('mode') || 'delta'),
          change_type: String(fd.get('change_type') || 'adjust'),
          amount: toFloat(fd.get('amount'), 0),
          note: String(fd.get('note') || '').trim() || '移动端代客调整余额',
        };

        try {
          const res = await request('POST', '/admin/customers/wallet-adjust', { body: payload });
          toast(`余额更新：${res.after_balance || '-'}`, 'ok');
          await refreshCurrentCustomer();
          renderTabContent();
        } catch (err) {
          toast(err.message || '余额调整失败', 'error');
        }
      });
    }

    if (formCardAdjust) {
      formCardAdjust.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(formCardAdjust);

        const payload = {
          customer_id: state.customer.id,
          card_no: String(fd.get('card_no') || '').trim(),
          mode: String(fd.get('mode') || 'set_remaining'),
          value: toInt(fd.get('value'), 0),
          note: String(fd.get('note') || '').trim() || '移动端代客调整次卡',
        };

        try {
          const res = await request('POST', '/admin/member-cards/adjust', { body: payload });
          toast(`次卡调整成功：剩余 ${res.remaining_sessions || 0} 次`, 'ok');
          await refreshCurrentCustomer();
          renderTabContent();
        } catch (err) {
          toast(err.message || '次卡调整失败', 'error');
        }
      });
    }

    if (formCouponGrant) {
      formCouponGrant.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(formCouponGrant);

        const payload = {
          customer_id: state.customer.id,
          mode: 'grant',
          coupon_name: String(fd.get('coupon_name') || '').trim(),
          coupon_type: 'cash',
          face_value: toFloat(fd.get('face_value'), 0),
          min_spend: toFloat(fd.get('min_spend'), 0),
          count: Math.max(1, toInt(fd.get('count'), 1)),
          expire_at: String(fd.get('expire_at') || '').trim(),
          note: String(fd.get('note') || '').trim() || '移动端代客发券',
        };

        try {
          const res = await request('POST', '/admin/customers/coupon-adjust', { body: payload });
          toast(`发券成功：${res.coupon_code || '-'}`, 'ok');
          await refreshCurrentCustomer();
          renderTabContent();
        } catch (err) {
          toast(err.message || '发券失败', 'error');
        }
      });
    }
  }

  function renderTabContent() {
    ensureMenuAccess();
    applyMainNavVisibility();
    const allowedTabs = getAllowedTabs();
    el.navButtons.forEach((btn) => {
      const t = String(btn.getAttribute('data-tab') || 'onboard');
      if (!allowedTabs.includes(t)) {
        btn.className = 'nav-btn hidden';
        return;
      }
      btn.className = tabButtonClass(t);
    });

    if (state.tab === 'onboard') {
      renderOnboardTab();
      return;
    }

    if (state.tab === 'agent') {
      renderAgentTab();
      return;
    }

    renderRecordsTab();
  }

  function bindMainEvents() {
    el.loginForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (el.loginBtn) el.loginBtn.disabled = true;

      try {
        const username = String(el.loginUsername.value || '').trim();
        const password = String(el.loginPassword.value || '').trim();
        const res = await request('POST', '/auth/login', {
          body: { username, password },
        });

        state.token = String(res.token || '');
        localStorage.setItem(TOKEN_KEY, state.token);
        state.user = res.user || null;
        await loadMobileMenu();
        await loadStoreOptions();
        setAuthView(true);
        el.userMeta.textContent = userMetaText();
        renderCustomerSummary();
        renderTabContent();
        toast('登录成功', 'ok');
      } catch (err) {
        toast(err.message || '登录失败', 'error');
      } finally {
        if (el.loginBtn) el.loginBtn.disabled = false;
      }
    });

    el.logoutBtn.addEventListener('click', () => {
      state.token = '';
      state.user = null;
      state.customer = null;
      state.orders = [];
      state.mobileMenu = {
        tabs: ['onboard', 'agent', 'records'],
        subtabs: {
          onboard: ['onboard_form', 'onboard_help'],
          agent: ['consume', 'wallet', 'card', 'coupon'],
          records: ['assets', 'consume', 'orders'],
        },
      };
      state.storeOptions = [];
      localStorage.removeItem(TOKEN_KEY);
      setAuthView(false);
      el.customerResult.innerHTML = '';
      el.customerKeyword.value = '';
      renderCustomerSummary();
      toast('已退出登录', 'info');
    });

    el.btnCustomerSearch.addEventListener('click', searchCustomer);
    el.customerKeyword.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        searchCustomer();
      }
    });

    el.navButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        const targetTab = String(btn.getAttribute('data-tab') || 'onboard');
        if (!getAllowedTabs().includes(targetTab)) {
          return;
        }
        state.tab = targetTab;
        renderTabContent();
      });
    });
  }

  async function bootstrap() {
    bindMainEvents();

    if (!state.token) {
      setAuthView(false);
      return;
    }

    try {
      const res = await request('GET', '/auth/me');
      state.user = res.user || null;
      if (!state.user) throw new Error('登录状态失效');
      await loadMobileMenu();
      await loadStoreOptions();
      setAuthView(true);
      el.userMeta.textContent = userMetaText();
      renderCustomerSummary();
      renderTabContent();
    } catch (_e) {
      state.token = '';
      state.user = null;
      localStorage.removeItem(TOKEN_KEY);
      setAuthView(false);
    }
  }

  bootstrap();
})();
