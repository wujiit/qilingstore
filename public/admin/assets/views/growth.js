window.__QILING_ADMIN_VIEW_FACTORIES__ = window.__QILING_ADMIN_VIEW_FACTORIES__ || {};
window.__QILING_ADMIN_VIEW_FACTORIES__['growth'] = function (shared) {
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


  return renderGrowth;
};
