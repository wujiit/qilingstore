window.__QILING_ADMIN_VIEW_FACTORIES__ = window.__QILING_ADMIN_VIEW_FACTORIES__ || {};
window.__QILING_ADMIN_VIEW_FACTORIES__['dashboard'] = function (shared) {
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

    // If user has switched away while requests were in flight, do not overwrite the current view.
    if (state.activeView !== 'dashboard') return;

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


  return renderDashboard;
};
