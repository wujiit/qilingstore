window.__QILING_ADMIN_VIEW_FACTORIES__ = window.__QILING_ADMIN_VIEW_FACTORIES__ || {};
window.__QILING_ADMIN_VIEW_FACTORIES__['integration'] = function (shared) {
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


  return renderIntegration;
};
