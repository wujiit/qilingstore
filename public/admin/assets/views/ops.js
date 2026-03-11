window.__QILING_ADMIN_VIEW_FACTORIES__ = window.__QILING_ADMIN_VIEW_FACTORIES__ || {};
window.__QILING_ADMIN_VIEW_FACTORIES__['ops'] = function (shared) {
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
  async function renderOps() {
    const [appointmentsRes, ordersRes, memberCardsRes, cardLogsRes] = await Promise.all([
      request('GET', '/appointments'),
      request('GET', '/orders', { query: { limit: 200 } }),
      request('GET', '/member-cards'),
      request('GET', '/member-card-logs', { query: { limit: 120 } }),
    ]);

    const appointments = pickData(appointmentsRes);
    const orders = pickData(ordersRes);
    const memberCards = pickData(memberCardsRes);
    const cardLogs = pickData(cardLogsRes);
    const tabKey = 'ops';
    const tabFallback = 'appointments';
    const tabHeader = renderSubTabNav(tabKey, [
      { id: 'appointments', title: '预约管理', subtitle: '新增预约、预约状态、预约列表' },
      { id: 'orders', title: '订单收款', subtitle: '订单收款登记、订单明细、订单列表' },
      { id: 'cards', title: '次卡管理', subtitle: '开卡核销、次卡列表、到期管理' },
      { id: 'card_logs', title: '次卡流水', subtitle: '次卡扣减、调整、补录流水查询' },
    ]);

    el.viewRoot.innerHTML = `
      ${tabHeader}
      <section class="row${subTabClass(tabKey, 'appointments', tabFallback)}">
        <article class="card">
          <h3>新增预约</h3>
          <p class="small-note">先在“客户档案/员工管理/服务套餐”查看对应ID，再创建预约。</p>
          <form id="formCreateAppointment" class="form-grid">
            <input name="customer_id" placeholder="客户ID" required />
            <input name="store_id" placeholder="门店ID(可空)" />
            <input name="staff_id" placeholder="员工ID" />
            <input name="service_id" placeholder="服务ID" />
            <label><span>开始时间</span><input type="datetime-local" name="start_at" required /></label>
            <label><span>结束时间</span><input type="datetime-local" name="end_at" required /></label>
            <button class="btn btn-primary" type="submit">创建预约</button>
          </form>
        </article>

        <article class="card">
          <h3>更新预约状态</h3>
          <p class="small-note">预约ID可在下方“预约列表”中查看。</p>
          <form id="formUpdateAppointment" class="form-grid">
            <input name="appointment_id" placeholder="预约ID" required />
            <select name="status">
              <option value="booked">已预约</option>
              <option value="completed">已完成</option>
              <option value="cancelled">已取消</option>
              <option value="no_show">未到店</option>
            </select>
            <input name="member_card_id" placeholder="完成时核销卡ID(可空)" />
            <input name="consume_sessions" placeholder="核销次数" value="1" />
            <input name="consume_note" placeholder="核销备注" />
            <button class="btn btn-primary" type="submit">更新状态</button>
          </form>
        </article>

      </section>

      <section class="card${subTabClass(tabKey, 'cards', tabFallback)}">
        <h3>次卡开卡与核销</h3>
        <p class="small-note">开卡需客户ID与套餐ID；核销需次卡ID（可在次卡列表查看）。</p>
        <div class="row">
          <form id="formCreateMemberCard" class="form-grid">
            <input name="customer_id" placeholder="客户ID" required />
            <input name="package_id" placeholder="套餐ID" required />
            <input name="total_sessions" placeholder="总次数" />
            <input name="sold_price" placeholder="售价" />
            <button class="btn btn-line" type="submit">开卡</button>
          </form>
          <form id="formConsumeMemberCard" class="form-grid">
            <input name="member_card_id" placeholder="次卡ID" required />
            <input name="consume_sessions" placeholder="核销次数" value="1" required />
            <input name="note" placeholder="备注" />
            <button class="btn btn-primary" type="submit">次卡核销</button>
          </form>
        </div>
      </section>

      <section class="card${subTabClass(tabKey, 'orders', tabFallback)}">
        <h3>订单支付与明细</h3>
        <p class="small-note">订单ID可在“订单列表”里查看；可先查询明细再登记收款。</p>
        <div class="row">
          <form id="formOrderPay" class="form-grid">
            <input name="order_id" placeholder="订单ID" required />
            <select name="pay_method">
              <option value="cash">现金</option>
              <option value="wechat">微信</option>
              <option value="alipay">支付宝</option>
              <option value="card">银行卡</option>
              <option value="bank">对公转账</option>
              <option value="other">其他</option>
            </select>
            <input name="amount" placeholder="支付金额(可空自动剩余)" />
            <input name="note" placeholder="支付备注" />
            <button class="btn btn-primary" type="submit">登记收款</button>
          </form>

          <form id="formOrderDetail" class="form-grid">
            <input name="order_id" placeholder="订单ID" required />
            <button class="btn btn-line" type="submit">查询订单明细与支付记录</button>
          </form>
        </div>
      </section>

      <section class="card${subTabClass(tabKey, 'orders', tabFallback)}">
        <h3>手工开单（后台）</h3>
        <p class="small-note">用于到店现场开单，支持服务项目、自定义项目与订单级优惠。可选“开单后自动生成双码”，直接给客户扫码支付。</p>
        <form id="formOrderCreate" class="form-grid">
          <input name="customer_id" placeholder="客户ID" required />
          <input name="store_id" placeholder="门店ID（可空=客户门店）" />
          <input name="appointment_id" placeholder="预约ID（可空）" />
          <input name="order_discount_amount" placeholder="订单级优惠金额" value="0" />
          <input name="coupon_amount" placeholder="券抵扣金额" value="0" />
          <textarea name="items_json" placeholder='订单明细（JSON格式），示例：[{"item_type":"service","item_ref_id":1,"qty":1,"staff_id":1}]' required>[{"item_type":"custom","item_name":"手工项目","qty":1,"unit_price":199,"discount_amount":0}]</textarea>
          <input name="note" placeholder="备注" value="后台开单" />
          <label class="check-line"><input type="checkbox" name="auto_create_dual_qr" value="1" /><span>创建后自动生成支付宝+微信双码</span></label>
          <button class="btn btn-primary" type="submit">创建订单</button>
        </form>
        <div id="opsOrderQrPreview" class="portal-qr-preview"><div class="small-note">未生成二维码</div></div>
      </section>

      <section class="card${subTabClass(tabKey, 'appointments', tabFallback)}"><h3>预约列表</h3>${table([
        { label: 'ID', key: 'id' },
        { label: '预约号', key: 'appointment_no' },
        { label: '客户', get: (r) => `${r.customer_name || ''} (${r.customer_mobile || ''})` },
        { label: '状态', get: (r) => zhStatus(r.status) },
        { label: '开始', key: 'start_at' },
        { label: '结束', key: 'end_at' },
      ], appointments, { maxRows: 60 })}</section>

      <section class="card${subTabClass(tabKey, 'orders', tabFallback)}"><h3>订单列表</h3>${table([
        { label: 'ID', key: 'id' },
        { label: '订单号', key: 'order_no' },
        { label: '客户', get: (r) => `${r.customer_name || ''} (${r.customer_mobile || ''})` },
        { label: '状态', get: (r) => zhStatus(r.status) },
        { label: '应收', key: 'payable_amount' },
        { label: '实收', key: 'paid_amount' },
        { label: '支付时间', key: 'paid_at' },
      ], orders, { maxRows: 80 })}</section>

      <section class="card${subTabClass(tabKey, 'cards', tabFallback)}"><h3>次卡列表</h3>${table([
        { label: 'ID', key: 'id' },
        { label: '卡号', key: 'card_no' },
        { label: '客户', get: (r) => `${r.customer_name || ''} (${r.customer_mobile || ''})` },
        { label: '剩余/总', get: (r) => `${r.remaining_sessions}/${r.total_sessions}` },
        { label: '状态', get: (r) => zhStatus(r.status) },
        { label: '到期', key: 'expire_at' },
      ], memberCards, { maxRows: 80 })}</section>

      <section class="row${subTabClass(tabKey, 'card_logs', tabFallback)}">
        <article class="card">
          <h3>次卡流水查询</h3>
          <form id="formOpsCardLogsQuery" class="form-grid">
            <input name="store_id" placeholder="门店ID（可空）" />
            <button class="btn btn-line" type="submit">查询次卡流水</button>
          </form>
        </article>
      </section>

      <section class="card${subTabClass(tabKey, 'card_logs', tabFallback)}"><h3>次卡流水列表</h3><div id="opsCardLogsTable">${table([
        { label: 'ID', key: 'id' },
        { label: '卡号', key: 'card_no' },
        { label: '动作', get: (r) => zhActionType(r.action_type) },
        { label: '变更', key: 'delta_sessions' },
        { label: '前值', key: 'before_sessions' },
        { label: '后值', key: 'after_sessions' },
        { label: '备注', key: 'note' },
        { label: '时间', key: 'created_at' },
      ], cardLogs, { maxRows: 150 })}</div></section>

      <section class="card"><h3>操作返回</h3>${jsonBox('opsResult', '等待操作')}</section>
    `;
    bindSubTabNav(tabKey, tabFallback);

    bindJsonForm('formCreateAppointment', 'opsResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/appointments', {
        body: {
          customer_id: toInt(v.customer_id, 0),
          store_id: toInt(v.store_id, 0),
          staff_id: toInt(v.staff_id, 0),
          service_id: toInt(v.service_id, 0),
          start_at: parseDateTimeInput(v.start_at),
          end_at: parseDateTimeInput(v.end_at),
        },
      });
    });

    bindJsonForm('formUpdateAppointment', 'opsResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/appointments/status', {
        body: {
          appointment_id: toInt(v.appointment_id, 0),
          status: v.status,
          member_card_id: toInt(v.member_card_id, 0),
          consume_sessions: toInt(v.consume_sessions, 1),
          consume_note: v.consume_note || '',
        },
      });
    });

    bindJsonForm('formCreateMemberCard', 'opsResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/member-cards', {
        body: {
          customer_id: toInt(v.customer_id, 0),
          package_id: toInt(v.package_id, 0),
          total_sessions: toInt(v.total_sessions, 0),
          sold_price: toFloat(v.sold_price, 0),
        },
      });
    });

    bindJsonForm('formConsumeMemberCard', 'opsResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/member-cards/consume', {
        body: {
          member_card_id: toInt(v.member_card_id, 0),
          consume_sessions: toInt(v.consume_sessions, 1),
          note: v.note || '',
        },
      });
    });

    bindJsonForm('formOrderPay', 'opsResult', async (form) => {
      const v = getFormValues(form);
      const body = {
        order_id: toInt(v.order_id, 0),
        pay_method: v.pay_method || 'cash',
        note: v.note || '',
      };
      if (v.amount !== '') body.amount = toFloat(v.amount, 0);
      return request('POST', '/orders/pay', { body });
    });

    bindJsonForm('formOrderCreate', 'opsResult', async (form) => {
      const v = getFormValues(form);
      const items = parseJsonText(v.items_json, []);
      if (!Array.isArray(items) || items.length === 0) {
        throw new Error('订单明细格式不正确，请填写至少一条项目');
      }
      const orderRes = await request('POST', '/orders', {
        body: {
          customer_id: toInt(v.customer_id, 0),
          store_id: toInt(v.store_id, 0),
          appointment_id: toInt(v.appointment_id, 0),
          order_discount_amount: toFloat(v.order_discount_amount, 0),
          coupon_amount: toFloat(v.coupon_amount, 0),
          items,
          note: v.note || '',
        },
      });

      const preview = document.getElementById('opsOrderQrPreview');
      const orderId = toInt(orderRes && (orderRes.order_id || orderRes.id), 0);
      const payable = toFloat(orderRes && orderRes.payable_amount, 0);
      const shouldDualQr = toInt(v.auto_create_dual_qr, 0) === 1 && orderId > 0 && payable > 0;
      if (!shouldDualQr) {
        if (preview) {
          preview.innerHTML = '<div class="small-note">本次未生成双码</div>';
        }
        return orderRes;
      }

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
            <p><b>支付链接：</b>${row.pay_url ? `<a href="${escapeHtml(row.pay_url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(row.pay_url)}</a>` : '-'}</p>
            <p><b>前台支付页：</b>${row.cashier_url ? `<a href="${escapeHtml(row.cashier_url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(row.cashier_url)}</a>` : '-'}</p>
            ${qrUrl ? `<img src="${escapeHtml(qrUrl)}" alt="${escapeHtml(title)}二维码" />` : '<p class="small-note">该通道未返回二维码</p>'}
          </article>
        `;
      };

      try {
        const dualRes = await request('POST', '/payments/online/create-dual-qr', {
          body: {
            order_id: orderId,
            alipay_scene: 'auto',
            subject: orderRes && orderRes.order_no ? `门店订单 ${orderRes.order_no}` : '',
          },
        });
        if (preview) {
          const ali = dualRes && dualRes.alipay ? dualRes.alipay : null;
          const wx = dualRes && dualRes.wechat ? dualRes.wechat : null;
          const errors = dualRes && dualRes.errors ? dualRes.errors : {};
          const alipayErr = errors.alipay || errors.alipay_f2f || errors.alipay_page || errors.alipay_wap || '';
          preview.innerHTML = `
            <div class="portal-link-grid">
              ${buildCard('支付宝二维码', ali, alipayErr)}
              ${buildCard('微信二维码', wx, errors.wechat || '')}
            </div>
          `;
        }
        return {
          ...orderRes,
          dual_qr: dualRes,
        };
      } catch (err) {
        if (preview) {
          preview.innerHTML = `<div class="small-note">订单已创建，但双码生成失败：${escapeHtml(err.message || '未知错误')}</div>`;
        }
        return {
          ...orderRes,
          dual_qr_error: err.message || '创建双码失败',
        };
      }
    });

    bindJsonForm('formOrderDetail', 'opsResult', async (form) => {
      const v = getFormValues(form);
      const orderId = toInt(v.order_id, 0);
      const [items, payments] = await Promise.all([
        request('GET', '/order-items', { query: { order_id: orderId } }),
        request('GET', '/order-payments', { query: { order_id: orderId } }),
      ]);
      return { order_id: orderId, items: pickData(items), payments: pickData(payments) };
    });

    bindJsonForm('formOpsCardLogsQuery', 'opsResult', async (form) => {
      const v = getFormValues(form);
      const res = await request('GET', '/member-card-logs', {
        query: {
          store_id: toInt(v.store_id, 0),
        },
      });
      const box = document.getElementById('opsCardLogsTable');
      if (box) {
        box.innerHTML = table([
          { label: 'ID', key: 'id' },
          { label: '卡号', key: 'card_no' },
          { label: '动作', get: (r) => zhActionType(r.action_type) },
          { label: '变更', key: 'delta_sessions' },
          { label: '前值', key: 'before_sessions' },
          { label: '后值', key: 'after_sessions' },
          { label: '备注', key: 'note' },
          { label: '时间', key: 'created_at' },
        ], pickData(res), { maxRows: 150 });
      }
      return res;
    });
  }


  return renderOps;
};
