window.__QILING_ADMIN_VIEW_FACTORIES__ = window.__QILING_ADMIN_VIEW_FACTORIES__ || {};
window.__QILING_ADMIN_VIEW_FACTORIES__['master'] = function (shared) {
  const {
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
  } = shared;
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
            <p class="small-note">建档会自动生成 16-64 位安全前台口令（字母/数字/_/-），方便门店和客户快速登录用户端。</p>
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


  return renderMaster;
};
