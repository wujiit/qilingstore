window.__QILING_ADMIN_VIEW_FACTORIES__ = window.__QILING_ADMIN_VIEW_FACTORIES__ || {};
window.__QILING_ADMIN_VIEW_FACTORIES__['finance'] = function (shared) {
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
  async function renderFinance() {
    const defaultStoreId = toInt(state.user && state.user.staff_store_id, 0);
    const defaultSettlementDate = dateInputValue(0);
    const defaultDateFrom = dateInputValue(-29);
    const defaultDateTo = dateInputValue(0);
    const inventoryDashboardQuery = {};
    const settlementOverviewQuery = { date: defaultSettlementDate };
    if (defaultStoreId > 0) {
      inventoryDashboardQuery.store_id = defaultStoreId;
      settlementOverviewQuery.store_id = defaultStoreId;
    }

    const [printersRes, printJobsRes, paymentConfigRes, settlementOverviewRes, inventoryDashboardRes] = await Promise.all([
      request('GET', '/printers'),
      request('GET', '/print-jobs', { query: { limit: 120 } }),
      request('GET', '/payments/config'),
      request('GET', '/finance/reconciliation/overview', { query: settlementOverviewQuery }),
      request('GET', '/inventory/dashboard', { query: inventoryDashboardQuery }),
    ]);

    const printers = pickData(printersRes);
    const printJobs = pickData(printJobsRes);
    const paymentConfig = paymentConfigRes || {};
    const alipayCfg = paymentConfig.alipay || {};
    const wechatCfg = paymentConfig.wechat || {};
    const settlementOverview = settlementOverviewRes || {};
    const settlementSummary = settlementOverview.summary || {};
    const settlementChannels = Array.isArray(settlementOverview.channels) ? settlementOverview.channels : [];
    const settlementExceptions = settlementOverview.exceptions || {};
    const settlementExceptionItems = Array.isArray(settlementExceptions.items) ? settlementExceptions.items : [];
    const inventoryDashboard = inventoryDashboardRes || {};
    const inventorySummary = inventoryDashboard.summary || {};
    const inventoryMaterials = Array.isArray(inventoryDashboard.materials) ? inventoryDashboard.materials : [];
    const inventoryMovements = Array.isArray(inventoryDashboard.movements) ? inventoryDashboard.movements : [];
    const inventoryPurchases = Array.isArray(inventoryDashboard.purchases) ? inventoryDashboard.purchases : [];
    const boolSelected = (v, expected) => (toInt(v, 0) === expected ? 'selected' : '');
    const yesNo = (v) => (toInt(v, 0) === 1 ? '已配置' : '未配置');

    const tabKey = 'finance';
    const tabFallback = 'config';
    const tabHeader = renderSubTabNav(tabKey, [
      { id: 'config', title: '支付配置', subtitle: '支付宝与微信支付参数、证书配置' },
      { id: 'payments', title: '在线支付', subtitle: '创建支付单、查单、关单' },
      { id: 'refunds', title: '退款管理', subtitle: '支付退款、退款记录查询' },
      { id: 'printers', title: '打印管理', subtitle: '打印机、打印任务、派发与日志' },
      { id: 'settlement', title: '日结对账', subtitle: '现金流、渠道对账、异常单闭环' },
      { id: 'inventory', title: '库存耗材采购', subtitle: '耗材管理、项目自动扣减、采购入库、成本归集' },
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

      <section class="row${subTabClass(tabKey, 'settlement', tabFallback)}">
        <article class="card">
          <h3>日结/对账中心</h3>
          <p class="small-note">支持按日期复核收款、退款、渠道差异，并可一键日结落库。</p>
          <form id="formFinanceSettlementQuery" class="form-grid">
            <input name="store_id" placeholder="门店ID（管理员可填，店员默认本店）" value="${defaultStoreId > 0 ? String(defaultStoreId) : ''}" />
            <input type="date" name="date" value="${escapeHtml(defaultSettlementDate)}" />
            <button class="btn btn-line" type="submit">查询对账概览</button>
          </form>
          <hr />
          <form id="formFinanceCloseDay" class="form-grid" data-confirm="确定执行日结并生成对账快照吗？">
            <input name="store_id" placeholder="门店ID（管理员可填）" value="${defaultStoreId > 0 ? String(defaultStoreId) : ''}" />
            <input type="date" name="date" value="${escapeHtml(defaultSettlementDate)}" />
            <input name="note" placeholder="日结备注（可空）" />
            <button class="btn btn-primary" type="submit">执行日结</button>
          </form>
        </article>

        <article class="card">
          <h3>异常单闭环</h3>
          <form id="formFinanceExceptionCreate" class="form-grid">
            <input name="store_id" placeholder="门店ID（管理员可填）" value="${defaultStoreId > 0 ? String(defaultStoreId) : ''}" />
            <input type="date" name="date" value="${escapeHtml(defaultSettlementDate)}" />
            <select name="channel">
              <option value="cash">现金</option>
              <option value="wechat">微信</option>
              <option value="alipay">支付宝</option>
              <option value="card">银行卡</option>
              <option value="bank">对公转账</option>
              <option value="other">其他</option>
            </select>
            <input name="order_id" placeholder="关联订单ID（可空）" />
            <input name="amount" placeholder="差异金额（可正可负）" />
            <input name="exception_type" placeholder="异常类型（默认 manual）" />
            <input name="detail" placeholder="异常说明（必填）" required />
            <button class="btn btn-line" type="submit">登记异常单</button>
          </form>
          <hr />
          <form id="formFinanceExceptionResolve" class="form-grid">
            <input name="exception_id" placeholder="异常ID" required />
            <select name="status">
              <option value="resolved">标记已解决</option>
              <option value="ignored">标记已忽略</option>
              <option value="open">重新打开</option>
            </select>
            <input name="resolution_note" placeholder="处理备注（可空）" />
            <button class="btn btn-primary" type="submit">更新异常状态</button>
          </form>
        </article>
      </section>

      <section class="card${subTabClass(tabKey, 'settlement', tabFallback)}">
        <h3>日结概览（${escapeHtml(String(settlementOverview.settlement_date || defaultSettlementDate))}）</h3>
        ${table([
          { label: '指标', key: 'k' },
          { label: '数值', key: 'v' },
        ], [
          { k: '现金流入', v: formatMoney(settlementSummary.cash_in_amount || 0) },
          { k: '退款流出', v: formatMoney(settlementSummary.refund_out_amount || 0) },
          { k: '净额', v: formatMoney(settlementSummary.net_amount || 0) },
          { k: '线上收入', v: formatMoney(settlementSummary.online_in_amount || 0) },
          { k: '线下收入', v: formatMoney(settlementSummary.offline_in_amount || 0) },
          { k: '渠道差异总额', v: formatMoney(settlementSummary.channel_diff_amount || 0) },
          { k: '收款笔数', v: formatNumber(settlementSummary.payment_count || 0) },
          { k: '退款笔数', v: formatNumber(settlementSummary.refund_count || 0) },
          { k: '未闭环异常', v: formatNumber(settlementSummary.exception_count || ((settlementExceptions.summary && settlementExceptions.summary.open_count) || 0)) },
        ], { maxRows: 20 })}
      </section>

      <section class="card${subTabClass(tabKey, 'settlement', tabFallback)}"><h3>渠道对账明细</h3><div id="financeSettlementChannelTable">${table([
        { label: '渠道', key: 'channel' },
        { label: '账务金额', get: (r) => formatMoney(r.ledger_amount || 0) },
        { label: '网关金额', get: (r) => formatMoney(r.gateway_amount || 0) },
        { label: '差异', get: (r) => formatMoney(r.diff_amount || 0) },
        { label: '收款笔数', key: 'payment_count' },
        { label: '退款笔数', key: 'refund_count' },
        { label: '异常数', key: 'exception_count' },
      ], settlementChannels, { maxRows: 120 })}</div></section>

      <section class="card${subTabClass(tabKey, 'settlement', tabFallback)}"><h3>异常单列表</h3><div id="financeSettlementExceptionTable">${table([
        { label: 'ID', key: 'id' },
        { label: '渠道', key: 'channel' },
        { label: '类型', key: 'exception_type' },
        { label: '订单', get: (r) => r.order_no || (r.order_id ? `#${r.order_id}` : '-') },
        { label: '金额', get: (r) => formatMoney(r.amount || 0) },
        { label: '状态', key: 'status' },
        { label: '说明', key: 'detail' },
        { label: '创建时间', key: 'created_at' },
      ], settlementExceptionItems, { maxRows: 180 })}</div></section>

      <section class="row${subTabClass(tabKey, 'inventory', tabFallback)}">
        <article class="card">
          <h3>物料档案</h3>
          <form id="formInventoryMaterialUpsert" class="form-grid">
            <input name="id" placeholder="物料ID（编辑时填）" />
            <input name="store_id" placeholder="门店ID（0=全局，管理员可配）" value="${defaultStoreId > 0 ? String(defaultStoreId) : ''}" />
            <input name="material_code" placeholder="物料编码（可空自动生成）" />
            <input name="material_name" placeholder="物料名称" required />
            <input name="category" placeholder="分类（如 注射耗材）" />
            <input name="unit" placeholder="单位（如 支/盒/ml）" value="个" />
            <input name="safety_stock" placeholder="安全库存" value="0" />
            <input name="current_stock" placeholder="当前库存（新建可填）" value="0" />
            <input name="avg_cost" placeholder="平均成本单价" value="0" />
            <select name="status">
              <option value="active">启用</option>
              <option value="inactive">停用</option>
            </select>
            <input name="note" placeholder="备注（可空）" />
            <button class="btn btn-primary" type="submit">保存物料</button>
          </form>
        </article>

        <article class="card">
          <h3>服务耗材映射</h3>
          <p class="small-note">映射后，订单首次完成支付会自动按服务数量扣减库存。</p>
          <form id="formInventoryServiceMapping" class="form-grid">
            <input name="id" placeholder="映射ID（编辑时填）" />
            <input name="store_id" placeholder="门店ID（0=全局）" value="${defaultStoreId > 0 ? String(defaultStoreId) : ''}" />
            <input name="service_id" placeholder="服务ID" required />
            <input name="material_id" placeholder="物料ID" required />
            <input name="consume_qty" placeholder="单次服务消耗数量" value="1" />
            <input name="wastage_rate" placeholder="损耗率%（可空）" value="0" />
            <select name="enabled">
              <option value="1">启用</option>
              <option value="0">停用</option>
            </select>
            <button class="btn btn-line" type="submit">保存映射</button>
          </form>
        </article>
      </section>

      <section class="row${subTabClass(tabKey, 'inventory', tabFallback)}">
        <article class="card">
          <h3>采购入库</h3>
          <p class="small-note">采购明细支持 JSON 批量录入：[{ "material_id":1,"qty":10,"unit_cost":28.5 }]</p>
          <form id="formInventoryPurchaseCreate" class="form-grid">
            <input name="store_id" placeholder="门店ID（0=全局）" value="${defaultStoreId > 0 ? String(defaultStoreId) : ''}" />
            <input name="supplier_name" placeholder="供应商名称（可空）" />
            <input name="expected_at" placeholder="预计到货时间（YYYY-MM-DD HH:MM:SS，可空）" />
            <textarea name="items_json" placeholder='采购明细 JSON' required>[{"material_id":1,"qty":10,"unit_cost":0}]</textarea>
            <label class="check-line"><input type="checkbox" name="auto_receive" value="1" /><span>创建后自动入库</span></label>
            <input name="note" placeholder="采购备注（可空）" />
            <button class="btn btn-primary" type="submit">创建采购单</button>
          </form>
          <hr />
          <form id="formInventoryPurchaseReceive" class="form-grid">
            <input name="purchase_id" placeholder="采购单ID" required />
            <input name="note" placeholder="入库备注（可空）" />
            <button class="btn btn-line" type="submit">执行入库</button>
          </form>
        </article>

        <article class="card">
          <h3>库存调整与查询</h3>
          <form id="formInventoryStockAdjust" class="form-grid">
            <input name="store_id" placeholder="门店ID（0=全局）" value="${defaultStoreId > 0 ? String(defaultStoreId) : ''}" />
            <input name="material_id" placeholder="物料ID" required />
            <input name="qty_delta" placeholder="变动数量（正数入库/负数扣减）" required />
            <input name="unit_cost" placeholder="单价（可空自动沿用）" />
            <input name="movement_type" placeholder="流水类型（默认 adjust）" value="adjust" />
            <input name="note" placeholder="调整说明" />
            <button class="btn btn-primary" type="submit">提交库存调整</button>
          </form>
          <hr />
          <form id="formInventoryMaterialsQuery" class="form-grid">
            <input name="store_id" placeholder="门店ID（可空）" value="${defaultStoreId > 0 ? String(defaultStoreId) : ''}" />
            <input name="keyword" placeholder="关键字（名称/编码）" />
            <select name="low_stock_only">
              <option value="0">全部物料</option>
              <option value="1">仅低库存</option>
            </select>
            <button class="btn btn-line" type="submit">查询物料</button>
          </form>
          <hr />
          <form id="formInventoryMovementsQuery" class="form-grid">
            <input name="store_id" placeholder="门店ID（可空）" value="${defaultStoreId > 0 ? String(defaultStoreId) : ''}" />
            <input name="material_id" placeholder="物料ID（可空）" />
            <input name="movement_type" placeholder="类型（consume/purchase_in/adjust）" />
            <input type="date" name="date_from" value="${escapeHtml(defaultDateFrom)}" />
            <input type="date" name="date_to" value="${escapeHtml(defaultDateTo)}" />
            <button class="btn btn-line" type="submit">查询库存流水</button>
          </form>
          <hr />
          <form id="formInventoryCostSummary" class="form-grid">
            <input name="store_id" placeholder="门店ID（可空）" value="${defaultStoreId > 0 ? String(defaultStoreId) : ''}" />
            <input type="date" name="date_from" value="${escapeHtml(defaultDateFrom)}" />
            <input type="date" name="date_to" value="${escapeHtml(defaultDateTo)}" />
            <button class="btn btn-line" type="submit">查询成本归集</button>
          </form>
        </article>
      </section>

      <section class="card${subTabClass(tabKey, 'inventory', tabFallback)}">
        <h3>库存概览</h3>
        ${table([
          { label: '指标', key: 'k' },
          { label: '数值', key: 'v' },
        ], [
          { k: '物料总数', v: formatNumber(inventorySummary.material_count || 0) },
          { k: '低库存数量', v: formatNumber(inventorySummary.low_stock_count || 0) },
          { k: '库存估值', v: formatMoney(inventorySummary.total_stock_value || 0) },
          { k: '近期流水数', v: formatNumber(inventorySummary.movement_count || 0) },
          { k: '近期采购单数', v: formatNumber(inventorySummary.purchase_count || 0) },
        ], { maxRows: 20 })}
      </section>

      <section class="card${subTabClass(tabKey, 'inventory', tabFallback)}"><h3>物料列表</h3><div id="inventoryMaterialsTable">${table([
        { label: 'ID', key: 'id' },
        { label: '门店', key: 'store_id' },
        { label: '编码', key: 'material_code' },
        { label: '名称', key: 'material_name' },
        { label: '分类', key: 'category' },
        { label: '库存/安全', get: (r) => `${r.current_stock || 0} / ${r.safety_stock || 0}` },
        { label: '单位', key: 'unit' },
        { label: '均价', get: (r) => formatMoney(r.avg_cost || 0) },
        { label: '库存估值', get: (r) => formatMoney(r.stock_value || 0) },
        { label: '状态', get: (r) => zhStatus(r.status) },
      ], inventoryMaterials, { maxRows: 200 })}</div></section>

      <section class="card${subTabClass(tabKey, 'inventory', tabFallback)}"><h3>库存流水</h3><div id="inventoryMovementsTable">${table([
        { label: 'ID', key: 'id' },
        { label: '门店', key: 'store_id' },
        { label: '物料', get: (r) => `${r.material_name || '-'} (#${r.material_id || 0})` },
        { label: '类型', key: 'movement_type' },
        { label: '变动', key: 'qty_delta' },
        { label: '前值', key: 'qty_before' },
        { label: '后值', key: 'qty_after' },
        { label: '单价', get: (r) => formatMoney(r.unit_cost || 0) },
        { label: '金额', get: (r) => formatMoney(r.total_cost || 0) },
        { label: '来源', get: (r) => `${r.reference_type || '-'}#${r.reference_id || '-'}` },
        { label: '时间', key: 'created_at' },
      ], inventoryMovements, { maxRows: 180 })}</div></section>

      <section class="card${subTabClass(tabKey, 'inventory', tabFallback)}"><h3>采购单列表</h3><div id="inventoryPurchasesTable">${table([
        { label: 'ID', key: 'id' },
        { label: '采购单号', key: 'purchase_no' },
        { label: '门店', key: 'store_id' },
        { label: '供应商', key: 'supplier_name' },
        { label: '状态', key: 'status' },
        { label: '总额', get: (r) => formatMoney(r.total_amount || 0) },
        { label: '明细数', key: 'item_count' },
        { label: '预计到货', key: 'expected_at' },
        { label: '入库时间', key: 'received_at' },
      ], inventoryPurchases, { maxRows: 180 })}</div></section>

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

    const refreshSettlementTables = (payload) => {
      const channels = payload && Array.isArray(payload.channels) ? payload.channels : [];
      const exceptions = payload && payload.exceptions && Array.isArray(payload.exceptions.items) ? payload.exceptions.items : [];
      const channelWrap = document.getElementById('financeSettlementChannelTable');
      const exceptionWrap = document.getElementById('financeSettlementExceptionTable');
      if (channelWrap) {
        channelWrap.innerHTML = table([
          { label: '渠道', key: 'channel' },
          { label: '账务金额', get: (r) => formatMoney(r.ledger_amount || 0) },
          { label: '网关金额', get: (r) => formatMoney(r.gateway_amount || 0) },
          { label: '差异', get: (r) => formatMoney(r.diff_amount || 0) },
          { label: '收款笔数', key: 'payment_count' },
          { label: '退款笔数', key: 'refund_count' },
          { label: '异常数', key: 'exception_count' },
        ], channels, { maxRows: 120 });
      }
      if (exceptionWrap) {
        exceptionWrap.innerHTML = table([
          { label: 'ID', key: 'id' },
          { label: '渠道', key: 'channel' },
          { label: '类型', key: 'exception_type' },
          { label: '订单', get: (r) => r.order_no || (r.order_id ? `#${r.order_id}` : '-') },
          { label: '金额', get: (r) => formatMoney(r.amount || 0) },
          { label: '状态', key: 'status' },
          { label: '说明', key: 'detail' },
          { label: '创建时间', key: 'created_at' },
        ], exceptions, { maxRows: 180 });
      }
    };

    const refreshInventoryMaterials = (rows) => {
      const wrap = document.getElementById('inventoryMaterialsTable');
      if (!wrap) return;
      wrap.innerHTML = table([
        { label: 'ID', key: 'id' },
        { label: '门店', key: 'store_id' },
        { label: '编码', key: 'material_code' },
        { label: '名称', key: 'material_name' },
        { label: '分类', key: 'category' },
        { label: '库存/安全', get: (r) => `${r.current_stock || 0} / ${r.safety_stock || 0}` },
        { label: '单位', key: 'unit' },
        { label: '均价', get: (r) => formatMoney(r.avg_cost || 0) },
        { label: '库存估值', get: (r) => formatMoney(r.stock_value || 0) },
        { label: '状态', get: (r) => zhStatus(r.status) },
      ], Array.isArray(rows) ? rows : [], { maxRows: 200 });
    };

    const refreshInventoryMovements = (rows) => {
      const wrap = document.getElementById('inventoryMovementsTable');
      if (!wrap) return;
      wrap.innerHTML = table([
        { label: 'ID', key: 'id' },
        { label: '门店', key: 'store_id' },
        { label: '物料', get: (r) => `${r.material_name || '-'} (#${r.material_id || 0})` },
        { label: '类型', key: 'movement_type' },
        { label: '变动', key: 'qty_delta' },
        { label: '前值', key: 'qty_before' },
        { label: '后值', key: 'qty_after' },
        { label: '单价', get: (r) => formatMoney(r.unit_cost || 0) },
        { label: '金额', get: (r) => formatMoney(r.total_cost || 0) },
        { label: '来源', get: (r) => `${r.reference_type || '-'}#${r.reference_id || '-'}` },
        { label: '时间', key: 'created_at' },
      ], Array.isArray(rows) ? rows : [], { maxRows: 180 });
    };

    const refreshInventoryPurchases = (rows) => {
      const wrap = document.getElementById('inventoryPurchasesTable');
      if (!wrap) return;
      wrap.innerHTML = table([
        { label: 'ID', key: 'id' },
        { label: '采购单号', key: 'purchase_no' },
        { label: '门店', key: 'store_id' },
        { label: '供应商', key: 'supplier_name' },
        { label: '状态', key: 'status' },
        { label: '总额', get: (r) => formatMoney(r.total_amount || 0) },
        { label: '明细数', key: 'item_count' },
        { label: '预计到货', key: 'expected_at' },
        { label: '入库时间', key: 'received_at' },
      ], Array.isArray(rows) ? rows : [], { maxRows: 180 });
    };

    bindJsonForm('formFinanceSettlementQuery', 'financeResult', async (form) => {
      const v = getFormValues(form);
      const query = {
        date: v.date || defaultSettlementDate,
      };
      if (toInt(v.store_id, 0) > 0) query.store_id = toInt(v.store_id, 0);
      const res = await request('GET', '/finance/reconciliation/overview', { query });
      refreshSettlementTables(res || {});
      return res;
    });

    bindJsonForm('formFinanceCloseDay', 'financeResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        store_id: toInt(v.store_id, 0),
        date: v.date || defaultSettlementDate,
        note: v.note || '',
      };
      const res = await request('POST', '/finance/reconciliation/close-day', { body });
      refreshSettlementTables(res || {});
      return res;
    });

    bindJsonForm('formFinanceExceptionCreate', 'financeResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        store_id: toInt(v.store_id, 0),
        date: v.date || defaultSettlementDate,
        channel: v.channel || 'other',
        amount: toFloat(v.amount, 0),
        exception_type: (v.exception_type || '').trim() || 'manual',
        detail: v.detail || '',
      };
      if (toInt(v.order_id, 0) > 0) body.order_id = toInt(v.order_id, 0);
      const res = await request('POST', '/finance/reconciliation/exceptions', { body });
      const overviewQuery = { date: body.date };
      if (body.store_id > 0) overviewQuery.store_id = body.store_id;
      const overviewRes = await request('GET', '/finance/reconciliation/overview', { query: overviewQuery });
      refreshSettlementTables(overviewRes || {});
      return {
        create: res,
        overview: overviewRes,
      };
    });

    bindJsonForm('formFinanceExceptionResolve', 'financeResult', async (form) => {
      const v = getFormValues(form);
      const res = await request('POST', '/finance/reconciliation/exceptions/resolve', {
        body: {
          exception_id: toInt(v.exception_id, 0),
          status: v.status || 'resolved',
          resolution_note: v.resolution_note || '',
        },
      });
      const queryForm = document.getElementById('formFinanceSettlementQuery');
      let overviewQuery = { date: defaultSettlementDate };
      if (queryForm) {
        const qv = getFormValues(queryForm);
        overviewQuery = { date: qv.date || defaultSettlementDate };
        if (toInt(qv.store_id, 0) > 0) overviewQuery.store_id = toInt(qv.store_id, 0);
      }
      const overviewRes = await request('GET', '/finance/reconciliation/overview', { query: overviewQuery });
      refreshSettlementTables(overviewRes || {});
      return {
        resolve: res,
        overview: overviewRes,
      };
    });

    bindJsonForm('formInventoryMaterialUpsert', 'financeResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/inventory/materials', {
        body: {
          id: toInt(v.id, 0),
          store_id: toInt(v.store_id, 0),
          material_code: v.material_code || '',
          material_name: v.material_name || '',
          category: v.category || '',
          unit: v.unit || '个',
          safety_stock: toFloat(v.safety_stock, 0),
          current_stock: toFloat(v.current_stock, 0),
          avg_cost: toFloat(v.avg_cost, 0),
          status: v.status || 'active',
          note: v.note || '',
        },
      });
    });

    bindJsonForm('formInventoryServiceMapping', 'financeResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/inventory/service-mappings', {
        body: {
          id: toInt(v.id, 0),
          store_id: toInt(v.store_id, 0),
          service_id: toInt(v.service_id, 0),
          material_id: toInt(v.material_id, 0),
          consume_qty: toFloat(v.consume_qty, 1),
          wastage_rate: toFloat(v.wastage_rate, 0),
          enabled: toInt(v.enabled, 1),
        },
      });
    });

    bindJsonForm('formInventoryPurchaseReceive', 'financeResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/inventory/purchases/receive', {
        body: {
          purchase_id: toInt(v.purchase_id, 0),
          note: v.note || '',
        },
      });
    });

    bindJsonForm('formInventoryStockAdjust', 'financeResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/inventory/stock/adjust', {
        body: {
          store_id: toInt(v.store_id, 0),
          material_id: toInt(v.material_id, 0),
          qty_delta: toFloat(v.qty_delta, 0),
          unit_cost: toFloat(v.unit_cost, 0),
          movement_type: v.movement_type || 'adjust',
          note: v.note || '',
        },
      });
    });

    bindJsonForm('formInventoryMaterialsQuery', 'financeResult', async (form) => {
      const v = getFormValues(form);
      const query = {
        store_id: toInt(v.store_id, 0),
        keyword: v.keyword || '',
        low_stock_only: toInt(v.low_stock_only, 0),
        limit: 300,
      };
      if (query.store_id <= 0) delete query.store_id;
      const res = await request('GET', '/inventory/materials', { query });
      refreshInventoryMaterials(pickData(res));
      return res;
    });

    bindJsonForm('formInventoryMovementsQuery', 'financeResult', async (form) => {
      const v = getFormValues(form);
      const query = {
        store_id: toInt(v.store_id, 0),
        material_id: toInt(v.material_id, 0),
        movement_type: v.movement_type || '',
        date_from: v.date_from || '',
        date_to: v.date_to || '',
        limit: 300,
      };
      if (query.store_id <= 0) delete query.store_id;
      if (query.material_id <= 0) delete query.material_id;
      if (!query.movement_type) delete query.movement_type;
      const res = await request('GET', '/inventory/stock-movements', { query });
      refreshInventoryMovements(pickData(res));
      return res;
    });

    bindJsonForm('formInventoryCostSummary', 'financeResult', async (form) => {
      const v = getFormValues(form);
      const query = {
        store_id: toInt(v.store_id, 0),
        date_from: v.date_from || defaultDateFrom,
        date_to: v.date_to || defaultDateTo,
      };
      if (query.store_id <= 0) delete query.store_id;
      return request('GET', '/inventory/cost-summary', { query });
    });

    bindJsonForm('formInventoryPurchaseCreate', 'financeResult', async (form) => {
      const v = getFormValues(form);
      const items = parseJsonText(v.items_json, []);
      if (!Array.isArray(items) || items.length === 0) {
        throw new Error('采购明细 JSON 无效');
      }
      const res = await request('POST', '/inventory/purchases', {
        body: {
          store_id: toInt(v.store_id, 0),
          supplier_name: v.supplier_name || '',
          expected_at: v.expected_at || '',
          note: v.note || '',
          auto_receive: toInt(v.auto_receive, 0),
          items,
        },
      });
      const purchaseRes = await request('GET', '/inventory/purchases', {
        query: { store_id: toInt(v.store_id, 0), limit: 200 },
      });
      refreshInventoryPurchases(pickData(purchaseRes));
      return {
        create: res,
        purchases: pickData(purchaseRes),
      };
    });

    [
      'formFinanceSettlementQuery',
      'formFinanceCloseDay',
      'formFinanceExceptionCreate',
      'formInventoryMaterialUpsert',
      'formInventoryServiceMapping',
      'formInventoryPurchaseCreate',
      'formInventoryStockAdjust',
      'formInventoryMaterialsQuery',
      'formInventoryMovementsQuery',
      'formInventoryCostSummary',
    ].forEach((formId) => applyStoreDefault(formId));
  }


  return renderFinance;
};
