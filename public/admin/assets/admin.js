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
  const CRM_TOKEN_KEY = 'qiling_crm_admin_token';

  function readStoredToken(key) {
    const storageKey = String(key || '').trim();
    if (!storageKey) return '';

    try {
      const current = String(sessionStorage.getItem(storageKey) || '').trim();
      if (current !== '') {
        return current;
      }
    } catch (_err) {
      // ignore sessionStorage access errors
    }

    const legacy = String(localStorage.getItem(storageKey) || '').trim();
    if (legacy !== '') {
      try {
        sessionStorage.setItem(storageKey, legacy);
      } catch (_err) {
        // ignore sessionStorage access errors
      }
      localStorage.removeItem(storageKey);
    }
    return legacy;
  }

  function writeStoredToken(key, value) {
    const storageKey = String(key || '').trim();
    const token = String(value || '').trim();
    if (!storageKey || !token) return;

    try {
      sessionStorage.setItem(storageKey, token);
    } catch (_err) {
      // ignore sessionStorage access errors
    }
    localStorage.removeItem(storageKey);
  }

  function clearStoredToken(key) {
    const storageKey = String(key || '').trim();
    if (!storageKey) return;
    try {
      sessionStorage.removeItem(storageKey);
    } catch (_err) {
      // ignore sessionStorage access errors
    }
    localStorage.removeItem(storageKey);
  }

  const el = {
    loginScreen: document.getElementById('loginScreen'),
    appScreen: document.getElementById('appScreen'),
    loginForm: document.getElementById('loginForm'),
    loginBtn: document.getElementById('loginBtn'),
    loginUsername: document.getElementById('loginUsername'),
    loginPassword: document.getElementById('loginPassword'),
    forgotPwdBtn: document.getElementById('forgotPwdBtn'),
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
    token: readStoredToken(TOKEN_KEY),
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
      { id: 'mobile_menu', title: '移动端菜单', subtitle: '员工菜单权限与角色配置', group: '系统管理' },
      { id: 'system_upgrade', title: '系统升级', subtitle: '版本信息、升级状态与一键升级', group: '系统管理' },
      { id: 'crm_admin', title: 'CRM 后台', subtitle: '打开 CRM 独立管理后台（新标签）', group: '系统管理' },
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

    const hidden = new Set(['api', 'system', 'mobile_menu', 'system_upgrade']);
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
      'password must be at least 8 chars': '密码至少 8 位',
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
      'load upgrade status failed': '读取升级状态失败',
      'run upgrade failed': '执行升级失败',
      'schema.sql not found': '升级脚本缺失：schema.sql 不存在',
      'schema.sql is empty': '升级脚本为空：schema.sql 无内容',
      'refresh frontend assets failed': '刷新前端资源失败',
      'channel cost table is not ready, please run system upgrade': '渠道成本表未就绪，请先执行系统升级',
      'report_date is invalid': '日期格式无效，请使用 YYYY-MM-DD',
      'cost_amount is invalid': '成本金额格式无效',
      'cost_amount must be >= 0': '成本金额不能小于 0',
      'items too many': '单次提交条目过多，请拆分后重试',
      'password reset is disabled': '当前未启用邮箱找回，请联系管理员处理',
      'account, email, code, new_password are required': '请填写账号、邮箱、验证码和新密码',
      'new_password must be at least 8 chars': '新密码至少 8 位',
      'invalid reset code': '验证码无效或已过期，请重新获取',
      'password reset success': '密码重置成功，请使用新密码登录',
      'If account info matches, a verification code has been sent': '如果账号和邮箱匹配，验证码已发送，请查收邮箱',
    };
    if (Object.prototype.hasOwnProperty.call(exact, raw)) {
      return exact[raw];
    }

    const dynamicMin = raw.match(/^(password|new_password) must be at least (\d+) chars$/);
    if (dynamicMin) {
      return dynamicMin[1] === 'new_password'
        ? `新密码至少 ${dynamicMin[2]} 位`
        : `密码至少 ${dynamicMin[2]} 位`;
    }

    const dynamicMax = raw.match(/^(password|new_password) must be at most (\d+) chars$/);
    if (dynamicMax) {
      return dynamicMax[1] === 'new_password'
        ? `新密码长度不能超过 ${dynamicMax[2]} 位`
        : `密码长度不能超过 ${dynamicMax[2]} 位`;
    }

    const dynamicClasses = raw.match(/^(password|new_password) must include at least (\d+) of uppercase, lowercase, number and symbol$/);
    if (dynamicClasses) {
      return dynamicClasses[1] === 'new_password'
        ? `新密码需包含大写字母、小写字母、数字、符号中的至少 ${dynamicClasses[2]} 类`
        : `密码需包含大写字母、小写字母、数字、符号中的至少 ${dynamicClasses[2]} 类`;
    }

    if (raw === 'password must not contain spaces') return '密码不能包含空格';
    if (raw === 'new_password must not contain spaces') return '新密码不能包含空格';
    if (raw === 'password is too common or leaked') return '密码过于常见或已泄露，请更换';
    if (raw === 'new_password is too common or leaked') return '新密码过于常见或已泄露，请更换';
    if (raw === 'password is too similar to account information') return '密码不能与账号信息过于相似';
    if (raw === 'new_password is too similar to account information') return '新密码不能与账号信息过于相似';

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
      const normalizedPath = `/${path.replace(/^\/+/, '')}`;
      const isCrmEntry = /^\/crm-admin(?:\/|$)/.test(normalizedPath);
      const full = absolutePageUrl(path);
      return `
        <article class="page-url-item">
          <div class="page-url-main">
            <h4>${escapeHtml(label)}</h4>
            <p><code>${escapeHtml(path)}</code></p>
            <a href="${escapeHtml(full)}" target="_blank" rel="noopener"${isCrmEntry ? ' data-open-crm-admin="1"' : ''}>${escapeHtml(full)}</a>
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

    scope.querySelectorAll('[data-open-crm-admin]').forEach((link) => {
      if (link.dataset.boundCrmSso === '1') return;
      link.dataset.boundCrmSso = '1';
      link.addEventListener('click', (e) => {
        e.preventDefault();
        openCrmAdminInNewTab();
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
          return `<button type="button" class="nav-item ${active}" data-view="${item.id}" data-title="${escapeHtml(item.title)}" title="${escapeHtml(item.subtitle)}">${escapeHtml(item.title)}</button>`;
        }).join('')}
      </section>
    `).join('');

    el.navList.onclick = (event) => {
      const target = event && event.target ? event.target : null;
      if (!target || typeof target.closest !== 'function') return;
      const btn = target.closest('.nav-item');
      if (!btn) return;

      let id = String(btn.getAttribute('data-view') || '').trim();
      const title = String(btn.getAttribute('data-title') || '').trim();
      if (!id && title === '报表中心') {
        id = 'reports';
      }
      if (!id) return;
      openView(id);
    };
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
    syncCrmTokenFromAdminToken();
    const user = state.user || {};
    const storeId = toInt(user.staff_store_id, 0);
    el.userName.textContent = user.username || '-';
    el.userMeta.textContent = `${zhRole(user.role_key)} · ${storeId > 0 ? `门店#${storeId}` : '总部/未绑定门店'}`;
  }

  function logout(showToast = true) {
    state.token = '';
    state.user = null;
    clearStoredToken(TOKEN_KEY);
    clearStoredToken(CRM_TOKEN_KEY);
    showLogin();
    if (showToast) toast('已退出登录', 'info');
  }

  function syncCrmTokenFromAdminToken() {
    const token = String(state.token || '').trim();
    if (!token) return false;
    writeStoredToken(CRM_TOKEN_KEY, token);
    try {
      // sessionStorage is tab-scoped; keep a short-lived localStorage bridge for new CRM tabs.
      localStorage.setItem(CRM_TOKEN_KEY, token);
    } catch (_err) {
      // ignore localStorage access errors
    }
    return true;
  }

  function openCrmAdminInNewTab() {
    const authed = syncCrmTokenFromAdminToken();
    const crmUrl = absolutePageUrl('/crm-admin');
    window.open(crmUrl, '_blank', 'noopener');
    if (authed) {
      toast('已自动授权并打开 CRM 后台', 'info');
    } else {
      toast('已打开 CRM 后台', 'info');
    }
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
    if (viewId === 'crm_admin') {
      openCrmAdminInNewTab();
      return;
    }

    const navItems = visibleNavItems();
    const allNavItems = Array.isArray(state.nav) ? state.nav : [];
    const requestedView = String(viewId || '').trim();
    const hasRequested = allNavItems.some((v) => v.id === requestedView);
    const hasCurrent = navItems.some((v) => v.id === state.activeView);
    const safeViewId = hasRequested
      ? requestedView
      : (hasCurrent ? state.activeView : ((navItems[0] && navItems[0].id) || 'dashboard'));
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
        case 'mobile_menu':
          await renderSystemMobileMenu();
          break;
        case 'system_upgrade':
          await renderSystemUpgrade();
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


  const VIEW_SCRIPT_MAP = {
    dashboard: '/admin/assets/views/dashboard.js',
    master: '/admin/assets/views/master.js',
    ops: '/admin/assets/views/ops.js',
    manual: '/admin/assets/views/manual.js',
    growth: '/admin/assets/views/growth.js',
    finance: '/admin/assets/views/finance.js',
    followpush: '/admin/assets/views/followpush.js',
    system: '/admin/assets/views/system.js',
    mobile_menu: '/admin/assets/views/mobile-menu.js',
    system_upgrade: '/admin/assets/views/system-upgrade.js',
    bizplus: '/admin/assets/views/bizplus.js',
    commission: '/admin/assets/views/commission.js',
    integration: '/admin/assets/views/integration.js',
    portal: '/admin/assets/views/portal.js',
    reports: '/admin/assets/views/reports.js',
    api: '/admin/assets/views/api.js',
  };

  const VIEW_RENDER_FN_MAP = {
    dashboard: 'renderDashboard',
    master: 'renderMaster',
    ops: 'renderOps',
    manual: 'renderManual',
    growth: 'renderGrowth',
    finance: 'renderFinance',
    followpush: 'renderFollowupPush',
    system: 'renderSystemSettings',
    mobile_menu: 'renderSystemMobileMenu',
    system_upgrade: 'renderSystemUpgrade',
    bizplus: 'renderBizPlus',
    commission: 'renderCommission',
    integration: 'renderIntegration',
    portal: 'renderPortal',
    reports: 'renderReports',
    api: 'renderApiLab',
  };

  const viewRendererCache = {};
  const viewScriptLoaders = {};
  const adminAssetVersion = (() => {
    try {
      const current = document.currentScript;
      const src = current && current.src ? current.src : '';
      if (!src) return '';
      return new URL(src, window.location.href).searchParams.get('v') || '';
    } catch (_e) {
      return '';
    }
  })();

  function buildViewSharedContext() {
    return {
      PATHNAME,
      ROOT_PATH,
      API_PREFIX,
      TOKEN_KEY,
      el,
      state,
      SOURCE_CHANNEL_OPTIONS,
      SOURCE_CHANNEL_ALIAS,
      SERVICE_CATEGORY_OPTIONS,
      MOBILE_VALUE_FIELDS,
      escapeHtml,
      renderSourceChannelOptionTags,
      renderSourceChannelDatalist,
      renderSourceChannelField,
      normalizeSourceChannel,
      normalizeMobileValue,
      bindSourceChannelAssist,
      normalizeServiceCategory,
      mergeServiceCategories,
      renderServiceCategoryOptionTags,
      renderServiceCategoryDatalist,
      renderServiceCategoryField,
      bindServiceCategoryAssist,
      applyStoreDefault,
      storeOptionLabel,
      renderStoreOptionTags,
      renderStoreDatalist,
      renderStoreField,
      normalizeStoreId,
      bindStoreAssist,
      toast,
      setLoading,
      renderEmpty,
      parseDateTimeInput,
      parseListInput,
      parseCsvLines,
      parseJsonText,
      zhStatus,
      zhEnabled,
      zhRole,
      zhPayMethod,
      zhCouponType,
      zhProvider,
      zhSecurityMode,
      zhTriggerType,
      zhBusinessType,
      zhActionType,
      zhChangeType,
      zhTriggerSource,
      zhTargetType,
      visibleNavItems,
      getSubTab,
      renderSubTabNav,
      subTabClass,
      bindSubTabNav,
      zhErrorMessage,
      table,
      getFormValues,
      jsonBox,
      setJsonBox,
      toInt,
      toFloat,
      formatMoney,
      formatPercent,
      formatNumber,
      dateInputValue,
      pickData,
      endpoint,
      appPath,
      appBaseUrl,
      absolutePageUrl,
      renderPageLinksCard,
      bindCopyUrlButtons,
      request,
      renderNav,
      tryAuthMe,
      showLogin,
      showApp,
      logout,
      bindJsonForm,
      openView,
      window,
      document,
      localStorage,
      Event,
      URL,
    };
  }

  function resolveViewScriptUrl(viewKey) {
    const scriptPath = VIEW_SCRIPT_MAP[viewKey];
    if (!scriptPath) {
      throw new Error(`未知视图模块：${viewKey}`);
    }
    const base = appPath(scriptPath);
    if (!adminAssetVersion) {
      return base;
    }
    const sep = base.includes('?') ? '&' : '?';
    return `${base}${sep}v=${encodeURIComponent(adminAssetVersion)}`;
  }

  async function ensureViewScriptLoaded(viewKey) {
    if (viewScriptLoaders[viewKey]) {
      return viewScriptLoaders[viewKey];
    }

    const url = resolveViewScriptUrl(viewKey);
    viewScriptLoaders[viewKey] = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = url;
      script.async = true;
      script.onload = () => resolve();
      script.onerror = () => reject(new Error(`加载模块失败：${viewKey}`));
      document.head.appendChild(script);
    }).catch((err) => {
      delete viewScriptLoaders[viewKey];
      throw err;
    });

    return viewScriptLoaders[viewKey];
  }

  async function ensureViewRenderer(viewKey) {
    const cached = viewRendererCache[viewKey];
    if (typeof cached === 'function') {
      return cached;
    }

    await ensureViewScriptLoaded(viewKey);

    const fnName = VIEW_RENDER_FN_MAP[viewKey];
    const factories = window.__QILING_ADMIN_VIEW_FACTORIES__ || {};
    const factory = factories[viewKey];

    if (typeof factory !== 'function') {
      throw new Error(`模块未注册：${viewKey}`);
    }

    const renderer = factory(buildViewSharedContext());
    if (typeof renderer !== 'function') {
      throw new Error(`模块未导出可执行函数：${viewKey}.${fnName}`);
    }

    viewRendererCache[viewKey] = renderer;
    return renderer;
  }

  async function invokeViewRenderer(viewKey) {
    const renderer = await ensureViewRenderer(viewKey);
    return renderer();
  }

  async function renderDashboard() {
    return invokeViewRenderer('dashboard');
  }

  async function renderMaster() {
    return invokeViewRenderer('master');
  }

  async function renderOps() {
    return invokeViewRenderer('ops');
  }

  async function renderManual() {
    return invokeViewRenderer('manual');
  }

  async function renderGrowth() {
    return invokeViewRenderer('growth');
  }

  async function renderFinance() {
    return invokeViewRenderer('finance');
  }

  async function renderFollowupPush() {
    return invokeViewRenderer('followpush');
  }

  async function renderSystemSettings() {
    return invokeViewRenderer('system');
  }

  async function renderSystemMobileMenu() {
    return invokeViewRenderer('mobile_menu');
  }

  async function renderSystemUpgrade() {
    return invokeViewRenderer('system_upgrade');
  }

  async function renderBizPlus() {
    return invokeViewRenderer('bizplus');
  }

  async function renderCommission() {
    return invokeViewRenderer('commission');
  }

  async function renderIntegration() {
    return invokeViewRenderer('integration');
  }

  async function renderPortal() {
    return invokeViewRenderer('portal');
  }

  async function renderReports() {
    return invokeViewRenderer('reports');
  }

  async function renderApiLab() {
    return invokeViewRenderer('api');
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

      writeStoredToken(TOKEN_KEY, state.token);
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

  async function forgotPasswordFlow() {
    const account = window.prompt('请输入账号（用户名或邮箱）', String(el.loginUsername.value || '').trim());
    if (!account) return;
    const email = window.prompt('请输入该账号绑定邮箱');
    if (!email) return;

    try {
      await request('POST', '/auth/password-reset/request', {
        auth: false,
        body: { account: String(account).trim(), email: String(email).trim() },
      });
      toast('如果账号和邮箱匹配，验证码已发送，请查收邮箱', 'info');

      const code = window.prompt('请输入收到的6位验证码');
      if (!code) return;
      const newPassword = window.prompt('请输入新密码（至少8位，且包含大小写字母/数字/符号中的至少3类）');
      if (!newPassword) return;

      await request('POST', '/auth/password-reset/confirm', {
        auth: false,
        body: {
          account: String(account).trim(),
          email: String(email).trim(),
          code: String(code).trim(),
          new_password: String(newPassword),
        },
      });

      toast('密码重置成功，请使用新密码登录', 'ok');
    } catch (err) {
      toast(err.message, 'error');
    }
  }

  async function bootstrap() {
    el.loginForm.addEventListener('submit', doLogin);
    if (el.forgotPwdBtn) {
      el.forgotPwdBtn.addEventListener('click', forgotPasswordFlow);
    }
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
