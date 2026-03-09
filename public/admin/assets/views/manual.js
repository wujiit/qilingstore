window.__QILING_ADMIN_VIEW_FACTORIES__ = window.__QILING_ADMIN_VIEW_FACTORIES__ || {};
window.__QILING_ADMIN_VIEW_FACTORIES__['manual'] = function (shared) {
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
  async function renderManual() {
    const storesRes = await request('GET', '/stores');
    const stores = pickData(storesRes);
    state.storeOptions = stores;
    const tabKey = 'manual';
    const tabFallback = 'profile';
    const tabHeader = renderSubTabNav(tabKey, [
      { id: 'profile', title: '客户建档', subtitle: '客户检索、建档、基础资料录入' },
      { id: 'consume', title: '代客消费', subtitle: '后台代客结算、余额和卡券扣减' },
      { id: 'adjust', title: '记录修正', subtitle: '余额券卡纠偏、消费记录修正、补录' },
    ]);

    el.viewRoot.innerHTML = `
      ${tabHeader}
      <section class="card${subTabClass(tabKey, 'profile', tabFallback)}">
        <h3>客户全量检索（按手机号/会员号/卡号）</h3>
        <form id="formSearchCustomer" class="inline-actions">
          <input name="keyword" placeholder="例如: 13800000000 / QLCxxxx / QLMCxxxx" style="max-width:420px" required />
          <button class="btn btn-primary" type="submit">检索</button>
        </form>
      </section>

      <section class="row${subTabClass(tabKey, 'profile', tabFallback)}">
        <article class="card">
          <h3>后台建档（可赠送余额/次卡/优惠券）</h3>
          <form id="formOnboard" class="form-grid">
            <input name="name" placeholder="客户姓名" required />
            <input name="mobile" placeholder="手机号" required />
            ${renderStoreField(stores, {
              inputName: 'store_id',
              presetName: 'store_id_preset',
              datalistId: 'qilingStoreListManualOnboard',
              inputPlaceholder: '门店ID（可搜索门店名，也可手动输入）',
              presetLabel: '所属门店',
            })}
            ${renderSourceChannelField('来源渠道（可手动填写，如：老客转介绍）')}
            <input name="gift_balance" placeholder="赠送余额" />
            <textarea name="gift_member_cards" placeholder="赠送次卡：每行填写 套餐ID,总次数,有效天数,备注（如：12,10,365,开业礼包）"></textarea>
            <textarea name="gift_coupons" placeholder="赠送优惠券：每行填写 券名,券类型(cash满减/discount折扣),面额,门槛,数量,到期时间,备注"></textarea>
            <button class="btn btn-primary" type="submit">提交建档</button>
          </form>
          ${renderStoreDatalist(stores, 'qilingStoreListManualOnboard')}
          ${renderSourceChannelDatalist()}
        </article>

      </section>

      <section class="row${subTabClass(tabKey, 'consume', tabFallback)}">
        <article class="card">
          <h3>后台登记消费（代客结算）</h3>
          <form id="formConsumeRecord" class="form-grid">
            <input name="customer_mobile" placeholder="客户手机号" required />
            ${renderStoreField(stores, {
              inputName: 'store_id',
              presetName: 'store_id_preset_consume',
              datalistId: 'qilingStoreListManualConsume',
              inputPlaceholder: '门店ID（可空，可搜索门店名或手填ID）',
              presetLabel: '消费归属门店',
            })}
            <input name="consume_amount" placeholder="消费金额" value="0" />
            <input name="deduct_balance_amount" placeholder="余额扣减" value="0" />
            <textarea name="coupon_usages" placeholder="优惠券核销：每行填写 券码(coupon_code),核销次数（如 QLCP001,1）"></textarea>
            <textarea name="member_card_usages" placeholder="次卡核销：每行填写 卡号(card_no),核销次数（如 QLMC001,1）"></textarea>
            <input name="note" placeholder="备注" value="后台代客结算" />
            <button class="btn btn-primary" type="submit">登记消费</button>
          </form>
          ${renderStoreDatalist(stores, 'qilingStoreListManualConsume')}
        </article>
      </section>

      <section class="row-3${subTabClass(tabKey, 'adjust', tabFallback)}">
        <article class="card">
          <h3>余额调整</h3>
          <form id="formWalletAdjust" class="form-grid">
            <input name="customer_mobile" placeholder="客户手机号" required />
            <select name="mode">
              <option value="delta">按增减值调整</option>
              <option value="set_balance">直接设置余额</option>
            </select>
            <input name="amount" placeholder="金额" required />
            <select name="change_type">
              <option value="adjust">手工调整</option>
              <option value="gift">赠送</option>
              <option value="recharge">充值</option>
              <option value="deduct">扣减</option>
            </select>
            <input name="note" placeholder="备注" value="后台调整余额" />
            <button class="btn btn-line" type="submit">提交</button>
          </form>
        </article>

        <article class="card">
          <h3>优惠券调整</h3>
          <form id="formCouponAdjust" class="form-grid">
            <input name="customer_mobile" placeholder="客户手机号" required />
            <select name="mode">
              <option value="grant">发券</option>
              <option value="set_remaining">直接设置剩余次数</option>
              <option value="delta_count">按增减值调整次数</option>
            </select>
            <input name="coupon_name" placeholder="发券模式填：券名称" />
            <input name="coupon_code" placeholder="调整模式填写：券码（coupon_code）" />
            <input name="count" placeholder="次数（发券/设剩余时填写）" />
            <input name="delta_count" placeholder="增减次数（可正可负）" />
            <input name="face_value" placeholder="面额（发券模式）" />
            <input name="min_spend" placeholder="最低消费门槛（发券模式）" />
            <input name="note" placeholder="备注" value="后台手工调整优惠券" />
            <button class="btn btn-line" type="submit">提交</button>
          </form>
        </article>

        <article class="card">
          <h3>次卡纠偏</h3>
          <form id="formCardAdjust" class="form-grid">
            <input name="card_no" placeholder="次卡卡号" required />
            <select name="mode">
              <option value="set_remaining">直接设置剩余次数</option>
              <option value="delta_sessions">按增减值调整次数</option>
            </select>
            <input name="value" placeholder="值" required />
            <input name="status" placeholder="强制状态(可空)" />
            <input name="note" placeholder="备注" value="后台手工调整" />
            <button class="btn btn-line" type="submit">提交</button>
          </form>
        </article>
      </section>

      <section class="row${subTabClass(tabKey, 'adjust', tabFallback)}">
        <article class="card">
          <h3>后台手工补录次卡消费</h3>
          <form id="formManualConsume" class="form-grid">
            <input name="card_no" placeholder="次卡卡号" required />
            <input name="consume_sessions" placeholder="核销次数" value="1" required />
            <input name="appointment_id" placeholder="关联预约ID(可空)" />
            <input name="note" placeholder="备注" value="后台手工补录消费" />
            <button class="btn btn-primary" type="submit">补录消费</button>
          </form>
        </article>

        <article class="card">
          <h3>修正消费记录金额</h3>
          <form id="formAdjustConsumeRecord" class="form-grid">
            <input name="consume_record_id" placeholder="消费记录ID(可空)" />
            <input name="consume_no" placeholder="消费单号(可空)" />
            <input name="consume_amount" placeholder="新消费金额(可空)" />
            <input name="deduct_balance_amount" placeholder="新余额扣减(可空)" />
            <input name="deduct_coupon_amount" placeholder="新优惠券扣减(可空)" />
            <input name="deduct_member_card_sessions" placeholder="新次卡扣减次数(可空)" />
            <input name="note" placeholder="备注(可空)" />
            <button class="btn btn-primary" type="submit">修正消费记录</button>
          </form>
        </article>

        <article class="card">
          <h3>修正预约消费记录</h3>
          <form id="formAdjustAppointmentConsume" class="form-grid">
            <input name="appointment_id" placeholder="预约ID" required />
            <input name="consume_sessions" placeholder="新核销次数" required />
            <input name="note" placeholder="备注" value="后台手工修正消费记录" />
            <button class="btn btn-primary" type="submit">修正记录</button>
          </form>
        </article>
      </section>

      <section class="card"><h3>操作返回</h3>${jsonBox('manualResult', '等待操作')}</section>
    `;
    bindSubTabNav(tabKey, tabFallback);

    bindSourceChannelAssist('formOnboard', 'source_channel', 'source_channel_preset', 'qiling_last_source_channel');
    bindStoreAssist('formOnboard', stores, {
      inputName: 'store_id',
      presetName: 'store_id_preset',
      memoryKey: 'qiling_last_store_id',
    });
    bindStoreAssist('formConsumeRecord', stores, {
      inputName: 'store_id',
      presetName: 'store_id_preset_consume',
      memoryKey: 'qiling_last_store_id',
    });

    bindJsonForm('formSearchCustomer', 'manualResult', async (form) => {
      const v = getFormValues(form);
      return request('GET', '/admin/customers/search', { query: { keyword: v.keyword, limit: 50 } });
    });

    bindJsonForm('formOnboard', 'manualResult', async (form) => {
      const v = getFormValues(form);
      const cards = parseCsvLines(v.gift_member_cards).map((a) => ({
        package_id: toInt(a[0], 0),
        total_sessions: toInt(a[1], 0),
        valid_days: toInt(a[2], 0),
        note: a[3] || '',
      })).filter((x) => x.package_id > 0);

      const coupons = parseCsvLines(v.gift_coupons).map((a) => ({
        coupon_name: a[0] || '',
        coupon_type: a[1] || 'cash',
        face_value: toFloat(a[2], 0),
        min_spend: toFloat(a[3], 0),
        count: toInt(a[4], 1),
        expire_at: a[5] || '',
        note: a[6] || '',
      })).filter((x) => x.coupon_name !== '');

      const body = {
        customer: {
          name: v.name,
          mobile: v.mobile,
          store_id: toInt(v.store_id, 0),
          source_channel: v.source_channel || '',
        },
        gift_balance: toFloat(v.gift_balance, 0),
        gift_member_cards: cards,
        gift_coupons: coupons,
      };
      return request('POST', '/admin/customers/onboard', { body });
    });

    bindJsonForm('formConsumeRecord', 'manualResult', async (form) => {
      const v = getFormValues(form);
      const couponUsages = parseCsvLines(v.coupon_usages).map((a) => ({
        coupon_code: a[0] || '',
        use_count: toInt(a[1], 1),
      })).filter((x) => x.coupon_code !== '');

      const memberCardUsages = parseCsvLines(v.member_card_usages).map((a) => {
        const first = a[0] || '';
        if (/^\d+$/.test(first)) {
          return { member_card_id: toInt(first, 0), consume_sessions: toInt(a[1], 1) };
        }
        return { card_no: first, consume_sessions: toInt(a[1], 1) };
      }).filter((x) => (x.member_card_id || 0) > 0 || (x.card_no || '') !== '');

      return request('POST', '/admin/customers/consume-record', {
        body: {
          customer_mobile: v.customer_mobile,
          store_id: toInt(v.store_id, 0),
          consume_amount: toFloat(v.consume_amount, 0),
          deduct_balance_amount: toFloat(v.deduct_balance_amount, 0),
          coupon_usages: couponUsages,
          member_card_usages: memberCardUsages,
          note: v.note || '',
        },
      });
    });

    bindJsonForm('formWalletAdjust', 'manualResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/admin/customers/wallet-adjust', {
        body: {
          customer_mobile: v.customer_mobile,
          mode: v.mode,
          amount: toFloat(v.amount, 0),
          change_type: v.change_type,
          note: v.note || '',
        },
      });
    });

    bindJsonForm('formCouponAdjust', 'manualResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        customer_mobile: v.customer_mobile,
        mode: v.mode,
        coupon_name: v.coupon_name || '',
        coupon_code: v.coupon_code || '',
        count: toInt(v.count, 0),
        delta_count: toInt(v.delta_count, 0),
        face_value: toFloat(v.face_value, 0),
        min_spend: toFloat(v.min_spend, 0),
        note: v.note || '',
      };
      return request('POST', '/admin/customers/coupon-adjust', { body });
    });

    bindJsonForm('formCardAdjust', 'manualResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/admin/member-cards/adjust', {
        body: {
          card_no: v.card_no,
          mode: v.mode,
          value: toInt(v.value, 0),
          status: v.status || '',
          note: v.note || '',
        },
      });
    });

    bindJsonForm('formManualConsume', 'manualResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/admin/member-cards/manual-consume', {
        body: {
          card_no: v.card_no,
          consume_sessions: toInt(v.consume_sessions, 1),
          appointment_id: toInt(v.appointment_id, 0),
          note: v.note || '',
        },
      });
    });

    bindJsonForm('formAdjustConsumeRecord', 'manualResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        consume_record_id: toInt(v.consume_record_id, 0),
        consume_no: v.consume_no || '',
      };
      if (v.consume_amount !== '') body.consume_amount = toFloat(v.consume_amount, 0);
      if (v.deduct_balance_amount !== '') body.deduct_balance_amount = toFloat(v.deduct_balance_amount, 0);
      if (v.deduct_coupon_amount !== '') body.deduct_coupon_amount = toFloat(v.deduct_coupon_amount, 0);
      if (v.deduct_member_card_sessions !== '') body.deduct_member_card_sessions = toInt(v.deduct_member_card_sessions, 0);
      if (v.note !== '') body.note = v.note;
      return request('POST', '/admin/customers/consume-record-adjust', { body });
    });

    bindJsonForm('formAdjustAppointmentConsume', 'manualResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/admin/appointment-consumes/adjust', {
        body: {
          appointment_id: toInt(v.appointment_id, 0),
          consume_sessions: toInt(v.consume_sessions, 1),
          note: v.note || '',
        },
      });
    });
  }


  return renderManual;
};
