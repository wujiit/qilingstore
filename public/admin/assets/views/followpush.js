window.__QILING_ADMIN_VIEW_FACTORIES__ = window.__QILING_ADMIN_VIEW_FACTORIES__ || {};
window.__QILING_ADMIN_VIEW_FACTORIES__['followpush'] = function (shared) {
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


  return renderFollowupPush;
};
