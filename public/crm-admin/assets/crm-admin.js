(function () {
  const ROOT_PATH = String(window.__QILING_ROOT_PATH__ || '').replace(/\/+$/, '');
  const API_PREFIX = `${ROOT_PATH}/api/v1`;
  const CRM_ADMIN_ASSET_BASE = `${ROOT_PATH}/crm-admin/assets`;
  const TOKEN_KEY = 'qiling_crm_admin_token';

  const state = {
    token: localStorage.getItem(TOKEN_KEY) || '',
    user: null,
    view: 'dashboard',
    meta: {
      customFieldCache: {},
      formConfigCache: {},
    },
    ui: {
      companies: { cursor: 0, prev: [], filters: {}, editing: null },
      contacts: { cursor: 0, prev: [], filters: {}, editing: null },
      leads: { cursor: 0, prev: [], filters: {}, editing: null },
      deals: { cursor: 0, prev: [], filters: {}, editing: null, view_mode: 'table' },
      activities: { cursor: 0, prev: [], filters: {} },
      trade: { cursor: 0, prev: [], filters: {} },
      automation: { cursor: 0, prev: [], filters: {} },
      analytics: { cursor: 0, prev: [], filters: {} },
      bridge: { cursor: 0, prev: [], filters: {}, lookup: {} },
      org: { cursor: 0, prev: [], filters: {} },
      customization: { cursor: 0, prev: [], filters: {} },
      reminders: { cursor: 0, prev: [], filters: {} },
    },
  };

  const views = [
    { id: 'dashboard', label: '总览' },
    { id: 'leads', label: '线索' },
    { id: 'companies', label: '企业' },
    { id: 'contacts', label: '联系人' },
    { id: 'deals', label: '商机' },
    { id: 'activities', label: '跟进任务' },
    { id: 'trade', label: '交易闭环' },
    { id: 'automation', label: '自动化引擎' },
    { id: 'analytics', label: '漏斗分析' },
    { id: 'bridge', label: '客户360' },
    { id: 'pipelines', label: '销售管道' },
    { id: 'org', label: '组织协作' },
    { id: 'customization', label: '自定义表单' },
    { id: 'reminders', label: '提醒中心' },
  ];
  const viewPermissions = {
    dashboard: 'crm.dashboard.view',
    leads: 'crm.leads.view',
    companies: 'crm.companies.view',
    contacts: 'crm.contacts.view',
    deals: 'crm.deals.view',
    activities: 'crm.activities.view',
    trade: 'crm.trade.view',
    automation: 'crm.automation.view',
    analytics: 'crm.analytics.view',
    bridge: 'crm.bridge.view',
    pipelines: 'crm.pipelines.view',
    org: 'crm.org.view',
    customization: 'crm.custom_fields.view',
    reminders: 'crm.reminders.view',
  };

  const el = {};
  const viewModules = {
    dashboard: null,
    leads: null,
    companies: null,
    contacts: null,
    deals: null,
    activities: null,
    trade: null,
    automation: null,
    analytics: null,
    bridge: null,
    pipelines: null,
    org: null,
    customization: null,
    reminders: null,
  };
  const viewModuleFiles = {
    dashboard: 'crm-dashboard-view.js',
    leads: 'crm-leads-view.js',
    companies: 'crm-companies-view.js',
    contacts: 'crm-contacts-view.js',
    deals: 'crm-deals-view.js',
    activities: 'crm-activities-view.js',
    trade: 'crm-trade-view.js',
    automation: 'crm-automation-view.js',
    analytics: 'crm-analytics-view.js',
    bridge: 'crm-bridge-view.js',
    pipelines: 'crm-pipelines-view.js',
    org: 'crm-org-view.js',
    customization: 'crm-customization-view.js',
    reminders: 'crm-reminders-view.js',
  };

  function $(id) {
    return document.getElementById(id);
  }

  function escapeHtml(input) {
    return String(input == null ? '' : input)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function asMoney(v) {
    const num = Number(v || 0);
    if (!Number.isFinite(num)) return '0.00';
    return num.toFixed(2);
  }

  function zhRole(role) {
    const map = {
      admin: '系统管理员',
      manager: '门店经理',
      consultant: '顾问',
      therapist: '护理师',
      reception: '前台',
    };
    return map[String(role || '').trim()] || String(role || '-');
  }

  function zhCrmValue(value) {
    const v = String(value || '').trim();
    if (v === '') return '-';
    const map = {
      active: '启用',
      inactive: '停用',
      enabled: '启用',
      disabled: '停用',
      new: '新建',
      contacted: '已联系',
      qualified: '已确认',
      disqualified: '无效',
      converted: '已转化',
      cold: '低意向',
      warm: '中意向',
      hot: '高意向',
      open: '进行中',
      won: '赢单',
      lost: '输单',
      todo: '待办',
      done: '已完成',
      cancelled: '已取消',
      unread: '未读',
      read: '已读',
      schedule: '日程提醒',
      due: '到期提醒',
      overdue: '逾期提醒',
      lead: '线索',
      contact: '联系人',
      company: '企业',
      deal: '商机',
      note: '笔记',
      call: '电话',
      email: '邮件',
      meeting: '会议',
      task: '任务',
      private: '私海',
      public_pool: '公海',
      all: '全部',
      round_robin: '轮询',
      random: '随机',
      fill_empty: '空值补齐',
      overwrite: '新值覆盖',
      member: '成员',
      leader: '负责人',
      enterprise: '企业',
      foreign_trade: '外贸',
      default: '默认',
      unassigned: '未分配',
      proposal: '提案',
      negotiation: '谈判',
      archived: '已归档',
      recycle: '回收站',
      draft: '草稿',
      sent: '已发送',
      accepted: '已接受',
      rejected: '已拒绝',
      expired: '已过期',
      completed: '已完成',
      pending: '待回款',
      partial: '部分回款',
      paid: '已回款',
      issued: '已开票',
      create_task: '创建任务',
      assign_owner: '分配负责人',
      create_reminder: '创建提醒',
      webhook: 'Webhook',
      manual: '手动',
      mobile: '手机号',
      name: '姓名',
      stage_key: '阶段',
      pipeline_key: '管道',
      deal_status: '商机状态',
    };
    return map[v] || v;
  }

  function zhCrmStatus(value) {
    return zhCrmValue(value);
  }

  function zhCrmIntent(value) {
    return zhCrmValue(value);
  }

  function zhCrmScope(value) {
    return zhCrmValue(value);
  }

  function zhCrmEntityType(value) {
    return zhCrmValue(value);
  }

  function zhCrmActivityType(value) {
    return zhCrmValue(value);
  }

  function zhCrmReminderType(value) {
    return zhCrmValue(value);
  }

  function zhCrmReminderStatus(value) {
    return zhCrmValue(value);
  }

  function zhCrmDealStatus(value) {
    return zhCrmValue(value);
  }

  function zhCrmMemberRole(value) {
    return zhCrmValue(value);
  }

  function zhCrmStage(value) {
    return zhCrmValue(value);
  }

  function zhCompanyType(value) {
    return zhCrmValue(value);
  }

  function toast(message, isErr) {
    const root = el.toastContainer;
    if (!root) return;
    const node = document.createElement('div');
    node.className = `toast${isErr ? ' err' : ''}`;
    node.textContent = String(message || '');
    root.appendChild(node);
    setTimeout(() => node.remove(), 2600);
  }

  function queryString(params) {
    const usp = new URLSearchParams();
    Object.keys(params || {}).forEach((k) => {
      const v = params[k];
      if (v === undefined || v === null || v === '') return;
      usp.append(k, String(v));
    });
    const raw = usp.toString();
    return raw ? `?${raw}` : '';
  }

  function uiState(viewId) {
    if (!state.ui[viewId]) {
      state.ui[viewId] = { cursor: 0, prev: [], filters: {} };
    }
    return state.ui[viewId];
  }

  function listQuery(viewId) {
    const ui = uiState(viewId);
    return {
      limit: 50,
      cursor: ui.cursor > 0 ? ui.cursor : '',
      ...(ui.filters || {}),
    };
  }

  function setFilters(viewId, filters) {
    const ui = uiState(viewId);
    ui.filters = filters || {};
    ui.cursor = 0;
    ui.prev = [];
    if (ui.selected && typeof ui.selected === 'object') {
      ui.selected = {};
    }
  }

  function readFormFilters(form) {
    const fd = new FormData(form);
    const out = {};
    Array.from(fd.entries()).forEach(([key, value]) => {
      const v = String(value || '').trim();
      if (v !== '') {
        out[key] = v;
      }
    });
    return out;
  }

  function optionSelected(current, expected) {
    return String(current || '') === String(expected || '') ? ' selected' : '';
  }

  function pagerHtml(viewId, pagination) {
    const ui = uiState(viewId);
    const hasPrev = Array.isArray(ui.prev) && ui.prev.length > 0;
    const nextCursor = Number((pagination && pagination.next_cursor) || 0);
    const hasNext = nextCursor > 0;
    return `
      <div class="pager">
        <small>第 ${ui.prev.length + 1} 页</small>
        <button type="button" class="btn btn-ghost" data-page-prev="${escapeHtml(viewId)}" ${hasPrev ? '' : 'disabled'}>上一页</button>
        <button type="button" class="btn btn-ghost" data-page-next="${escapeHtml(viewId)}" data-next-cursor="${nextCursor}" ${hasNext ? '' : 'disabled'}>下一页</button>
      </div>
    `;
  }

  function bindPager(viewId, pagination, rerender) {
    const ui = uiState(viewId);
    const prevBtn = el.viewRoot.querySelector(`[data-page-prev="${viewId}"]`);
    const nextBtn = el.viewRoot.querySelector(`[data-page-next="${viewId}"]`);

    if (prevBtn) {
      prevBtn.addEventListener('click', async () => {
        if (!Array.isArray(ui.prev) || ui.prev.length === 0) return;
        const prevCursor = Number(ui.prev.pop() || 0);
        ui.cursor = prevCursor > 0 ? prevCursor : 0;
        await rerender();
      });
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', async () => {
        const nextCursor = Number((pagination && pagination.next_cursor) || nextBtn.getAttribute('data-next-cursor') || 0);
        if (nextCursor <= 0) return;
        ui.prev.push(ui.cursor > 0 ? ui.cursor : 0);
        ui.cursor = nextCursor;
        await rerender();
      });
    }
  }

  function ensureSelectedMap(viewId) {
    const ui = uiState(viewId);
    if (!ui.selected || typeof ui.selected !== 'object') {
      ui.selected = {};
    }
    return ui.selected;
  }

  function setSelectedRow(viewId, row, checked) {
    const id = Number((row && row.id) || 0);
    if (!id) return;
    const map = ensureSelectedMap(viewId);
    if (checked) {
      map[String(id)] = { ...(row || {}), id };
    } else {
      delete map[String(id)];
    }
  }

  function isRowSelected(viewId, id) {
    const map = ensureSelectedMap(viewId);
    return Boolean(map[String(Number(id || 0))]);
  }

  function selectedRows(viewId) {
    const map = ensureSelectedMap(viewId);
    return Object.values(map);
  }

  function clearSelectedRows(viewId) {
    const ui = uiState(viewId);
    ui.selected = {};
  }

  function selectedCount(viewId) {
    return selectedRows(viewId).length;
  }

  function ensureDrawer() {
    if (el.drawerRoot) return el.drawerRoot;
    const node = document.createElement('div');
    node.id = 'crmDetailDrawer';
    node.className = 'drawer hidden';
    node.innerHTML = `
      <div class="drawer-backdrop" data-drawer-backdrop></div>
      <aside class="drawer-panel">
        <header class="drawer-header">
          <div>
            <h3 id="drawerTitle">详情</h3>
            <p id="drawerSubtitle" class="drawer-subtitle">-</p>
          </div>
          <button type="button" class="btn btn-ghost" id="drawerClose">关闭</button>
        </header>
        <section id="drawerBody" class="drawer-body"></section>
      </aside>
    `;
    document.body.appendChild(node);
    el.drawerRoot = node;
    el.drawerTitle = node.querySelector('#drawerTitle');
    el.drawerSubtitle = node.querySelector('#drawerSubtitle');
    el.drawerBody = node.querySelector('#drawerBody');
    const closeBtn = node.querySelector('#drawerClose');
    const backdrop = node.querySelector('[data-drawer-backdrop]');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => {
        closeDrawer();
      });
    }
    if (backdrop) {
      backdrop.addEventListener('click', () => {
        closeDrawer();
      });
    }
    return node;
  }

  function closeDrawer() {
    if (!el.drawerRoot) return;
    el.drawerRoot.classList.add('hidden');
  }

  function openDrawer(title, subtitle, html) {
    ensureDrawer();
    if (el.drawerTitle) el.drawerTitle.textContent = String(title || '详情');
    if (el.drawerSubtitle) el.drawerSubtitle.textContent = String(subtitle || '');
    if (el.drawerBody) el.drawerBody.innerHTML = String(html || '');
    if (el.drawerRoot) el.drawerRoot.classList.remove('hidden');
  }

  function fieldsHtml(items) {
    const rows = Array.isArray(items) ? items : [];
    if (!rows.length) return '<p class="empty">暂无详情字段</p>';
    return `
      <dl class="detail-grid">
        ${rows
          .map(
            (item) => `<div>
                <dt>${escapeHtml(item.label || '-')}</dt>
                <dd>${escapeHtml(item.value == null || item.value === '' ? '-' : item.value)}</dd>
              </div>`
          )
          .join('')}
      </dl>
    `;
  }

  async function openEntityDetail(entityType, row) {
    const id = Number((row && row.id) || 0);
    if (!id) return;

    const type = String(entityType || '').trim();
    const titleMap = {
      company: '企业详情',
      contact: '联系人详情',
      lead: '线索详情',
      deal: '商机详情',
    };

    let fields = [];
    if (type === 'company') {
      fields = [
        { label: '企业名称', value: row.company_name },
        { label: '类型', value: zhCompanyType(row.company_type) },
        { label: '地区', value: row.country_code },
        { label: '行业', value: row.industry },
        { label: '官网', value: row.website },
        { label: '来源', value: row.source_channel },
        { label: '负责人', value: row.owner_username },
        { label: '状态', value: zhCrmStatus(row.status) },
      ];
    } else if (type === 'contact') {
      fields = [
        { label: '联系人', value: row.contact_name },
        { label: '手机号', value: row.mobile },
        { label: '邮箱', value: row.email },
        { label: 'WhatsApp', value: row.whatsapp },
        { label: '企业', value: row.company_name || row.company_id },
        { label: '地区', value: row.country_code },
        { label: '语言', value: row.language_code },
        { label: '状态', value: zhCrmStatus(row.status) },
      ];
    } else if (type === 'lead') {
      fields = [
        { label: '线索', value: row.lead_name },
        { label: '手机号', value: row.mobile },
        { label: '邮箱', value: row.email },
        { label: '企业名称', value: row.company_name },
        { label: '意向', value: zhCrmIntent(row.intent_level) },
        { label: '状态', value: zhCrmStatus(row.status) },
        { label: '归属范围', value: zhCrmScope(row.visibility_scope || 'private') },
        { label: '下次跟进', value: row.next_followup_at },
        { label: '来源', value: row.source_channel },
      ];
    } else if (type === 'deal') {
      fields = [
        { label: '商机', value: row.deal_name },
        { label: '企业', value: row.company_name || row.company_id },
        { label: '联系人', value: row.contact_name || row.contact_id },
        { label: '管道/阶段', value: `${row.pipeline_key || '-'} / ${zhCrmStage(row.stage_key || '-')}` },
        { label: '状态', value: zhCrmDealStatus(row.deal_status) },
        { label: '金额', value: `${row.currency_code || 'CNY'} ${asMoney(row.amount || 0)}` },
        { label: '预计成交日', value: row.expected_close_date },
        { label: '来源', value: row.source_channel },
      ];
    }

    openDrawer(titleMap[type] || '详情', `${zhCrmEntityType(type)} #${id}`, '<p class="empty">加载中...</p>');

    let activities = [];
    let activityError = '';
    if (hasPermission('crm.activities.view')) {
      try {
        const payload = await request('GET', '/crm/activities', {
          query: {
            limit: 20,
            entity_type: type,
            entity_id: id,
          },
        });
        activities = Array.isArray(payload.data) ? payload.data : [];
      } catch (err) {
        activityError = err && err.message ? String(err.message) : '加载失败';
      }
    }

    const canCreateActivity = hasPermission('crm.activities.edit');
    const timelineHtml = activityError
      ? `<p class="empty">跟进记录加载失败：${escapeHtml(activityError)}</p>`
      : !hasPermission('crm.activities.view')
      ? '<p class="empty">当前账号无跟进记录查看权限</p>'
      : activities.length
      ? `<ul class="timeline">
          ${activities
            .map(
              (a) => `<li>
                  <p><b>${escapeHtml(zhCrmActivityType(a.activity_type || '-'))}</b> · ${escapeHtml(zhCrmStatus(a.status || '-'))}</p>
                  <p>${escapeHtml(a.subject || '-')}</p>
                  <p class="muted">${escapeHtml(a.due_at || a.created_at || '-')}</p>
                </li>`
            )
            .join('')}
        </ul>`
      : '<p class="empty">暂无跟进记录</p>';

    const html = `
      <section class="card">
        <h3>基础信息</h3>
        ${fieldsHtml(fields)}
      </section>
      <section class="card" style="margin-top:12px;">
        <h3>跟进记录</h3>
        ${timelineHtml}
      </section>
      ${
        canCreateActivity
          ? `<section class="card" style="margin-top:12px;">
              <h3>新增跟进</h3>
              <form id="drawerActivityForm" class="grid-2">
                <label><span>类型</span>
                  <select name="activity_type">
                    <option value="note">笔记</option>
                    <option value="call">电话</option>
                    <option value="email">邮件</option>
                    <option value="meeting">会议</option>
                    <option value="task">任务</option>
                  </select>
                </label>
                <label><span>状态</span>
                  <select name="status">
                    <option value="todo">待办</option>
                    <option value="done">已完成</option>
                    <option value="cancelled">已取消</option>
                  </select>
                </label>
                <label><span>标题</span><input name="subject" /></label>
                <label><span>截止时间</span><input name="due_at" placeholder="2026-03-10 18:00:00" /></label>
                <label style="grid-column:1 / -1;"><span>内容</span><textarea name="content"></textarea></label>
                <div><button class="btn btn-primary" type="submit">保存跟进</button></div>
              </form>
            </section>`
          : ''
      }
    `;

    openDrawer(titleMap[type] || '详情', `${zhCrmEntityType(type)} #${id}`, html);

    const form = document.getElementById('drawerActivityForm');
    if (form) {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(form);
        const body = Object.fromEntries(fd.entries());
        body.entity_type = type;
        body.entity_id = id;
        try {
          await request('POST', '/crm/activities', { body });
          toast('跟进已创建');
          await openEntityDetail(type, row);
        } catch (err) {
          toast(err.message || '创建失败', true);
        }
      });
    }
  }

  async function request(method, path, { query = null, body = null, auth = true } = {}) {
    let url = `${API_PREFIX}${path}`;
    if (query && typeof query === 'object') {
      url += queryString(query);
    }
    const headers = { 'Content-Type': 'application/json' };
    if (auth && state.token) {
      headers.Authorization = `Bearer ${state.token}`;
    }
    const res = await fetch(url, {
      method,
      headers,
      body: body == null ? null : JSON.stringify(body),
    });
    const text = await res.text();
    let payload = {};
    try {
      payload = text ? JSON.parse(text) : {};
    } catch (e) {
      payload = {};
    }
    if (!res.ok) {
      const msg = payload && payload.message ? payload.message : `HTTP ${res.status}`;
      throw new Error(msg);
    }
    return payload || {};
  }

  async function requestDownload(path, { query = null, filenamePrefix = 'export' } = {}) {
    let url = `${API_PREFIX}${path}`;
    if (query && typeof query === 'object') {
      url += queryString(query);
    }

    const headers = {};
    if (state.token) {
      headers.Authorization = `Bearer ${state.token}`;
    }

    const res = await fetch(url, {
      method: 'GET',
      headers,
    });

    if (!res.ok) {
      const text = await res.text();
      let message = `HTTP ${res.status}`;
      if (text) {
        try {
          const payload = JSON.parse(text);
          if (payload && payload.message) {
            message = String(payload.message);
          } else {
            message = text;
          }
        } catch (_err) {
          message = text;
        }
      }
      throw new Error(message);
    }

    const blob = await res.blob();
    const disposition = String(res.headers.get('Content-Disposition') || '');
    const match = disposition.match(/filename="?([^";]+)"?/i);
    const rawFilename = match ? match[1] : '';
    const safeFallback = `${filenamePrefix}-${new Date().toISOString().slice(0, 10)}.csv`;
    const filename = rawFilename ? rawFilename : safeFallback;

    const objectUrl = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(objectUrl);
  }

  function normalizeEntityType(entityType) {
    const value = String(entityType || '').trim().toLowerCase();
    if (['company', 'contact', 'lead', 'deal'].includes(value)) {
      return value;
    }
    return '';
  }

  async function loadCustomFields(entityType, { activeOnly = true, force = false } = {}) {
    const type = normalizeEntityType(entityType);
    if (!type) return [];
    const cacheKey = `${type}#${activeOnly ? 1 : 0}`;
    if (!force && Array.isArray(state.meta.customFieldCache[cacheKey])) {
      return state.meta.customFieldCache[cacheKey];
    }
    const payload = await request('GET', '/crm/custom-fields', {
      query: {
        entity_type: type,
        active_only: activeOnly ? 1 : 0,
      },
    });
    const rows = Array.isArray(payload.data) ? payload.data : [];
    state.meta.customFieldCache[cacheKey] = rows;
    return rows;
  }

  async function loadFormConfig(entityType, { force = false } = {}) {
    const type = normalizeEntityType(entityType);
    if (!type) return null;
    if (!force && state.meta.formConfigCache[type]) {
      return state.meta.formConfigCache[type];
    }
    const payload = await request('GET', '/crm/form-config', {
      query: { entity_type: type },
    });
    const config = payload && payload.config ? payload.config : null;
    state.meta.formConfigCache[type] = config;
    return config;
  }

  function sortCustomFields(defs, layout) {
    const rows = Array.isArray(defs) ? defs.slice() : [];
    const orderMap = {};
    if (Array.isArray(layout)) {
      layout.forEach((item, idx) => {
        const key = String(item || '').trim();
        if (key) orderMap[key] = idx + 1;
      });
    }
    rows.sort((a, b) => {
      const ak = String((a && a.field_key) || '');
      const bk = String((b && b.field_key) || '');
      const ao = orderMap[ak] || 9999;
      const bo = orderMap[bk] || 9999;
      if (ao !== bo) return ao - bo;
      return Number((a && a.sort_order) || 0) - Number((b && b.sort_order) || 0);
    });
    return rows;
  }

  function customFieldInputsHtml(defs, values = {}) {
    const rows = Array.isArray(defs) ? defs : [];
    if (!rows.length) return '';

    return `<div class="grid-3">` +
      rows
        .map((field) => {
          const key = String((field && field.field_key) || '').trim();
          if (!key) return '';
          const label = String((field && field.field_label) || key);
          const type = String((field && field.field_type) || 'text').toLowerCase();
          const required = Number((field && field.is_required) || 0) === 1;
          const placeholder = String((field && field.placeholder) || '');
          const defaultValue = field && Object.prototype.hasOwnProperty.call(values, key)
            ? values[key]
            : field.default_value;
          const value = defaultValue == null ? '' : String(defaultValue);
          const attr = `data-custom-field="${escapeHtml(key)}"`;
          const req = required ? ' required' : '';

          if (type === 'textarea') {
            return `<label style="grid-column:1 / -1;"><span>${escapeHtml(label)}${required ? ' *' : ''}</span><textarea ${attr}${req} placeholder="${escapeHtml(
              placeholder
            )}">${escapeHtml(value)}</textarea></label>`;
          }
          if (type === 'select') {
            const options = Array.isArray(field.options) ? field.options : [];
            const optionHtml = ['<option value="">请选择</option>']
              .concat(
                options.map((opt) => {
                  const ov = String(opt == null ? '' : opt);
                  return `<option value="${escapeHtml(ov)}"${optionSelected(value, ov)}>${escapeHtml(ov)}</option>`;
                })
              )
              .join('');
            return `<label><span>${escapeHtml(label)}${required ? ' *' : ''}</span><select ${attr}${req}>${optionHtml}</select></label>`;
          }
          if (type === 'checkbox') {
            const checked = ['1', 'true', 'yes', 'on'].includes(String(value).toLowerCase()) ? ' checked' : '';
            return `<label><span>${escapeHtml(label)}</span><input type="checkbox" ${attr}${checked} /></label>`;
          }

          const htmlTypeMap = {
            number: 'number',
            email: 'email',
            url: 'url',
            date: 'date',
            datetime: 'datetime-local',
            phone: 'text',
          };
          const htmlType = htmlTypeMap[type] || 'text';
          return `<label><span>${escapeHtml(label)}${required ? ' *' : ''}</span><input type="${htmlType}" ${attr}${req} value="${escapeHtml(
            value
          )}" placeholder="${escapeHtml(placeholder)}" /></label>`;
        })
        .join('') +
      `</div>`;
  }

  function collectCustomFieldValues(form, defs) {
    if (!form || !Array.isArray(defs) || !defs.length) return {};
    const payload = {};
    const esc = (value) => {
      if (window.CSS && typeof window.CSS.escape === 'function') {
        return window.CSS.escape(value);
      }
      return String(value).replace(/["\\]/g, '\\$&');
    };
    defs.forEach((field) => {
      const key = String((field && field.field_key) || '').trim();
      if (!key) return;
      const type = String((field && field.field_type) || 'text').toLowerCase();
      const el = form.querySelector(`[data-custom-field="${esc(key)}"]`);
      if (!el) return;

      let value = '';
      if (type === 'checkbox') {
        value = el.checked ? 1 : 0;
      } else {
        value = String(el.value || '').trim();
      }
      if (value === '') return;
      payload[key] = value;
    });
    return payload;
  }

  function setScreen(authed) {
    el.loginScreen.classList.toggle('hidden', authed);
    el.appScreen.classList.toggle('hidden', !authed);
  }

  function userPermissions() {
    const items = state.user && Array.isArray(state.user.permissions) ? state.user.permissions : [];
    return items
      .map((item) => String(item || '').trim())
      .filter((item) => item !== '');
  }

  function hasPermission(permission) {
    const code = String(permission || '').trim();
    if (!code) return true;
    const role = String((state.user && state.user.role_key) || '').trim();
    if (role === 'admin') return true;

    const perms = userPermissions();
    if (perms.length === 0) return false;
    if (perms.includes('*') || perms.includes(code)) return true;

    const parts = code.split('.');
    while (parts.length > 1) {
      parts.pop();
      if (perms.includes(`${parts.join('.')}.*`)) {
        return true;
      }
    }

    const module = code.split('.', 1)[0] || '';
    if (module && perms.includes(module)) {
      return true;
    }
    return false;
  }

  function allowedViews() {
    return views.filter((v) => hasPermission(viewPermissions[v.id] || ''));
  }

  function ensureActiveView() {
    const allowed = allowedViews();
    if (!allowed.length) {
      state.view = '';
      return allowed;
    }

    if (!allowed.some((v) => v.id === state.view)) {
      state.view = allowed[0].id;
    }
    return allowed;
  }

  function navHtml() {
    const allowed = ensureActiveView();
    return allowed
      .map(
        (v) =>
          `<button class="nav-btn${state.view === v.id ? ' active' : ''}" data-view="${escapeHtml(v.id)}">${escapeHtml(v.label)}</button>`
      )
      .join('');
  }

  function bindNav() {
    el.navList.innerHTML = navHtml();
    if (!String(el.navList.innerHTML || '').trim()) {
      el.navList.innerHTML = '<p class="empty">暂无可访问模块</p>';
      return;
    }
    el.navList.querySelectorAll('[data-view]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const view = String(btn.getAttribute('data-view') || '');
        if (!view || view === state.view) return;
        state.view = view;
        await renderView();
      });
    });
  }

  function setUserMeta() {
    if (!state.user) return;
    el.userName.textContent = state.user.username || '-';
    el.userMeta.textContent = `${zhRole(state.user.role_key)} · 用户#${state.user.id || 0}`;
  }

  async function restoreSession() {
    if (!state.token) return false;
    try {
      const payload = await request('GET', '/auth/me');
      state.user = payload.user || null;
      if (!state.user) return false;
      return true;
    } catch (err) {
      return false;
    }
  }

  function signOut() {
    state.token = '';
    state.user = null;
    localStorage.removeItem(TOKEN_KEY);
    closeDrawer();
    setScreen(false);
  }

  function screenTitle(title, subtitle) {
    el.viewTitle.textContent = title;
    el.viewSubtitle.textContent = subtitle;
  }

  function rowActions(buttons) {
    return `<div class="row-actions">${buttons.join('')}</div>`;
  }

  async function ensureViewModule(viewId) {
    const key = String(viewId || '').trim();
    const moduleFile = viewModuleFiles[key];
    if (!moduleFile) {
      return null;
    }
    if (viewModules[key]) {
      return viewModules[key];
    }
    const mod = await import(`${CRM_ADMIN_ASSET_BASE}/modules/${moduleFile}`);
    viewModules[key] = mod || null;
    return viewModules[key];
  }

  function viewRuntimeContext() {
    return {
      screenTitle,
      uiState,
      request,
      listQuery,
      hasPermission,
      selectedCount,
      el,
      escapeHtml,
      optionSelected,
      rowActions,
      isRowSelected,
      selectedRows,
      setSelectedRow,
      ensureSelectedMap,
      clearSelectedRows,
      $,
      setFilters,
      readFormFilters,
      requestDownload,
      toast,
      openEntityDetail,
      bindPager,
      pagerHtml,
      asMoney,
      zhCrmValue,
      zhCrmStatus,
      zhCrmIntent,
      zhCrmScope,
      zhCrmEntityType,
      zhCrmActivityType,
      zhCrmReminderType,
      zhCrmReminderStatus,
      zhCrmDealStatus,
      zhCrmMemberRole,
      zhCrmStage,
      zhCompanyType,
      loadCustomFields,
      loadFormConfig,
      sortCustomFields,
      customFieldInputsHtml,
      collectCustomFieldValues,
    };
  }

  async function renderModuleView(viewId, renderFnName, setContextFnName) {
    const mod = await ensureViewModule(viewId);
    if (!mod || typeof mod[renderFnName] !== 'function') {
      throw new Error(`load ${viewId} module failed`);
    }
    if (setContextFnName && typeof mod[setContextFnName] === 'function') {
      mod[setContextFnName](viewRuntimeContext());
    }
    await mod[renderFnName]();
  }
  async function renderDashboard() {
    await renderModuleView('dashboard', 'renderDashboard', 'setRenderDashboardContext');
  }

  async function renderCompanies() {
    await renderModuleView('companies', 'renderCompanies', 'setRenderCompaniesContext');
  }

  async function renderContacts() {
    await renderModuleView('contacts', 'renderContacts', 'setRenderContactsContext');
  }

  async function renderLeads() {
    await renderModuleView('leads', 'renderLeads', 'setRenderLeadsContext');
  }

  async function renderDeals() {
    await renderModuleView('deals', 'renderDeals', 'setRenderDealsContext');
  }

  async function renderActivities() {
    await renderModuleView('activities', 'renderActivities', 'setRenderActivitiesContext');
  }

  async function renderTrade() {
    await renderModuleView('trade', 'renderTrade', 'setRenderTradeContext');
  }

  async function renderAutomation() {
    await renderModuleView('automation', 'renderAutomation', 'setRenderAutomationContext');
  }

  async function renderAnalytics() {
    await renderModuleView('analytics', 'renderAnalytics', 'setRenderAnalyticsContext');
  }

  async function renderBridge() {
    await renderModuleView('bridge', 'renderBridge', 'setRenderBridgeContext');
  }

  async function renderPipelines() {
    await renderModuleView('pipelines', 'renderPipelines', 'setRenderPipelinesContext');
  }

  async function renderOrg() {
    await renderModuleView('org', 'renderOrg', 'setRenderOrgContext');
  }

  async function renderCustomization() {
    await renderModuleView('customization', 'renderCustomization', 'setRenderCustomizationContext');
  }

  async function renderReminders() {
    await renderModuleView('reminders', 'renderReminders', 'setRenderRemindersContext');
  }

  async function renderView() {
    const allowed = ensureActiveView();
    bindNav();
    closeDrawer();
    if (!allowed.length) {
      screenTitle('CRM 控制台', '当前角色无可访问模块');
      el.viewRoot.innerHTML = '<section class="card"><h3>无权限</h3><p class="empty">请联系管理员分配 CRM 权限。</p></section>';
      return;
    }

    try {
      if (state.view === 'dashboard') {
        await renderDashboard();
        return;
      }
      if (state.view === 'companies') {
        await renderCompanies();
        return;
      }
      if (state.view === 'contacts') {
        await renderContacts();
        return;
      }
      if (state.view === 'leads') {
        await renderLeads();
        return;
      }
      if (state.view === 'deals') {
        await renderDeals();
        return;
      }
      if (state.view === 'activities') {
        await renderActivities();
        return;
      }
      if (state.view === 'trade') {
        await renderTrade();
        return;
      }
      if (state.view === 'automation') {
        await renderAutomation();
        return;
      }
      if (state.view === 'analytics') {
        await renderAnalytics();
        return;
      }
      if (state.view === 'bridge') {
        await renderBridge();
        return;
      }
      if (state.view === 'pipelines') {
        await renderPipelines();
        return;
      }
      if (state.view === 'org') {
        await renderOrg();
        return;
      }
      if (state.view === 'customization') {
        await renderCustomization();
        return;
      }
      if (state.view === 'reminders') {
        await renderReminders();
        return;
      }
      await renderDashboard();
    } catch (err) {
      const msg = err && err.message ? err.message : '加载失败';
      if (/forbidden|unauthorized/i.test(String(msg))) {
        el.viewRoot.innerHTML = `<section class="card"><h3>无权限</h3><p class="empty">${escapeHtml(msg)}</p></section>`;
      } else {
        el.viewRoot.innerHTML = `<section class="card"><h3>加载失败</h3><p class="empty">${escapeHtml(msg)}</p></section>`;
      }
    }
  }

  function bindEvents() {
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        closeDrawer();
      }
    });

    el.logoutBtn.addEventListener('click', () => {
      signOut();
      toast('已退出 CRM');
    });

    el.loginForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const username = String(el.loginUsername.value || '').trim();
      const password = String(el.loginPassword.value || '').trim();
      if (!username || !password) {
        toast('请输入账号和密码', true);
        return;
      }

      try {
        const payload = await request('POST', '/auth/login', {
          auth: false,
          body: { username, password },
        });
        const token = String(payload.token || '').trim();
        if (!token) {
          throw new Error('登录响应缺少 token');
        }
        state.token = token;
        localStorage.setItem(TOKEN_KEY, token);
        const loginUser = payload && payload.user && typeof payload.user === 'object' ? payload.user : null;
        if (loginUser && Array.isArray(loginUser.permissions)) {
          state.user = loginUser;
        } else {
          const me = await request('GET', '/auth/me');
          state.user = me.user || null;
        }
        if (!state.user) {
          throw new Error('用户信息获取失败');
        }
        setUserMeta();
        setScreen(true);
        await renderView();
      } catch (err) {
        toast(err.message || '登录失败', true);
      }
    });

    if (el.forgotPwdBtn) {
      el.forgotPwdBtn.addEventListener('click', async () => {
        const resetMsg = (raw) => {
          const key = String(raw || '').trim();
          const map = {
            'If account info matches, a verification code has been sent': '如果账号和邮箱匹配，验证码已发送，请查收邮箱',
            'password reset is disabled': '当前未启用邮箱找回，请联系管理员处理',
            'account, email, code, new_password are required': '请填写账号、邮箱、验证码和新密码',
            'new_password must be at least 8 chars': '新密码至少 8 位',
            'invalid reset code': '验证码无效或已过期，请重新获取',
            'password reset success': '密码重置成功，请使用新密码登录',
          };
          return map[key] || key || '找回失败';
        };

        const account = window.prompt('请输入账号（用户名或邮箱）', String(el.loginUsername.value || '').trim());
        if (!account) return;
        const email = window.prompt('请输入该账号绑定邮箱');
        if (!email) return;

        try {
          await request('POST', '/auth/password-reset/request', {
            auth: false,
            body: {
              account: String(account).trim(),
              email: String(email).trim(),
            },
          });
          toast('如果账号和邮箱匹配，验证码已发送，请查收邮箱');

          const code = window.prompt('请输入收到的6位验证码');
          if (!code) return;
          const newPassword = window.prompt('请输入新密码（至少8位）');
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
          toast('密码重置成功，请使用新密码登录');
        } catch (err) {
          toast(resetMsg(err && err.message ? err.message : ''), true);
        }
      });
    }
  }

  async function init() {
    el.toastContainer = $('toastContainer');
    el.loginScreen = $('loginScreen');
    el.appScreen = $('appScreen');
    el.loginForm = $('loginForm');
    el.loginUsername = $('loginUsername');
    el.loginPassword = $('loginPassword');
    el.forgotPwdBtn = $('forgotPwdBtn');
    el.navList = $('navList');
    el.userName = $('userName');
    el.userMeta = $('userMeta');
    el.logoutBtn = $('logoutBtn');
    el.viewTitle = $('viewTitle');
    el.viewSubtitle = $('viewSubtitle');
    el.viewRoot = $('viewRoot');

    bindEvents();

    const ok = await restoreSession();
    if (!ok) {
      signOut();
      return;
    }

    setUserMeta();
    setScreen(true);
    await renderView();
  }

  init().catch(() => {
    signOut();
  });
})();
