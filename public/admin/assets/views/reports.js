window.__QILING_ADMIN_VIEW_FACTORIES__ = window.__QILING_ADMIN_VIEW_FACTORIES__ || {};
window.__QILING_ADMIN_VIEW_FACTORIES__['reports'] = function (shared) {
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
  async function renderReports() {
    const tabKey = 'reports';
    const tabFallback = 'overview';
    const availableTabs = new Set([
      'overview',
      'cockpit',
      'trend',
      'channels',
      'services',
      'payments',
      'store_daily',
      'repurchase',
      'performance',
    ]);
    if (!availableTabs.has(String((state.subTabs && state.subTabs[tabKey]) || '').trim())) {
      state.subTabs[tabKey] = tabFallback;
    }
    const defaultDateFrom = dateInputValue(-29);
    const defaultDateTo = dateInputValue(0);
    const tabHeader = renderSubTabNav(tabKey, [
      { id: 'overview', title: '运营总览', subtitle: '营收、订单、客户、预约、复购全局概览' },
      { id: 'cockpit', title: '经营驾驶舱', subtitle: '门店/员工/项目利润贡献 + 复购周期 + 渠道 ROI 联动' },
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

    const renderCockpit = (res) => {
      const s = (res && res.summary) ? res.summary : {};
      const storeProfit = (res && res.store_profit && Array.isArray(res.store_profit.data)) ? res.store_profit.data : [];
      const staffProfit = (res && res.staff_profit && Array.isArray(res.staff_profit.data)) ? res.staff_profit.data : [];
      const serviceProfit = (res && res.service_profit && Array.isArray(res.service_profit.data)) ? res.service_profit.data : [];
      const channelRoi = (res && res.channel_roi && Array.isArray(res.channel_roi.data)) ? res.channel_roi.data : [];
      const repurchase = (res && res.repurchase_cycle) ? res.repurchase_cycle : {};
      const repOverall = (repurchase && repurchase.overall) ? repurchase.overall : {};
      const repStore = (repurchase && Array.isArray(repurchase.by_store)) ? repurchase.by_store : [];
      const repStaff = (repurchase && Array.isArray(repurchase.by_staff)) ? repurchase.by_staff : [];
      const repService = (repurchase && Array.isArray(repurchase.by_service)) ? repurchase.by_service : [];
      const formatDays = (v) => toFloat(v, 0).toFixed(2);

      return `
        ${renderKpi([
          { label: '销售金额', value: `¥${formatMoney(s.sales_amount)}` },
          { label: '提成成本', value: `¥${formatMoney(s.commission_amount)}` },
          { label: '耗材成本', value: `¥${formatMoney(s.material_cost)}` },
          { label: '毛利润', value: `¥${formatMoney(s.gross_profit)}` },
          { label: '毛利率', value: formatPercent(s.gross_margin_rate) },
          { label: '渠道投放成本', value: `¥${formatMoney(s.channel_cost_amount)}` },
          { label: '扣投放后利润', value: `¥${formatMoney(s.channel_profit_after_acq)}` },
          { label: '平均复购周期(天)', value: formatDays(s.avg_repurchase_cycle_days) },
        ])}

        <h4>门店利润贡献（Top）</h4>
        ${table([
          { label: '门店', get: (r) => r.store_label || `${r.store_name || '-'} (#${r.store_id || 0})` },
          { label: '订单数', get: (r) => formatNumber(r.order_count) },
          { label: '客户数', get: (r) => formatNumber(r.customer_count) },
          { label: '销售额', get: (r) => `¥${formatMoney(r.sales_amount)}` },
          { label: '提成', get: (r) => `¥${formatMoney(r.commission_amount)}` },
          { label: '耗材成本', get: (r) => `¥${formatMoney(r.material_cost)}` },
          { label: '毛利润', get: (r) => `¥${formatMoney(r.gross_profit)}` },
          { label: '毛利率', get: (r) => formatPercent(r.gross_margin_rate) },
          { label: '利润贡献', get: (r) => formatPercent(r.contribution_rate) },
        ], storeProfit, { maxRows: 50, emptyText: '暂无门店利润数据' })}

        <h4>员工业绩利润贡献（Top）</h4>
        ${table([
          { label: '员工', get: (r) => r.staff_label || `${r.staff_username || '-'} (${r.staff_no || '-'})` },
          { label: '项目条目', get: (r) => formatNumber(r.item_count) },
          { label: '订单数', get: (r) => formatNumber(r.order_count) },
          { label: '销售额', get: (r) => `¥${formatMoney(r.sales_amount)}` },
          { label: '提成', get: (r) => `¥${formatMoney(r.commission_amount)}` },
          { label: '分摊耗材成本', get: (r) => `¥${formatMoney(r.material_cost)}` },
          { label: '毛利润', get: (r) => `¥${formatMoney(r.gross_profit)}` },
          { label: '毛利率', get: (r) => formatPercent(r.gross_margin_rate) },
          { label: '利润贡献', get: (r) => formatPercent(r.contribution_rate) },
        ], staffProfit, { maxRows: 50, emptyText: '暂无员工业绩利润数据' })}

        <h4>项目利润贡献（Top）</h4>
        ${table([
          { label: '项目', get: (r) => `${r.item_name || '-'}（${r.item_type || '-'}）` },
          { label: '项目ID', get: (r) => formatNumber(r.item_ref_id) },
          { label: '销量', get: (r) => formatNumber(r.total_qty) },
          { label: '订单数', get: (r) => formatNumber(r.order_count) },
          { label: '销售额', get: (r) => `¥${formatMoney(r.sales_amount)}` },
          { label: '提成', get: (r) => `¥${formatMoney(r.commission_amount)}` },
          { label: '分摊耗材成本', get: (r) => `¥${formatMoney(r.material_cost)}` },
          { label: '毛利润', get: (r) => `¥${formatMoney(r.gross_profit)}` },
          { label: '毛利率', get: (r) => formatPercent(r.gross_margin_rate) },
          { label: '利润贡献', get: (r) => formatPercent(r.contribution_rate) },
        ], serviceProfit, { maxRows: 50, emptyText: '暂无项目利润数据' })}

        <h4>渠道 ROI（Top）</h4>
        ${table([
          { label: '渠道', key: 'source_channel' },
          { label: '新增客户', get: (r) => formatNumber(r.new_customers) },
          { label: '成交客户', get: (r) => formatNumber(r.paid_customers) },
          { label: '支付订单', get: (r) => formatNumber(r.paid_orders) },
          { label: '净收入', get: (r) => `¥${formatMoney(r.net_amount)}` },
          { label: '投放成本', get: (r) => `¥${formatMoney(r.cost_amount)}` },
          { label: '扣投放后利润', get: (r) => `¥${formatMoney(r.profit_after_acq)}` },
          { label: 'ROI', get: (r) => formatPercent(r.roi_percent) },
          { label: 'CAC', get: (r) => `¥${formatMoney(r.cac_amount)}` },
        ], channelRoi, { maxRows: 50, emptyText: '暂无渠道 ROI 数据' })}

        <h4>复购周期总览</h4>
        ${table([
          { label: '复购触发订单', get: (r) => formatNumber(r.repeat_orders) },
          { label: '平均周期(天)', get: (r) => formatDays(r.avg_cycle_days) },
          { label: 'P50(天)', get: (r) => formatDays(r.p50_cycle_days) },
          { label: 'P90(天)', get: (r) => formatDays(r.p90_cycle_days) },
          { label: '最短(天)', get: (r) => formatDays(r.min_cycle_days) },
          { label: '最长(天)', get: (r) => formatDays(r.max_cycle_days) },
        ], [repOverall], { maxRows: 1, emptyText: '暂无复购周期数据' })}

        <h4>复购周期 - 门店（Top）</h4>
        ${table([
          { label: '门店', key: 'store_label' },
          { label: '复购触发订单', get: (r) => formatNumber(r.repeat_orders) },
          { label: '平均周期(天)', get: (r) => formatDays(r.avg_cycle_days) },
          { label: 'P50(天)', get: (r) => formatDays(r.p50_cycle_days) },
          { label: 'P90(天)', get: (r) => formatDays(r.p90_cycle_days) },
        ], repStore, { maxRows: 50, emptyText: '暂无门店复购周期数据' })}

        <h4>复购周期 - 员工（Top）</h4>
        ${table([
          { label: '员工', key: 'staff_label' },
          { label: '复购触发订单', get: (r) => formatNumber(r.repeat_orders) },
          { label: '平均周期(天)', get: (r) => formatDays(r.avg_cycle_days) },
          { label: 'P50(天)', get: (r) => formatDays(r.p50_cycle_days) },
          { label: 'P90(天)', get: (r) => formatDays(r.p90_cycle_days) },
        ], repStaff, { maxRows: 50, emptyText: '暂无员工复购周期数据' })}

        <h4>复购周期 - 项目（Top）</h4>
        ${table([
          { label: '项目', key: 'item_label' },
          { label: '复购触发订单', get: (r) => formatNumber(r.repeat_orders) },
          { label: '平均周期(天)', get: (r) => formatDays(r.avg_cycle_days) },
          { label: 'P50(天)', get: (r) => formatDays(r.p50_cycle_days) },
          { label: 'P90(天)', get: (r) => formatDays(r.p90_cycle_days) },
        ], repService, { maxRows: 50, emptyText: '暂无项目复购周期数据' })}
      `;
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
      <section class="row${subTabClass(tabKey, 'cockpit', tabFallback)}">
        <article class="card">
          <h3>经营驾驶舱筛选</h3>
          <form id="formCockpit" class="form-grid">
            <input name="store_id" placeholder="门店ID(可空=全部可见门店；录入成本时请填写)" />
            <input type="date" name="date_from" value="${defaultDateFrom}" />
            <input type="date" name="date_to" value="${defaultDateTo}" />
            <input name="top_n" placeholder="Top条数(5-100)" value="20" />
            <textarea name="channel_cost_lines" rows="4" placeholder="可选：批量录入渠道成本，格式：日期,渠道,成本,备注&#10;例如：${defaultDateTo},抖音,1200,信息流投放"></textarea>
            <button class="btn btn-primary" type="submit">查询驾驶舱（并可同步成本）</button>
          </form>
          <p class="small-note">渠道成本按“日期+门店+渠道”写入，门店经理默认写入本人门店；管理员需填写门店ID。</p>
        </article>
      </section>
      <section class="card${subTabClass(tabKey, 'cockpit', tabFallback)}"><h3>经营驾驶舱结果</h3><div id="reportCockpitResult">${renderEmpty('请先查询经营驾驶舱')}</div></section>

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

    const cockpitForm = document.getElementById('formCockpit');
    if (cockpitForm) {
      cockpitForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const box = document.getElementById('reportCockpitResult');
        if (box) box.innerHTML = '<div class="loading">查询中...</div>';
        try {
          const v = getFormValues(cockpitForm);
          const storeId = toInt(v.store_id, 0);
          const topN = toInt(v.top_n, 20);
          const costRows = parseCsvLines(v.channel_cost_lines || '');

          if (costRows.length > 0) {
            const items = costRows
              .map((row) => ({
                report_date: String((row && row[0]) || '').trim(),
                source_channel: String((row && row[1]) || '').trim(),
                cost_amount: toFloat((row && row[2]) || 0, 0),
                note: String((row && row[3]) || '').trim(),
                store_id: storeId,
              }))
              .filter((r) => r.report_date && r.source_channel);
            if (items.length === 0) {
              throw new Error('渠道成本格式不正确，请按“日期,渠道,成本,备注”填写');
            }
            await request('POST', '/reports/channel-costs', {
              body: { items },
            });
          }

          const res = await request('GET', '/reports/cockpit', {
            query: {
              store_id: storeId,
              date_from: v.date_from,
              date_to: v.date_to,
              top_n: topN,
            },
          });

          if (box) box.innerHTML = renderCockpit(res);
          toast(costRows.length > 0 ? '成本已保存并刷新驾驶舱' : '查询完成', 'ok');
        } catch (err) {
          if (box) box.innerHTML = `<div class="empty">${escapeHtml(err.message || '查询失败')}</div>`;
          toast(err.message || '查询失败', 'error');
        }
      });
    }

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
      cockpit: 'formCockpit',
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


  return renderReports;
};
