window.__QILING_ADMIN_VIEW_FACTORIES__ = window.__QILING_ADMIN_VIEW_FACTORIES__ || {};
window.__QILING_ADMIN_VIEW_FACTORIES__['bizplus'] = function (shared) {
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
  async function renderBizPlus() {
    const [couponTransfersRes, memberCardTransfersRes, openGiftsRes] = await Promise.all([
      request('GET', '/coupon-transfers', { query: { limit: 120 } }),
      request('GET', '/member-card-transfers', { query: { limit: 120 } }),
      request('GET', '/open-gifts'),
    ]);

    const couponTransfers = pickData(couponTransfersRes);
    const memberCardTransfers = pickData(memberCardTransfersRes);
    const openGifts = pickData(openGiftsRes);
    const tabKey = 'bizplus';
    const tabFallback = 'transfers';
    const tabHeader = renderSubTabNav(tabKey, [
      { id: 'transfers', title: '卡券转赠', subtitle: '优惠券转赠、次卡转赠' },
      { id: 'gifts', title: '开单礼', subtitle: '开单礼规则维护与手工触发' },
    ]);

    el.viewRoot.innerHTML = `
      ${tabHeader}
      <section class="row-3${subTabClass(tabKey, 'transfers', tabFallback)}">
        <article class="card">
          <h3>优惠券转赠</h3>
          <form id="formCouponTransfer" class="form-grid">
            <input name="coupon_id" placeholder="优惠券ID（可空）" />
            <input name="coupon_code" placeholder="券码（可空）" />
            <input name="from_customer_id" placeholder="来源客户ID（可空校验）" />
            <input name="from_customer_mobile" placeholder="来源手机号（可空校验）" />
            <input name="to_customer_id" placeholder="目标客户ID（可空）" />
            <input name="to_customer_mobile" placeholder="目标手机号（可空）" />
            <input name="note" placeholder="备注" value="后台优惠券转赠" />
            <button class="btn btn-primary" type="submit">提交转赠</button>
          </form>
        </article>

        <article class="card">
          <h3>次卡转赠</h3>
          <form id="formMemberCardTransfer" class="form-grid">
            <input name="member_card_id" placeholder="次卡ID（可空）" />
            <input name="card_no" placeholder="次卡卡号（可空）" />
            <input name="from_customer_id" placeholder="来源客户ID（可空校验）" />
            <input name="from_customer_mobile" placeholder="来源手机号（可空校验）" />
            <input name="to_customer_id" placeholder="目标客户ID（可空）" />
            <input name="to_customer_mobile" placeholder="目标手机号（可空）" />
            <input name="note" placeholder="备注" value="后台次卡转赠" />
            <button class="btn btn-primary" type="submit">提交转赠</button>
          </form>
        </article>

        <article class="card">
          <h3>手工开单入口</h3>
          <p class="small-note">开单已归到「预约与订单」模块，防止与营销规则混放。</p>
          <button id="btnGoOpsOrder" class="btn btn-line" type="button">前往预约与订单 > 开单</button>
        </article>
      </section>

      <section class="row${subTabClass(tabKey, 'gifts', tabFallback)}">
        <article class="card">
          <h3>开单礼规则</h3>
          <form id="formOpenGiftUpsert" class="form-grid">
            <input name="id" placeholder="规则ID（编辑时填）" />
            <input name="store_id" placeholder="门店ID（0=全局）" />
            <select name="trigger_type">
              <option value="onboard">建档后触发</option>
              <option value="first_paid">首单支付后触发</option>
              <option value="manual">手工触发</option>
            </select>
            <input name="gift_name" placeholder="礼包名称" required />
            <select name="enabled">
              <option value="1">启用</option>
              <option value="0">停用</option>
            </select>
            <textarea name="items_text" placeholder="礼包项示例：每行一条，积分=points,100,30；优惠券=coupon,新客券,cash,50,199,1,30"></textarea>
            <button class="btn btn-primary" type="submit">保存开单礼规则</button>
          </form>
        </article>

        <article class="card">
          <h3>手工触发开单礼</h3>
          <form id="formOpenGiftTrigger" class="form-grid">
            <select name="trigger_type">
              <option value="manual">手工触发</option>
              <option value="onboard">建档后触发</option>
              <option value="first_paid">首单支付后触发</option>
            </select>
            <input name="customer_id" placeholder="客户ID（可空）" />
            <input name="customer_mobile" placeholder="客户手机号（可空）" />
            <input name="store_id" placeholder="门店ID（可空）" />
            <input name="reference_type" placeholder="引用类型（默认：manual 手工）" value="manual" />
            <input name="reference_id" placeholder="引用ID（可空）" />
            <button class="btn btn-primary" type="submit">触发开单礼</button>
          </form>
        </article>
      </section>

      <section class="card${subTabClass(tabKey, 'transfers', tabFallback)}"><h3>优惠券转赠记录</h3>${table([
        { label: 'ID', key: 'id' },
        { label: '转赠单号', key: 'transfer_no' },
        { label: '券码', key: 'coupon_code' },
        { label: '来源客户', get: (r) => `${r.from_customer_name || ''} (${r.from_customer_mobile || ''})` },
        { label: '目标客户', get: (r) => `${r.to_customer_name || ''} (${r.to_customer_mobile || ''})` },
        { label: '状态', get: (r) => zhStatus(r.status) },
        { label: '时间', key: 'created_at' },
      ], couponTransfers, { maxRows: 120 })}</section>

      <section class="card${subTabClass(tabKey, 'transfers', tabFallback)}"><h3>次卡转赠记录</h3>${table([
        { label: 'ID', key: 'id' },
        { label: '转赠单号', key: 'transfer_no' },
        { label: '卡号', key: 'card_no' },
        { label: '来源客户', get: (r) => `${r.from_customer_name || ''} (${r.from_customer_mobile || ''})` },
        { label: '目标客户', get: (r) => `${r.to_customer_name || ''} (${r.to_customer_mobile || ''})` },
        { label: '状态', get: (r) => zhStatus(r.status) },
        { label: '时间', key: 'created_at' },
      ], memberCardTransfers, { maxRows: 120 })}</section>

      <section class="card${subTabClass(tabKey, 'gifts', tabFallback)}"><h3>开单礼规则</h3>${table([
        { label: 'ID', key: 'id' },
        { label: '门店ID', key: 'store_id' },
        { label: '触发类型', get: (r) => zhTriggerType(r.trigger_type) },
        { label: '名称', key: 'gift_name' },
        { label: '启用', get: (r) => zhEnabled(r.enabled) },
        { label: '礼项数', get: (r) => Array.isArray(r.items) ? r.items.length : 0 },
      ], openGifts, { maxRows: 120 })}</section>

      <section class="card"><h3>操作返回</h3>${jsonBox('bizPlusResult', '等待操作')}</section>
    `;
    bindSubTabNav(tabKey, tabFallback);

    const goOpsBtn = document.getElementById('btnGoOpsOrder');
    if (goOpsBtn) {
      goOpsBtn.addEventListener('click', async () => {
        state.subTabs.ops = 'orders';
        await openView('ops');
      });
    }

    bindJsonForm('formCouponTransfer', 'bizPlusResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/coupons/transfer', {
        body: {
          coupon_id: toInt(v.coupon_id, 0),
          coupon_code: v.coupon_code || '',
          from_customer_id: toInt(v.from_customer_id, 0),
          from_customer_mobile: v.from_customer_mobile || '',
          to_customer_id: toInt(v.to_customer_id, 0),
          to_customer_mobile: v.to_customer_mobile || '',
          note: v.note || '后台优惠券转赠',
        },
      });
    });

    bindJsonForm('formMemberCardTransfer', 'bizPlusResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/member-cards/transfer', {
        body: {
          member_card_id: toInt(v.member_card_id, 0),
          card_no: v.card_no || '',
          from_customer_id: toInt(v.from_customer_id, 0),
          from_customer_mobile: v.from_customer_mobile || '',
          to_customer_id: toInt(v.to_customer_id, 0),
          to_customer_mobile: v.to_customer_mobile || '',
          note: v.note || '后台次卡转赠',
        },
      });
    });

    bindJsonForm('formOpenGiftUpsert', 'bizPlusResult', async (form) => {
      const v = getFormValues(form);
      const items = parseCsvLines(v.items_text).map((row) => {
        const type = String(row[0] || '').trim().toLowerCase();
        if (type === 'points') {
          return {
            item_type: 'points',
            points_value: toInt(row[1], 0),
            expire_days: toInt(row[2], 30),
          };
        }
        if (type === 'coupon') {
          return {
            item_type: 'coupon',
            coupon_name: row[1] || '',
            coupon_type: row[2] || 'cash',
            face_value: toFloat(row[3], 0),
            min_spend: toFloat(row[4], 0),
            remain_count: toInt(row[5], 1),
            expire_days: toInt(row[6], 30),
          };
        }
        return null;
      }).filter((x) => x !== null);

      return request('POST', '/open-gifts', {
        body: {
          id: toInt(v.id, 0),
          store_id: toInt(v.store_id, 0),
          trigger_type: v.trigger_type || 'manual',
          gift_name: v.gift_name,
          enabled: toInt(v.enabled, 1),
          items,
        },
      });
    });

    bindJsonForm('formOpenGiftTrigger', 'bizPlusResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/open-gifts/trigger', {
        body: {
          trigger_type: v.trigger_type || 'manual',
          customer_id: toInt(v.customer_id, 0),
          customer_mobile: v.customer_mobile || '',
          store_id: toInt(v.store_id, 0),
          reference_type: v.reference_type || 'manual',
          reference_id: toInt(v.reference_id, 0),
        },
      });
    });
  }


  return renderBizPlus;
};
