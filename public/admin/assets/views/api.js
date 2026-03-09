window.__QILING_ADMIN_VIEW_FACTORIES__ = window.__QILING_ADMIN_VIEW_FACTORIES__ || {};
window.__QILING_ADMIN_VIEW_FACTORIES__['api'] = function (shared) {
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
  async function renderApiLab() {
    el.viewRoot.innerHTML = `
      <section class="card panel-top">
        <h3>接口调试台</h3>
        <p class="small-note">可直连所有后端接口。路径填写 <code>/api/v1/...</code> 或简写 <code>/...</code>（自动加前缀）。</p>
        <form id="formApiLab" class="form-grid">
          <div class="row">
            <select name="method">
              <option>GET</option>
              <option>POST</option>
              <option>PUT</option>
              <option>PATCH</option>
              <option>DELETE</option>
            </select>
            <input name="path" value="/dashboard/summary" />
          </div>
          <textarea name="payload" placeholder='请求体（JSON格式），例如 {"limit":100}'></textarea>
          <button class="btn btn-primary" type="submit">发送请求</button>
        </form>
      </section>

      <section class="card"><h3>响应</h3>${jsonBox('apiLabResult', '等待请求')}</section>
    `;

    bindJsonForm('formApiLab', 'apiLabResult', async (form) => {
      const v = getFormValues(form);
      const method = (v.method || 'GET').toUpperCase();
      const rawPath = v.path || '/dashboard/summary';
      const path = rawPath.startsWith('/api/v1') ? rawPath.replace('/api/v1', '') : rawPath;
      let body = null;

      if (v.payload) {
        try {
          body = JSON.parse(v.payload);
        } catch (_e) {
          throw new Error('请求体不是有效的JSON格式');
        }
      }

      if (method === 'GET') {
        return request('GET', path, { query: body || null });
      }

      return request(method, path, { body: body || {} });
    });
  }


  return renderApiLab;
};
