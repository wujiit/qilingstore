window.__QILING_ADMIN_VIEW_FACTORIES__ = window.__QILING_ADMIN_VIEW_FACTORIES__ || {};
window.__QILING_ADMIN_VIEW_FACTORIES__['commission'] = function (shared) {
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


  return renderCommission;
};
