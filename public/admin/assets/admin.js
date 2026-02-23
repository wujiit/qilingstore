(() => {
  const PATHNAME = window.location.pathname;
  const ROOT_PATH = (() => {
    if (Object.prototype.hasOwnProperty.call(window, '__QILING_ROOT_PATH__')) {
      const v = String(window.__QILING_ROOT_PATH__ || '');
      return v === '/' ? '' : v.replace(/\/+$/, '');
    }
    return PATHNAME.includes('/admin') ? PATHNAME.split('/admin')[0] : '';
  })();
  const API_PREFIX = `${ROOT_PATH}/api/v1`;

  const TOKEN_KEY = 'qiling_admin_token';

  const el = {
    loginScreen: document.getElementById('loginScreen'),
    appScreen: document.getElementById('appScreen'),
    loginForm: document.getElementById('loginForm'),
    loginBtn: document.getElementById('loginBtn'),
    loginUsername: document.getElementById('loginUsername'),
    loginPassword: document.getElementById('loginPassword'),
    navList: document.getElementById('navList'),
    viewRoot: document.getElementById('viewRoot'),
    viewTitle: document.getElementById('viewTitle'),
    viewSubtitle: document.getElementById('viewSubtitle'),
    userName: document.getElementById('userName'),
    userMeta: document.getElementById('userMeta'),
    logoutBtn: document.getElementById('logoutBtn'),
    toastContainer: document.getElementById('toastContainer'),
  };

  const state = {
    token: localStorage.getItem(TOKEN_KEY) || '',
    user: null,
    storeOptions: [],
    activeView: 'dashboard',
    masterTab: 'stores',
    subTabs: {
      ops: 'appointments',
      manual: 'profile',
      growth: 'grades',
      finance: 'config',
      followpush: 'plans',
      bizplus: 'transfers',
      commission: 'rules',
      integration: 'wp',
      reports: 'overview',
    },
    nav: [
      { id: 'dashboard', title: '经营看板', subtitle: '核心指标与待办任务', group: '总览' },
      { id: 'master', title: '基础资料', subtitle: '门店、员工、账号、客户、服务套餐', group: '基础资料' },
      { id: 'ops', title: '预约与订单', subtitle: '预约管理、开单收款、次卡核销', group: '门店业务' },
      { id: 'manual', title: '后台代客', subtitle: '建档赠送、代客消费、记录修正', group: '门店业务' },
      { id: 'growth', title: '会员与营销', subtitle: '积分等级、券包、批量发券', group: '门店业务' },
      { id: 'finance', title: '收银与退款', subtitle: '在线支付、退款、小票打印', group: '财务结算' },
      { id: 'followpush', title: '回访与消息', subtitle: '回访计划任务、钉钉/飞书推送', group: '运营增长' },
      { id: 'bizplus', title: '转赠与开单礼', subtitle: '优惠券/次卡转赠与开单礼规则', group: '运营增长' },
      { id: 'commission', title: '提成管理', subtitle: '提成规则与员工业绩入口', group: '运营增长' },
      { id: 'reports', title: '报表中心', subtitle: '运营、渠道、项目、支付、复购、业绩', group: '经营分析' },
      { id: 'integration', title: '系统集成', subtitle: '站点用户同步、外部定时任务', group: '系统管理' },
      { id: 'portal', title: '用户端入口', subtitle: '客户扫码入口与访问令牌管理', group: '系统管理' },
      { id: 'system', title: '系统设置', subtitle: '后台入口、安全限制、基础配置', group: '系统管理' },
      { id: 'api', title: '接口调试台（高级）', subtitle: '开发调试工具，普通运营无需使用', group: '系统管理' },
    ],
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
    red: '小红书',
    meituan: '美团',
    dianping: '大众点评',
    dazhongdianping: '大众点评',
    shipinhao: '微信视频号',
    视频号: '微信视频号',
    weixinshipinhao: '微信视频号',
    wechat: '微信公众号',
    gongzhonghao: '微信公众号',
    微信公众号: '微信公众号',
    qiyeweixin: '企业微信',
    企微: '企业微信',
    pengyouqu: '朋友圈广告',
    baiduditu: '百度地图',
    gaodeditu: '高德地图',
    laodaixin: '老客转介绍',
    zhuanjieshao: '老客转介绍',
    dianhua: '电话咨询',
  };

  const SERVICE_CATEGORY_OPTIONS = [
    '皮肤管理',
    '面部护理',
    '身体养护',
    '头疗养发',
    '美甲美睫',
    '私密养护',
    '轻医美护理',
    '产后修复',
    '体态管理',
    '其他',
  ];

  const MOBILE_VALUE_FIELDS = new Set([
    'mobile',
    'customer_mobile',
    'from_customer_mobile',
    'to_customer_mobile',
    'contact_phone',
    'phone',
  ]);

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

  function renderSourceChannelDatalist() {
    const options = SOURCE_CHANNEL_OPTIONS
      .map((item) => `<option value="${escapeHtml(item)}"></option>`)
      .join('');
    return `<datalist id="qilingSourceChannelList">${options}</datalist>`;
  }

  function renderSourceChannelField(inputPlaceholder = '来源渠道（可手动填写）') {
    return `
      <select name="source_channel_preset" data-source-target="source_channel">
        ${renderSourceChannelOptionTags(true)}
      </select>
      <input name="source_channel" list="qilingSourceChannelList" placeholder="${escapeHtml(inputPlaceholder)}" />
    `;
  }

  function normalizeSourceChannel(value) {
    const raw = String(value || '').trim();
    if (raw === '') return '';
    const compact = raw.toLowerCase().replace(/[\s\-_/]/g, '');
    if (Object.prototype.hasOwnProperty.call(SOURCE_CHANNEL_ALIAS, compact)) {
      return SOURCE_CHANNEL_ALIAS[compact];
    }
    for (const item of SOURCE_CHANNEL_OPTIONS) {
      if (item.toLowerCase() === raw.toLowerCase()) {
        return item;
      }
    }
    return raw;
  }

  function normalizeMobileValue(value) {
    let raw = String(value || '').trim().replace(/\s+/g, '');
    if (raw.startsWith('+86')) raw = raw.slice(3);
    if (raw.startsWith('86') && raw.length === 13) raw = raw.slice(2);
    return raw;
  }

  function bindSourceChannelAssist(formId, inputName = 'source_channel', presetName = 'source_channel_preset', memoryKey = 'qiling_last_source_channel') {
    const form = document.getElementById(formId);
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

  function normalizeServiceCategory(value) {
    const raw = String(value || '').trim().replace(/\s+/g, ' ');
    if (raw === '') return '';
    return raw.length > 60 ? raw.slice(0, 60) : raw;
  }

  function mergeServiceCategories(categoryRows = []) {
    const set = new Set();
    SERVICE_CATEGORY_OPTIONS.forEach((name) => {
      const v = normalizeServiceCategory(name);
      if (v) set.add(v);
    });
    if (Array.isArray(categoryRows)) {
      categoryRows.forEach((row) => {
        const v = normalizeServiceCategory(row && row.category_name);
        if (v) set.add(v);
      });
    }
    return Array.from(set.values());
  }

  function renderServiceCategoryOptionTags(categoryRows = [], includeEmpty = true) {
    const names = mergeServiceCategories(categoryRows);
    const empty = includeEmpty ? '<option value="">快捷选择分类（可选）</option>' : '';
    const options = names.map((name) => `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`).join('');
    return `${empty}${options}`;
  }

  function renderServiceCategoryDatalist(categoryRows = [], datalistId = 'qilingServiceCategoryList') {
    const names = mergeServiceCategories(categoryRows);
    const options = names.map((name) => `<option value="${escapeHtml(name)}"></option>`).join('');
    return `<datalist id="${escapeHtml(datalistId)}">${options}</datalist>`;
  }

  function renderServiceCategoryField(categoryRows = [], inputPlaceholder = '服务分类（可手动填写）') {
    return `
      <select name="category_preset" data-service-category-target="category">
        ${renderServiceCategoryOptionTags(categoryRows, true)}
      </select>
      <input name="category" list="qilingServiceCategoryList" placeholder="${escapeHtml(inputPlaceholder)}" />
    `;
  }

  function bindServiceCategoryAssist(formId, categoryRows, inputName = 'category', presetName = 'category_preset', memoryKey = 'qiling_last_service_category') {
    const form = document.getElementById(formId);
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
      const normalized = normalizeServiceCategory(input.value);
      input.value = normalized;
      if (normalized) {
        localStorage.setItem(memoryKey, normalized);
      }
    };
    input.addEventListener('blur', persist);
    input.addEventListener('change', persist);
  }

  function applyStoreDefault(formId, inputName = 'store_id') {
    const form = document.getElementById(formId);
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

  function renderStoreDatalist(stores, datalistId = 'qilingStoreList') {
    const rows = Array.isArray(stores) ? stores : [];
    const options = rows.map((s) => {
      const id = toInt(s && s.id, 0);
      if (id <= 0) return '';
      const name = String((s && (s.store_name || s.name)) || '').trim();
      const code = String((s && s.store_code) || '').trim();
      const label = storeOptionLabel(s);
      const extra = code ? `${name} ${code}` : name;
      return `<option value="${escapeHtml(label)}" label="${escapeHtml(extra)}"></option>`;
    }).join('');
    return `<datalist id="${escapeHtml(datalistId)}">${options}</datalist>`;
  }

  function renderStoreField(stores, options = {}) {
    const inputName = String(options.inputName || 'store_id');
    const presetName = String(options.presetName || `${inputName}_preset`);
    const datalistId = String(options.datalistId || 'qilingStoreList');
    const inputPlaceholder = String(options.inputPlaceholder || '门店ID（可搜索门店名，也可手动输入）');
    const presetLabel = String(options.presetLabel || '快捷门店');
    const manualLabel = String(options.manualLabel || '');
    const inputClass = String(options.inputClass || '');
    const inputClassAttr = inputClass ? ` class="${escapeHtml(inputClass)}"` : '';
    const manualInput = `<input${inputClassAttr} name="${escapeHtml(inputName)}" list="${escapeHtml(datalistId)}" placeholder="${escapeHtml(inputPlaceholder)}" />`;
    const manualField = manualLabel !== ''
      ? `
      <label>
        <span>${escapeHtml(manualLabel)}</span>
        ${manualInput}
      </label>
    `
      : manualInput;
    return `
      <label>
        <span>${escapeHtml(presetLabel)}</span>
        <select name="${escapeHtml(presetName)}">
          ${renderStoreOptionTags(stores, true)}
        </select>
      </label>
      ${manualField}
    `;
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

  function bindStoreAssist(formId, stores, options = {}) {
    const form = document.getElementById(formId);
    if (!form) return;
    const inputName = String(options.inputName || 'store_id');
    const presetName = String(options.presetName || `${inputName}_preset`);
    const memoryKey = String(options.memoryKey || 'qiling_last_store_id');
    const input = form.querySelector(`[name="${inputName}"]`);
    if (!input) return;
    const preset = form.querySelector(`[name="${presetName}"]`);
    const defaultStoreId = toInt(state.user && state.user.staff_store_id, 0);
    const remembered = String(localStorage.getItem(memoryKey) || '').trim();

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
    window.setTimeout(() => node.remove(), 2600);
  }

  function setLoading(text = '加载中...') {
    el.viewRoot.innerHTML = `<div class="card loading">${escapeHtml(text)}</div>`;
  }

  function renderEmpty(text = '暂无数据') {
    return `<div class="empty">${escapeHtml(text)}</div>`;
  }

  function parseDateTimeInput(value) {
    const raw = String(value || '').trim();
    if (raw === '') return '';
    if (raw.includes('T')) {
      return raw.length === 16 ? `${raw.replace('T', ' ')}:00` : raw.replace('T', ' ');
    }
    return raw;
  }

  function parseListInput(value) {
    return String(value || '')
      .split(/[\n,，]/)
      .map((s) => s.trim())
      .filter(Boolean);
  }

  function parseCsvLines(text) {
    return String(text || '')
      .split(/\n+/)
      .map((line) => line.trim())
      .filter(Boolean)
      .map((line) => line.split(',').map((x) => x.trim()));
  }

  function parseJsonText(text, fallback = null) {
    const raw = String(text || '').trim();
    if (raw === '') return fallback;
    try {
      return JSON.parse(raw);
    } catch (_e) {
      throw new Error('数据格式解析失败，请检查输入格式后重试');
    }
  }

  function zhStatus(value) {
    const v = String(value ?? '').trim();
    const map = {
      active: '启用',
      inactive: '停用',
      booked: '已预约',
      completed: '已完成',
      cancelled: '已取消',
      no_show: '未到店',
      unpaid: '待支付',
      paid: '已支付',
      refunded: '已退款',
      pending: '待处理',
      skipped: '已跳过',
      sent: '已发送',
      success: '成功',
      failed: '失败',
      depleted: '已耗尽',
      expired: '已过期',
      processing: '处理中',
      closed: '已关闭',
    };
    return map[v] || v || '-';
  }

  function zhEnabled(value) {
    return toInt(value, 0) === 1 ? '是' : '否';
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

  function zhPayMethod(value) {
    const v = String(value || '').trim();
    const map = {
      cash: '现金',
      wechat: '微信',
      alipay: '支付宝',
      card: '银行卡',
      bank: '对公转账',
      other: '其他',
    };
    return map[v] || v || '-';
  }

  function zhCouponType(value) {
    const v = String(value || '').trim();
    const map = {
      cash: '满减券',
      discount: '折扣券',
    };
    return map[v] || v || '-';
  }

  function zhProvider(value) {
    const v = String(value || '').trim();
    const map = {
      dingtalk: '钉钉',
      feishu: '飞书',
      manual: '手工/本地',
    };
    return map[v] || v || '-';
  }

  function zhSecurityMode(value) {
    const v = String(value || '').trim();
    const map = {
      auto: '自动判断',
      none: '无校验',
      keyword: '关键词校验',
      sign: '签名校验',
    };
    return map[v] || v || '-';
  }

  function zhTriggerType(value) {
    const v = String(value || '').trim();
    const map = {
      appointment_completed: '预约完成',
      onboard: '建档后',
      first_paid: '首单支付后',
      manual: '手工触发',
    };
    return map[v] || v || '-';
  }

  function zhBusinessType(value) {
    const v = String(value || '').trim();
    const map = {
      order_receipt: '订单小票',
      manual: '手工内容',
    };
    return map[v] || v || '-';
  }

  function zhActionType(value) {
    const v = String(value || '').trim();
    const map = {
      open_card: '开卡',
      consume: '核销',
      adjust: '调整',
      rollback: '回滚',
      grant: '发放',
      manual_adjust: '手工调整',
      manual_consume: '手工核销',
      manual_consume_admin: '后台补录核销',
      manual_consume_adjust: '补录修正',
      transfer_in: '转入',
      transfer_out: '转出',
    };
    return map[v] || v || '-';
  }

  function zhChangeType(value) {
    const v = String(value || '').trim();
    const map = {
      manual_adjust: '手工调整',
      adjust: '调整',
      gift: '赠送',
      recharge: '充值',
      deduct: '扣减',
      consume: '消费',
      award: '奖励',
      open_gift: '开单礼',
      coupon_send: '发券',
    };
    return map[v] || v || '-';
  }

  function zhTriggerSource(value) {
    const v = String(value || '').trim();
    const map = {
      manual: '后台手工',
      cron: '定时任务',
      followup: '回访任务',
      followup_notify: '回访通知',
      appointment_created: '新预约通知',
      appointment_created_manual: '后台新建预约',
      appointment_created_portal: '用户端在线预约',
      system: '系统触发',
    };
    return map[v] || v || '-';
  }

  function zhTargetType(value) {
    const v = String(value || '').trim();
    const map = {
      all: '全部项目',
      service: '服务项目',
      package: '套餐/次卡',
      custom: '自定义项目',
    };
    return map[v] || v || '-';
  }

  function visibleNavItems() {
    const role = String((state.user && state.user.role_key) || '').trim();
    if (role === 'admin') return state.nav;

    const hidden = new Set(['api', 'system']);
    if (role === 'consultant' || role === 'operator' || role === '') {
      hidden.add('integration');
      hidden.add('portal');
    }
    if (role === 'consultant' || role === '') {
      hidden.add('commission');
    }
    return state.nav.filter((item) => !hidden.has(item.id));
  }

  function getSubTab(key, fallback) {
    const bucket = state.subTabs || {};
    const current = String(bucket[key] || '').trim();
    return current !== '' ? current : fallback;
  }

  function renderSubTabNav(key, tabs) {
    const fallback = tabs.length > 0 ? tabs[0].id : '';
    const current = getSubTab(key, fallback);
    return `
      <section class="card panel-top">
        <h3>功能导航</h3>
        <div class="subnav" data-subnav-key="${escapeHtml(key)}">
          ${tabs.map((t) => {
            const active = current === t.id ? 'active' : '';
            return `<button type="button" class="subnav-btn ${active}" data-subtab="${escapeHtml(t.id)}">${escapeHtml(t.title)}</button>`;
          }).join('')}
        </div>
        <p class="small-note">当前功能：${escapeHtml((tabs.find((t) => t.id === current) || tabs[0] || { subtitle: '' }).subtitle || '')}</p>
      </section>
    `;
  }

  function subTabClass(key, tabIds, fallback = '') {
    const ids = Array.isArray(tabIds) ? tabIds : [tabIds];
    const active = getSubTab(key, fallback || (ids[0] || ''));
    return ids.includes(active) ? '' : ' hidden';
  }

  function bindSubTabNav(key, fallback) {
    const nav = el.viewRoot.querySelector(`[data-subnav-key="${key}"]`);
    if (!nav) return;
    nav.querySelectorAll('[data-subtab]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const tab = btn.getAttribute('data-subtab') || fallback;
        if (getSubTab(key, fallback) === tab) return;
        state.subTabs[key] = tab;
        setLoading('正在切换菜单...');
        await openView(state.activeView);
      });
    });
  }

  function zhErrorMessage(message) {
    const raw = String(message || '').trim();
    if (raw === '') return '请求失败';
    const exact = {
      'Unauthorized': '未登录或登录已过期',
      'Forbidden': '没有权限执行此操作',
      'invalid credentials': '账号或密码错误',
      'account disabled': '账号已被停用',
      'account locked': '账号已临时锁定，请稍后重试',
      'account locked due to too many failed attempts': '连续输错次数过多，账号已临时锁定',
      'Route not found': '接口不存在',
      'admin only': '仅管理员可操作',
      'forbidden: manager only': '仅店长或管理员可操作',
      'cross-store query is forbidden': '不允许跨门店查询',
      'cross-store operation is forbidden': '不允许跨门店操作',
      'staff store is not configured': '员工未绑定门店，请先设置门店',
      'store_id is required': '门店ID不能为空',
      'order_id is required': '订单ID不能为空',
      'customer_id is required': '客户ID不能为空',
      'username and password are required': '请输入账号和密码',
      'username and email are required': '账号和邮箱不能为空',
      'username, email, password are required': '账号、邮箱、密码不能为空',
      'username or email already exists': '账号或邮箱已存在',
      'status must be active or inactive': '状态仅支持启用或停用',
      'payment_no is required': '支付单号不能为空',
      'alipay and wechat create both failed': '支付宝和微信二维码都创建失败，请检查支付配置',
      'ticket is required': '支付票据不能为空',
      'ticket invalid or expired': '支付票据无效或已过期',
      'channel_id is required': '渠道ID不能为空',
      'channel_ids invalid': '渠道ID列表格式不正确',
      'user not found': '用户不存在',
      'staff not found': '员工不存在',
      'store not found': '门店不存在',
      'cannot disable current login account': '不能停用当前登录账号',
      'new_password must be at least 6 chars': '新密码至少 6 位',
      'user_id is required': '用户ID不能为空',
      'id is required': 'ID 不能为空',
      'name and mobile are required': '姓名和手机号不能为空',
      'customer not found': '客户不存在',
      'customer reference is required': '请至少输入客户ID/会员编号/手机号之一',
      'invalid status': '状态参数无效',
      'token_id is required': '令牌ID不能为空',
      'token not found': '访问令牌不存在',
      'payment not found': '支付单不存在',
      'order not found': '订单不存在',
      'APP_KEY is missing': '系统密钥未配置，请先检查环境配置',
      'CRON_SHARED_KEY is missing': '定时任务密钥未配置，请先检查环境配置',
      'portal token is required': '访问令牌不能为空',
      'portal token invalid or expired': '访问令牌无效或已过期',
      'category_name is required': '服务分类名称不能为空',
      'service category already exists': '该服务分类已存在，请勿重复创建',
      'service category not found': '服务分类不存在，请刷新后重试',
      'create service failed': '创建服务失败，请检查输入后重试',
      'customer account disabled': '客户账号已停用',
      'keyword is required': '请输入检索关键词',
      'items are required': '请至少填写一条订单项目',
      'valid items are required': '订单项目格式无效，请检查后重试',
      'no users payload': '同步数据为空，请填写用户数据后再提交',
      'no valid setting fields to update': '没有可更新的配置项，请先修改内容',
    };
    if (Object.prototype.hasOwnProperty.call(exact, raw)) {
      return exact[raw];
    }

    const lower = raw.toLowerCase();

    if (lower.startsWith('http ')) {
      return `请求失败（${raw}）`;
    }

    if (lower.includes('required')) return '必填参数缺失，请检查输入后重试';
    if (lower.includes('not found')) return '数据不存在或已被删除，请刷新后重试';
    if (lower.includes('forbidden')) return '没有权限执行此操作';
    if (lower.includes('already exists')) return '数据已存在，不能重复创建';
    if (lower.includes('mismatch')) return '数据归属不匹配，请检查门店、客户或项目是否一致';
    if (lower.includes('must be positive')) return '输入值必须大于 0';
    if (lower.includes('cannot be zero')) return '输入值不能为 0';
    if (lower.includes('not enough')) return '可用余额或次数不足，无法继续';
    if (lower.includes('not active') || lower.includes('disabled')) return '当前状态不可操作，请先启用后再试';
    if (lower.includes('invalid')) return '参数格式无效，请检查后重试';
    if (lower.includes('failed')) return '操作失败，请检查输入后重试';
    if (lower.includes('server error')) return '服务器异常，请稍后重试';
    return raw;
  }

  function table(columns, rows, options = {}) {
    if (!Array.isArray(rows) || rows.length === 0) {
      return renderEmpty(options.emptyText || '暂无记录');
    }

    const maxRows = Math.min(rows.length, options.maxRows || 200);
    const head = columns.map((c) => `<th>${escapeHtml(c.label)}</th>`).join('');
    const body = rows.slice(0, maxRows).map((row) => {
      const cells = columns.map((c) => {
        const raw = typeof c.get === 'function' ? c.get(row) : row[c.key];
        const val = raw === null || raw === undefined ? '' : raw;
        if (typeof val === 'object') {
          return `<td><code>${escapeHtml(JSON.stringify(val))}</code></td>`;
        }
        return `<td>${escapeHtml(val)}</td>`;
      }).join('');
      return `<tr>${cells}</tr>`;
    }).join('');

    return `<div class="table-wrap"><table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`;
  }

  function getFormValues(form) {
    const fd = new FormData(form);
    const out = {};
    for (const [key, val] of fd.entries()) {
      if (typeof val !== 'string') {
        out[key] = val;
        continue;
      }
      let text = val.trim();
      if (MOBILE_VALUE_FIELDS.has(key)) {
        text = normalizeMobileValue(text);
      }
      if (key === 'source_channel') {
        text = normalizeSourceChannel(text);
      }
      if (key === 'category' || key === 'category_name') {
        text = normalizeServiceCategory(text);
      }
      if (key === 'store_id') {
        text = normalizeStoreId(text, state.storeOptions || []);
      }
      out[key] = text;
    }
    return out;
  }

  function jsonBox(id, initial = '') {
    return `<pre id="${id}">${escapeHtml(initial)}</pre>`;
  }

  function setJsonBox(id, payload) {
    const node = document.getElementById(id);
    if (!node) return;
    node.textContent = typeof payload === 'string' ? payload : JSON.stringify(payload, null, 2);
  }

  function toInt(v, fallback = 0) {
    const n = Number(v);
    return Number.isFinite(n) ? Math.trunc(n) : fallback;
  }

  function toFloat(v, fallback = 0) {
    const n = Number(v);
    return Number.isFinite(n) ? n : fallback;
  }

  function formatMoney(v) {
    const n = toFloat(v, 0);
    return n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function formatPercent(v) {
    return `${toFloat(v, 0).toFixed(2)}%`;
  }

  function formatNumber(v) {
    return toInt(v, 0).toLocaleString('zh-CN');
  }

  function dateInputValue(offsetDays = 0) {
    const d = new Date();
    d.setDate(d.getDate() + offsetDays);
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  }

  function pickData(payload) {
    if (Array.isArray(payload)) return payload;
    if (payload && Array.isArray(payload.data)) return payload.data;
    return [];
  }

  function endpoint(path) {
    return `${API_PREFIX}${path.startsWith('/') ? path : `/${path}`}`;
  }

  function appPath(path) {
    if (/^https?:\/\//i.test(String(path || ''))) {
      return String(path || '');
    }
    const suffix = `/${String(path || '').replace(/^\/+/, '')}`;
    return `${ROOT_PATH}${suffix === '/.' ? '/' : suffix}`.replace(/\/{2,}/g, '/');
  }

  function appBaseUrl() {
    return `${window.location.origin}${ROOT_PATH}`;
  }

  function absolutePageUrl(path) {
    if (/^https?:\/\//i.test(String(path || ''))) {
      return String(path || '');
    }
    return `${window.location.origin}${appPath(path)}`;
  }

  function renderPageLinksCard(title, items) {
    const rows = Array.isArray(items) ? items : [];
    const cards = rows.map((item) => {
      const label = String(item && item.label ? item.label : '');
      const path = String(item && item.path ? item.path : '/');
      const full = absolutePageUrl(path);
      return `
        <article class="page-url-item">
          <div class="page-url-main">
            <h4>${escapeHtml(label)}</h4>
            <p><code>${escapeHtml(path)}</code></p>
            <a href="${escapeHtml(full)}" target="_blank" rel="noopener">${escapeHtml(full)}</a>
          </div>
          <div class="page-url-actions">
            <button type="button" class="btn btn-line" data-copy-url="${escapeHtml(full)}">复制地址</button>
          </div>
        </article>
      `;
    }).join('');
    return `
      <h3>${escapeHtml(title)}</h3>
      <div class="page-url-list">${cards || '<div class="empty">暂无地址</div>'}</div>
    `;
  }

  async function copyTextWithFallback(text) {
    const content = String(text || '').trim();
    if (content === '') return false;
    try {
      await navigator.clipboard.writeText(content);
      return true;
    } catch (_e) {
      window.prompt('复制失败，请手动复制以下地址：', content);
      return false;
    }
  }

  function bindCopyUrlButtons(scope = el.viewRoot) {
    if (!scope) return;
    scope.querySelectorAll('[data-copy-url]').forEach((btn) => {
      if (btn.dataset.boundCopy === '1') return;
      btn.dataset.boundCopy = '1';
      btn.addEventListener('click', async () => {
        const text = String(btn.getAttribute('data-copy-url') || '').trim();
        const ok = await copyTextWithFallback(text);
        if (ok) toast('地址已复制', 'ok');
      });
    });
  }

  async function hmacSha256Hex(secret, message) {
    const keyText = String(secret || '');
    if (!keyText) {
      throw new Error('同步密钥不能为空');
    }
    if (!window.crypto || !window.crypto.subtle) {
      throw new Error('当前浏览器不支持签名计算（WebCrypto）');
    }
    const enc = new TextEncoder();
    const key = await window.crypto.subtle.importKey(
      'raw',
      enc.encode(keyText),
      { name: 'HMAC', hash: 'SHA-256' },
      false,
      ['sign']
    );
    const sig = await window.crypto.subtle.sign('HMAC', key, enc.encode(String(message || '')));
    return Array.from(new Uint8Array(sig))
      .map((b) => b.toString(16).padStart(2, '0'))
      .join('');
  }

  async function request(method, path, { query = null, body = null, auth = true, extraHeaders = null } = {}) {
    const url = new URL(endpoint(path), window.location.origin);
    if (query && typeof query === 'object') {
      Object.entries(query).forEach(([k, v]) => {
        if (v === undefined || v === null || v === '') return;
        url.searchParams.set(k, String(v));
      });
    }

    const headers = {
      'Content-Type': 'application/json',
    };

    if (auth && state.token) {
      headers.Authorization = `Bearer ${state.token}`;
    }

    if (extraHeaders && typeof extraHeaders === 'object') {
      Object.entries(extraHeaders).forEach(([k, v]) => {
        if (v === undefined || v === null || v === '') return;
        headers[k] = String(v);
      });
    }

    const resp = await fetch(url.toString(), {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined,
    });

    let payload = null;
    try {
      payload = await resp.json();
    } catch (_e) {
      payload = null;
    }

    if (!resp.ok) {
      const message = (payload && payload.message) ? payload.message : `HTTP ${resp.status}`;
      const errorDetail = (payload && payload.error) ? payload.error : '';
      if (resp.status === 401) {
        logout(false);
      }
      const msg = zhErrorMessage(message);
      const detail = errorDetail ? zhErrorMessage(errorDetail) : '';
      const merged = detail && detail !== msg ? `${msg}（${detail}）` : msg;
      throw new Error(merged);
    }

    return payload || {};
  }

  function renderNav() {
    const navItems = visibleNavItems();
    if (!navItems.some((item) => item.id === state.activeView) && navItems.length > 0) {
      state.activeView = navItems[0].id;
    }

    const grouped = navItems.reduce((acc, item) => {
      const key = item.group || '其他';
      if (!Object.prototype.hasOwnProperty.call(acc, key)) {
        acc[key] = [];
      }
      acc[key].push(item);
      return acc;
    }, {});

    el.navList.innerHTML = Object.entries(grouped).map(([group, items]) => `
      <section class="nav-group">
        <p class="nav-group-title">${escapeHtml(group)}</p>
        ${items.map((item) => {
          const active = item.id === state.activeView ? 'active' : '';
          return `<button class="nav-item ${active}" data-view="${item.id}" title="${escapeHtml(item.subtitle)}">${escapeHtml(item.title)}</button>`;
        }).join('')}
      </section>
    `).join('');

    el.navList.querySelectorAll('.nav-item').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-view') || 'dashboard';
        openView(id);
      });
    });
  }

  async function tryAuthMe() {
    if (!state.token) return false;
    try {
      const me = await request('GET', '/auth/me');
      state.user = me.user || null;
      return !!state.user;
    } catch (_e) {
      return false;
    }
  }

  function showLogin() {
    el.loginScreen.classList.remove('hidden');
    el.appScreen.classList.add('hidden');
  }

  function showApp() {
    el.loginScreen.classList.add('hidden');
    el.appScreen.classList.remove('hidden');
    const user = state.user || {};
    const storeId = toInt(user.staff_store_id, 0);
    el.userName.textContent = user.username || '-';
    el.userMeta.textContent = `${zhRole(user.role_key)} · ${storeId > 0 ? `门店#${storeId}` : '总部/未绑定门店'}`;
  }

  function logout(showToast = true) {
    state.token = '';
    state.user = null;
    localStorage.removeItem(TOKEN_KEY);
    showLogin();
    if (showToast) toast('已退出登录', 'info');
  }

  async function bindJsonForm(formId, resultId, handler) {
    const form = document.getElementById(formId);
    if (!form) return;

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const confirmText = String(form.getAttribute('data-confirm') || '').trim();
      if (confirmText !== '' && !window.confirm(confirmText)) {
        return;
      }
      try {
        const result = await handler(form);
        setJsonBox(resultId, result);
        const successText = String(form.getAttribute('data-success') || '操作成功，已为你保存本次操作').trim();
        toast(successText, 'ok');
      } catch (err) {
        setJsonBox(resultId, { message: err.message });
        toast(err.message, 'error');
      }
    });
  }

  async function openView(viewId) {
    const navItems = visibleNavItems();
    const safeViewId = navItems.some((v) => v.id === viewId) ? viewId : ((navItems[0] && navItems[0].id) || 'dashboard');
    state.activeView = safeViewId;
    renderNav();

    const meta = navItems.find((v) => v.id === safeViewId) || state.nav.find((v) => v.id === safeViewId);
    el.viewTitle.textContent = meta ? meta.title : '控制台';
    el.viewSubtitle.textContent = meta ? meta.subtitle : '实时连接业务接口';

    setLoading('正在加载模块...');

    try {
      switch (safeViewId) {
        case 'dashboard':
          await renderDashboard();
          break;
        case 'master':
          await renderMaster();
          break;
        case 'ops':
          await renderOps();
          break;
        case 'manual':
          await renderManual();
          break;
        case 'growth':
          await renderGrowth();
          break;
        case 'finance':
          await renderFinance();
          break;
        case 'followpush':
          await renderFollowupPush();
          break;
        case 'bizplus':
          await renderBizPlus();
          break;
        case 'commission':
          await renderCommission();
          break;
        case 'integration':
          await renderIntegration();
          break;
        case 'portal':
          await renderPortal();
          break;
        case 'reports':
          await renderReports();
          break;
        case 'system':
          await renderSystemSettings();
          break;
        case 'api':
          await renderApiLab();
          break;
        default:
          await renderDashboard();
      }
    } catch (err) {
      el.viewRoot.innerHTML = `<div class="card"><h3>加载失败</h3><p>${escapeHtml(err.message)}</p></div>`;
      toast(err.message, 'error');
    }
  }

  async function renderDashboard() {
    const [summary, opsOverview, followup, pushLogs] = await Promise.all([
      request('GET', '/dashboard/summary'),
      request('GET', '/reports/operation-overview', {
        query: {
          date_from: dateInputValue(-6),
          date_to: dateInputValue(0),
        },
      }),
      request('GET', '/followup/tasks', { query: { limit: 20 } }),
      request('GET', '/push/logs', { query: { limit: 20 } }),
    ]);

    const s = summary.summary || {};
    const ops = opsOverview.summary || {};
    const followupRows = pickData(followup);
    const pushRows = pickData(pushLogs);

    el.viewRoot.innerHTML = `
      <section class="grid kpi">
        <article class="kpi-item"><span>近7天收款</span><b>¥${escapeHtml(formatMoney(ops.paid_amount))}</b></article>
        <article class="kpi-item"><span>近7天退款</span><b>¥${escapeHtml(formatMoney(ops.refund_amount))}</b></article>
        <article class="kpi-item"><span>近7天净收入</span><b>¥${escapeHtml(formatMoney(ops.net_amount))}</b></article>
        <article class="kpi-item"><span>近7天支付订单</span><b>${escapeHtml(formatNumber(ops.paid_orders))}</b></article>
        <article class="kpi-item"><span>近7天新增客户</span><b>${escapeHtml(formatNumber(ops.new_customers))}</b></article>
        <article class="kpi-item"><span>近7天活跃客户</span><b>${escapeHtml(formatNumber(ops.active_customers))}</b></article>
        <article class="kpi-item"><span>近7天复购率</span><b>${escapeHtml(formatPercent(ops.repurchase_rate))}</b></article>
        <article class="kpi-item"><span>近7天客单价</span><b>¥${escapeHtml(formatMoney(ops.avg_order_amount))}</b></article>
      </section>

      <section class="grid kpi">
        <article class="kpi-item"><span>门店数</span><b>${escapeHtml(formatNumber(s.stores))}</b></article>
        <article class="kpi-item"><span>员工数</span><b>${escapeHtml(formatNumber(s.staff))}</b></article>
        <article class="kpi-item"><span>客户数</span><b>${escapeHtml(formatNumber(s.customers))}</b></article>
        <article class="kpi-item"><span>已同步站点用户</span><b>${escapeHtml(formatNumber(s.wp_users_synced))}</b></article>
      </section>

      <section class="card">
        <h3>运营提示</h3>
        <p class="small-note">当前概览区间：最近 7 天。更详细的趋势、渠道、项目与支付结构请进入「报表中心」。</p>
      </section>

      <section class="card">
        <h3>待处理回访任务</h3>
        ${table([
          { label: '任务ID', key: 'id' },
          { label: '客户', get: (r) => `${r.customer_name || ''} (${r.customer_mobile || ''})` },
          { label: '状态', get: (r) => zhStatus(r.status) },
          { label: '到期时间', key: 'due_at' },
          { label: '计划', key: 'plan_name' },
        ], followupRows, { maxRows: 12, emptyText: '暂无回访任务' })}
      </section>

      <section class="card">
        <h3>推送日志</h3>
        ${table([
          { label: '日志ID', key: 'id' },
          { label: '渠道', key: 'channel_name' },
          { label: '状态', get: (r) => zhStatus(r.status) },
          { label: '目标', key: 'target' },
          { label: '时间', key: 'created_at' },
        ], pushRows, { maxRows: 12, emptyText: '暂无推送日志' })}
      </section>
    `;
  }

  async function renderMaster() {
    const tabs = [
      { id: 'stores', title: '门店管理', subtitle: '门店档案、营业信息、状态启停' },
      { id: 'staff', title: '员工管理', subtitle: '员工档案、工号、岗位、状态' },
      { id: 'users', title: '账号用户', subtitle: '登录账号、角色权限、密码重置' },
      { id: 'customers', title: '客户档案', subtitle: '会员建档、来源、标签' },
      { id: 'services', title: '服务套餐', subtitle: '服务项目、套餐次卡定义' },
    ];

    const activeTab = tabs.some((x) => x.id === state.masterTab) ? state.masterTab : 'stores';
    state.masterTab = activeTab;
    const activeMeta = tabs.find((x) => x.id === activeTab) || tabs[0];

    const tabHeader = `
      <section class="card panel-top">
        <h3>主数据二级菜单</h3>
        <div class="subnav">
          ${tabs.map((t) => {
            const active = t.id === activeTab ? 'active' : '';
            return `<button type="button" class="subnav-btn ${active}" data-master-tab="${t.id}">${escapeHtml(t.title)}</button>`;
          }).join('')}
        </div>
        <p class="small-note">${escapeHtml(activeMeta.subtitle)}</p>
      </section>
    `;

    let contentHtml = '';

    if (activeTab === 'stores') {
      const storesRes = await request('GET', '/stores');
      const stores = pickData(storesRes);
      contentHtml = `
        <section class="row">
          <article class="card">
            <h3>新增门店</h3>
            <form id="formCreateStore" class="form-grid">
              <input name="store_name" placeholder="门店名称" required />
              <input name="store_code" placeholder="门店编码（可空自动生成）" />
              <input name="contact_name" placeholder="联系人" />
              <input name="contact_phone" placeholder="联系电话" />
              <input name="address" placeholder="门店地址" />
              <input name="open_time" placeholder="营业开始时间（如 09:00）" />
              <input name="close_time" placeholder="营业结束时间（如 21:00）" />
              <button class="btn btn-primary" type="submit">创建门店</button>
            </form>
          </article>
          <article class="card">
            <h3>编辑门店</h3>
            <form id="formUpdateStore" class="form-grid">
              <input name="id" placeholder="门店ID" required />
              <input name="store_name" placeholder="门店名称（可改）" required />
              <input name="contact_name" placeholder="联系人" />
              <input name="contact_phone" placeholder="联系电话" />
              <input name="address" placeholder="门店地址" />
              <input name="open_time" placeholder="营业开始时间（如 09:00）" />
              <input name="close_time" placeholder="营业结束时间（如 21:00）" />
              <select name="status">
                <option value="active">启用</option>
                <option value="inactive">停用</option>
              </select>
              <button class="btn btn-primary" type="submit">保存门店信息</button>
            </form>
          </article>
        </section>
        <section class="card"><h3>门店列表</h3>${table([
          { label: 'ID', key: 'id' },
          { label: '编码', key: 'store_code' },
          { label: '门店名称', key: 'store_name' },
          { label: '联系人', key: 'contact_name' },
          { label: '联系电话', key: 'contact_phone' },
          { label: '营业时间', get: (r) => `${r.open_time || '-'} ~ ${r.close_time || '-'}` },
          { label: '状态', get: (r) => zhStatus(r.status) },
        ], stores, { maxRows: 100 })}</section>
      `;
    }

    if (activeTab === 'staff') {
      const staffRes = await request('GET', '/staff');
      const staff = pickData(staffRes);
      contentHtml = `
        <section class="row">
          <article class="card">
            <h3>新增员工（自动创建登录账号）</h3>
            <form id="formCreateStaff" class="form-grid">
              <input name="username" placeholder="登录账号" required />
              <input type="email" name="email" placeholder="登录邮箱" required />
              <input type="password" name="password" placeholder="登录密码" required />
              <select name="role_key">
                <option value="consultant">顾问</option>
                <option value="manager">店长</option>
                <option value="admin">管理员</option>
              </select>
              <input name="store_id" placeholder="门店ID" />
              <input name="staff_no" placeholder="员工工号（可空）" />
              <input name="phone" placeholder="手机号" />
              <input name="title" placeholder="岗位/头衔（可空）" />
              <button class="btn btn-primary" type="submit">创建员工</button>
            </form>
          </article>
          <article class="card">
            <h3>编辑员工资料</h3>
            <form id="formUpdateStaff" class="form-grid">
              <input name="id" placeholder="员工ID" required />
              <input name="store_id" placeholder="门店ID" />
              <select name="role_key">
                <option value="">员工角色（不修改）</option>
                <option value="consultant">顾问</option>
                <option value="manager">店长</option>
                <option value="admin">管理员</option>
              </select>
              <select name="user_role_key">
                <option value="">登录角色（不修改）</option>
                <option value="consultant">顾问</option>
                <option value="manager">店长</option>
                <option value="admin">管理员</option>
              </select>
              <input name="staff_no" placeholder="员工工号" />
              <input name="phone" placeholder="手机号" />
              <input name="title" placeholder="岗位/头衔" />
              <select name="status">
                <option value="active">启用</option>
                <option value="inactive">停用</option>
              </select>
              <button class="btn btn-primary" type="submit">保存员工资料</button>
            </form>
          </article>
        </section>
        <section class="card"><h3>员工列表</h3>${table([
          { label: '员工ID', key: 'id' },
          { label: '账号', key: 'username' },
          { label: '邮箱', key: 'email' },
          { label: '登录角色', get: (r) => zhRole(r.user_role_key) },
          { label: '员工角色', get: (r) => zhRole(r.role_key) },
          { label: '门店ID', key: 'store_id' },
          { label: '门店名称', key: 'store_name' },
          { label: '工号', key: 'staff_no' },
          { label: '电话', key: 'phone' },
          { label: '状态', get: (r) => zhStatus(r.status) },
        ], staff, { maxRows: 120 })}</section>
      `;
    }

    if (activeTab === 'users') {
      const usersRes = await request('GET', '/users');
      const users = pickData(usersRes);
      contentHtml = `
        <section class="row">
          <article class="card">
            <h3>账号信息编辑</h3>
            <form id="formUserUpdate" class="form-grid">
              <input name="user_id" placeholder="用户ID" required />
              <input name="username" placeholder="登录账号" required />
              <input name="email" placeholder="登录邮箱" required />
              <select name="role_key">
                <option value="consultant">顾问</option>
                <option value="manager">店长</option>
                <option value="admin">管理员</option>
              </select>
              <select name="status">
                <option value="active">启用</option>
                <option value="inactive">停用</option>
              </select>
              <input name="store_id" placeholder="所属门店ID（无门店可填 0）" value="0" />
              <select name="staff_role_key">
                <option value="consultant">顾问</option>
                <option value="manager">店长</option>
                <option value="admin">管理员</option>
              </select>
              <input name="staff_no" placeholder="员工工号（可空）" />
              <input name="phone" placeholder="手机号（可空）" />
              <input name="title" placeholder="岗位/头衔（可空）" />
              <select name="staff_status">
                <option value="active">员工状态：启用</option>
                <option value="inactive">员工状态：停用</option>
              </select>
              <button class="btn btn-primary" type="submit">保存账号</button>
            </form>
          </article>
          <article class="card">
            <h3>账号状态与密码</h3>
            <p class="small-note">用户ID可在下方“账号用户列表”第一列查看。</p>
            <form id="formUserStatus" class="form-grid" data-confirm="确定修改账号状态？停用后该账号将无法登录。">
              <input name="user_id" placeholder="用户ID" required />
              <select name="status">
                <option value="active">启用账号</option>
                <option value="inactive">停用账号</option>
              </select>
              <button class="btn btn-line" type="submit">修改账号状态</button>
            </form>
            <hr />
            <form id="formUserResetPassword" class="form-grid" data-confirm="确定重置该账号密码？请先通知员工。">
              <input name="user_id" placeholder="用户ID" required />
              <input type="password" name="new_password" placeholder="新密码（至少6位）" required />
              <button class="btn btn-primary" type="submit">重置登录密码</button>
            </form>
            <p class="small-note">员工登录入口和管理员一致，使用“账号 + 密码”登录。</p>
          </article>
        </section>
        <section class="card"><h3>账号用户列表</h3>${table([
          { label: '用户ID', key: 'id' },
          { label: '账号', key: 'username' },
          { label: '邮箱', key: 'email' },
          { label: '账号角色', get: (r) => zhRole(r.role_key) },
          { label: '账号状态', get: (r) => zhStatus(r.status) },
          { label: '员工ID', key: 'staff_id' },
          { label: '门店', get: (r) => `${r.store_name || '-'} (#${r.store_id || 0})` },
          { label: '员工角色', get: (r) => zhRole(r.staff_role_key) },
          { label: '员工状态', get: (r) => zhStatus(r.staff_status) },
          { label: '更新时间', key: 'updated_at' },
        ], users, { maxRows: 150 })}</section>
      `;
    }

    if (activeTab === 'customers') {
      const [customersRes, storesRes] = await Promise.all([
        request('GET', '/customers'),
        request('GET', '/stores'),
      ]);
      const customers = pickData(customersRes);
      const stores = pickData(storesRes);
      state.storeOptions = stores;
      contentHtml = `
        <section class="row">
          <article class="card">
            <h3>新建客户档案</h3>
            <form id="formCreateCustomer" class="form-grid">
              <input name="name" placeholder="客户姓名" required />
              <input name="mobile" placeholder="手机号" required />
              ${renderStoreField(stores, {
                inputName: 'store_id',
                presetName: 'store_id_preset',
                datalistId: 'qilingStoreListCreateCustomer',
                inputPlaceholder: '门店ID（默认当前登录门店，可手动改）',
                presetLabel: '所属门店',
                inputClass: 'field-compact',
              })}
              ${renderSourceChannelField('来源渠道（可手动填写，如：抖音）')}
              <input name="tags" placeholder="标签，逗号分隔（例如：敏感肌,高复购）" />
              <button class="btn btn-primary" type="submit">创建客户</button>
            </form>
            <p class="small-note">建档会自动生成 4-6 位数字前台口令，方便门店和客户快速登录用户端。</p>
            ${renderStoreDatalist(stores, 'qilingStoreListCreateCustomer')}
            ${renderSourceChannelDatalist()}
          </article>
        </section>
        <section class="card"><h3>客户列表</h3>${table([
          { label: '客户ID', key: 'id' },
          { label: '会员编号', key: 'customer_no' },
          { label: '姓名', key: 'name' },
          { label: '手机', key: 'mobile' },
          { label: '门店', get: (r) => `${r.store_name || '-'} (#${r.store_id || 0})` },
          { label: '前台口令', get: (r) => (r.portal_token || '-') },
          { label: '口令到期', get: (r) => (r.portal_expire_at || '长期有效') },
          { label: '来源渠道', key: 'source_channel' },
          { label: '标签', get: (r) => Array.isArray(r.tags) ? r.tags.join(' / ') : '' },
          { label: '状态', get: (r) => zhStatus(r.status) },
        ], customers, { maxRows: 120 })}</section>
      `;
    }

    if (activeTab === 'services') {
      const [servicesRes, packageRes, serviceCategoryRes, storesRes] = await Promise.all([
        request('GET', '/services'),
        request('GET', '/service-packages'),
        request('GET', '/service-categories'),
        request('GET', '/stores'),
      ]);
      const services = pickData(servicesRes);
      const packages = pickData(packageRes);
      const serviceCategories = pickData(serviceCategoryRes);
      const stores = pickData(storesRes);
      state.storeOptions = stores;
      const serviceRows = Array.isArray(services) ? services : [];
      const serviceCategoryRows = Array.isArray(serviceCategories) ? serviceCategories : [];
      const serviceQuickOptions = serviceRows.map((s) => {
        const id = toInt(s && s.id, 0);
        if (id <= 0) return '';
        const name = String((s && s.service_name) || '').trim() || `服务#${id}`;
        const category = String((s && s.category) || '').trim();
        const suffix = category ? ` · ${category}` : '';
        return `<option value="${id}">${escapeHtml(`${name} (#${id})${suffix}`)}</option>`;
      }).join('');
      contentHtml = `
        <section class="row">
          <article class="card">
            <h3>新增服务分类</h3>
            <form id="formCreateServiceCategory" class="form-grid">
              ${renderStoreField(stores, {
                inputName: 'store_id',
                presetName: 'store_id_preset',
                datalistId: 'qilingStoreListCreateServiceCategory',
                inputPlaceholder: '门店ID（默认当前登录门店，可手动改）',
                presetLabel: '所属门店',
                manualLabel: '门店ID',
                inputClass: 'field-compact',
              })}
              <input name="category_name" placeholder="分类名称（如 皮肤管理）" required />
              <input name="sort_order" type="number" placeholder="排序（越小越靠前）" value="100" />
              <select name="status">
                <option value="active">启用</option>
                <option value="inactive">停用</option>
              </select>
              <button class="btn btn-line" type="submit">创建分类</button>
            </form>
            ${renderStoreDatalist(stores, 'qilingStoreListCreateServiceCategory')}
          </article>

          <article class="card">
            <h3>新增服务项目</h3>
            <form id="formCreateService" class="form-grid">
              <input name="service_name" placeholder="服务名称" required />
              <input name="service_code" placeholder="服务编码（可空自动生成）" />
              <input name="store_id" placeholder="门店ID" />
              ${renderServiceCategoryField(serviceCategoryRows, '服务分类（可手动填写）')}
              <label class="check-line"><input type="checkbox" name="supports_online_booking" value="1" /><span>支持用户端在线预约</span></label>
              <input name="duration_minutes" type="number" placeholder="时长（分钟）" value="60" />
              <input name="list_price" type="number" step="0.01" placeholder="标价" value="0" />
              <button class="btn btn-primary" type="submit">创建服务</button>
            </form>
            ${renderServiceCategoryDatalist(serviceCategoryRows, 'qilingServiceCategoryList')}
          </article>
          <article class="card">
            <h3>新增套餐/次卡</h3>
            <form id="formCreatePackage" class="form-grid">
              <input name="package_name" placeholder="套餐名称" required />
              <input name="package_code" placeholder="套餐编码（可空自动生成）" />
              <input name="store_id" placeholder="门店ID" />
              <select name="service_id_preset">
                <option value="">快捷选择服务（可选）</option>
                ${serviceQuickOptions}
              </select>
              <input name="service_id" placeholder="关联服务ID（可手动填写）" />
              <input name="total_sessions" type="number" placeholder="总次数" value="10" />
              <input name="sale_price" type="number" step="0.01" placeholder="售价" value="0" />
              <input name="valid_days" type="number" placeholder="有效天数" value="365" />
              <button class="btn btn-primary" type="submit">创建套餐</button>
            </form>
          </article>
        </section>
        <section class="card">
          <h3>服务分类管理</h3>
          <p class="small-note">分类可用于服务项目的标准化录入。支持改名、排序和停用，停用后不会影响历史服务记录。</p>
          <form id="formUpdateServiceCategory" class="form-grid">
            <input name="id" placeholder="分类ID（从下方列表复制）" required />
            <input name="category_name" placeholder="分类名称" required />
            <input name="sort_order" type="number" placeholder="排序（越小越靠前）" value="100" />
            <select name="status">
              <option value="active">启用</option>
              <option value="inactive">停用</option>
            </select>
            <button class="btn btn-line" type="submit">更新分类</button>
          </form>
          ${table([
            { label: '分类ID', key: 'id' },
            { label: '分类名称', key: 'category_name' },
            { label: '门店', get: (r) => `${r.store_name || '总部'} (#${r.store_id || 0})` },
            { label: '排序', key: 'sort_order' },
            { label: '状态', get: (r) => zhStatus(r.status) },
            { label: '更新时间', key: 'updated_at' },
          ], serviceCategoryRows, { maxRows: 120, emptyText: '暂无服务分类，请先创建' })}
        </section>
        <section class="card"><h3>服务项目列表</h3>${table([
          { label: '服务ID', key: 'id' },
          { label: '编码', key: 'service_code' },
          { label: '服务名称', key: 'service_name' },
          { label: '门店ID', key: 'store_id' },
          { label: '分类', key: 'category' },
          { label: '在线预约', get: (r) => toInt(r.supports_online_booking, 0) === 1 ? '支持' : '关闭' },
          { label: '时长', key: 'duration_minutes' },
          { label: '标价', key: 'list_price' },
          { label: '状态', get: (r) => zhStatus(r.status) },
        ], services, { maxRows: 120 })}</section>
        <section class="card"><h3>套餐/次卡定义</h3>${table([
          { label: '套餐ID', key: 'id' },
          { label: '编码', key: 'package_code' },
          { label: '名称', key: 'package_name' },
          { label: '服务', key: 'service_name' },
          { label: '总次数', key: 'total_sessions' },
          { label: '售价', key: 'sale_price' },
          { label: '有效天数', key: 'valid_days' },
          { label: '状态', get: (r) => zhStatus(r.status) },
        ], packages, { maxRows: 120 })}</section>
      `;
    }

    el.viewRoot.innerHTML = `${tabHeader}${contentHtml}<section class="card"><h3>操作返回</h3>${jsonBox('masterResult', '等待操作')}</section>`;

    el.viewRoot.querySelectorAll('[data-master-tab]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const tab = btn.getAttribute('data-master-tab') || 'stores';
        if (tab === state.masterTab) return;
        state.masterTab = tab;
        setLoading('正在切换子菜单...');
        try {
          await renderMaster();
        } catch (err) {
          const msg = err && err.message ? err.message : '切换失败';
          toast(msg, 'error');
        }
      });
    });

    bindSourceChannelAssist('formCreateCustomer', 'source_channel', 'source_channel_preset', 'qiling_last_source_channel');
    bindStoreAssist('formCreateCustomer', state.storeOptions || [], {
      inputName: 'store_id',
      presetName: 'store_id_preset',
      memoryKey: 'qiling_last_store_id',
    });
    bindStoreAssist('formCreateServiceCategory', state.storeOptions || [], {
      inputName: 'store_id',
      presetName: 'store_id_preset',
      memoryKey: 'qiling_last_store_id',
    });
    bindServiceCategoryAssist('formCreateService', null, 'category', 'category_preset', 'qiling_last_service_category');
    applyStoreDefault('formCreateService');
    applyStoreDefault('formCreatePackage');
    applyStoreDefault('formCreateServiceCategory');

    const servicePreset = document.querySelector('#formCreatePackage [name="service_id_preset"]');
    const serviceInput = document.querySelector('#formCreatePackage [name="service_id"]');
    if (servicePreset && serviceInput) {
      servicePreset.addEventListener('change', () => {
        const selected = String(servicePreset.value || '').trim();
        if (selected !== '') {
          serviceInput.value = selected;
          serviceInput.focus();
        }
      });
    }

    bindJsonForm('formCreateStore', 'masterResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/stores', { body: v });
    });

    bindJsonForm('formUpdateStore', 'masterResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/stores/update', {
        body: {
          id: toInt(v.id, 0),
          store_name: v.store_name,
          contact_name: v.contact_name || '',
          contact_phone: v.contact_phone || '',
          address: v.address || '',
          open_time: v.open_time || '',
          close_time: v.close_time || '',
          status: v.status || 'active',
        },
      });
    });

    bindJsonForm('formCreateStaff', 'masterResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        username: v.username,
        email: v.email,
        password: v.password,
        role_key: v.role_key || 'consultant',
        store_id: toInt(v.store_id, 0),
        phone: v.phone || '',
        staff_no: v.staff_no || '',
        title: v.title || '',
      };
      return request('POST', '/staff', { body });
    });

    bindJsonForm('formUpdateStaff', 'masterResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/staff/update', {
        body: {
          id: toInt(v.id, 0),
          store_id: toInt(v.store_id, 0),
          role_key: v.role_key || '',
          user_role_key: v.user_role_key || '',
          staff_no: v.staff_no || '',
          phone: v.phone || '',
          title: v.title || '',
          status: v.status || 'active',
        },
      });
    });

    bindJsonForm('formUserUpdate', 'masterResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/users/update', {
        body: {
          user_id: toInt(v.user_id, 0),
          username: v.username,
          email: v.email,
          role_key: v.role_key || 'consultant',
          status: v.status || 'active',
          store_id: toInt(v.store_id, 0),
          staff_role_key: v.staff_role_key || 'consultant',
          staff_no: v.staff_no || '',
          phone: v.phone || '',
          title: v.title || '',
          staff_status: v.staff_status || 'active',
        },
      });
    });

    bindJsonForm('formUserStatus', 'masterResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/users/status', {
        body: {
          user_id: toInt(v.user_id, 0),
          status: v.status || 'active',
        },
      });
    });

    bindJsonForm('formUserResetPassword', 'masterResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/users/reset-password', {
        body: {
          user_id: toInt(v.user_id, 0),
          new_password: v.new_password || '',
        },
      });
    });

    bindJsonForm('formCreateCustomer', 'masterResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        name: v.name,
        mobile: v.mobile,
        store_id: toInt(v.store_id, 0),
        source_channel: v.source_channel || '',
        tags: parseListInput(v.tags),
      };
      return request('POST', '/customers', { body });
    });

    bindJsonForm('formCreateServiceCategory', 'masterResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        store_id: toInt(v.store_id, 0),
        category_name: v.category_name || '',
        sort_order: toInt(v.sort_order, 100),
        status: v.status || 'active',
      };
      return request('POST', '/service-categories', { body });
    });

    bindJsonForm('formUpdateServiceCategory', 'masterResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        id: toInt(v.id, 0),
        category_name: v.category_name || '',
        sort_order: toInt(v.sort_order, 100),
        status: v.status || 'active',
      };
      return request('POST', '/service-categories/update', { body });
    });

    bindJsonForm('formCreateService', 'masterResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        service_name: v.service_name,
        service_code: v.service_code || '',
        store_id: toInt(v.store_id, 0),
        category: v.category || '',
        supports_online_booking: toInt(v.supports_online_booking, 0) === 1 ? 1 : 0,
        duration_minutes: toInt(v.duration_minutes, 60),
        list_price: toFloat(v.list_price, 0),
      };
      return request('POST', '/services', { body });
    });

    bindJsonForm('formCreatePackage', 'masterResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        package_name: v.package_name,
        package_code: v.package_code || '',
        store_id: toInt(v.store_id, 0),
        service_id: toInt(v.service_id, 0),
        total_sessions: toInt(v.total_sessions, 1),
        sale_price: toFloat(v.sale_price, 0),
        valid_days: toInt(v.valid_days, 365),
      };
      return request('POST', '/service-packages', { body });
    });
  }

  async function renderOps() {
    const [appointmentsRes, ordersRes, memberCardsRes, cardLogsRes] = await Promise.all([
      request('GET', '/appointments'),
      request('GET', '/orders', { query: { limit: 200 } }),
      request('GET', '/member-cards'),
      request('GET', '/member-card-logs', { query: { limit: 120 } }),
    ]);

    const appointments = pickData(appointmentsRes);
    const orders = pickData(ordersRes);
    const memberCards = pickData(memberCardsRes);
    const cardLogs = pickData(cardLogsRes);
    const tabKey = 'ops';
    const tabFallback = 'appointments';
    const tabHeader = renderSubTabNav(tabKey, [
      { id: 'appointments', title: '预约管理', subtitle: '新增预约、预约状态、预约列表' },
      { id: 'orders', title: '订单收款', subtitle: '订单收款登记、订单明细、订单列表' },
      { id: 'cards', title: '次卡管理', subtitle: '开卡核销、次卡列表、到期管理' },
      { id: 'card_logs', title: '次卡流水', subtitle: '次卡扣减、调整、补录流水查询' },
    ]);

    el.viewRoot.innerHTML = `
      ${tabHeader}
      <section class="row${subTabClass(tabKey, 'appointments', tabFallback)}">
        <article class="card">
          <h3>新增预约</h3>
          <p class="small-note">先在“客户档案/员工管理/服务套餐”查看对应ID，再创建预约。</p>
          <form id="formCreateAppointment" class="form-grid">
            <input name="customer_id" placeholder="客户ID" required />
            <input name="store_id" placeholder="门店ID(可空)" />
            <input name="staff_id" placeholder="员工ID" />
            <input name="service_id" placeholder="服务ID" />
            <label><span>开始时间</span><input type="datetime-local" name="start_at" required /></label>
            <label><span>结束时间</span><input type="datetime-local" name="end_at" required /></label>
            <button class="btn btn-primary" type="submit">创建预约</button>
          </form>
        </article>

        <article class="card">
          <h3>更新预约状态</h3>
          <p class="small-note">预约ID可在下方“预约列表”中查看。</p>
          <form id="formUpdateAppointment" class="form-grid">
            <input name="appointment_id" placeholder="预约ID" required />
            <select name="status">
              <option value="booked">已预约</option>
              <option value="completed">已完成</option>
              <option value="cancelled">已取消</option>
              <option value="no_show">未到店</option>
            </select>
            <input name="member_card_id" placeholder="完成时核销卡ID(可空)" />
            <input name="consume_sessions" placeholder="核销次数" value="1" />
            <input name="consume_note" placeholder="核销备注" />
            <button class="btn btn-primary" type="submit">更新状态</button>
          </form>
        </article>

      </section>

      <section class="card${subTabClass(tabKey, 'cards', tabFallback)}">
        <h3>次卡开卡与核销</h3>
        <p class="small-note">开卡需客户ID与套餐ID；核销需次卡ID（可在次卡列表查看）。</p>
        <div class="row">
          <form id="formCreateMemberCard" class="form-grid">
            <input name="customer_id" placeholder="客户ID" required />
            <input name="package_id" placeholder="套餐ID" required />
            <input name="total_sessions" placeholder="总次数" />
            <input name="sold_price" placeholder="售价" />
            <button class="btn btn-line" type="submit">开卡</button>
          </form>
          <form id="formConsumeMemberCard" class="form-grid">
            <input name="member_card_id" placeholder="次卡ID" required />
            <input name="consume_sessions" placeholder="核销次数" value="1" required />
            <input name="note" placeholder="备注" />
            <button class="btn btn-primary" type="submit">次卡核销</button>
          </form>
        </div>
      </section>

      <section class="card${subTabClass(tabKey, 'orders', tabFallback)}">
        <h3>订单支付与明细</h3>
        <p class="small-note">订单ID可在“订单列表”里查看；可先查询明细再登记收款。</p>
        <div class="row">
          <form id="formOrderPay" class="form-grid">
            <input name="order_id" placeholder="订单ID" required />
            <select name="pay_method">
              <option value="cash">现金</option>
              <option value="wechat">微信</option>
              <option value="alipay">支付宝</option>
              <option value="card">银行卡</option>
              <option value="bank">对公转账</option>
              <option value="other">其他</option>
            </select>
            <input name="amount" placeholder="支付金额(可空自动剩余)" />
            <input name="note" placeholder="支付备注" />
            <button class="btn btn-primary" type="submit">登记收款</button>
          </form>

          <form id="formOrderDetail" class="form-grid">
            <input name="order_id" placeholder="订单ID" required />
            <button class="btn btn-line" type="submit">查询订单明细与支付记录</button>
          </form>
        </div>
      </section>

      <section class="card${subTabClass(tabKey, 'orders', tabFallback)}">
        <h3>手工开单（后台）</h3>
        <p class="small-note">用于到店现场开单，支持服务项目、自定义项目与订单级优惠。可选“开单后自动生成双码”，直接给客户扫码支付。</p>
        <form id="formOrderCreate" class="form-grid">
          <input name="customer_id" placeholder="客户ID" required />
          <input name="store_id" placeholder="门店ID（可空=客户门店）" />
          <input name="appointment_id" placeholder="预约ID（可空）" />
          <input name="order_discount_amount" placeholder="订单级优惠金额" value="0" />
          <input name="coupon_amount" placeholder="券抵扣金额" value="0" />
          <textarea name="items_json" placeholder='订单明细（JSON格式），示例：[{"item_type":"service","item_ref_id":1,"qty":1,"staff_id":1}]' required>[{"item_type":"custom","item_name":"手工项目","qty":1,"unit_price":199,"discount_amount":0}]</textarea>
          <input name="note" placeholder="备注" value="后台开单" />
          <label class="check-line"><input type="checkbox" name="auto_create_dual_qr" value="1" /><span>创建后自动生成支付宝+微信双码</span></label>
          <button class="btn btn-primary" type="submit">创建订单</button>
        </form>
        <div id="opsOrderQrPreview" class="portal-qr-preview"><div class="small-note">未生成二维码</div></div>
      </section>

      <section class="card${subTabClass(tabKey, 'appointments', tabFallback)}"><h3>预约列表</h3>${table([
        { label: 'ID', key: 'id' },
        { label: '预约号', key: 'appointment_no' },
        { label: '客户', get: (r) => `${r.customer_name || ''} (${r.customer_mobile || ''})` },
        { label: '状态', get: (r) => zhStatus(r.status) },
        { label: '开始', key: 'start_at' },
        { label: '结束', key: 'end_at' },
      ], appointments, { maxRows: 60 })}</section>

      <section class="card${subTabClass(tabKey, 'orders', tabFallback)}"><h3>订单列表</h3>${table([
        { label: 'ID', key: 'id' },
        { label: '订单号', key: 'order_no' },
        { label: '客户', get: (r) => `${r.customer_name || ''} (${r.customer_mobile || ''})` },
        { label: '状态', get: (r) => zhStatus(r.status) },
        { label: '应收', key: 'payable_amount' },
        { label: '实收', key: 'paid_amount' },
        { label: '支付时间', key: 'paid_at' },
      ], orders, { maxRows: 80 })}</section>

      <section class="card${subTabClass(tabKey, 'cards', tabFallback)}"><h3>次卡列表</h3>${table([
        { label: 'ID', key: 'id' },
        { label: '卡号', key: 'card_no' },
        { label: '客户', get: (r) => `${r.customer_name || ''} (${r.customer_mobile || ''})` },
        { label: '剩余/总', get: (r) => `${r.remaining_sessions}/${r.total_sessions}` },
        { label: '状态', get: (r) => zhStatus(r.status) },
        { label: '到期', key: 'expire_at' },
      ], memberCards, { maxRows: 80 })}</section>

      <section class="row${subTabClass(tabKey, 'card_logs', tabFallback)}">
        <article class="card">
          <h3>次卡流水查询</h3>
          <form id="formOpsCardLogsQuery" class="form-grid">
            <input name="store_id" placeholder="门店ID（可空）" />
            <button class="btn btn-line" type="submit">查询次卡流水</button>
          </form>
        </article>
      </section>

      <section class="card${subTabClass(tabKey, 'card_logs', tabFallback)}"><h3>次卡流水列表</h3><div id="opsCardLogsTable">${table([
        { label: 'ID', key: 'id' },
        { label: '卡号', key: 'card_no' },
        { label: '动作', get: (r) => zhActionType(r.action_type) },
        { label: '变更', key: 'delta_sessions' },
        { label: '前值', key: 'before_sessions' },
        { label: '后值', key: 'after_sessions' },
        { label: '备注', key: 'note' },
        { label: '时间', key: 'created_at' },
      ], cardLogs, { maxRows: 150 })}</div></section>

      <section class="card"><h3>操作返回</h3>${jsonBox('opsResult', '等待操作')}</section>
    `;
    bindSubTabNav(tabKey, tabFallback);

    bindJsonForm('formCreateAppointment', 'opsResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/appointments', {
        body: {
          customer_id: toInt(v.customer_id, 0),
          store_id: toInt(v.store_id, 0),
          staff_id: toInt(v.staff_id, 0),
          service_id: toInt(v.service_id, 0),
          start_at: parseDateTimeInput(v.start_at),
          end_at: parseDateTimeInput(v.end_at),
        },
      });
    });

    bindJsonForm('formUpdateAppointment', 'opsResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/appointments/status', {
        body: {
          appointment_id: toInt(v.appointment_id, 0),
          status: v.status,
          member_card_id: toInt(v.member_card_id, 0),
          consume_sessions: toInt(v.consume_sessions, 1),
          consume_note: v.consume_note || '',
        },
      });
    });

    bindJsonForm('formCreateMemberCard', 'opsResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/member-cards', {
        body: {
          customer_id: toInt(v.customer_id, 0),
          package_id: toInt(v.package_id, 0),
          total_sessions: toInt(v.total_sessions, 0),
          sold_price: toFloat(v.sold_price, 0),
        },
      });
    });

    bindJsonForm('formConsumeMemberCard', 'opsResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/member-cards/consume', {
        body: {
          member_card_id: toInt(v.member_card_id, 0),
          consume_sessions: toInt(v.consume_sessions, 1),
          note: v.note || '',
        },
      });
    });

    bindJsonForm('formOrderPay', 'opsResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        order_id: toInt(v.order_id, 0),
        pay_method: v.pay_method || 'cash',
        note: v.note || '',
      };
      if (v.amount !== '') body.amount = toFloat(v.amount, 0);
      return request('POST', '/orders/pay', { body });
    });

    bindJsonForm('formOrderCreate', 'opsResult', async (form) => {
      const v = getFormValues(form);
      const items = parseJsonText(v.items_json, []);
      if (!Array.isArray(items) || items.length === 0) {
        throw new Error('订单明细格式不正确，请填写至少一条项目');
      }
      const orderRes = await request('POST', '/orders', {
        body: {
          customer_id: toInt(v.customer_id, 0),
          store_id: toInt(v.store_id, 0),
          appointment_id: toInt(v.appointment_id, 0),
          order_discount_amount: toFloat(v.order_discount_amount, 0),
          coupon_amount: toFloat(v.coupon_amount, 0),
          items,
          note: v.note || '',
        },
      });

      const preview = document.getElementById('opsOrderQrPreview');
      const orderId = toInt(orderRes && (orderRes.order_id || orderRes.id), 0);
      const payable = toFloat(orderRes && orderRes.payable_amount, 0);
      const shouldDualQr = toInt(v.auto_create_dual_qr, 0) === 1 && orderId > 0 && payable > 0;
      if (!shouldDualQr) {
        if (preview) {
          preview.innerHTML = '<div class="small-note">本次未生成双码</div>';
        }
        return orderRes;
      }

      const buildCard = (title, row, errText) => {
        if (!row && !errText) return '';
        if (!row) {
          return `<article class="portal-link-box"><h4>${escapeHtml(title)}</h4><p class="small-note">生成失败：${escapeHtml(errText || '-')}</p></article>`;
        }
        const qrSource = String(row.qr_code || row.pay_url || '').trim();
        const qrUrl = qrSource === '' ? '' : `https://quickchart.io/qr?size=280&margin=1&text=${encodeURIComponent(qrSource)}`;
        return `
          <article class="portal-link-box">
            <h4>${escapeHtml(title)}</h4>
            <p><b>支付单号：</b>${escapeHtml(row.payment_no || '-')}</p>
            <p><b>支付场景：</b>${escapeHtml(row.scene || '-')}</p>
            <p><b>支付链接：</b>${row.pay_url ? `<a href="${escapeHtml(row.pay_url)}" target="_blank" rel="noopener">${escapeHtml(row.pay_url)}</a>` : '-'}</p>
            <p><b>前台支付页：</b>${row.cashier_url ? `<a href="${escapeHtml(row.cashier_url)}" target="_blank" rel="noopener">${escapeHtml(row.cashier_url)}</a>` : '-'}</p>
            ${qrUrl ? `<img src="${escapeHtml(qrUrl)}" alt="${escapeHtml(title)}二维码" />` : '<p class="small-note">该通道未返回二维码</p>'}
          </article>
        `;
      };

      try {
        const dualRes = await request('POST', '/payments/online/create-dual-qr', {
          body: {
            order_id: orderId,
            alipay_scene: 'auto',
            subject: orderRes && orderRes.order_no ? `门店订单 ${orderRes.order_no}` : '',
          },
        });
        if (preview) {
          const ali = dualRes && dualRes.alipay ? dualRes.alipay : null;
          const wx = dualRes && dualRes.wechat ? dualRes.wechat : null;
          const errors = dualRes && dualRes.errors ? dualRes.errors : {};
          const alipayErr = errors.alipay || errors.alipay_f2f || errors.alipay_page || errors.alipay_wap || '';
          preview.innerHTML = `
            <div class="portal-link-grid">
              ${buildCard('支付宝二维码', ali, alipayErr)}
              ${buildCard('微信二维码', wx, errors.wechat || '')}
            </div>
          `;
        }
        return {
          ...orderRes,
          dual_qr: dualRes,
        };
      } catch (err) {
        if (preview) {
          preview.innerHTML = `<div class="small-note">订单已创建，但双码生成失败：${escapeHtml(err.message || '未知错误')}</div>`;
        }
        return {
          ...orderRes,
          dual_qr_error: err.message || '创建双码失败',
        };
      }
    });

    bindJsonForm('formOrderDetail', 'opsResult', async (form) => {
      const v = getFormValues(form);
      const orderId = toInt(v.order_id, 0);
      const [items, payments] = await Promise.all([
        request('GET', '/order-items', { query: { order_id: orderId } }),
        request('GET', '/order-payments', { query: { order_id: orderId } }),
      ]);
      return { order_id: orderId, items: pickData(items), payments: pickData(payments) };
    });

    bindJsonForm('formOpsCardLogsQuery', 'opsResult', async (form) => {
      const v = getFormValues(form);
      const res = await request('GET', '/member-card-logs', {
        query: {
          store_id: toInt(v.store_id, 0),
        },
      });
      const box = document.getElementById('opsCardLogsTable');
      if (box) {
        box.innerHTML = table([
          { label: 'ID', key: 'id' },
          { label: '卡号', key: 'card_no' },
          { label: '动作', get: (r) => zhActionType(r.action_type) },
          { label: '变更', key: 'delta_sessions' },
          { label: '前值', key: 'before_sessions' },
          { label: '后值', key: 'after_sessions' },
          { label: '备注', key: 'note' },
          { label: '时间', key: 'created_at' },
        ], pickData(res), { maxRows: 150 });
      }
      return res;
    });
  }

  async function renderManual() {
    const storesRes = await request('GET', '/stores');
    const stores = pickData(storesRes);
    state.storeOptions = stores;
    const tabKey = 'manual';
    const tabFallback = 'profile';
    const tabHeader = renderSubTabNav(tabKey, [
      { id: 'profile', title: '客户建档', subtitle: '客户检索、建档、基础资料录入' },
      { id: 'consume', title: '代客消费', subtitle: '后台代客结算、余额和卡券扣减' },
      { id: 'adjust', title: '记录修正', subtitle: '余额券卡纠偏、消费记录修正、补录' },
    ]);

    el.viewRoot.innerHTML = `
      ${tabHeader}
      <section class="card${subTabClass(tabKey, 'profile', tabFallback)}">
        <h3>客户全量检索（按手机号/会员号/卡号）</h3>
        <form id="formSearchCustomer" class="inline-actions">
          <input name="keyword" placeholder="例如: 13800000000 / QLCxxxx / QLMCxxxx" style="max-width:420px" required />
          <button class="btn btn-primary" type="submit">检索</button>
        </form>
      </section>

      <section class="row${subTabClass(tabKey, 'profile', tabFallback)}">
        <article class="card">
          <h3>后台建档（可赠送余额/次卡/优惠券）</h3>
          <form id="formOnboard" class="form-grid">
            <input name="name" placeholder="客户姓名" required />
            <input name="mobile" placeholder="手机号" required />
            ${renderStoreField(stores, {
              inputName: 'store_id',
              presetName: 'store_id_preset',
              datalistId: 'qilingStoreListManualOnboard',
              inputPlaceholder: '门店ID（可搜索门店名，也可手动输入）',
              presetLabel: '所属门店',
            })}
            ${renderSourceChannelField('来源渠道（可手动填写，如：老客转介绍）')}
            <input name="gift_balance" placeholder="赠送余额" />
            <textarea name="gift_member_cards" placeholder="赠送次卡：每行填写 套餐ID,总次数,有效天数,备注（如：12,10,365,开业礼包）"></textarea>
            <textarea name="gift_coupons" placeholder="赠送优惠券：每行填写 券名,券类型(cash满减/discount折扣),面额,门槛,数量,到期时间,备注"></textarea>
            <button class="btn btn-primary" type="submit">提交建档</button>
          </form>
          ${renderStoreDatalist(stores, 'qilingStoreListManualOnboard')}
          ${renderSourceChannelDatalist()}
        </article>

      </section>

      <section class="row${subTabClass(tabKey, 'consume', tabFallback)}">
        <article class="card">
          <h3>后台登记消费（代客结算）</h3>
          <form id="formConsumeRecord" class="form-grid">
            <input name="customer_mobile" placeholder="客户手机号" required />
            ${renderStoreField(stores, {
              inputName: 'store_id',
              presetName: 'store_id_preset_consume',
              datalistId: 'qilingStoreListManualConsume',
              inputPlaceholder: '门店ID（可空，可搜索门店名或手填ID）',
              presetLabel: '消费归属门店',
            })}
            <input name="consume_amount" placeholder="消费金额" value="0" />
            <input name="deduct_balance_amount" placeholder="余额扣减" value="0" />
            <textarea name="coupon_usages" placeholder="优惠券核销：每行填写 券码(coupon_code),核销次数（如 QLCP001,1）"></textarea>
            <textarea name="member_card_usages" placeholder="次卡核销：每行填写 卡号(card_no),核销次数（如 QLMC001,1）"></textarea>
            <input name="note" placeholder="备注" value="后台代客结算" />
            <button class="btn btn-primary" type="submit">登记消费</button>
          </form>
          ${renderStoreDatalist(stores, 'qilingStoreListManualConsume')}
        </article>
      </section>

      <section class="row-3${subTabClass(tabKey, 'adjust', tabFallback)}">
        <article class="card">
          <h3>余额调整</h3>
          <form id="formWalletAdjust" class="form-grid">
            <input name="customer_mobile" placeholder="客户手机号" required />
            <select name="mode">
              <option value="delta">按增减值调整</option>
              <option value="set_balance">直接设置余额</option>
            </select>
            <input name="amount" placeholder="金额" required />
            <select name="change_type">
              <option value="adjust">手工调整</option>
              <option value="gift">赠送</option>
              <option value="recharge">充值</option>
              <option value="deduct">扣减</option>
            </select>
            <input name="note" placeholder="备注" value="后台调整余额" />
            <button class="btn btn-line" type="submit">提交</button>
          </form>
        </article>

        <article class="card">
          <h3>优惠券调整</h3>
          <form id="formCouponAdjust" class="form-grid">
            <input name="customer_mobile" placeholder="客户手机号" required />
            <select name="mode">
              <option value="grant">发券</option>
              <option value="set_remaining">直接设置剩余次数</option>
              <option value="delta_count">按增减值调整次数</option>
            </select>
            <input name="coupon_name" placeholder="发券模式填：券名称" />
            <input name="coupon_code" placeholder="调整模式填写：券码（coupon_code）" />
            <input name="count" placeholder="次数（发券/设剩余时填写）" />
            <input name="delta_count" placeholder="增减次数（可正可负）" />
            <input name="face_value" placeholder="面额（发券模式）" />
            <input name="min_spend" placeholder="最低消费门槛（发券模式）" />
            <input name="note" placeholder="备注" value="后台手工调整优惠券" />
            <button class="btn btn-line" type="submit">提交</button>
          </form>
        </article>

        <article class="card">
          <h3>次卡纠偏</h3>
          <form id="formCardAdjust" class="form-grid">
            <input name="card_no" placeholder="次卡卡号" required />
            <select name="mode">
              <option value="set_remaining">直接设置剩余次数</option>
              <option value="delta_sessions">按增减值调整次数</option>
            </select>
            <input name="value" placeholder="值" required />
            <input name="status" placeholder="强制状态(可空)" />
            <input name="note" placeholder="备注" value="后台手工调整" />
            <button class="btn btn-line" type="submit">提交</button>
          </form>
        </article>
      </section>

      <section class="row${subTabClass(tabKey, 'adjust', tabFallback)}">
        <article class="card">
          <h3>后台手工补录次卡消费</h3>
          <form id="formManualConsume" class="form-grid">
            <input name="card_no" placeholder="次卡卡号" required />
            <input name="consume_sessions" placeholder="核销次数" value="1" required />
            <input name="appointment_id" placeholder="关联预约ID(可空)" />
            <input name="note" placeholder="备注" value="后台手工补录消费" />
            <button class="btn btn-primary" type="submit">补录消费</button>
          </form>
        </article>

        <article class="card">
          <h3>修正消费记录金额</h3>
          <form id="formAdjustConsumeRecord" class="form-grid">
            <input name="consume_record_id" placeholder="消费记录ID(可空)" />
            <input name="consume_no" placeholder="消费单号(可空)" />
            <input name="consume_amount" placeholder="新消费金额(可空)" />
            <input name="deduct_balance_amount" placeholder="新余额扣减(可空)" />
            <input name="deduct_coupon_amount" placeholder="新优惠券扣减(可空)" />
            <input name="deduct_member_card_sessions" placeholder="新次卡扣减次数(可空)" />
            <input name="note" placeholder="备注(可空)" />
            <button class="btn btn-primary" type="submit">修正消费记录</button>
          </form>
        </article>

        <article class="card">
          <h3>修正预约消费记录</h3>
          <form id="formAdjustAppointmentConsume" class="form-grid">
            <input name="appointment_id" placeholder="预约ID" required />
            <input name="consume_sessions" placeholder="新核销次数" required />
            <input name="note" placeholder="备注" value="后台手工修正消费记录" />
            <button class="btn btn-primary" type="submit">修正记录</button>
          </form>
        </article>
      </section>

      <section class="card"><h3>操作返回</h3>${jsonBox('manualResult', '等待操作')}</section>
    `;
    bindSubTabNav(tabKey, tabFallback);

    bindSourceChannelAssist('formOnboard', 'source_channel', 'source_channel_preset', 'qiling_last_source_channel');
    bindStoreAssist('formOnboard', stores, {
      inputName: 'store_id',
      presetName: 'store_id_preset',
      memoryKey: 'qiling_last_store_id',
    });
    bindStoreAssist('formConsumeRecord', stores, {
      inputName: 'store_id',
      presetName: 'store_id_preset_consume',
      memoryKey: 'qiling_last_store_id',
    });

    bindJsonForm('formSearchCustomer', 'manualResult', async (form) => {
      const v = getFormValues(form);
      return request('GET', '/admin/customers/search', { query: { keyword: v.keyword, limit: 50 } });
    });

    bindJsonForm('formOnboard', 'manualResult', async (form) => {
      const v = getFormValues(form);
      const cards = parseCsvLines(v.gift_member_cards).map((a) => ({
        package_id: toInt(a[0], 0),
        total_sessions: toInt(a[1], 0),
        valid_days: toInt(a[2], 0),
        note: a[3] || '',
      })).filter((x) => x.package_id > 0);

      const coupons = parseCsvLines(v.gift_coupons).map((a) => ({
        coupon_name: a[0] || '',
        coupon_type: a[1] || 'cash',
        face_value: toFloat(a[2], 0),
        min_spend: toFloat(a[3], 0),
        count: toInt(a[4], 1),
        expire_at: a[5] || '',
        note: a[6] || '',
      })).filter((x) => x.coupon_name !== '');

      const body = {
        customer: {
          name: v.name,
          mobile: v.mobile,
          store_id: toInt(v.store_id, 0),
          source_channel: v.source_channel || '',
        },
        gift_balance: toFloat(v.gift_balance, 0),
        gift_member_cards: cards,
        gift_coupons: coupons,
      };
      return request('POST', '/admin/customers/onboard', { body });
    });

    bindJsonForm('formConsumeRecord', 'manualResult', async (form) => {
      const v = getFormValues(form);
      const couponUsages = parseCsvLines(v.coupon_usages).map((a) => ({
        coupon_code: a[0] || '',
        use_count: toInt(a[1], 1),
      })).filter((x) => x.coupon_code !== '');

      const memberCardUsages = parseCsvLines(v.member_card_usages).map((a) => {
        const first = a[0] || '';
        if (/^\d+$/.test(first)) {
          return { member_card_id: toInt(first, 0), consume_sessions: toInt(a[1], 1) };
        }
        return { card_no: first, consume_sessions: toInt(a[1], 1) };
      }).filter((x) => (x.member_card_id || 0) > 0 || (x.card_no || '') !== '');

      return request('POST', '/admin/customers/consume-record', {
        body: {
          customer_mobile: v.customer_mobile,
          store_id: toInt(v.store_id, 0),
          consume_amount: toFloat(v.consume_amount, 0),
          deduct_balance_amount: toFloat(v.deduct_balance_amount, 0),
          coupon_usages: couponUsages,
          member_card_usages: memberCardUsages,
          note: v.note || '',
        },
      });
    });

    bindJsonForm('formWalletAdjust', 'manualResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/admin/customers/wallet-adjust', {
        body: {
          customer_mobile: v.customer_mobile,
          mode: v.mode,
          amount: toFloat(v.amount, 0),
          change_type: v.change_type,
          note: v.note || '',
        },
      });
    });

    bindJsonForm('formCouponAdjust', 'manualResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        customer_mobile: v.customer_mobile,
        mode: v.mode,
        coupon_name: v.coupon_name || '',
        coupon_code: v.coupon_code || '',
        count: toInt(v.count, 0),
        delta_count: toInt(v.delta_count, 0),
        face_value: toFloat(v.face_value, 0),
        min_spend: toFloat(v.min_spend, 0),
        note: v.note || '',
      };
      return request('POST', '/admin/customers/coupon-adjust', { body });
    });

    bindJsonForm('formCardAdjust', 'manualResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/admin/member-cards/adjust', {
        body: {
          card_no: v.card_no,
          mode: v.mode,
          value: toInt(v.value, 0),
          status: v.status || '',
          note: v.note || '',
        },
      });
    });

    bindJsonForm('formManualConsume', 'manualResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/admin/member-cards/manual-consume', {
        body: {
          card_no: v.card_no,
          consume_sessions: toInt(v.consume_sessions, 1),
          appointment_id: toInt(v.appointment_id, 0),
          note: v.note || '',
        },
      });
    });

    bindJsonForm('formAdjustConsumeRecord', 'manualResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        consume_record_id: toInt(v.consume_record_id, 0),
        consume_no: v.consume_no || '',
      };
      if (v.consume_amount !== '') body.consume_amount = toFloat(v.consume_amount, 0);
      if (v.deduct_balance_amount !== '') body.deduct_balance_amount = toFloat(v.deduct_balance_amount, 0);
      if (v.deduct_coupon_amount !== '') body.deduct_coupon_amount = toFloat(v.deduct_coupon_amount, 0);
      if (v.deduct_member_card_sessions !== '') body.deduct_member_card_sessions = toInt(v.deduct_member_card_sessions, 0);
      if (v.note !== '') body.note = v.note;
      return request('POST', '/admin/customers/consume-record-adjust', { body });
    });

    bindJsonForm('formAdjustAppointmentConsume', 'manualResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/admin/appointment-consumes/adjust', {
        body: {
          appointment_id: toInt(v.appointment_id, 0),
          consume_sessions: toInt(v.consume_sessions, 1),
          note: v.note || '',
        },
      });
    });
  }

  async function renderGrowth() {
    const [gradesRes, groupRes, sendRes, pointLogsRes] = await Promise.all([
      request('GET', '/customer-grades'),
      request('GET', '/coupon-groups'),
      request('GET', '/coupon-group-sends', { query: { limit: 100 } }),
      request('GET', '/customer-points/logs', { query: { limit: 120 } }),
    ]);

    const grades = pickData(gradesRes);
    const groups = pickData(groupRes);
    const sends = pickData(sendRes);
    const pointLogs = pickData(pointLogsRes);
    const tabKey = 'growth';
    const tabFallback = 'grades';
    const tabHeader = renderSubTabNav(tabKey, [
      { id: 'grades', title: '积分等级', subtitle: '等级规则、积分调整、账户查询' },
      { id: 'coupons', title: '券包管理', subtitle: '券包配置、批量发放、发放记录' },
      { id: 'point_logs', title: '积分流水', subtitle: '积分增减流水查询与核对' },
    ]);

    el.viewRoot.innerHTML = `
      ${tabHeader}
      <section class="row${subTabClass(tabKey, 'grades', tabFallback)}">
        <article class="card">
          <h3>积分等级管理</h3>
          <form id="formGradeUpsert" class="form-grid">
            <input name="id" placeholder="等级ID(编辑时填)" />
            <input name="store_id" placeholder="门店ID(0=全局)" />
            <input name="grade_name" placeholder="等级名称" required />
            <input name="grade_code" placeholder="等级编码(可空自动生成)" />
            <input name="threshold_points" placeholder="门槛积分" value="0" />
            <input name="discount_rate" placeholder="折扣率(0-100)" value="100" />
            <button class="btn btn-primary" type="submit">保存等级</button>
          </form>
        </article>

        <article class="card">
          <h3>积分手工调整</h3>
          <form id="formPointChange" class="form-grid">
            <input name="customer_mobile" placeholder="客户手机号" required />
            <input name="store_id" placeholder="门店ID(可空)" />
            <input name="delta_points" placeholder="积分变更（可负数）" required />
            <input name="change_type" placeholder="变更类型（默认：manual_adjust 手工调整）" value="manual_adjust" />
            <input name="note" placeholder="备注" value="后台手工调整积分" />
            <button class="btn btn-primary" type="submit">调整积分</button>
          </form>

          <hr />

          <form id="formPointAccount" class="form-grid">
            <input name="customer_mobile" placeholder="查询积分账户手机号" required />
            <button class="btn btn-line" type="submit">查询账户</button>
          </form>
        </article>
      </section>

      <section class="row${subTabClass(tabKey, 'coupons', tabFallback)}">
        <article class="card">
          <h3>券包管理</h3>
          <form id="formCouponGroup" class="form-grid">
            <input name="id" placeholder="券包ID(编辑时填)" />
            <input name="store_id" placeholder="门店ID(0=全局)" />
            <input name="group_name" placeholder="券包名称" required />
            <input name="coupon_name" placeholder="券名称" required />
            <input name="coupon_type" placeholder="券类型（cash=满减券，discount=折扣券）" value="cash" />
            <input name="face_value" placeholder="面额" value="0" />
            <input name="min_spend" placeholder="最低消费" value="0" />
            <input name="per_user_limit" placeholder="每人上限" value="1" />
            <input name="total_limit" placeholder="总上限(0=不限)" value="0" />
            <input name="expire_days" placeholder="有效天数" value="30" />
            <button class="btn btn-primary" type="submit">保存券包</button>
          </form>
        </article>

        <article class="card">
          <h3>券包发放</h3>
          <form id="formCouponSend" class="form-grid">
            <input name="group_id" placeholder="券包ID" required />
            <textarea name="customer_mobiles" placeholder="客户手机号，每行一个"></textarea>
            <textarea name="customer_ids" placeholder="客户ID，每行一个"></textarea>
            <input name="batch_no" placeholder="批次号(可空自动生成)" />
            <button class="btn btn-primary" type="submit">批量发放</button>
          </form>
        </article>
      </section>

      <section class="card${subTabClass(tabKey, 'grades', tabFallback)}"><h3>积分等级</h3>${table([
        { label: 'ID', key: 'id' },
        { label: '门店ID', key: 'store_id' },
        { label: '编码', key: 'grade_code' },
        { label: '名称', key: 'grade_name' },
        { label: '门槛', key: 'threshold_points' },
        { label: '折扣率', key: 'discount_rate' },
      ], grades, { maxRows: 80 })}</section>

      <section class="card${subTabClass(tabKey, 'coupons', tabFallback)}"><h3>券包列表</h3>${table([
        { label: 'ID', key: 'id' },
        { label: '门店ID', key: 'store_id' },
        { label: '券包编码', key: 'group_code' },
        { label: '券包名称', key: 'group_name' },
        { label: '券名称', key: 'coupon_name' },
        { label: '已发/上限', get: (r) => `${r.sent_total}/${r.total_limit}` },
        { label: '启用', get: (r) => zhEnabled(r.enabled) },
      ], groups, { maxRows: 80 })}</section>

      <section class="card${subTabClass(tabKey, 'coupons', tabFallback)}"><h3>券包发放记录</h3>${table([
        { label: 'ID', key: 'id' },
        { label: '券包', key: 'group_name' },
        { label: '客户', get: (r) => `${r.customer_name || ''} (${r.customer_mobile || ''})` },
        { label: '券码', key: 'coupon_code' },
        { label: '批次', key: 'batch_no' },
        { label: '时间', key: 'created_at' },
      ], sends, { maxRows: 100 })}</section>

      <section class="row${subTabClass(tabKey, 'point_logs', tabFallback)}">
        <article class="card">
          <h3>积分流水筛选</h3>
          <form id="formGrowthPointLogsQuery" class="form-grid">
            <input name="store_id" placeholder="门店ID（可空）" />
            <input name="customer_id" placeholder="客户ID（可空）" />
            <input name="customer_mobile" placeholder="客户手机号（可空）" />
            <input name="change_type" placeholder="变更类型（可空）" />
            <input name="limit" placeholder="查询条数" value="200" />
            <button class="btn btn-line" type="submit">查询积分流水</button>
          </form>
        </article>
      </section>

      <section class="card${subTabClass(tabKey, 'point_logs', tabFallback)}"><h3>积分流水列表</h3><div id="growthPointLogsTable">${table([
        { label: 'ID', key: 'id' },
        { label: '客户', get: (r) => `${r.customer_name || ''} (${r.customer_mobile || ''})` },
        { label: '变更类型', get: (r) => zhChangeType(r.change_type) },
        { label: '积分变更', key: 'delta_points' },
        { label: '变更前', key: 'before_points' },
        { label: '变更后', key: 'after_points' },
        { label: '备注', key: 'note' },
        { label: '时间', key: 'created_at' },
      ], pointLogs, { maxRows: 150 })}</div></section>

      <section class="card"><h3>操作返回</h3>${jsonBox('growthResult', '等待操作')}</section>
    `;
    bindSubTabNav(tabKey, tabFallback);

    bindJsonForm('formGradeUpsert', 'growthResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/customer-grades', {
        body: {
          id: toInt(v.id, 0),
          store_id: toInt(v.store_id, 0),
          grade_name: v.grade_name,
          grade_code: v.grade_code || '',
          threshold_points: toInt(v.threshold_points, 0),
          discount_rate: toFloat(v.discount_rate, 100),
        },
      });
    });

    bindJsonForm('formPointChange', 'growthResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/customer-points/change', {
        body: {
          customer_mobile: v.customer_mobile,
          store_id: toInt(v.store_id, 0),
          delta_points: toInt(v.delta_points, 0),
          change_type: v.change_type || 'manual_adjust',
          note: v.note || '',
        },
      });
    });

    bindJsonForm('formPointAccount', 'growthResult', async (form) => {
      const v = getFormValues(form);
      return request('GET', '/customer-points/account', {
        query: {
          customer_mobile: v.customer_mobile,
        },
      });
    });

    bindJsonForm('formCouponGroup', 'growthResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/coupon-groups', {
        body: {
          id: toInt(v.id, 0),
          store_id: toInt(v.store_id, 0),
          group_name: v.group_name,
          coupon_name: v.coupon_name,
          coupon_type: v.coupon_type || 'cash',
          face_value: toFloat(v.face_value, 0),
          min_spend: toFloat(v.min_spend, 0),
          per_user_limit: toInt(v.per_user_limit, 1),
          total_limit: toInt(v.total_limit, 0),
          expire_days: toInt(v.expire_days, 30),
        },
      });
    });

    bindJsonForm('formCouponSend', 'growthResult', async (form) => {
      const v = getFormValues(form);
      const customerMobiles = String(v.customer_mobiles || '')
        .split(/\n+/)
        .map((s) => s.trim())
        .filter(Boolean);
      const customerIds = String(v.customer_ids || '')
        .split(/\n+/)
        .map((s) => toInt(s.trim(), 0))
        .filter((x) => x > 0);

      return request('POST', '/coupon-groups/send', {
        body: {
          group_id: toInt(v.group_id, 0),
          customer_mobiles: customerMobiles,
          customer_ids: customerIds,
          batch_no: v.batch_no || '',
        },
      });
    });

    bindJsonForm('formGrowthPointLogsQuery', 'growthResult', async (form) => {
      const v = getFormValues(form);
      const res = await request('GET', '/customer-points/logs', {
        query: {
          store_id: toInt(v.store_id, 0),
          customer_id: toInt(v.customer_id, 0),
          customer_mobile: v.customer_mobile || '',
          change_type: v.change_type || '',
          limit: toInt(v.limit, 200),
        },
      });
      const box = document.getElementById('growthPointLogsTable');
      if (box) {
        box.innerHTML = table([
          { label: 'ID', key: 'id' },
          { label: '客户', get: (r) => `${r.customer_name || ''} (${r.customer_mobile || ''})` },
          { label: '变更类型', get: (r) => zhChangeType(r.change_type) },
          { label: '积分变更', key: 'delta_points' },
          { label: '变更前', key: 'before_points' },
          { label: '变更后', key: 'after_points' },
          { label: '备注', key: 'note' },
          { label: '时间', key: 'created_at' },
        ], pickData(res), { maxRows: 150 });
      }
      return res;
    });
  }

  async function renderFinance() {
    const [printersRes, printJobsRes, paymentConfigRes] = await Promise.all([
      request('GET', '/printers'),
      request('GET', '/print-jobs', { query: { limit: 120 } }),
      request('GET', '/payments/config'),
    ]);

    const printers = pickData(printersRes);
    const printJobs = pickData(printJobsRes);
    const paymentConfig = paymentConfigRes || {};
    const alipayCfg = paymentConfig.alipay || {};
    const wechatCfg = paymentConfig.wechat || {};
    const boolSelected = (v, expected) => (toInt(v, 0) === expected ? 'selected' : '');
    const yesNo = (v) => (toInt(v, 0) === 1 ? '已配置' : '未配置');

    const tabKey = 'finance';
    const tabFallback = 'config';
    const tabHeader = renderSubTabNav(tabKey, [
      { id: 'config', title: '支付配置', subtitle: '支付宝与微信支付参数、证书配置' },
      { id: 'payments', title: '在线支付', subtitle: '创建支付单、查单、关单' },
      { id: 'refunds', title: '退款管理', subtitle: '支付退款、退款记录查询' },
      { id: 'printers', title: '打印管理', subtitle: '打印机、打印任务、派发与日志' },
    ]);
    const financePageLinks = renderPageLinksCard('前台收银与会员入口', [
      { label: '支付页面', path: '/pay' },
      { label: '会员中心', path: '/customer' },
      { label: '品牌首页', path: '/' },
    ]);

    el.viewRoot.innerHTML = `
      ${tabHeader}
      <section class="card${subTabClass(tabKey, 'config', tabFallback)}">
        ${financePageLinks}
      </section>
      <section class="row${subTabClass(tabKey, 'config', tabFallback)}">
        <article class="card">
          <h3>支付宝配置</h3>
          <p class="small-note">私钥状态：${yesNo(alipayCfg.has_private_key)}，公钥状态：${yesNo(alipayCfg.has_public_key)}。不修改密钥时请留空。</p>
          <form id="formPayCfgAlipay" class="form-grid" data-confirm="确定保存支付宝配置？">
            <select name="alipay_enabled">
              <option value="1" ${boolSelected(alipayCfg.enabled, 1)}>启用支付宝</option>
              <option value="0" ${boolSelected(alipayCfg.enabled, 0)}>停用支付宝</option>
            </select>
            <input name="alipay_app_id" placeholder="应用ID（AppID）" value="${escapeHtml(alipayCfg.app_id || '')}" />
            <select name="alipay_web_enabled">
              <option value="1" ${boolSelected(alipayCfg.web_enabled, 1)}>启用网页支付</option>
              <option value="0" ${boolSelected(alipayCfg.web_enabled, 0)}>关闭网页支付</option>
            </select>
            <select name="alipay_f2f_enabled">
              <option value="1" ${boolSelected(alipayCfg.f2f_enabled, 1)}>启用当面付</option>
              <option value="0" ${boolSelected(alipayCfg.f2f_enabled, 0)}>关闭当面付</option>
            </select>
            <select name="alipay_h5_enabled">
              <option value="1" ${boolSelected(alipayCfg.h5_enabled, 1)}>启用H5支付</option>
              <option value="0" ${boolSelected(alipayCfg.h5_enabled, 0)}>关闭H5支付</option>
            </select>
            <select name="alipay_app_enabled">
              <option value="1" ${boolSelected(alipayCfg.app_enabled, 1)}>启用APP支付</option>
              <option value="0" ${boolSelected(alipayCfg.app_enabled, 0)}>关闭APP支付</option>
            </select>
            <input name="alipay_gateway" placeholder="网关地址（可空默认官方）" value="${escapeHtml(alipayCfg.gateway || '')}" />
            <input name="alipay_notify_url" placeholder="异步回调地址（可空自动拼接）" value="${escapeHtml(alipayCfg.notify_url || '')}" />
            <input name="alipay_return_url" placeholder="同步跳转地址（可空）" value="${escapeHtml(alipayCfg.return_url || '')}" />
            <textarea name="alipay_private_key" placeholder="应用私钥（PKCS8，留空不修改）"></textarea>
            <textarea name="alipay_public_key" placeholder="支付宝公钥（留空不修改）"></textarea>
            <label><input type="checkbox" name="alipay_private_key_clear" value="1" /> 清空已保存私钥</label>
            <label><input type="checkbox" name="alipay_public_key_clear" value="1" /> 清空已保存公钥</label>
            <button class="btn btn-primary" type="submit">保存支付宝配置</button>
          </form>
        </article>

        <article class="card">
          <h3>微信支付配置</h3>
          <p class="small-note">应用密钥：${yesNo(wechatCfg.has_secret)}，支付密钥：${yesNo(wechatCfg.has_api_key)}，证书：${yesNo(wechatCfg.has_cert_content)}，证书私钥：${yesNo(wechatCfg.has_key_content)}。</p>
          <form id="formPayCfgWechat" class="form-grid" data-confirm="确定保存微信支付配置？">
            <select name="wechat_enabled">
              <option value="1" ${boolSelected(wechatCfg.enabled, 1)}>启用微信支付</option>
              <option value="0" ${boolSelected(wechatCfg.enabled, 0)}>停用微信支付</option>
            </select>
            <input name="wechat_mch_id" placeholder="商户号（MCHID）" value="${escapeHtml(wechatCfg.mch_id || '')}" />
            <input name="wechat_app_id" placeholder="应用ID（AppID）" value="${escapeHtml(wechatCfg.app_id || '')}" />
            <select name="wechat_jsapi_enabled">
              <option value="1" ${boolSelected(wechatCfg.jsapi_enabled, 1)}>启用JSAPI支付</option>
              <option value="0" ${boolSelected(wechatCfg.jsapi_enabled, 0)}>关闭JSAPI支付</option>
            </select>
            <select name="wechat_h5_enabled">
              <option value="1" ${boolSelected(wechatCfg.h5_enabled, 1)}>启用H5支付</option>
              <option value="0" ${boolSelected(wechatCfg.h5_enabled, 0)}>关闭H5支付</option>
            </select>
            <input name="wechat_notify_url" placeholder="支付回调地址（可空自动拼接）" value="${escapeHtml(wechatCfg.notify_url || '')}" />
            <input name="wechat_refund_notify_url" placeholder="退款回调地址（可空）" value="${escapeHtml(wechatCfg.refund_notify_url || '')}" />
            <input name="wechat_unifiedorder_url" placeholder="统一下单地址（可空默认官方）" value="${escapeHtml(wechatCfg.unifiedorder_url || '')}" />
            <input name="wechat_orderquery_url" placeholder="订单查询地址（可空默认官方）" value="${escapeHtml(wechatCfg.orderquery_url || '')}" />
            <input name="wechat_closeorder_url" placeholder="关闭订单地址（可空默认官方）" value="${escapeHtml(wechatCfg.closeorder_url || '')}" />
            <input name="wechat_refund_url" placeholder="退款接口地址（可空默认官方）" value="${escapeHtml(wechatCfg.refund_url || '')}" />
            <input name="wechat_secret" placeholder="应用密钥（AppSecret，留空不修改）" />
            <input name="wechat_api_key" placeholder="商户支付密钥（KEY，留空不修改）" />
            <input name="wechat_cert_passphrase" placeholder="证书口令（留空不修改）" />
            <textarea name="wechat_cert_content" placeholder="退款证书内容（apiclient_cert.pem，留空不修改）"></textarea>
            <textarea name="wechat_key_content" placeholder="退款证书私钥内容（apiclient_key.pem，留空不修改）"></textarea>
            <label><input type="checkbox" name="wechat_secret_clear" value="1" /> 清空应用密钥（AppSecret）</label>
            <label><input type="checkbox" name="wechat_api_key_clear" value="1" /> 清空支付密钥</label>
            <label><input type="checkbox" name="wechat_cert_content_clear" value="1" /> 清空退款证书</label>
            <label><input type="checkbox" name="wechat_key_content_clear" value="1" /> 清空证书私钥</label>
            <label><input type="checkbox" name="wechat_cert_passphrase_clear" value="1" /> 清空证书口令</label>
            <button class="btn btn-primary" type="submit">保存微信配置</button>
          </form>
          <p class="small-note">服务器证书文件路径：证书 cert=${escapeHtml(wechatCfg.cert_path || '-')}, 私钥 key=${escapeHtml(wechatCfg.key_path || '-')}。</p>
        </article>
      </section>

      <section class="row${subTabClass(tabKey, 'payments', tabFallback)}">
        <article class="card">
          <h3>在线支付创建（支付宝/微信）</h3>
          <p class="small-note">支付宝场景支持：auto（推荐，优先当面付失败回退网页支付）、f2f（当面付）、page/web（网页支付）、wap/h5（H5支付）、app（APP支付）；微信支持：native、jsapi、h5。</p>
          <form id="formOnlineCreate" class="form-grid">
            <input name="order_id" placeholder="订单ID" required />
            <select name="channel">
              <option value="alipay">支付宝</option>
              <option value="wechat">微信支付</option>
            </select>
            <input name="scene" placeholder="支付场景（支付宝:auto/f2f/page/web/wap/h5/app，微信:native/jsapi/h5）" value="auto" />
            <input name="subject" placeholder="支付标题（可空）" />
            <input name="openid" placeholder="用户标识（openid，仅微信JSAPI场景填写）" />
            <input name="client_ip" placeholder="客户端IP（可空）" />
            <button class="btn btn-primary" type="submit">创建在线支付单</button>
          </form>
          <hr />
          <h3>双码直付（推荐门店收银台）</h3>
          <p class="small-note">同一订单一键生成“支付宝二维码 + 微信Native二维码”，客户现场扫码任选其一支付。支付宝可选当面付/网页付/H5；若选自动，优先当面付，权限不足时回退网页支付。任一支付成功后，系统会尝试自动关闭同订单的其他待支付单。</p>
          <form id="formOnlineCreateDualQr" class="form-grid">
            <input name="order_id" placeholder="订单ID" required />
            <select name="alipay_scene">
              <option value="auto">支付宝自动（优先当面付，失败回退网页支付）</option>
              <option value="f2f">支付宝当面付（需当面付权限）</option>
              <option value="page">支付宝网页支付（可扫码后跳转支付）</option>
              <option value="wap">支付宝H5支付</option>
            </select>
            <input name="subject" placeholder="支付标题（可空）" />
            <input name="client_ip" placeholder="客户端IP（可空）" />
            <button class="btn btn-primary" type="submit">一键生成双码</button>
          </form>
          <div id="onlineDualQrPreview" class="portal-qr-preview"><div class="small-note">暂无双码预览</div></div>
        </article>

        <article class="card">
          <h3>在线支付状态/关单</h3>
          <form id="formOnlineStatus" class="form-grid">
            <input name="payment_no" placeholder="支付单号（payment_no）" required />
            <button class="btn btn-line" type="submit">查询状态（本地）</button>
          </form>
          <hr />
          <form id="formOnlineQuery" class="form-grid">
            <input name="payment_no" placeholder="支付单号（payment_no）" required />
            <button class="btn btn-line" type="submit">网关查单并同步</button>
          </form>
          <hr />
          <form id="formOnlineClose" class="form-grid" data-confirm="确定关闭该支付单？关闭后将无法继续支付。">
            <input name="payment_no" placeholder="支付单号（payment_no）" required />
            <button class="btn btn-danger" type="submit">关闭支付单</button>
          </form>
        </article>
      </section>

      <section class="row${subTabClass(tabKey, 'refunds', tabFallback)}">
        <article class="card">
          <h3>退款管理</h3>
          <form id="formOnlineRefund" class="form-grid" data-confirm="确定发起退款？退款后请与客户确认到账。">
            <input name="payment_no" placeholder="支付单号（payment_no）" required />
            <input name="refund_amount" placeholder="退款金额（可空=全额）" />
            <input name="reason" placeholder="退款原因" value="后台发起退款" />
            <button class="btn btn-primary" type="submit">发起退款</button>
          </form>
          <hr />
          <form id="formOnlineRefundList" class="form-grid">
            <input name="payment_no" placeholder="支付单号（payment_no）" required />
            <button class="btn btn-line" type="submit">查询退款记录</button>
          </form>
        </article>

      </section>

      <section class="row${subTabClass(tabKey, 'printers', tabFallback)}">
        <article class="card">
          <h3>打印机管理</h3>
          <form id="formPrinterUpsert" class="form-grid">
            <input name="id" placeholder="打印机ID（编辑时填）" />
            <input name="store_id" placeholder="门店ID（0=全局）" />
            <input name="printer_code" placeholder="打印机编码（可空自动生成）" />
            <input name="printer_name" placeholder="打印机名称" required />
            <input name="provider" placeholder="服务商标识（如 manual 手工）" value="manual" />
            <input name="endpoint" placeholder="服务地址（endpoint，可空）" />
            <input name="api_key" placeholder="接口密钥（api_key，可空）" />
            <select name="enabled">
              <option value="1">启用</option>
              <option value="0">停用</option>
            </select>
            <button class="btn btn-primary" type="submit">保存打印机</button>
          </form>
        </article>
      </section>

      <section class="row${subTabClass(tabKey, 'printers', tabFallback)}">
        <article class="card">
          <h3>创建打印任务</h3>
          <form id="formPrintJobCreate" class="form-grid">
            <select name="business_type">
              <option value="order_receipt">订单小票</option>
              <option value="manual">手工内容</option>
            </select>
            <input name="business_id" placeholder="业务ID（订单小票时填订单ID）" />
            <input name="store_id" placeholder="门店ID（手工内容时可填）" />
            <input name="printer_id" placeholder="指定打印机ID（可空）" />
            <textarea name="content" placeholder="手工内容模式下填写打印文本（订单小票可空）"></textarea>
            <button class="btn btn-primary" type="submit">创建打印任务</button>
          </form>
        </article>

        <article class="card">
          <h3>派发待打印任务</h3>
          <form id="formPrintDispatch" class="form-grid">
            <input name="store_id" placeholder="门店ID（可空）" />
            <input name="printer_id" placeholder="打印机ID（可空）" />
            <input name="limit" placeholder="批量上限" value="20" />
            <button class="btn btn-primary" type="submit">立即派发</button>
          </form>

          <hr />

          <form id="formPrintJobsQuery" class="form-grid">
            <input name="store_id" placeholder="筛选门店ID（可空）" />
            <input name="printer_id" placeholder="筛选打印机ID（可空）" />
            <input name="status" placeholder="状态（pending待处理 / sent已发送 / failed失败）" />
            <input name="business_type" placeholder="业务类型筛选（可空）" />
            <input name="limit" placeholder="查询条数" value="200" />
            <button class="btn btn-line" type="submit">查询打印任务</button>
          </form>
        </article>
      </section>

      <section class="card${subTabClass(tabKey, 'printers', tabFallback)}"><h3>打印机列表</h3>${table([
        { label: 'ID', key: 'id' },
        { label: '门店ID', key: 'store_id' },
        { label: '编码', key: 'printer_code' },
        { label: '名称', key: 'printer_name' },
        { label: '服务商', get: (r) => zhProvider(r.provider) },
        { label: '启用', get: (r) => zhEnabled(r.enabled) },
        { label: '状态', get: (r) => zhStatus(r.last_status) },
        { label: '更新时间', key: 'updated_at' },
      ], printers, { maxRows: 120 })}</section>

      <section class="card${subTabClass(tabKey, 'printers', tabFallback)}"><h3>打印任务列表</h3>${table([
        { label: 'ID', key: 'id' },
        { label: '门店ID', key: 'store_id' },
        { label: '打印机', get: (r) => `${r.printer_name || '-'} (#${r.printer_id || 0})` },
        { label: '类型', get: (r) => zhBusinessType(r.business_type) },
        { label: '业务ID', key: 'business_id' },
        { label: '状态', get: (r) => zhStatus(r.status) },
        { label: '重试次数', key: 'retry_count' },
        { label: '创建时间', key: 'created_at' },
      ], printJobs, { maxRows: 200 })}</section>

      <section class="card"><h3>操作返回</h3>${jsonBox('financeResult', '等待操作')}</section>
    `;
    bindSubTabNav(tabKey, tabFallback);
    bindCopyUrlButtons();

    bindJsonForm('formPayCfgAlipay', 'financeResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        alipay_enabled: toInt(v.alipay_enabled, 0),
        alipay_app_id: v.alipay_app_id || '',
        alipay_web_enabled: toInt(v.alipay_web_enabled, 0),
        alipay_f2f_enabled: toInt(v.alipay_f2f_enabled, 0),
        alipay_h5_enabled: toInt(v.alipay_h5_enabled, 0),
        alipay_app_enabled: toInt(v.alipay_app_enabled, 0),
        alipay_gateway: v.alipay_gateway || '',
        alipay_notify_url: v.alipay_notify_url || '',
        alipay_return_url: v.alipay_return_url || '',
      };
      if (v.alipay_private_key !== '') body.alipay_private_key = v.alipay_private_key;
      if (v.alipay_public_key !== '') body.alipay_public_key = v.alipay_public_key;
      if (v.alipay_private_key_clear) body.alipay_private_key_clear = 1;
      if (v.alipay_public_key_clear) body.alipay_public_key_clear = 1;
      return request('POST', '/payments/config', { body });
    });

    bindJsonForm('formPayCfgWechat', 'financeResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        wechat_enabled: toInt(v.wechat_enabled, 0),
        wechat_mch_id: v.wechat_mch_id || '',
        wechat_app_id: v.wechat_app_id || '',
        wechat_jsapi_enabled: toInt(v.wechat_jsapi_enabled, 0),
        wechat_h5_enabled: toInt(v.wechat_h5_enabled, 0),
        wechat_notify_url: v.wechat_notify_url || '',
        wechat_refund_notify_url: v.wechat_refund_notify_url || '',
        wechat_unifiedorder_url: v.wechat_unifiedorder_url || '',
        wechat_orderquery_url: v.wechat_orderquery_url || '',
        wechat_closeorder_url: v.wechat_closeorder_url || '',
        wechat_refund_url: v.wechat_refund_url || '',
      };
      if (v.wechat_secret !== '') body.wechat_secret = v.wechat_secret;
      if (v.wechat_api_key !== '') body.wechat_api_key = v.wechat_api_key;
      if (v.wechat_cert_passphrase !== '') body.wechat_cert_passphrase = v.wechat_cert_passphrase;
      if (v.wechat_cert_content !== '') body.wechat_cert_content = v.wechat_cert_content;
      if (v.wechat_key_content !== '') body.wechat_key_content = v.wechat_key_content;
      if (v.wechat_secret_clear) body.wechat_secret_clear = 1;
      if (v.wechat_api_key_clear) body.wechat_api_key_clear = 1;
      if (v.wechat_cert_content_clear) body.wechat_cert_content_clear = 1;
      if (v.wechat_key_content_clear) body.wechat_key_content_clear = 1;
      if (v.wechat_cert_passphrase_clear) body.wechat_cert_passphrase_clear = 1;
      return request('POST', '/payments/config', { body });
    });

    bindJsonForm('formOnlineCreate', 'financeResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        order_id: toInt(v.order_id, 0),
        channel: v.channel || 'alipay',
        scene: v.scene || '',
        subject: v.subject || '',
        openid: v.openid || '',
        client_ip: v.client_ip || '',
      };
      return request('POST', '/payments/online/create', { body });
    });

    bindJsonForm('formOnlineCreateDualQr', 'financeResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        order_id: toInt(v.order_id, 0),
        alipay_scene: v.alipay_scene || 'auto',
        subject: v.subject || '',
        client_ip: v.client_ip || '',
      };
      const res = await request('POST', '/payments/online/create-dual-qr', { body });
      const preview = document.getElementById('onlineDualQrPreview');
      if (preview) {
        const ali = res && res.alipay ? res.alipay : null;
        const wx = res && res.wechat ? res.wechat : null;
        const errors = res && res.errors ? res.errors : {};
        const alipayErr = errors.alipay || errors.alipay_f2f || errors.alipay_page || errors.alipay_wap || '';
        const buildCard = (title, row, errText) => {
          if (!row && !errText) return '';
          if (!row) {
            return `<article class="portal-link-box"><h4>${escapeHtml(title)}</h4><p class="small-note">生成失败：${escapeHtml(errText || '-')}</p></article>`;
          }
          const qrSource = String(row.qr_code || row.pay_url || '').trim();
          const qrUrl = qrSource === '' ? '' : `https://quickchart.io/qr?size=280&margin=1&text=${encodeURIComponent(qrSource)}`;
          return `
            <article class="portal-link-box">
              <h4>${escapeHtml(title)}</h4>
              <p><b>支付单号：</b>${escapeHtml(row.payment_no || '-')}</p>
              <p><b>支付场景：</b>${escapeHtml(row.scene || '-')}</p>
              <p><b>支付链接：</b>${row.pay_url ? `<a href="${escapeHtml(row.pay_url)}" target="_blank" rel="noopener">${escapeHtml(row.pay_url)}</a>` : '-'}</p>
              <p><b>前台支付页：</b>${row.cashier_url ? `<a href="${escapeHtml(row.cashier_url)}" target="_blank" rel="noopener">${escapeHtml(row.cashier_url)}</a>` : '-'}</p>
              ${qrUrl ? `<img src="${escapeHtml(qrUrl)}" alt="${escapeHtml(title)}二维码" />` : '<p class="small-note">该通道未返回二维码</p>'}
            </article>
          `;
        };
        preview.innerHTML = `
          <div class="portal-link-grid">
            ${buildCard('支付宝二维码', ali, alipayErr)}
            ${buildCard('微信二维码', wx, errors.wechat || '')}
          </div>
        `;
      }
      return res;
    });

    bindJsonForm('formOnlineStatus', 'financeResult', async (form) => {
      const v = getFormValues(form);
      return request('GET', '/payments/online/status', {
        query: { payment_no: v.payment_no },
      });
    });

    bindJsonForm('formOnlineQuery', 'financeResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/payments/online/query', {
        body: { payment_no: v.payment_no },
      });
    });

    bindJsonForm('formOnlineClose', 'financeResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/payments/online/close', {
        body: { payment_no: v.payment_no },
      });
    });

    bindJsonForm('formOnlineRefund', 'financeResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        payment_no: v.payment_no,
        reason: v.reason || '后台发起退款',
      };
      if (v.refund_amount !== '') body.refund_amount = toFloat(v.refund_amount, 0);
      return request('POST', '/payments/online/refund', { body });
    });

    bindJsonForm('formOnlineRefundList', 'financeResult', async (form) => {
      const v = getFormValues(form);
      return request('GET', '/payments/online/refunds', {
        query: { payment_no: v.payment_no },
      });
    });

    bindJsonForm('formPrinterUpsert', 'financeResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/printers', {
        body: {
          id: toInt(v.id, 0),
          store_id: toInt(v.store_id, 0),
          printer_code: v.printer_code || '',
          printer_name: v.printer_name,
          provider: v.provider || 'manual',
          endpoint: v.endpoint || '',
          api_key: v.api_key || '',
          enabled: toInt(v.enabled, 1),
        },
      });
    });

    bindJsonForm('formPrintJobCreate', 'financeResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        business_type: v.business_type || 'order_receipt',
        business_id: toInt(v.business_id, 0),
        store_id: toInt(v.store_id, 0),
        printer_id: toInt(v.printer_id, 0),
        content: v.content || '',
      };
      return request('POST', '/print-jobs', { body });
    });

    bindJsonForm('formPrintDispatch', 'financeResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/print-jobs/dispatch', {
        body: {
          store_id: toInt(v.store_id, 0),
          printer_id: toInt(v.printer_id, 0),
          limit: toInt(v.limit, 20),
        },
      });
    });

    bindJsonForm('formPrintJobsQuery', 'financeResult', async (form) => {
      const v = getFormValues(form);
      return request('GET', '/print-jobs', {
        query: {
          store_id: toInt(v.store_id, 0),
          printer_id: toInt(v.printer_id, 0),
          status: v.status || '',
          business_type: v.business_type || '',
          limit: toInt(v.limit, 200),
        },
      });
    });
  }

  async function renderFollowupPush() {
    const [plansRes, tasksRes, channelsRes, pushLogsRes] = await Promise.all([
      request('GET', '/followup/plans'),
      request('GET', '/followup/tasks', { query: { limit: 120 } }),
      request('GET', '/push/channels'),
      request('GET', '/push/logs', { query: { limit: 120 } }),
    ]);

    const plans = pickData(plansRes);
    const tasks = pickData(tasksRes);
    const channels = pickData(channelsRes);
    const pushLogs = pickData(pushLogsRes);
    const tabKey = 'followpush';
    const tabFallback = 'plans';
    const tabHeader = renderSubTabNav(tabKey, [
      { id: 'plans', title: '回访计划', subtitle: '计划配置、触发规则、计划列表' },
      { id: 'tasks', title: '回访任务', subtitle: '任务生成、任务处理、任务状态查询' },
      { id: 'channels', title: '推送渠道', subtitle: '钉钉/飞书渠道管理、测试发送' },
      { id: 'logs', title: '推送日志', subtitle: '推送结果查询、失败排查' },
    ]);

    el.viewRoot.innerHTML = `
      ${tabHeader}
      <section class="row${subTabClass(tabKey, 'plans', tabFallback)}">
        <article class="card">
          <h3>回访计划管理</h3>
          <form id="formFollowupPlan" class="form-grid">
            <input name="store_id" placeholder="门店ID（0=全局）" />
            <input name="trigger_type" placeholder="触发类型（默认：appointment_completed 预约完成）" value="appointment_completed" />
            <input name="plan_name" placeholder="计划名称" value="预约完成回访计划" />
            <input name="schedule_days" placeholder="回访天数，逗号分隔（如 1,3,7）" value="1,3,7" />
            <select name="enabled">
              <option value="1">启用</option>
              <option value="0">停用</option>
            </select>
            <button class="btn btn-primary" type="submit">保存回访计划</button>
          </form>
        </article>
      </section>

      <section class="card${subTabClass(tabKey, 'plans', tabFallback)}"><h3>回访计划列表</h3><div id="followupPlanTable">${table([
        { label: 'ID', key: 'id' },
        { label: '门店ID', key: 'store_id' },
        { label: '触发类型', get: (r) => zhTriggerType(r.trigger_type) },
        { label: '计划名称', key: 'plan_name' },
        { label: '回访天数', key: 'schedule_days_json' },
        { label: '启用', get: (r) => zhEnabled(r.enabled) },
      ], plans, { maxRows: 120 })}</div></section>

      <section class="row${subTabClass(tabKey, 'tasks', tabFallback)}">
        <article class="card">
          <h3>回访任务生成与通知</h3>
          <form id="formFollowupGenerate" class="form-grid">
            <input name="appointment_id" placeholder="预约ID（可空=批量生成）" />
            <input name="store_id" placeholder="门店ID（可空）" />
            <input name="limit" placeholder="批量上限" value="200" />
            <button class="btn btn-primary" type="submit">生成回访任务</button>
          </form>
          <hr />
          <form id="formFollowupNotify" class="form-grid">
            <input name="channel_ids" placeholder="推送渠道ID列表（逗号分隔；可空=全部启用渠道）" />
            <input name="store_id" placeholder="门店ID（可空）" />
            <input name="limit" placeholder="通知上限" value="100" />
            <select name="retry_failed">
              <option value="0">仅待处理任务</option>
              <option value="1">包含失败任务重试</option>
            </select>
            <button class="btn btn-primary" type="submit">发送回访通知</button>
          </form>
        </article>

        <article class="card">
          <h3>回访任务处理</h3>
          <form id="formFollowupTaskStatus" class="form-grid">
            <input name="task_id" placeholder="任务ID" required />
            <select name="status">
              <option value="completed">已完成</option>
              <option value="skipped">已跳过</option>
              <option value="cancelled">已取消</option>
              <option value="pending">待处理（重置）</option>
            </select>
            <input name="note" placeholder="处理备注" />
            <button class="btn btn-primary" type="submit">更新任务状态</button>
          </form>
          <hr />
          <form id="formFollowupTasksQuery" class="form-grid">
            <input name="store_id" placeholder="门店ID（可空）" />
            <input name="status" placeholder="状态筛选（可空）" />
            <input name="limit" placeholder="查询条数" value="200" />
            <button class="btn btn-line" type="submit">查询回访任务</button>
          </form>
        </article>
      </section>

      <section class="card${subTabClass(tabKey, 'tasks', tabFallback)}"><h3>回访任务列表</h3><div id="followupTaskTable">${table([
        { label: 'ID', key: 'id' },
        { label: '客户', get: (r) => `${r.customer_name || ''} (${r.customer_mobile || ''})` },
        { label: '预约号', key: 'appointment_no' },
        { label: '状态', get: (r) => zhStatus(r.status) },
        { label: '通知状态', get: (r) => zhStatus(r.notify_status) },
        { label: '到期时间', key: 'due_at' },
        { label: '标题', key: 'title' },
      ], tasks, { maxRows: 200 })}</div></section>

      <section class="row${subTabClass(tabKey, 'channels', tabFallback)}">
        <article class="card">
          <h3>推送渠道管理（钉钉/飞书）</h3>
          <p class="small-note">启用后将用于：回访任务通知、后台新建预约通知、用户端在线预约通知。</p>
          <form id="formPushChannel" class="form-grid">
            <input name="id" placeholder="渠道ID（编辑时填）" />
            <input name="channel_code" placeholder="渠道编码（可空自动）" />
            <input name="channel_name" placeholder="渠道名称" required />
            <select name="provider">
              <option value="dingtalk">钉钉</option>
              <option value="feishu">飞书</option>
            </select>
            <input name="webhook_url" placeholder="机器人回调地址（Webhook）" required />
            <input name="secret" placeholder="签名密钥（可空）" />
            <input name="keyword" placeholder="关键词（可空）" />
            <select name="security_mode">
              <option value="auto">自动判断</option>
              <option value="none">无安全校验</option>
              <option value="keyword">关键词校验</option>
              <option value="sign">签名校验</option>
            </select>
            <select name="enabled">
              <option value="1">启用</option>
              <option value="0">停用</option>
            </select>
            <button class="btn btn-primary" type="submit">保存渠道</button>
          </form>
          <hr />
          <form id="formPushTest" class="form-grid">
            <input name="channel_id" placeholder="渠道ID" required />
            <input name="message" placeholder="测试消息（可空自动）" />
            <button class="btn btn-line" type="submit">发送测试消息</button>
          </form>
        </article>
      </section>

      <section class="card${subTabClass(tabKey, 'channels', tabFallback)}"><h3>推送渠道列表</h3><div id="pushChannelTable">${table([
        { label: 'ID', key: 'id' },
        { label: '编码', key: 'channel_code' },
        { label: '名称', key: 'channel_name' },
        { label: '服务商', get: (r) => zhProvider(r.provider) },
        { label: '安全模式', get: (r) => zhSecurityMode(r.security_mode) },
        { label: '启用', get: (r) => zhEnabled(r.enabled) },
        { label: '有密钥', get: (r) => zhEnabled(r.has_secret) },
      ], channels, { maxRows: 120 })}</div></section>

      <section class="row${subTabClass(tabKey, 'logs', tabFallback)}">
        <article class="card">
          <h3>推送日志查询</h3>
          <form id="formPushLogsQuery" class="form-grid">
            <input name="channel_id" placeholder="渠道ID（可空）" />
            <input name="status" placeholder="状态（success成功 / failed失败）" />
            <input name="limit" placeholder="查询条数" value="200" />
            <button class="btn btn-line" type="submit">查询推送日志</button>
          </form>
        </article>
      </section>

      <section class="card${subTabClass(tabKey, 'logs', tabFallback)}"><h3>推送日志列表</h3><div id="pushLogTable">${table([
        { label: 'ID', key: 'id' },
        { label: '渠道', key: 'channel_name' },
        { label: '状态', get: (r) => zhStatus(r.status) },
        { label: '来源', get: (r) => zhTriggerSource(r.trigger_source) },
        { label: '任务ID', key: 'task_id' },
        { label: '目标', key: 'target' },
        { label: '时间', key: 'created_at' },
      ], pushLogs, { maxRows: 200 })}</div></section>

      <section class="card"><h3>操作返回</h3>${jsonBox('followupResult', '等待操作')}</section>
    `;
    bindSubTabNav(tabKey, tabFallback);

    bindJsonForm('formFollowupPlan', 'followupResult', async (form) => {
      const v = getFormValues(form);
      const days = parseListInput(v.schedule_days)
        .map((x) => toInt(x, 0))
        .filter((x) => x > 0);
      return request('POST', '/followup/plans', {
        body: {
          store_id: toInt(v.store_id, 0),
          trigger_type: v.trigger_type || 'appointment_completed',
          plan_name: v.plan_name || '预约完成回访计划',
          schedule_days: days,
          enabled: toInt(v.enabled, 1),
        },
      });
    });

    bindJsonForm('formFollowupTaskStatus', 'followupResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/followup/tasks/status', {
        body: {
          task_id: toInt(v.task_id, 0),
          status: v.status || 'completed',
          note: v.note || '',
        },
      });
    });

    bindJsonForm('formFollowupTasksQuery', 'followupResult', async (form) => {
      const v = getFormValues(form);
      const res = await request('GET', '/followup/tasks', {
        query: {
          store_id: toInt(v.store_id, 0),
          status: v.status || '',
          limit: toInt(v.limit, 200),
        },
      });
      const box = document.getElementById('followupTaskTable');
      if (box) {
        box.innerHTML = table([
          { label: 'ID', key: 'id' },
          { label: '客户', get: (r) => `${r.customer_name || ''} (${r.customer_mobile || ''})` },
          { label: '预约号', key: 'appointment_no' },
          { label: '状态', get: (r) => zhStatus(r.status) },
          { label: '通知状态', get: (r) => zhStatus(r.notify_status) },
          { label: '到期时间', key: 'due_at' },
          { label: '标题', key: 'title' },
        ], pickData(res), { maxRows: 200 });
      }
      return res;
    });

    bindJsonForm('formFollowupGenerate', 'followupResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/followup/generate', {
        body: {
          appointment_id: toInt(v.appointment_id, 0),
          store_id: toInt(v.store_id, 0),
          limit: toInt(v.limit, 200),
        },
      });
    });

    bindJsonForm('formFollowupNotify', 'followupResult', async (form) => {
      const v = getFormValues(form);
      const channelIds = parseListInput(v.channel_ids)
        .map((x) => toInt(x, 0))
        .filter((x) => x > 0);
      const body = {
        store_id: toInt(v.store_id, 0),
        limit: toInt(v.limit, 100),
        retry_failed: toInt(v.retry_failed, 0),
      };
      if (channelIds.length > 0) {
        body.channel_ids = channelIds;
      }
      return request('POST', '/followup/notify', {
        body,
      });
    });

    bindJsonForm('formPushChannel', 'followupResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        id: toInt(v.id, 0),
        channel_code: v.channel_code || '',
        channel_name: v.channel_name,
        provider: v.provider || 'dingtalk',
        webhook_url: v.webhook_url || '',
        keyword: v.keyword || '',
        security_mode: v.security_mode || 'auto',
        enabled: toInt(v.enabled, 1),
      };
      if (v.secret !== '') body.secret = v.secret;
      return request('POST', '/push/channels', { body });
    });

    bindJsonForm('formPushTest', 'followupResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/push/test', {
        body: {
          channel_id: toInt(v.channel_id, 0),
          message: v.message || '',
        },
      });
    });

    bindJsonForm('formPushLogsQuery', 'followupResult', async (form) => {
      const v = getFormValues(form);
      const res = await request('GET', '/push/logs', {
        query: {
          channel_id: toInt(v.channel_id, 0),
          status: v.status || '',
          limit: toInt(v.limit, 200),
        },
      });
      const box = document.getElementById('pushLogTable');
      if (box) {
        box.innerHTML = table([
          { label: 'ID', key: 'id' },
          { label: '渠道', key: 'channel_name' },
          { label: '状态', get: (r) => zhStatus(r.status) },
          { label: '来源', get: (r) => zhTriggerSource(r.trigger_source) },
          { label: '任务ID', key: 'task_id' },
          { label: '目标', key: 'target' },
          { label: '时间', key: 'created_at' },
        ], pickData(res), { maxRows: 200 });
      }
      return res;
    });
  }

  async function renderSystemSettings() {
    const settingsRes = await request('GET', '/system/settings');
    const sys = (settingsRes && settingsRes.settings) ? settingsRes.settings : {};
    const derived = (settingsRes && settingsRes.derived) ? settingsRes.derived : {};
    const adminEntryPath = sys.admin_entry_path || 'admin';
    const frontSiteEnabled = toInt(sys.front_site_enabled, 1);
    const securityHeadersEnabled = toInt(sys.security_headers_enabled, 1);
    const frontAllowIps = sys.front_allow_ips || '';
    const frontMaintenanceMessage = sys.front_maintenance_message || '';
    const mobileRoleMenuJson = (sys.mobile_role_menu_json && String(sys.mobile_role_menu_json).trim() !== '')
      ? String(sys.mobile_role_menu_json)
      : '';
    const installPath = derived.install_url || '/install.php';
    const systemPageLinks = renderPageLinksCard('前台与后台入口地址', [
      { label: '品牌首页', path: '/' },
      { label: '会员中心', path: '/customer' },
      { label: '支付页面', path: '/pay' },
      { label: '员工移动端', path: '/mobile' },
      { label: '后台入口', path: `/${String(adminEntryPath).replace(/^\/+/, '')}` },
    ]);

    const TAB_OPTIONS = ['onboard', 'agent', 'records'];
    const TAB_LABELS = {
      onboard: '建档与接待',
      agent: '代客操作',
      records: '记录查询',
    };
    const SUBTAB_OPTIONS = {
      onboard: {
        onboard_form: '建档表单',
        onboard_help: '建档说明',
      },
      agent: {
        consume: '登记消费',
        wallet: '余额调整',
        card: '次卡调整',
        coupon: '发放优惠券',
      },
      records: {
        assets: '资产总览',
        consume: '消费记录',
        orders: '订单记录',
      },
    };
    const ROLE_LABELS = {
      default: '默认角色',
      admin: '管理员',
      manager: '店长',
      consultant: '顾问',
    };

    function defaultRoleMenuMap() {
      const allSubtabs = {
        onboard: ['onboard_form', 'onboard_help'],
        agent: ['consume', 'wallet', 'card', 'coupon'],
        records: ['assets', 'consume', 'orders'],
      };
      const allTabs = ['onboard', 'agent', 'records'];
      return {
        default: { tabs: allTabs.slice(), subtabs: { ...allSubtabs } },
        admin: { tabs: allTabs.slice(), subtabs: { ...allSubtabs } },
        manager: { tabs: allTabs.slice(), subtabs: { ...allSubtabs } },
        consultant: { tabs: allTabs.slice(), subtabs: { ...allSubtabs } },
      };
    }

    function normalizeRoleConfig(config) {
      const cfg = (config && typeof config === 'object') ? config : {};
      const tabsRaw = Array.isArray(cfg.tabs) ? cfg.tabs : [];
      const tabs = tabsRaw
        .map((x) => String(x || '').trim())
        .filter((x, i, arr) => TAB_OPTIONS.includes(x) && arr.indexOf(x) === i);
      const finalTabs = tabs.length > 0 ? tabs : TAB_OPTIONS.slice();

      const subtabsObj = (cfg.subtabs && typeof cfg.subtabs === 'object') ? cfg.subtabs : {};
      const subtabs = {};
      finalTabs.forEach((tab) => {
        const allowed = Object.keys(SUBTAB_OPTIONS[tab] || {});
        const listRaw = Array.isArray(subtabsObj[tab]) ? subtabsObj[tab] : [];
        const list = listRaw
          .map((x) => String(x || '').trim())
          .filter((x, i, arr) => allowed.includes(x) && arr.indexOf(x) === i);
        subtabs[tab] = list.length > 0 ? list : allowed;
      });

      return { tabs: finalTabs, subtabs };
    }

    function normalizeRoleMap(input) {
      const source = (input && typeof input === 'object') ? input : {};
      const out = {};
      Object.entries(source).forEach(([rawRole, config]) => {
        const roleKey = String(rawRole || '').trim().toLowerCase();
        if (!/^[a-z0-9_-]{2,40}$/.test(roleKey)) return;
        out[roleKey] = normalizeRoleConfig(config);
      });
      if (!out.default) out.default = normalizeRoleConfig({});
      return out;
    }

    function parseRoleMap(rawText) {
      const raw = String(rawText || '').trim();
      if (!raw) return defaultRoleMenuMap();
      try {
        const parsed = JSON.parse(raw);
        return normalizeRoleMap(parsed);
      } catch (_e) {
        return defaultRoleMenuMap();
      }
    }

    function roleLabel(roleKey) {
      return ROLE_LABELS[roleKey] || (`自定义角色：${roleKey}`);
    }

    let roleMenuMap = parseRoleMap(mobileRoleMenuJson);
    let activeRole = roleMenuMap.default ? 'default' : (Object.keys(roleMenuMap)[0] || 'default');

    el.viewRoot.innerHTML = `
      <section class="row panel-top">
        <article class="card">
          <h3>后台入口与前台安全</h3>
          <form id="formSystemSettings" class="form-grid" data-confirm="确定保存系统设置？后台入口修改后将立即生效。">
            <input name="admin_entry_path" placeholder="后台入口路径（不含斜杠）" value="${escapeHtml(adminEntryPath)}" />
            <select name="front_site_enabled">
              <option value="1" ${frontSiteEnabled === 1 ? 'selected' : ''}>前台开放</option>
              <option value="0" ${frontSiteEnabled === 0 ? 'selected' : ''}>前台维护中</option>
            </select>
            <select name="security_headers_enabled">
              <option value="1" ${securityHeadersEnabled === 1 ? 'selected' : ''}>启用安全响应头</option>
              <option value="0" ${securityHeadersEnabled === 0 ? 'selected' : ''}>关闭安全响应头</option>
            </select>
            <textarea name="front_maintenance_message" placeholder="前台维护提示文案">${escapeHtml(frontMaintenanceMessage)}</textarea>
            <textarea name="front_allow_ips" placeholder="前台白名单IP（每行一个，或用逗号分隔）">${escapeHtml(frontAllowIps)}</textarea>

            <section class="mobile-role-builder">
              <h4>移动端员工菜单权限（可视化）</h4>
              <div class="mobile-role-toolbar">
                <select id="mobileRoleSelect"></select>
                <button id="btnMobileRoleAdd" class="btn btn-line" type="button">新增角色</button>
                <button id="btnMobileRoleDelete" class="btn btn-line" type="button">删除当前角色</button>
                <button id="btnMobileMenuPreset" class="btn btn-line" type="button">恢复默认菜单</button>
              </div>
              <div id="mobileRoleEditor" class="mobile-role-editor"></div>
              <textarea id="mobileRoleMenuJson" name="mobile_role_menu_json" class="hidden-json">${escapeHtml(JSON.stringify(roleMenuMap, null, 2))}</textarea>
            </section>

            <button class="btn btn-primary" type="submit">保存系统设置</button>
          </form>
          <p class="small-note">修改后台入口后会立即生效，默认 <code>/admin</code> 将自动返回 404。</p>
          <p class="small-note">菜单权限按员工角色编码 <code>role_key</code> 生效，支持新增自定义角色，无需手写配置。</p>
        </article>

        <article class="card">
          ${systemPageLinks}
          <p class="small-note">安装向导地址（安装后会自动阻止重复安装）</p>
          <pre>${escapeHtml(absolutePageUrl(installPath))}</pre>
        </article>
      </section>

      <section class="card">
        <h3>说明</h3>
        <p class="small-note">如果前台维护开启，只有白名单IP可以访问首页。建议先保存新后台入口，再到新入口验证可登录。</p>
      </section>

      <section class="card"><h3>操作返回</h3>${jsonBox('systemResult', '等待操作')}</section>
    `;
    bindCopyUrlButtons();

    const roleSelect = document.getElementById('mobileRoleSelect');
    const roleEditor = document.getElementById('mobileRoleEditor');
    const roleJsonInput = document.getElementById('mobileRoleMenuJson');
    const btnRoleAdd = document.getElementById('btnMobileRoleAdd');
    const btnRoleDelete = document.getElementById('btnMobileRoleDelete');
    const btnPreset = document.getElementById('btnMobileMenuPreset');

    function ensureRoleConfig(roleKey) {
      if (!roleMenuMap[roleKey]) roleMenuMap[roleKey] = normalizeRoleConfig({});
      roleMenuMap[roleKey] = normalizeRoleConfig(roleMenuMap[roleKey]);
      return roleMenuMap[roleKey];
    }

    function syncRoleMapJson() {
      if (roleJsonInput) {
        roleJsonInput.value = JSON.stringify(normalizeRoleMap(roleMenuMap), null, 2);
      }
    }

    function renderRoleSelect() {
      if (!roleSelect) return;
      const roleKeys = Object.keys(roleMenuMap).sort((a, b) => {
        if (a === 'default') return -1;
        if (b === 'default') return 1;
        return a.localeCompare(b);
      });
      roleSelect.innerHTML = roleKeys
        .map((roleKey) => `<option value="${escapeHtml(roleKey)}"${roleKey === activeRole ? ' selected' : ''}>${escapeHtml(roleLabel(roleKey))}</option>`)
        .join('');
    }

    function renderRoleEditor() {
      if (!roleEditor) return;
      const cfg = ensureRoleConfig(activeRole);
      const tabs = Array.isArray(cfg.tabs) ? cfg.tabs : [];
      const tabHtml = TAB_OPTIONS.map((tab) => {
        const checked = tabs.includes(tab) ? ' checked' : '';
        return `
          <label class="check-line">
            <input type="checkbox" data-menu-tab="${escapeHtml(tab)}"${checked} />
            <span>${escapeHtml(TAB_LABELS[tab] || tab)}</span>
          </label>
        `;
      }).join('');

      const subtabHtml = tabs.map((tab) => {
        const selectedSubtabs = Array.isArray(cfg.subtabs[tab]) ? cfg.subtabs[tab] : [];
        const rowHtml = Object.entries(SUBTAB_OPTIONS[tab] || {}).map(([subtab, subLabel]) => {
          const checked = selectedSubtabs.includes(subtab) ? ' checked' : '';
          return `
            <label class="check-line">
              <input type="checkbox" data-menu-subtab="${escapeHtml(subtab)}" data-parent-tab="${escapeHtml(tab)}"${checked} />
              <span>${escapeHtml(subLabel)}</span>
            </label>
          `;
        }).join('');
        return `
          <section class="mobile-subtab-section">
            <h5>${escapeHtml(TAB_LABELS[tab] || tab)} 子菜单</h5>
            <div class="mobile-subtab-grid">${rowHtml}</div>
          </section>
        `;
      }).join('');

      roleEditor.innerHTML = `
        <section class="mobile-menu-section">
          <h5>主菜单权限</h5>
          <div class="mobile-tab-grid">${tabHtml}</div>
        </section>
        ${subtabHtml || '<div class="empty">至少保留一个主菜单</div>'}
      `;

      roleEditor.querySelectorAll('[data-menu-tab]').forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
          const tab = String(checkbox.getAttribute('data-menu-tab') || '').trim();
          if (!tab || !TAB_OPTIONS.includes(tab)) return;
          const roleCfg = ensureRoleConfig(activeRole);
          let nextTabs = Array.isArray(roleCfg.tabs) ? roleCfg.tabs.slice() : [];
          if (checkbox.checked) {
            if (!nextTabs.includes(tab)) nextTabs.push(tab);
          } else {
            nextTabs = nextTabs.filter((x) => x !== tab);
          }
          if (nextTabs.length === 0) {
            checkbox.checked = true;
            toast('至少保留一个主菜单', 'error');
            return;
          }
          roleCfg.tabs = nextTabs;
          const nextSubtabs = {};
          nextTabs.forEach((keepTab) => {
            const allowed = Object.keys(SUBTAB_OPTIONS[keepTab] || {});
            const current = Array.isArray(roleCfg.subtabs[keepTab]) ? roleCfg.subtabs[keepTab] : [];
            const keep = current.filter((x) => allowed.includes(x));
            nextSubtabs[keepTab] = keep.length > 0 ? keep : allowed;
          });
          roleCfg.subtabs = nextSubtabs;
          roleMenuMap[activeRole] = roleCfg;
          syncRoleMapJson();
          renderRoleEditor();
        });
      });

      roleEditor.querySelectorAll('[data-menu-subtab]').forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
          const subtab = String(checkbox.getAttribute('data-menu-subtab') || '').trim();
          const tab = String(checkbox.getAttribute('data-parent-tab') || '').trim();
          if (!subtab || !tab || !TAB_OPTIONS.includes(tab)) return;
          const roleCfg = ensureRoleConfig(activeRole);
          const allowed = Object.keys(SUBTAB_OPTIONS[tab] || {});
          if (!allowed.includes(subtab)) return;
          const list = Array.isArray(roleCfg.subtabs[tab]) ? roleCfg.subtabs[tab].slice() : allowed.slice();
          let nextList = list;
          if (checkbox.checked) {
            if (!nextList.includes(subtab)) nextList.push(subtab);
          } else {
            nextList = nextList.filter((x) => x !== subtab);
          }
          if (nextList.length === 0) {
            checkbox.checked = true;
            toast('每个主菜单至少保留一个子菜单', 'error');
            return;
          }
          roleCfg.subtabs[tab] = nextList;
          roleMenuMap[activeRole] = roleCfg;
          syncRoleMapJson();
        });
      });
    }

    if (roleSelect) {
      roleSelect.addEventListener('change', () => {
        activeRole = String(roleSelect.value || 'default').trim().toLowerCase() || 'default';
        ensureRoleConfig(activeRole);
        syncRoleMapJson();
        renderRoleEditor();
      });
    }

    if (btnRoleAdd) {
      btnRoleAdd.addEventListener('click', () => {
        const roleKey = String(window.prompt('请输入角色编码（role_key，2-40位：字母/数字/_/-）', '') || '').trim().toLowerCase();
        if (!roleKey) return;
        if (!/^[a-z0-9_-]{2,40}$/.test(roleKey)) {
          toast('角色编码格式不正确，请重新输入', 'error');
          return;
        }
        if (roleMenuMap[roleKey]) {
          activeRole = roleKey;
          renderRoleSelect();
          renderRoleEditor();
          toast('该角色已存在，已切换到该角色', 'info');
          return;
        }
        roleMenuMap[roleKey] = normalizeRoleConfig({});
        activeRole = roleKey;
        syncRoleMapJson();
        renderRoleSelect();
        renderRoleEditor();
        toast('角色已新增', 'ok');
      });
    }

    if (btnRoleDelete) {
      btnRoleDelete.addEventListener('click', () => {
        if (activeRole === 'default') {
          toast('默认角色不能删除', 'error');
          return;
        }
        if (!roleMenuMap[activeRole]) return;
        const yes = window.confirm(`确定删除角色 ${activeRole} 的菜单配置吗？`);
        if (!yes) return;
        delete roleMenuMap[activeRole];
        activeRole = 'default';
        syncRoleMapJson();
        renderRoleSelect();
        renderRoleEditor();
        toast('角色配置已删除', 'ok');
      });
    }

    if (btnPreset) {
      btnPreset.addEventListener('click', () => {
        roleMenuMap = defaultRoleMenuMap();
        activeRole = 'default';
        syncRoleMapJson();
        renderRoleSelect();
        renderRoleEditor();
        toast('已恢复默认菜单模板', 'ok');
      });
    }

    renderRoleSelect();
    syncRoleMapJson();
    renderRoleEditor();

    bindJsonForm('formSystemSettings', 'systemResult', async (form) => {
      syncRoleMapJson();
      const v = getFormValues(form);
      return request('POST', '/system/settings', {
        body: {
          admin_entry_path: v.admin_entry_path || '',
          front_site_enabled: toInt(v.front_site_enabled, 1),
          security_headers_enabled: toInt(v.security_headers_enabled, 1),
          front_maintenance_message: v.front_maintenance_message || '',
          front_allow_ips: v.front_allow_ips || '',
          mobile_role_menu_json: (roleJsonInput && roleJsonInput.value) ? roleJsonInput.value : (v.mobile_role_menu_json || ''),
        },
      });
    });
  }

  async function renderBizPlus() {
    const [couponTransfersRes, memberCardTransfersRes, openGiftsRes] = await Promise.all([
      request('GET', '/coupon-transfers', { query: { limit: 120 } }),
      request('GET', '/member-card-transfers', { query: { limit: 120 } }),
      request('GET', '/open-gifts'),
    ]);

    const couponTransfers = pickData(couponTransfersRes);
    const memberCardTransfers = pickData(memberCardTransfersRes);
    const openGifts = pickData(openGiftsRes);
    const tabKey = 'bizplus';
    const tabFallback = 'transfers';
    const tabHeader = renderSubTabNav(tabKey, [
      { id: 'transfers', title: '卡券转赠', subtitle: '优惠券转赠、次卡转赠' },
      { id: 'gifts', title: '开单礼', subtitle: '开单礼规则维护与手工触发' },
    ]);

    el.viewRoot.innerHTML = `
      ${tabHeader}
      <section class="row-3${subTabClass(tabKey, 'transfers', tabFallback)}">
        <article class="card">
          <h3>优惠券转赠</h3>
          <form id="formCouponTransfer" class="form-grid">
            <input name="coupon_id" placeholder="优惠券ID（可空）" />
            <input name="coupon_code" placeholder="券码（可空）" />
            <input name="from_customer_id" placeholder="来源客户ID（可空校验）" />
            <input name="from_customer_mobile" placeholder="来源手机号（可空校验）" />
            <input name="to_customer_id" placeholder="目标客户ID（可空）" />
            <input name="to_customer_mobile" placeholder="目标手机号（可空）" />
            <input name="note" placeholder="备注" value="后台优惠券转赠" />
            <button class="btn btn-primary" type="submit">提交转赠</button>
          </form>
        </article>

        <article class="card">
          <h3>次卡转赠</h3>
          <form id="formMemberCardTransfer" class="form-grid">
            <input name="member_card_id" placeholder="次卡ID（可空）" />
            <input name="card_no" placeholder="次卡卡号（可空）" />
            <input name="from_customer_id" placeholder="来源客户ID（可空校验）" />
            <input name="from_customer_mobile" placeholder="来源手机号（可空校验）" />
            <input name="to_customer_id" placeholder="目标客户ID（可空）" />
            <input name="to_customer_mobile" placeholder="目标手机号（可空）" />
            <input name="note" placeholder="备注" value="后台次卡转赠" />
            <button class="btn btn-primary" type="submit">提交转赠</button>
          </form>
        </article>

        <article class="card">
          <h3>手工开单入口</h3>
          <p class="small-note">开单已归到「预约与订单」模块，防止与营销规则混放。</p>
          <button id="btnGoOpsOrder" class="btn btn-line" type="button">前往预约与订单 > 开单</button>
        </article>
      </section>

      <section class="row${subTabClass(tabKey, 'gifts', tabFallback)}">
        <article class="card">
          <h3>开单礼规则</h3>
          <form id="formOpenGiftUpsert" class="form-grid">
            <input name="id" placeholder="规则ID（编辑时填）" />
            <input name="store_id" placeholder="门店ID（0=全局）" />
            <select name="trigger_type">
              <option value="onboard">建档后触发</option>
              <option value="first_paid">首单支付后触发</option>
              <option value="manual">手工触发</option>
            </select>
            <input name="gift_name" placeholder="礼包名称" required />
            <select name="enabled">
              <option value="1">启用</option>
              <option value="0">停用</option>
            </select>
            <textarea name="items_text" placeholder="礼包项示例：每行一条，积分=points,100,30；优惠券=coupon,新客券,cash,50,199,1,30"></textarea>
            <button class="btn btn-primary" type="submit">保存开单礼规则</button>
          </form>
        </article>

        <article class="card">
          <h3>手工触发开单礼</h3>
          <form id="formOpenGiftTrigger" class="form-grid">
            <select name="trigger_type">
              <option value="manual">手工触发</option>
              <option value="onboard">建档后触发</option>
              <option value="first_paid">首单支付后触发</option>
            </select>
            <input name="customer_id" placeholder="客户ID（可空）" />
            <input name="customer_mobile" placeholder="客户手机号（可空）" />
            <input name="store_id" placeholder="门店ID（可空）" />
            <input name="reference_type" placeholder="引用类型（默认：manual 手工）" value="manual" />
            <input name="reference_id" placeholder="引用ID（可空）" />
            <button class="btn btn-primary" type="submit">触发开单礼</button>
          </form>
        </article>
      </section>

      <section class="card${subTabClass(tabKey, 'transfers', tabFallback)}"><h3>优惠券转赠记录</h3>${table([
        { label: 'ID', key: 'id' },
        { label: '转赠单号', key: 'transfer_no' },
        { label: '券码', key: 'coupon_code' },
        { label: '来源客户', get: (r) => `${r.from_customer_name || ''} (${r.from_customer_mobile || ''})` },
        { label: '目标客户', get: (r) => `${r.to_customer_name || ''} (${r.to_customer_mobile || ''})` },
        { label: '状态', get: (r) => zhStatus(r.status) },
        { label: '时间', key: 'created_at' },
      ], couponTransfers, { maxRows: 120 })}</section>

      <section class="card${subTabClass(tabKey, 'transfers', tabFallback)}"><h3>次卡转赠记录</h3>${table([
        { label: 'ID', key: 'id' },
        { label: '转赠单号', key: 'transfer_no' },
        { label: '卡号', key: 'card_no' },
        { label: '来源客户', get: (r) => `${r.from_customer_name || ''} (${r.from_customer_mobile || ''})` },
        { label: '目标客户', get: (r) => `${r.to_customer_name || ''} (${r.to_customer_mobile || ''})` },
        { label: '状态', get: (r) => zhStatus(r.status) },
        { label: '时间', key: 'created_at' },
      ], memberCardTransfers, { maxRows: 120 })}</section>

      <section class="card${subTabClass(tabKey, 'gifts', tabFallback)}"><h3>开单礼规则</h3>${table([
        { label: 'ID', key: 'id' },
        { label: '门店ID', key: 'store_id' },
        { label: '触发类型', get: (r) => zhTriggerType(r.trigger_type) },
        { label: '名称', key: 'gift_name' },
        { label: '启用', get: (r) => zhEnabled(r.enabled) },
        { label: '礼项数', get: (r) => Array.isArray(r.items) ? r.items.length : 0 },
      ], openGifts, { maxRows: 120 })}</section>

      <section class="card"><h3>操作返回</h3>${jsonBox('bizPlusResult', '等待操作')}</section>
    `;
    bindSubTabNav(tabKey, tabFallback);

    const goOpsBtn = document.getElementById('btnGoOpsOrder');
    if (goOpsBtn) {
      goOpsBtn.addEventListener('click', async () => {
        state.subTabs.ops = 'orders';
        await openView('ops');
      });
    }

    bindJsonForm('formCouponTransfer', 'bizPlusResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/coupons/transfer', {
        body: {
          coupon_id: toInt(v.coupon_id, 0),
          coupon_code: v.coupon_code || '',
          from_customer_id: toInt(v.from_customer_id, 0),
          from_customer_mobile: v.from_customer_mobile || '',
          to_customer_id: toInt(v.to_customer_id, 0),
          to_customer_mobile: v.to_customer_mobile || '',
          note: v.note || '后台优惠券转赠',
        },
      });
    });

    bindJsonForm('formMemberCardTransfer', 'bizPlusResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/member-cards/transfer', {
        body: {
          member_card_id: toInt(v.member_card_id, 0),
          card_no: v.card_no || '',
          from_customer_id: toInt(v.from_customer_id, 0),
          from_customer_mobile: v.from_customer_mobile || '',
          to_customer_id: toInt(v.to_customer_id, 0),
          to_customer_mobile: v.to_customer_mobile || '',
          note: v.note || '后台次卡转赠',
        },
      });
    });

    bindJsonForm('formOpenGiftUpsert', 'bizPlusResult', async (form) => {
      const v = getFormValues(form);
      const items = parseCsvLines(v.items_text).map((row) => {
        const type = String(row[0] || '').trim().toLowerCase();
        if (type === 'points') {
          return {
            item_type: 'points',
            points_value: toInt(row[1], 0),
            expire_days: toInt(row[2], 30),
          };
        }
        if (type === 'coupon') {
          return {
            item_type: 'coupon',
            coupon_name: row[1] || '',
            coupon_type: row[2] || 'cash',
            face_value: toFloat(row[3], 0),
            min_spend: toFloat(row[4], 0),
            remain_count: toInt(row[5], 1),
            expire_days: toInt(row[6], 30),
          };
        }
        return null;
      }).filter((x) => x !== null);

      return request('POST', '/open-gifts', {
        body: {
          id: toInt(v.id, 0),
          store_id: toInt(v.store_id, 0),
          trigger_type: v.trigger_type || 'manual',
          gift_name: v.gift_name,
          enabled: toInt(v.enabled, 1),
          items,
        },
      });
    });

    bindJsonForm('formOpenGiftTrigger', 'bizPlusResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/open-gifts/trigger', {
        body: {
          trigger_type: v.trigger_type || 'manual',
          customer_id: toInt(v.customer_id, 0),
          customer_mobile: v.customer_mobile || '',
          store_id: toInt(v.store_id, 0),
          reference_type: v.reference_type || 'manual',
          reference_id: toInt(v.reference_id, 0),
        },
      });
    });
  }

  async function renderCommission() {
    const defaultFrom = dateInputValue(-29);
    const defaultTo = dateInputValue(0);
    const [rulesRes, performanceRes] = await Promise.all([
      request('GET', '/commission/rules'),
      request('GET', '/performance/staff', {
        query: {
          date_from: defaultFrom,
          date_to: defaultTo,
        },
      }),
    ]);

    const rules = pickData(rulesRes);
    const perfRows = pickData(performanceRes);
    const renderPerfTable = (rows) => table([
      { label: '员工', get: (r) => `${r.staff_username || '-'} (${r.staff_no || '-'})` },
      { label: '角色', get: (r) => zhRole(r.role_key) },
      { label: '项目条目', get: (r) => formatNumber(r.item_count) },
      { label: '订单数', get: (r) => formatNumber(r.order_count) },
      { label: '销售金额', get: (r) => `¥${formatMoney(r.sales_amount)}` },
      { label: '提成金额', get: (r) => `¥${formatMoney(r.commission_amount)}` },
    ], rows, { maxRows: 120, emptyText: '该区间暂无员工业绩' });

    el.viewRoot.innerHTML = `
      <section class="row">
        <article class="card">
          <h3>提成规则管理</h3>
          <form id="formCommissionRule" class="form-grid">
            <input name="id" placeholder="规则ID（编辑时填）" />
            <input name="store_id" placeholder="门店ID（0=全局）" />
            <input name="rule_name" placeholder="规则名称" required />
            <select name="target_type">
              <option value="all">全部项目</option>
              <option value="service">服务项目</option>
              <option value="package">套餐/次卡</option>
              <option value="custom">自定义项目</option>
            </select>
            <input name="target_ref_id" placeholder="目标ID（按目标类型填写）" />
            <input name="staff_role_key" placeholder="员工角色（可空）" />
            <input name="rate_percent" placeholder="提成比例(%)" value="0" />
            <select name="enabled">
              <option value="1">启用</option>
              <option value="0">停用</option>
            </select>
            <button class="btn btn-primary" type="submit">保存提成规则</button>
          </form>
        </article>

        <article class="card">
          <h3>员工业绩快速查询</h3>
          <form id="formCommissionPerformance" class="form-grid">
            <input name="store_id" placeholder="门店ID（可空）" />
            <input type="date" name="date_from" value="${defaultFrom}" />
            <input type="date" name="date_to" value="${defaultTo}" />
            <button class="btn btn-line" type="submit">查询业绩</button>
          </form>
          <hr />
          <button id="btnGoReportPerformance" class="btn btn-line" type="button">进入报表中心 > 员工业绩</button>
        </article>
      </section>

      <section class="card"><h3>提成规则列表</h3>${table([
        { label: 'ID', key: 'id' },
        { label: '门店ID', key: 'store_id' },
        { label: '规则名', key: 'rule_name' },
        { label: '目标类型', get: (r) => zhTargetType(r.target_type) },
        { label: '目标ID', key: 'target_ref_id' },
        { label: '员工角色', get: (r) => zhRole(r.staff_role_key) },
        { label: '提成比', key: 'rate_percent' },
        { label: '启用', get: (r) => zhEnabled(r.enabled) },
      ], rules, { maxRows: 120 })}</section>

      <section class="card"><h3>业绩查询结果</h3><div id="commissionPerformanceTable">${renderPerfTable(perfRows)}</div></section>

      <section class="card"><h3>操作返回</h3>${jsonBox('commissionResult', '等待操作')}</section>
    `;

    bindJsonForm('formCommissionRule', 'commissionResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/commission/rules', {
        body: {
          id: toInt(v.id, 0),
          store_id: toInt(v.store_id, 0),
          rule_name: v.rule_name,
          target_type: v.target_type || 'all',
          target_ref_id: toInt(v.target_ref_id, 0),
          staff_role_key: v.staff_role_key || '',
          rate_percent: toFloat(v.rate_percent, 0),
          enabled: toInt(v.enabled, 1),
        },
      });
    });

    const perfForm = document.getElementById('formCommissionPerformance');
    if (perfForm) {
      perfForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const v = getFormValues(perfForm);
        try {
          const res = await request('GET', '/performance/staff', {
            query: {
              store_id: toInt(v.store_id, 0),
              date_from: v.date_from,
              date_to: v.date_to,
            },
          });
          const rows = pickData(res);
          const box = document.getElementById('commissionPerformanceTable');
          if (box) box.innerHTML = renderPerfTable(rows);
          setJsonBox('commissionResult', res);
          toast('业绩查询完成', 'ok');
        } catch (err) {
          toast(err.message, 'error');
          setJsonBox('commissionResult', { message: err.message });
        }
      });
    }

    const reportBtn = document.getElementById('btnGoReportPerformance');
    if (reportBtn) {
      reportBtn.addEventListener('click', async () => {
        state.subTabs.reports = 'performance';
        await openView('reports');
      });
    }
  }

  async function renderIntegration() {
    const wpUsersRes = await request('GET', '/wp/users');
    const wpUsers = pickData(wpUsersRes);
    const tabKey = 'integration';
    const tabFallback = 'wp';
    const tabHeader = renderSubTabNav(tabKey, [
      { id: 'wp', title: '站点用户同步', subtitle: '站点用户同步、同步结果查询' },
      { id: 'cron', title: '外部定时任务', subtitle: '第三方监控平台定时访问地址' },
    ]);
    const base = `${window.location.origin}${ROOT_PATH}`;
    const cronGenerateUrl = `${base}/api/v1/cron/followup/generate`;
    const cronNotifyUrl = `${base}/api/v1/cron/followup/notify`;
    const cronRunUrl = `${base}/api/v1/cron/followup/run`;

    el.viewRoot.innerHTML = `
      ${tabHeader}
      <section class="row${subTabClass(tabKey, 'wp', tabFallback)}">
        <article class="card">
          <h3>站点用户同步（可对接第三方站点）</h3>
          <form id="formWpSync" class="form-grid">
            <input name="wp_secret" placeholder="同步密钥（自动计算 X-QILING-WP-TS / X-QILING-WP-SIGN）" required />
            <textarea name="payload_json" placeholder='同步数据（JSON格式），示例：{"users":[{"wp_user_id":1,"username":"demo","email":"demo@x.com"}]}' required>{"users":[{"wp_user_id":1,"username":"demo","email":"demo@example.com","display_name":"Demo User","roles":["subscriber"],"meta":{"mobile":"13800000000"}}]}</textarea>
            <button class="btn btn-primary" type="submit">执行同步</button>
          </form>
          <hr />
          <form id="formWpUsersQuery" class="form-grid">
            <button class="btn btn-line" type="submit">查询已同步站点用户</button>
          </form>
        </article>
      </section>

      <section class="card${subTabClass(tabKey, 'wp', tabFallback)}"><h3>已同步站点用户</h3>${table([
        { label: 'ID', key: 'id' },
        { label: 'WP用户ID', key: 'wp_user_id' },
        { label: '用户名', key: 'username' },
        { label: '邮箱', key: 'email' },
        { label: '显示名', key: 'display_name' },
        { label: '同步时间', key: 'synced_at' },
      ], wpUsers, { maxRows: 120 })}</section>

      <section class="row-3${subTabClass(tabKey, 'cron', tabFallback)}">
        <article class="card">
          <h3>生成回访任务（手工执行）</h3>
          <form id="formCronGenerate" class="form-grid">
            <input name="cron_key" placeholder="定时任务密钥（CRON_SHARED_KEY）" required />
            <input name="store_id" placeholder="门店ID（可空）" />
            <input name="limit" placeholder="生成上限" value="200" />
            <button class="btn btn-primary" type="submit">执行生成</button>
          </form>
        </article>

        <article class="card">
          <h3>发送回访通知（手工执行）</h3>
          <form id="formCronNotify" class="form-grid">
            <input name="cron_key" placeholder="定时任务密钥（CRON_SHARED_KEY）" required />
            <input name="channel_ids" placeholder="渠道ID列表（逗号分隔；可空=全部启用）" />
            <input name="store_id" placeholder="门店ID（可空）" />
            <input name="limit" placeholder="推送上限" value="100" />
            <select name="retry_failed">
              <option value="0">仅待处理任务</option>
              <option value="1">重试失败任务</option>
            </select>
            <button class="btn btn-primary" type="submit">执行推送</button>
          </form>
        </article>

        <article class="card">
          <h3>一键执行（生成+推送）</h3>
          <form id="formCronRun" class="form-grid">
            <input name="cron_key" placeholder="定时任务密钥（CRON_SHARED_KEY）" required />
            <input name="channel_ids" placeholder="渠道ID列表（逗号分隔；可空=全部启用）" />
            <input name="store_id" placeholder="门店ID（可空）" />
            <input name="generate_limit" placeholder="生成上限" value="200" />
            <input name="notify_limit" placeholder="推送上限" value="100" />
            <select name="retry_failed">
              <option value="0">仅待处理任务</option>
              <option value="1">重试失败任务</option>
            </select>
            <button class="btn btn-primary" type="submit">执行一键任务</button>
          </form>
        </article>
      </section>

      <section class="card${subTabClass(tabKey, 'cron', tabFallback)}">
        <h3>外部监控访问地址模板</h3>
        <p class="small-note">可复制接口地址用于外部任务；请求时需携带请求头 <code>X-QILING-CRON-KEY</code>。</p>
        <pre>${escapeHtml(cronGenerateUrl)}</pre>
        <pre>${escapeHtml(cronNotifyUrl)}</pre>
        <pre>${escapeHtml(cronRunUrl)}</pre>
      </section>

      <section class="card"><h3>操作返回</h3>${jsonBox('integrationResult', '等待操作')}</section>
    `;
    bindSubTabNav(tabKey, tabFallback);

    bindJsonForm('formWpSync', 'integrationResult', async (form) => {
      const v = getFormValues(form);
      const payload = parseJsonText(v.payload_json, {});
      const secret = String(v.wp_secret || '').trim();
      const bodyText = JSON.stringify(payload || {});
      const ts = String(Math.floor(Date.now() / 1000));
      const sign = await hmacSha256Hex(secret, `${ts}.${bodyText}`);
      return request('POST', '/wp/users/sync', {
        body: payload,
        extraHeaders: {
          'X-QILING-WP-SECRET': secret,
          'X-QILING-WP-TS': ts,
          'X-QILING-WP-SIGN': sign,
        },
      });
    });

    bindJsonForm('formWpUsersQuery', 'integrationResult', async () => {
      return request('GET', '/wp/users');
    });

    bindJsonForm('formCronGenerate', 'integrationResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/cron/followup/generate', {
        body: {
          store_id: toInt(v.store_id, 0),
          limit: toInt(v.limit, 200),
        },
        extraHeaders: {
          'X-QILING-CRON-KEY': v.cron_key || '',
        },
      });
    });

    bindJsonForm('formCronNotify', 'integrationResult', async (form) => {
      const v = getFormValues(form);
      const channelIds = parseListInput(v.channel_ids)
        .map((x) => toInt(x, 0))
        .filter((x) => x > 0);
      const body = {
        store_id: toInt(v.store_id, 0),
        limit: toInt(v.limit, 100),
        retry_failed: toInt(v.retry_failed, 0),
      };
      if (channelIds.length > 0) {
        body.channel_ids = channelIds;
      }
      return request('POST', '/cron/followup/notify', {
        body,
        extraHeaders: {
          'X-QILING-CRON-KEY': v.cron_key || '',
        },
      });
    });

    bindJsonForm('formCronRun', 'integrationResult', async (form) => {
      const v = getFormValues(form);
      const channelIds = parseListInput(v.channel_ids)
        .map((x) => toInt(x, 0))
        .filter((x) => x > 0);
      const body = {
        store_id: toInt(v.store_id, 0),
        generate_limit: toInt(v.generate_limit, 200),
        notify_limit: toInt(v.notify_limit, 100),
        retry_failed: toInt(v.retry_failed, 0),
      };
      if (channelIds.length > 0) {
        body.channel_ids = channelIds;
      }
      return request('POST', '/cron/followup/run', {
        body,
        extraHeaders: {
          'X-QILING-CRON-KEY': v.cron_key || '',
        },
      });
    });
  }

  async function renderPortal() {
    const tabKey = 'portal';
    const tabFallback = 'links';
    const tabHeader = renderSubTabNav(tabKey, [
      { id: 'links', title: '客户扫码入口', subtitle: '生成/重置访问口令，查询并处理锁定记录' },
    ]);
    const initRes = await request('GET', '/customer-portal/tokens', {
      query: { status: 'active', limit: 80 },
    });
    let currentRows = pickData(initRes);
    let currentGuardRows = [];
    try {
      const initGuardRes = await request('GET', '/customer-portal/guards', {
        query: { locked_only: 1, limit: 80 },
      });
      currentGuardRows = pickData(initGuardRes);
    } catch (_err) {
      currentGuardRows = [];
    }
    const portalPageLinks = renderPageLinksCard('客户与收银入口地址', [
      { label: '会员中心入口', path: '/customer' },
      { label: '支付页面入口', path: '/pay' },
      { label: '品牌首页', path: '/' },
    ]);

    const renderTokenTable = (rows) => {
      if (!Array.isArray(rows) || rows.length === 0) {
        return renderEmpty('暂无扫码入口记录');
      }

      const body = rows.map((r) => `
        <tr>
          <td>${escapeHtml(r.id)}</td>
          <td>${escapeHtml(r.customer_name || '-')}<br /><small>${escapeHtml(r.customer_no || '-')} / ${escapeHtml(r.customer_mobile || '-')}</small></td>
          <td>${escapeHtml(r.store_name || `门店#${r.store_id || 0}`)}</td>
          <td>${escapeHtml(zhStatus(r.status || '-'))}</td>
          <td>${escapeHtml(r.expire_at || '-')}</td>
          <td>${escapeHtml(r.use_count || 0)} / ${escapeHtml(r.last_used_at || '-')}</td>
          <td>${escapeHtml(r.note || '-')}</td>
          <td>${escapeHtml(r.created_at || '-')}</td>
          <td><button type="button" class="btn btn-danger portal-revoke-btn" data-token-id="${escapeHtml(r.id)}">作废</button></td>
        </tr>
      `).join('');

      return `
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>客户</th>
                <th>门店</th>
                <th>状态</th>
                <th>到期时间</th>
                <th>使用情况</th>
                <th>备注</th>
                <th>创建时间</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>${body}</tbody>
          </table>
        </div>
      `;
    };

    const renderGuardTable = (rows) => {
      if (!Array.isArray(rows) || rows.length === 0) {
        return renderEmpty('暂无命中的访问口令锁定记录');
      }

      const body = rows.map((r) => `
        <tr>
          <td>${escapeHtml(r.customer_name || '-')}<br /><small>${escapeHtml(r.customer_no || '-')} / ${escapeHtml(r.customer_mobile || '-')}</small></td>
          <td>${escapeHtml(r.store_name || `门店#${r.store_id || 0}`)}</td>
          <td>#${escapeHtml(r.token_id || 0)} / ${escapeHtml(r.token_prefix || '-')}</td>
          <td>${escapeHtml(zhStatus(r.token_status || '-'))}</td>
          <td>${escapeHtml(r.fail_count || 0)}</td>
          <td>${escapeHtml(r.first_failed_at || '-')}</td>
          <td>${escapeHtml(r.locked_until || '-')}</td>
          <td>${escapeHtml(r.updated_at || '-')}</td>
          <td><button type="button" class="btn btn-danger portal-guard-unlock-btn" data-customer-id="${escapeHtml(r.customer_id || 0)}" data-customer-name="${escapeHtml(r.customer_name || '')}">解锁</button></td>
        </tr>
      `).join('');

      return `
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>客户</th>
                <th>门店</th>
                <th>口令</th>
                <th>口令状态</th>
                <th>失败次数</th>
                <th>首次失败时间</th>
                <th>锁定截止时间</th>
                <th>最近更新时间</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>${body}</tbody>
          </table>
        </div>
      `;
    };

    el.viewRoot.innerHTML = `
      ${tabHeader}

      <section class="row${subTabClass(tabKey, 'links', tabFallback)}">
        <article class="card">
          <h3>生成客户扫码链接</h3>
          <form id="formPortalCreate" class="form-grid">
            <input name="customer_id" placeholder="客户ID（可空）" />
            <input name="customer_no" placeholder="会员编号（可空）" />
            <input name="customer_mobile" placeholder="客户手机号（可空）" />
            <input name="expire_days" placeholder="有效天数（默认365）" value="365" />
            <input name="note" placeholder="备注（如 首次建档二维码）" />
            <button class="btn btn-primary" type="submit">生成扫码链接</button>
          </form>
          <div id="portalQrPreview" class="portal-qr-preview">
            <div class="empty">生成后将显示二维码预览和访问链接</div>
          </div>
        </article>

        <article class="card">
          <h3>查询入口记录</h3>
          <form id="formPortalQuery" class="form-grid">
            <input name="customer_id" placeholder="客户ID（可空）" />
            <input name="customer_no" placeholder="会员编号（可空）" />
            <input name="customer_mobile" placeholder="手机号（可空）" />
            <select name="status">
              <option value=\"\">全部状态</option>
              <option value=\"active\" selected>仅启用</option>
              <option value=\"revoked\">已作废</option>
              <option value=\"expired\">已过期（历史）</option>
            </select>
            <input name="limit" placeholder="查询条数（默认80）" value="80" />
            <button class="btn btn-line" type="submit">查询入口记录</button>
          </form>
          <p class="small-note">建议每位客户单独生成入口，客户更换手机或入口泄露时可一键作废后重建。</p>
        </article>
      </section>

      <section class="row${subTabClass(tabKey, 'links', tabFallback)}">
        <article class="card">
          <h3>管理员重置客户访问口令</h3>
          <form id="formPortalReset" class="form-grid">
            <input name="customer_id" placeholder="客户ID（可空）" />
            <input name="customer_no" placeholder="会员编号（可空）" />
            <input name="customer_mobile" placeholder="客户手机号（可空）" />
            <input name="new_token" placeholder="新口令（4-6位数字，可空则自动生成）" />
            <input name="expire_days" placeholder="有效天数（默认365）" value="365" />
            <input name="note" placeholder="备注（如 管理员代重置）" />
            <button class="btn btn-primary" type="submit">重置口令并作废旧口令</button>
          </form>
          <div id="portalResetPreview" class="portal-qr-preview">
            <div class="empty">重置后将显示新口令与二维码</div>
          </div>
        </article>

        <article class="card">
          <h3>访问口令锁定管理（按客户）</h3>
          <form id="formPortalUnlock" class="form-grid">
            <input name="customer_id" placeholder="客户ID（可空）" />
            <input name="customer_no" placeholder="会员编号（可空）" />
            <input name="customer_mobile" placeholder="客户手机号（可空）" />
            <button class="btn btn-danger" type="submit">手动按客户解锁</button>
          </form>
          <form id="formPortalGuardQuery" class="form-grid">
            <input name="customer_id" placeholder="客户ID（可空）" />
            <input name="customer_no" placeholder="会员编号（可空）" />
            <input name="customer_mobile" placeholder="客户手机号（可空）" />
            <select name="locked_only">
              <option value="1" selected>仅当前锁定</option>
              <option value="0">全部记录</option>
            </select>
            <input name="limit" placeholder="查询条数（默认80）" value="80" />
            <button class="btn btn-line" type="submit">刷新锁定列表</button>
          </form>
          <p class="small-note">口令失败锁定按客户口令隔离，不再按IP误伤同网用户。</p>
        </article>
      </section>

      <section class="card${subTabClass(tabKey, 'links', tabFallback)}">
        <h3>扫码入口记录</h3>
        <div id="portalTokenTable">${renderTokenTable(currentRows)}</div>
      </section>

      <section class="card${subTabClass(tabKey, 'links', tabFallback)}">
        <h3>访问口令锁定记录</h3>
        <div id="portalGuardTable">${renderGuardTable(currentGuardRows)}</div>
      </section>

      <section class="card${subTabClass(tabKey, 'links', tabFallback)}">
        ${portalPageLinks}
      </section>

      <section class="card"><h3>操作返回</h3>${jsonBox('portalResult', '等待操作')}</section>
    `;

    bindSubTabNav(tabKey, tabFallback);
    bindCopyUrlButtons();

    const queryForm = document.getElementById('formPortalQuery');
    const createForm = document.getElementById('formPortalCreate');
    const resetForm = document.getElementById('formPortalReset');
    const resetPreview = document.getElementById('portalResetPreview');
    const unlockForm = document.getElementById('formPortalUnlock');
    const guardQueryForm = document.getElementById('formPortalGuardQuery');
    const tableBox = document.getElementById('portalTokenTable');
    const guardTableBox = document.getElementById('portalGuardTable');
    const qrPreview = document.getElementById('portalQrPreview');

    const tableRefresh = (rows) => {
      currentRows = Array.isArray(rows) ? rows : [];
      if (tableBox) {
        tableBox.innerHTML = renderTokenTable(currentRows);
      }
      bindRevokeButtons();
    };

    const guardTableRefresh = (rows) => {
      currentGuardRows = Array.isArray(rows) ? rows : [];
      if (guardTableBox) {
        guardTableBox.innerHTML = renderGuardTable(currentGuardRows);
      }
      bindGuardUnlockButtons();
    };

    const readQuery = () => {
      if (!queryForm) {
        return { status: 'active', limit: 80 };
      }
      const v = getFormValues(queryForm);
      return {
        customer_id: toInt(v.customer_id, 0),
        customer_no: v.customer_no || '',
        customer_mobile: v.customer_mobile || '',
        status: v.status || '',
        limit: toInt(v.limit, 80),
      };
    };

    const readGuardQuery = () => {
      if (!guardQueryForm) {
        return { locked_only: 1, limit: 80 };
      }
      const v = getFormValues(guardQueryForm);
      return {
        customer_id: toInt(v.customer_id, 0),
        customer_no: v.customer_no || '',
        customer_mobile: v.customer_mobile || '',
        locked_only: toInt(v.locked_only, 1),
        limit: toInt(v.limit, 80),
      };
    };

    const loadRows = async () => {
      const res = await request('GET', '/customer-portal/tokens', { query: readQuery() });
      const rows = pickData(res);
      tableRefresh(rows);
      setJsonBox('portalResult', res);
      toast('入口记录已刷新', 'ok');
    };

    const loadGuards = async (showToast = true) => {
      const res = await request('GET', '/customer-portal/guards', { query: readGuardQuery() });
      const rows = pickData(res);
      guardTableRefresh(rows);
      setJsonBox('portalResult', res);
      if (showToast) {
        toast('锁定列表已刷新', 'ok');
      }
    };

    const bindRevokeButtons = () => {
      el.viewRoot.querySelectorAll('.portal-revoke-btn').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const tokenId = toInt(btn.getAttribute('data-token-id'), 0);
          if (tokenId <= 0) return;
          if (!window.confirm(`确认作废入口令牌 #${tokenId} ?`)) return;
          try {
            const res = await request('POST', '/customer-portal/tokens/revoke', {
              body: { token_id: tokenId },
            });
            setJsonBox('portalResult', res);
            await loadRows();
          } catch (err) {
            setJsonBox('portalResult', { message: err.message });
            toast(err.message, 'error');
          }
        });
      });
    };

    const bindGuardUnlockButtons = () => {
      el.viewRoot.querySelectorAll('.portal-guard-unlock-btn').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const customerId = toInt(btn.getAttribute('data-customer-id'), 0);
          const customerName = String(btn.getAttribute('data-customer-name') || '').trim();
          if (customerId <= 0) return;
          if (!window.confirm(`确认解锁客户 ${customerName || `#${customerId}`} 的访问口令？`)) return;
          try {
            const res = await request('POST', '/customer-portal/guards/unlock', {
              body: { customer_id: customerId },
            });
            setJsonBox('portalResult', res);
            await loadGuards(false);
            toast('客户口令锁定已解锁', 'ok');
          } catch (err) {
            setJsonBox('portalResult', { message: err.message });
            toast(err.message, 'error');
          }
        });
      });
    };

    bindRevokeButtons();
    bindGuardUnlockButtons();

    if (queryForm) {
      queryForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
          await loadRows();
        } catch (err) {
          setJsonBox('portalResult', { message: err.message });
          toast(err.message, 'error');
        }
      });
    }

    if (guardQueryForm) {
      guardQueryForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
          await loadGuards();
        } catch (err) {
          setJsonBox('portalResult', { message: err.message });
          toast(err.message, 'error');
        }
      });
    }

    if (unlockForm) {
      unlockForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const v = getFormValues(unlockForm);
        const customerId = toInt(v.customer_id, 0);
        const customerNo = String(v.customer_no || '').trim();
        const customerMobile = String(v.customer_mobile || '').trim();
        if (customerId <= 0 && !customerNo && !customerMobile) {
          toast('请输入客户ID、会员编号或手机号', 'error');
          return;
        }
        try {
          const res = await request('POST', '/customer-portal/guards/unlock', {
            body: {
              customer_id: customerId,
              customer_no: customerNo,
              customer_mobile: customerMobile,
            },
          });
          setJsonBox('portalResult', res);
          await loadGuards(false);
          toast('客户口令锁定已解锁', 'ok');
        } catch (err) {
          setJsonBox('portalResult', { message: err.message });
          toast(err.message, 'error');
        }
      });
    }

    if (createForm) {
      createForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const v = getFormValues(createForm);
        try {
          const res = await request('POST', '/customer-portal/tokens/create', {
            body: {
              customer_id: toInt(v.customer_id, 0),
              customer_no: v.customer_no || '',
              customer_mobile: v.customer_mobile || '',
              expire_days: toInt(v.expire_days, 365),
              note: v.note || '',
            },
          });
          setJsonBox('portalResult', res);
          if (qrPreview) {
            qrPreview.innerHTML = `
              <div class="portal-link-box">
                <p><b>客户：</b>${escapeHtml((res.customer && res.customer.name) ? res.customer.name : '-')}</p>
                <p><b>链接：</b><a href="${escapeHtml(res.portal_url || '#')}" target="_blank" rel="noopener">${escapeHtml(res.portal_url || '-')}</a></p>
                <p><button type="button" class="btn btn-line" id="btnPortalCopyLink">复制链接</button></p>
                <img src="${escapeHtml(res.qr_code_url || '')}" alt="客户扫码二维码" />
              </div>
            `;
            const copyBtn = document.getElementById('btnPortalCopyLink');
            if (copyBtn) {
              copyBtn.addEventListener('click', async () => {
                const text = String(res.portal_url || '').trim();
                if (!text) return;
                try {
                  await navigator.clipboard.writeText(text);
                  toast('链接已复制', 'ok');
                } catch (_e) {
                  window.prompt('复制失败，请手动复制以下链接：', text);
                }
              });
            }
          }
          await loadRows();
        } catch (err) {
          setJsonBox('portalResult', { message: err.message });
          toast(err.message, 'error');
        }
      });
    }

    if (resetForm) {
      resetForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const v = getFormValues(resetForm);
        try {
          const res = await request('POST', '/customer-portal/tokens/reset', {
            body: {
              customer_id: toInt(v.customer_id, 0),
              customer_no: v.customer_no || '',
              customer_mobile: v.customer_mobile || '',
              new_token: v.new_token || '',
              expire_days: toInt(v.expire_days, 365),
              note: v.note || '',
            },
          });
          setJsonBox('portalResult', res);
          if (resetPreview) {
            resetPreview.innerHTML = `
              <div class="portal-link-box">
                <p><b>客户：</b>${escapeHtml((res.customer && res.customer.name) ? res.customer.name : '-')}</p>
                <p><b>新口令：</b>${escapeHtml(res.token || (res.token_info && res.token_info.token_prefix) || '-')}</p>
                <p><b>已作废旧口令：</b>${escapeHtml(res.revoked_count || 0)} 个</p>
                <p><b>链接：</b><a href="${escapeHtml(res.portal_url || '#')}" target="_blank" rel="noopener">${escapeHtml(res.portal_url || '-')}</a></p>
                <p><button type="button" class="btn btn-line" id="btnPortalCopyResetLink">复制链接</button></p>
                <img src="${escapeHtml(res.qr_code_url || '')}" alt="重置后二维码" />
              </div>
            `;
            const copyBtn = document.getElementById('btnPortalCopyResetLink');
            if (copyBtn) {
              copyBtn.addEventListener('click', async () => {
                const text = String(res.portal_url || '').trim();
                if (!text) return;
                try {
                  await navigator.clipboard.writeText(text);
                  toast('链接已复制', 'ok');
                } catch (_e) {
                  window.prompt('复制失败，请手动复制以下链接：', text);
                }
              });
            }
          }
          await loadRows();
        } catch (err) {
          setJsonBox('portalResult', { message: err.message });
          toast(err.message, 'error');
        }
      });
    }
  }

  async function renderReports() {
    const tabKey = 'reports';
    const tabFallback = 'overview';
    const defaultDateFrom = dateInputValue(-29);
    const defaultDateTo = dateInputValue(0);
    const tabHeader = renderSubTabNav(tabKey, [
      { id: 'overview', title: '运营总览', subtitle: '营收、订单、客户、预约、复购全局概览' },
      { id: 'trend', title: '营收趋势', subtitle: '按天查看收款、退款、净收入和新客变化' },
      { id: 'channels', title: '渠道分析', subtitle: '来源渠道的新客、成交、转化与净收入' },
      { id: 'services', title: '项目排行', subtitle: '服务/套餐销售排行与贡献分析' },
      { id: 'payments', title: '支付分析', subtitle: '各支付方式占比、净收款与退款结构' },
      { id: 'store_daily', title: '门店日报', subtitle: '按门店与时间统计日报数据' },
      { id: 'repurchase', title: '复购报表', subtitle: '客户复购与复购率分析' },
      { id: 'performance', title: '员工业绩', subtitle: '员工区间业绩与贡献度' },
    ]);

    const renderKpi = (items) => `
      <section class="grid kpi report-kpi">
        ${items.map((item) => `<article class="kpi-item"><span>${escapeHtml(item.label)}</span><b>${escapeHtml(item.value)}</b></article>`).join('')}
      </section>
    `;

    const bindReportQuery = (formId, resultId, requester, renderer) => {
      const form = document.getElementById(formId);
      if (!form) return;
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const box = document.getElementById(resultId);
        if (box) box.innerHTML = '<div class="loading">查询中...</div>';
        try {
          const v = getFormValues(form);
          const res = await requester(v);
          if (box) box.innerHTML = renderer(res);
          toast('查询完成', 'ok');
        } catch (err) {
          if (box) box.innerHTML = `<div class="empty">${escapeHtml(err.message || '查询失败')}</div>`;
          toast(err.message || '查询失败', 'error');
        }
      });
    };

    const renderOverview = (res) => {
      const s = (res && res.summary) ? res.summary : {};
      const apptRows = [
        { name: '预约总量', value: formatNumber(s.appointments_total) },
        { name: '完成预约', value: formatNumber(s.appointments_completed) },
        { name: '取消预约', value: formatNumber(s.appointments_cancelled) },
        { name: '未到店', value: formatNumber(s.appointments_no_show) },
        { name: '次卡核销次数', value: formatNumber(s.card_consumed_sessions) },
      ];

      return `
        ${renderKpi([
          { label: '收款金额', value: `¥${formatMoney(s.paid_amount)}` },
          { label: '退款金额', value: `¥${formatMoney(s.refund_amount)}` },
          { label: '净收入', value: `¥${formatMoney(s.net_amount)}` },
          { label: '支付订单', value: formatNumber(s.paid_orders) },
          { label: '客单价', value: `¥${formatMoney(s.avg_order_amount)}` },
          { label: '活跃客户', value: formatNumber(s.active_customers) },
          { label: '新增客户', value: formatNumber(s.new_customers) },
          { label: '复购率', value: formatPercent(s.repurchase_rate) },
        ])}
        ${table([
          { label: '预约运营指标', key: 'name' },
          { label: '指标值', key: 'value' },
        ], apptRows, { maxRows: 20 })}
      `;
    };

    const renderTrend = (res) => {
      const rows = pickData(res);
      const s = (res && res.summary) ? res.summary : {};
      return `
        ${renderKpi([
          { label: '统计天数', value: formatNumber(s.days) },
          { label: '总收款', value: `¥${formatMoney(s.paid_amount)}` },
          { label: '总退款', value: `¥${formatMoney(s.refund_amount)}` },
          { label: '净收入', value: `¥${formatMoney(s.net_amount)}` },
          { label: '支付订单', value: formatNumber(s.paid_orders) },
          { label: '支付客户', value: formatNumber(s.paid_customers) },
          { label: '新增客户', value: formatNumber(s.new_customers) },
        ])}
        ${table([
          { label: '日期', key: 'report_date' },
          { label: '收款金额', get: (r) => `¥${formatMoney(r.paid_amount)}` },
          { label: '退款金额', get: (r) => `¥${formatMoney(r.refund_amount)}` },
          { label: '净收入', get: (r) => `¥${formatMoney(r.net_amount)}` },
          { label: '支付订单', get: (r) => formatNumber(r.paid_orders) },
          { label: '支付客户', get: (r) => formatNumber(r.paid_customers) },
          { label: '新增客户', get: (r) => formatNumber(r.new_customers) },
        ], rows, { maxRows: 120, emptyText: '该时间段暂无趋势数据' })}
      `;
    };

    const renderChannels = (res) => {
      const rows = pickData(res);
      const s = (res && res.summary) ? res.summary : {};
      return `
        ${renderKpi([
          { label: '渠道数', value: formatNumber(s.channels) },
          { label: '新增客户', value: formatNumber(s.new_customers) },
          { label: '成交客户', value: formatNumber(s.paid_customers) },
          { label: '支付订单', value: formatNumber(s.paid_orders) },
          { label: '渠道收款', value: `¥${formatMoney(s.paid_amount)}` },
          { label: '渠道退款', value: `¥${formatMoney(s.refund_amount)}` },
          { label: '渠道净收入', value: `¥${formatMoney(s.net_amount)}` },
        ])}
        ${table([
          { label: '来源渠道', key: 'source_channel' },
          { label: '新增客户', get: (r) => formatNumber(r.new_customers) },
          { label: '成交客户', get: (r) => formatNumber(r.paid_customers) },
          { label: '支付订单', get: (r) => formatNumber(r.paid_orders) },
          { label: '收款金额', get: (r) => `¥${formatMoney(r.paid_amount)}` },
          { label: '退款金额', get: (r) => `¥${formatMoney(r.refund_amount)}` },
          { label: '净收入', get: (r) => `¥${formatMoney(r.net_amount)}` },
          { label: '客单价', get: (r) => `¥${formatMoney(r.avg_order_amount)}` },
          { label: '渠道转化率', get: (r) => formatPercent(r.conversion_rate) },
        ], rows, { maxRows: 120, emptyText: '该时间段暂无渠道数据' })}
      `;
    };

    const renderServiceTop = (res) => {
      const rows = pickData(res).map((r, i) => ({ ...r, rank: i + 1 }));
      const s = (res && res.summary) ? res.summary : {};
      return `
        ${renderKpi([
          { label: '项目数', value: formatNumber(s.items) },
          { label: '总销量(次数)', value: formatNumber(s.total_qty) },
          { label: '覆盖订单', value: formatNumber(s.order_count) },
          { label: '销售金额', value: `¥${formatMoney(s.sales_amount)}` },
          { label: '提成金额', value: `¥${formatMoney(s.commission_amount)}` },
        ])}
        ${table([
          { label: '排名', key: 'rank' },
          { label: '项目名称', get: (r) => `${r.item_name || '-'}（${r.item_type || '-'}）` },
          { label: '项目ID', get: (r) => formatNumber(r.item_ref_id) },
          { label: '销量(次数)', get: (r) => formatNumber(r.total_qty) },
          { label: '订单数', get: (r) => formatNumber(r.order_count) },
          { label: '销售金额', get: (r) => `¥${formatMoney(r.sales_amount)}` },
          { label: '单均金额', get: (r) => `¥${formatMoney(r.avg_order_amount)}` },
          { label: '提成金额', get: (r) => `¥${formatMoney(r.commission_amount)}` },
        ], rows, { maxRows: 120, emptyText: '该时间段暂无项目销售数据' })}
      `;
    };

    const renderPaymentMethods = (res) => {
      const rows = pickData(res);
      const s = (res && res.summary) ? res.summary : {};
      return `
        ${renderKpi([
          { label: '支付方式数', value: formatNumber(s.methods) },
          { label: '收款金额', value: `¥${formatMoney(s.paid_amount)}` },
          { label: '退款金额', value: `¥${formatMoney(s.refund_amount)}` },
          { label: '净收入', value: `¥${formatMoney(s.net_amount)}` },
          { label: '支付流水笔数', value: formatNumber(s.txn_count) },
          { label: '涉及订单数', value: formatNumber(s.order_count) },
        ])}
        ${table([
          { label: '支付方式', get: (r) => zhPayMethod(r.pay_method) },
          { label: '流水笔数', get: (r) => formatNumber(r.txn_count) },
          { label: '订单数', get: (r) => formatNumber(r.order_count) },
          { label: '收款金额', get: (r) => `¥${formatMoney(r.paid_amount)}` },
          { label: '退款金额', get: (r) => `¥${formatMoney(r.refund_amount)}` },
          { label: '净收入', get: (r) => `¥${formatMoney(r.net_amount)}` },
          { label: '收款占比', get: (r) => formatPercent(r.amount_share) },
        ], rows, { maxRows: 120, emptyText: '该时间段暂无支付方式数据' })}
      `;
    };

    const renderStoreDaily = (res) => {
      const rows = pickData(res);
      const s = (res && res.summary) ? res.summary : {};
      return `
        ${renderKpi([
          { label: '统计行数', value: formatNumber(s.days) },
          { label: '支付订单', value: formatNumber(s.paid_orders) },
          { label: '支付金额', value: `¥${formatMoney(s.paid_amount)}` },
          { label: '支付客户', value: formatNumber(s.paid_customers) },
          { label: '新增客户', value: formatNumber(s.new_customers) },
        ])}
        ${table([
          { label: '日期', key: 'report_date' },
          { label: '门店', get: (r) => `${r.store_name || '-'} (#${r.store_id || 0})` },
          { label: '支付订单', get: (r) => formatNumber(r.paid_orders) },
          { label: '支付金额', get: (r) => `¥${formatMoney(r.paid_amount)}` },
          { label: '客单价', get: (r) => `¥${formatMoney(r.avg_order_amount)}` },
          { label: '支付客户', get: (r) => formatNumber(r.paid_customers) },
          { label: '新增客户', get: (r) => formatNumber(r.new_customers) },
        ], rows, { maxRows: 120, emptyText: '该时间段暂无门店日报数据' })}
      `;
    };

    const renderRepurchase = (res) => {
      const rows = pickData(res);
      const s = (res && res.summary) ? res.summary : {};
      return `
        ${renderKpi([
          { label: '复购客户数', value: formatNumber(s.customers) },
          { label: '复购订单数', value: formatNumber(s.total_paid_orders) },
          { label: '复购金额', value: `¥${formatMoney(s.total_spent)}` },
        ])}
        ${table([
          { label: '客户', get: (r) => `${r.customer_name || ''} (${r.customer_mobile || ''})` },
          { label: '会员编号', key: 'customer_no' },
          { label: '门店', get: (r) => `${r.store_name || '-'} (#${r.store_id || 0})` },
          { label: '已付订单', get: (r) => formatNumber(r.paid_orders) },
          { label: '总消费', get: (r) => `¥${formatMoney(r.total_spent)}` },
          { label: '首次支付', key: 'first_paid_at' },
          { label: '最近支付', key: 'last_paid_at' },
        ], rows, { maxRows: 200, emptyText: '该时间段暂无复购客户' })}
      `;
    };

    const renderPerformance = (res) => {
      const rows = pickData(res);
      const s = (res && res.summary) ? res.summary : {};
      return `
        ${renderKpi([
          { label: '员工人数', value: formatNumber(s.staff_count) },
          { label: '销售金额', value: `¥${formatMoney(s.sales_amount)}` },
          { label: '提成金额', value: `¥${formatMoney(s.commission_amount)}` },
          { label: '项目条目', value: formatNumber(s.item_count) },
          { label: '订单数', value: formatNumber(s.order_count) },
        ])}
        ${table([
          { label: '员工', get: (r) => `${r.staff_username || '-'} (${r.staff_no || '-'})` },
          { label: '角色', get: (r) => zhRole(r.role_key) },
          { label: '邮箱', key: 'staff_email' },
          { label: '项目条目', get: (r) => formatNumber(r.item_count) },
          { label: '订单数', get: (r) => formatNumber(r.order_count) },
          { label: '销售金额', get: (r) => `¥${formatMoney(r.sales_amount)}` },
          { label: '提成金额', get: (r) => `¥${formatMoney(r.commission_amount)}` },
        ], rows, { maxRows: 200, emptyText: '该时间段暂无员工业绩数据' })}
      `;
    };

    el.viewRoot.innerHTML = `
      ${tabHeader}
      <section class="row${subTabClass(tabKey, 'overview', tabFallback)}">
        <article class="card">
          <h3>运营总览筛选</h3>
          <form id="formReportOverview" class="form-grid">
            <input name="store_id" placeholder="门店ID(可空=全部可见门店)" />
            <input type="date" name="date_from" value="${defaultDateFrom}" />
            <input type="date" name="date_to" value="${defaultDateTo}" />
            <button class="btn btn-primary" type="submit">查询运营总览</button>
          </form>
        </article>
      </section>
      <section class="card${subTabClass(tabKey, 'overview', tabFallback)}"><h3>运营总览结果</h3><div id="reportOverviewResult">${renderEmpty('请先查询运营总览')}</div></section>

      <section class="row${subTabClass(tabKey, 'trend', tabFallback)}">
        <article class="card">
          <h3>营收趋势筛选</h3>
          <form id="formReportTrend" class="form-grid">
            <input name="store_id" placeholder="门店ID(可空=全部可见门店)" />
            <input type="date" name="date_from" value="${defaultDateFrom}" />
            <input type="date" name="date_to" value="${defaultDateTo}" />
            <button class="btn btn-primary" type="submit">查询营收趋势</button>
          </form>
        </article>
      </section>
      <section class="card${subTabClass(tabKey, 'trend', tabFallback)}"><h3>营收趋势结果</h3><div id="reportTrendResult">${renderEmpty('请先查询营收趋势')}</div></section>

      <section class="row${subTabClass(tabKey, 'channels', tabFallback)}">
        <article class="card">
          <h3>渠道分析筛选</h3>
          <form id="formReportChannels" class="form-grid">
            <input name="store_id" placeholder="门店ID(可空=全部可见门店)" />
            <input type="date" name="date_from" value="${defaultDateFrom}" />
            <input type="date" name="date_to" value="${defaultDateTo}" />
            <button class="btn btn-primary" type="submit">查询渠道分析</button>
          </form>
        </article>
      </section>
      <section class="card${subTabClass(tabKey, 'channels', tabFallback)}"><h3>渠道分析结果</h3><div id="reportChannelResult">${renderEmpty('请先查询渠道分析')}</div></section>

      <section class="row${subTabClass(tabKey, 'services', tabFallback)}">
        <article class="card">
          <h3>项目排行筛选</h3>
          <form id="formReportServices" class="form-grid">
            <input name="store_id" placeholder="门店ID(可空=全部可见门店)" />
            <input type="date" name="date_from" value="${defaultDateFrom}" />
            <input type="date" name="date_to" value="${defaultDateTo}" />
            <input name="limit" placeholder="排行条数（1-100）" value="20" />
            <button class="btn btn-primary" type="submit">查询项目排行</button>
          </form>
        </article>
      </section>
      <section class="card${subTabClass(tabKey, 'services', tabFallback)}"><h3>项目排行结果</h3><div id="reportServiceResult">${renderEmpty('请先查询项目排行')}</div></section>

      <section class="row${subTabClass(tabKey, 'payments', tabFallback)}">
        <article class="card">
          <h3>支付分析筛选</h3>
          <form id="formReportPayments" class="form-grid">
            <input name="store_id" placeholder="门店ID(可空=全部可见门店)" />
            <input type="date" name="date_from" value="${defaultDateFrom}" />
            <input type="date" name="date_to" value="${defaultDateTo}" />
            <button class="btn btn-primary" type="submit">查询支付分析</button>
          </form>
        </article>
      </section>
      <section class="card${subTabClass(tabKey, 'payments', tabFallback)}"><h3>支付分析结果</h3><div id="reportPaymentResult">${renderEmpty('请先查询支付分析')}</div></section>

      <section class="row${subTabClass(tabKey, 'store_daily', tabFallback)}">
        <article class="card">
          <h3>门店日报</h3>
          <form id="formStoreDaily" class="form-grid">
            <input name="store_id" placeholder="门店ID(可空)" />
            <input type="date" name="date_from" value="${defaultDateFrom}" />
            <input type="date" name="date_to" value="${defaultDateTo}" />
            <button class="btn btn-primary" type="submit">查询日报</button>
          </form>
        </article>
      </section>
      <section class="card${subTabClass(tabKey, 'store_daily', tabFallback)}"><h3>门店日报结果</h3><div id="reportDailyResult">${renderEmpty('请先查询门店日报')}</div></section>

      <section class="row${subTabClass(tabKey, 'repurchase', tabFallback)}">
        <article class="card">
          <h3>客户复购报表</h3>
          <form id="formRepurchase" class="form-grid">
            <input name="store_id" placeholder="门店ID(可空)" />
            <input type="date" name="date_from" value="${defaultDateFrom}" />
            <input type="date" name="date_to" value="${defaultDateTo}" />
            <input name="min_orders" placeholder="最少已付订单数" value="2" />
            <button class="btn btn-primary" type="submit">查询复购</button>
          </form>
        </article>
      </section>
      <section class="card${subTabClass(tabKey, 'repurchase', tabFallback)}"><h3>复购报表结果</h3><div id="reportRepurchaseResult">${renderEmpty('请先查询复购报表')}</div></section>

      <section class="row${subTabClass(tabKey, 'performance', tabFallback)}">
        <article class="card">
          <h3>员工业绩</h3>
          <form id="formPerformance" class="form-grid">
            <input name="store_id" placeholder="门店ID(可空)" />
            <input type="date" name="date_from" value="${defaultDateFrom}" />
            <input type="date" name="date_to" value="${defaultDateTo}" />
            <button class="btn btn-primary" type="submit">查询业绩</button>
          </form>
        </article>
      </section>
      <section class="card${subTabClass(tabKey, 'performance', tabFallback)}"><h3>员工业绩结果</h3><div id="reportPerformanceResult">${renderEmpty('请先查询员工业绩')}</div></section>
    `;
    bindSubTabNav(tabKey, tabFallback);

    bindReportQuery('formReportOverview', 'reportOverviewResult', (v) => request('GET', '/reports/operation-overview', {
      query: {
        store_id: toInt(v.store_id, 0),
        date_from: v.date_from,
        date_to: v.date_to,
      },
    }), renderOverview);

    bindReportQuery('formReportTrend', 'reportTrendResult', (v) => request('GET', '/reports/revenue-trend', {
      query: {
        store_id: toInt(v.store_id, 0),
        date_from: v.date_from,
        date_to: v.date_to,
      },
    }), renderTrend);

    bindReportQuery('formReportChannels', 'reportChannelResult', (v) => request('GET', '/reports/channel-stats', {
      query: {
        store_id: toInt(v.store_id, 0),
        date_from: v.date_from,
        date_to: v.date_to,
      },
    }), renderChannels);

    bindReportQuery('formReportServices', 'reportServiceResult', (v) => request('GET', '/reports/service-top', {
      query: {
        store_id: toInt(v.store_id, 0),
        date_from: v.date_from,
        date_to: v.date_to,
        limit: toInt(v.limit, 20),
      },
    }), renderServiceTop);

    bindReportQuery('formReportPayments', 'reportPaymentResult', (v) => request('GET', '/reports/payment-methods', {
      query: {
        store_id: toInt(v.store_id, 0),
        date_from: v.date_from,
        date_to: v.date_to,
      },
    }), renderPaymentMethods);

    bindReportQuery('formStoreDaily', 'reportDailyResult', (v) => request('GET', '/reports/store-daily', {
      query: {
        store_id: toInt(v.store_id, 0),
        date_from: v.date_from,
        date_to: v.date_to,
      },
    }), renderStoreDaily);

    bindReportQuery('formRepurchase', 'reportRepurchaseResult', (v) => request('GET', '/reports/customer-repurchase', {
      query: {
        store_id: toInt(v.store_id, 0),
        date_from: v.date_from,
        date_to: v.date_to,
        min_orders: toInt(v.min_orders, 2),
      },
    }), renderRepurchase);

    bindReportQuery('formPerformance', 'reportPerformanceResult', (v) => request('GET', '/performance/staff', {
      query: {
        store_id: toInt(v.store_id, 0),
        date_from: v.date_from,
        date_to: v.date_to,
      },
    }), renderPerformance);

    const tabToForm = {
      overview: 'formReportOverview',
      trend: 'formReportTrend',
      channels: 'formReportChannels',
      services: 'formReportServices',
      payments: 'formReportPayments',
      store_daily: 'formStoreDaily',
      repurchase: 'formRepurchase',
      performance: 'formPerformance',
    };
    const activeTab = getSubTab(tabKey, tabFallback);
    const activeFormId = tabToForm[activeTab] || tabToForm[tabFallback];
    const activeForm = document.getElementById(activeFormId);
    if (activeForm) {
      activeForm.dispatchEvent(new Event('submit'));
    }
  }

  async function renderApiLab() {
    el.viewRoot.innerHTML = `
      <section class="card panel-top">
        <h3>接口调试台</h3>
        <p class="small-note">可直连所有后端接口。路径填写 <code>/api/v1/...</code> 或简写 <code>/...</code>（自动加前缀）。</p>
        <form id="formApiLab" class="form-grid">
          <div class="row">
            <select name="method">
              <option>GET</option>
              <option>POST</option>
              <option>PUT</option>
              <option>PATCH</option>
              <option>DELETE</option>
            </select>
            <input name="path" value="/dashboard/summary" />
          </div>
          <textarea name="payload" placeholder='请求体（JSON格式），例如 {"limit":100}'></textarea>
          <button class="btn btn-primary" type="submit">发送请求</button>
        </form>
      </section>

      <section class="card"><h3>响应</h3>${jsonBox('apiLabResult', '等待请求')}</section>
    `;

    bindJsonForm('formApiLab', 'apiLabResult', async (form) => {
      const v = getFormValues(form);
      const method = (v.method || 'GET').toUpperCase();
      const rawPath = v.path || '/dashboard/summary';
      const path = rawPath.startsWith('/api/v1') ? rawPath.replace('/api/v1', '') : rawPath;
      let body = null;

      if (v.payload) {
        try {
          body = JSON.parse(v.payload);
        } catch (_e) {
          throw new Error('请求体不是有效的JSON格式');
        }
      }

      if (method === 'GET') {
        return request('GET', path, { query: body || null });
      }

      return request(method, path, { body: body || {} });
    });
  }

  async function doLogin(e) {
    e.preventDefault();
    el.loginBtn.disabled = true;
    el.loginBtn.textContent = '登录中...';

    try {
      const payload = await request('POST', '/auth/login', {
        auth: false,
        body: {
          username: el.loginUsername.value.trim(),
          password: el.loginPassword.value,
        },
      });

      state.token = payload.token || '';
      state.user = payload.user || null;

      if (!state.token) {
        throw new Error('登录返回缺少 token');
      }

      localStorage.setItem(TOKEN_KEY, state.token);
      showApp();
      renderNav();
      await openView(state.activeView);
      toast('登录成功', 'ok');
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      el.loginBtn.disabled = false;
      el.loginBtn.textContent = '登录后台';
    }
  }

  async function bootstrap() {
    el.loginForm.addEventListener('submit', doLogin);
    el.logoutBtn.addEventListener('click', () => logout(true));

    if (await tryAuthMe()) {
      showApp();
      renderNav();
      await openView(state.activeView);
      return;
    }

    logout(false);
  }

  bootstrap();
})();
