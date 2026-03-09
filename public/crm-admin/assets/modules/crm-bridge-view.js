let runtimeCtx = null;

export function setRenderBridgeContext(ctx) {
  runtimeCtx = ctx || null;
}

function trimText(value) {
  return String(value == null ? '' : value).trim();
}

function numberOr(value, fallback = 0) {
  const n = Number(value);
  return Number.isFinite(n) ? n : fallback;
}

function toIntText(value) {
  const t = trimText(value);
  if (!t) return '';
  const n = Number(t);
  if (!Number.isFinite(n) || n <= 0) return '';
  return String(Math.floor(n));
}

function summaryRow(label, value, escapeHtml) {
  return `<div><small>${escapeHtml(label)}</small><div><b>${escapeHtml(value)}</b></div></div>`;
}

export async function renderBridge() {
  const ctx = runtimeCtx || {};
  const {
    screenTitle,
    request,
    el,
    escapeHtml,
    optionSelected,
    $, 
    toast,
    asMoney,
    zhCrmValue,
    uiState,
    setFilters,
    readFormFilters,
  } = ctx;

  screenTitle('客户360', '门店会员与CRM主数据桥接（企业/联系人/会员映射）');

  const ui = uiState('bridge');
  const filters = ui.filters || {};
  const lookup = ui.lookup || {};

  const linkQuery = {
    limit: 100,
    q: trimText(filters.q || ''),
    customer_id: toIntText(filters.customer_id || ''),
    crm_contact_id: toIntText(filters.crm_contact_id || ''),
    crm_company_id: toIntText(filters.crm_company_id || ''),
    status: trimText(filters.status || ''),
  };

  const linksPayload = await request('GET', '/crm/bridge/links', { query: linkQuery });
  const links = Array.isArray(linksPayload.data) ? linksPayload.data : [];

  const lookupQuery = {
    customer_id: toIntText(lookup.customer_id || ''),
    link_id: toIntText(lookup.link_id || ''),
    crm_contact_id: toIntText(lookup.crm_contact_id || ''),
    crm_company_id: toIntText(lookup.crm_company_id || ''),
  };

  let customer360 = null;
  let customer360Error = '';
  if (lookupQuery.customer_id || lookupQuery.link_id || lookupQuery.crm_contact_id || lookupQuery.crm_company_id) {
    try {
      customer360 = await request('GET', '/crm/bridge/customer-360', { query: lookupQuery });
    } catch (err) {
      customer360Error = err && err.message ? String(err.message) : '加载失败';
    }
  }

  const mappingRows = customer360 && customer360.mapping && Array.isArray(customer360.mapping.links)
    ? customer360.mapping.links
    : [];
  const storeSummary = customer360 && customer360.store_summary ? customer360.store_summary : {};
  const crmSummary = customer360 && customer360.crm_summary ? customer360.crm_summary : {};
  const storeRecent = customer360 && customer360.store_recent ? customer360.store_recent : {};
  const crmRecent = customer360 && customer360.crm_recent ? customer360.crm_recent : {};

  el.viewRoot.innerHTML = `
    <section class="card">
      <h3>映射筛选</h3>
      <form id="bridgeFilterForm" class="toolbar">
        <input name="q" placeholder="搜索 会员/联系人/企业" value="${escapeHtml(linkQuery.q)}" />
        <input name="customer_id" placeholder="会员ID" value="${escapeHtml(linkQuery.customer_id)}" />
        <input name="crm_contact_id" placeholder="联系人ID" value="${escapeHtml(linkQuery.crm_contact_id)}" />
        <input name="crm_company_id" placeholder="企业ID" value="${escapeHtml(linkQuery.crm_company_id)}" />
        <select name="status">
          <option value="">全部状态</option>
          <option value="active"${optionSelected(linkQuery.status, 'active')}>启用</option>
          <option value="disabled"${optionSelected(linkQuery.status, 'disabled')}>停用</option>
        </select>
        <button class="btn btn-primary" type="submit">筛选</button>
        <button class="btn btn-ghost" type="button" id="bridgeFilterReset">重置</button>
      </form>
    </section>

    <section class="card" style="margin-top:12px;">
      <h3>新建/编辑映射</h3>
      <form id="bridgeLinkForm" class="grid-3">
        <label><span>映射ID（编辑可填）</span><input name="id" type="number" min="0" /></label>
        <label><span>门店会员ID</span><input name="customer_id" type="number" min="1" required /></label>
        <label><span>CRM联系人ID</span><input name="crm_contact_id" type="number" min="0" /></label>
        <label><span>CRM企业ID</span><input name="crm_company_id" type="number" min="0" /></label>
        <label><span>匹配规则</span>
          <select name="match_rule">
            <option value="manual">手动</option>
            <option value="mobile">手机号</option>
            <option value="email">邮箱</option>
            <option value="name">姓名</option>
          </select>
        </label>
        <label><span>状态</span>
          <select name="status">
            <option value="active">启用</option>
            <option value="disabled">停用</option>
          </select>
        </label>
        <label style="grid-column:1 / -1;"><span>备注</span><textarea name="note"></textarea></label>
        <div>
          <button class="btn btn-primary" type="submit">保存映射</button>
          <button class="btn btn-ghost" type="button" id="bridgeLinkClear">清空</button>
        </div>
      </form>

      <div class="table-wrap" style="margin-top:12px;">
        <table>
          <thead><tr><th>ID</th><th>门店会员</th><th>CRM联系人</th><th>CRM企业</th><th>匹配</th><th>状态</th><th>更新时间</th><th>操作</th></tr></thead>
          <tbody>
            ${
              links.length
                ? links
                    .map(
                      (row, idx) => `<tr>
                        <td>${escapeHtml(row.id)}</td>
                        <td>${escapeHtml(`#${row.customer_id || 0} ${row.customer_name || '-'} (${row.customer_mobile || '-'})`)}</td>
                        <td>${escapeHtml(row.crm_contact_id ? `#${row.crm_contact_id} ${row.contact_name || '-'}` : '-')}</td>
                        <td>${escapeHtml(row.crm_company_id ? `#${row.crm_company_id} ${row.company_name || '-'}` : '-')}</td>
                        <td>${escapeHtml(zhCrmValue(row.match_rule || '-'))}</td>
                        <td>${escapeHtml(zhCrmValue(row.status || '-'))}</td>
                        <td>${escapeHtml(row.updated_at || '-')}</td>
                        <td>
                          <button class="btn btn-ghost" type="button" data-link-fill="${idx}">编辑</button>
                          <button class="btn btn-ghost" type="button" data-link-360="${idx}">客户360</button>
                        </td>
                      </tr>`
                    )
                    .join('')
                : '<tr><td colspan="8" class="empty">暂无映射</td></tr>'
            }
          </tbody>
        </table>
      </div>
    </section>

    <section class="card" style="margin-top:12px;">
      <h3>客户360查询</h3>
      <form id="bridgeLookupForm" class="toolbar">
        <input name="customer_id" placeholder="会员ID" value="${escapeHtml(lookupQuery.customer_id)}" />
        <input name="link_id" placeholder="映射ID" value="${escapeHtml(lookupQuery.link_id)}" />
        <input name="crm_contact_id" placeholder="联系人ID" value="${escapeHtml(lookupQuery.crm_contact_id)}" />
        <input name="crm_company_id" placeholder="企业ID" value="${escapeHtml(lookupQuery.crm_company_id)}" />
        <button class="btn btn-primary" type="submit">加载客户360</button>
        <button class="btn btn-ghost" type="button" id="bridgeLookupReset">清空</button>
      </form>

      ${
        customer360Error
          ? `<p class="empty">${escapeHtml(customer360Error)}</p>`
          : customer360 && customer360.customer
            ? `<div style="margin-top:10px;">
                <h4>基础资料</h4>
                <div class="grid-3">
                  ${summaryRow('会员ID', `#${customer360.customer.id || 0}`, escapeHtml)}
                  ${summaryRow('会员编号', customer360.customer.customer_no || '-', escapeHtml)}
                  ${summaryRow('姓名', customer360.customer.name || '-', escapeHtml)}
                  ${summaryRow('手机号', customer360.customer.mobile || '-', escapeHtml)}
                  ${summaryRow('门店ID', String(customer360.customer.store_id || 0), escapeHtml)}
                  ${summaryRow('来源渠道', zhCrmValue(customer360.customer.source_channel || '-'), escapeHtml)}
                  ${summaryRow('累计消费', asMoney(customer360.customer.total_spent || 0), escapeHtml)}
                  ${summaryRow('到店次数', String(customer360.customer.visit_count || 0), escapeHtml)}
                  ${summaryRow('状态', zhCrmValue(customer360.customer.status || '-'), escapeHtml)}
                </div>

                <h4 style="margin-top:14px;">门店经营数据</h4>
                <div class="grid-3">
                  ${summaryRow('订单总数', String(storeSummary.total_orders || 0), escapeHtml)}
                  ${summaryRow('已支付订单', String(storeSummary.paid_orders || 0), escapeHtml)}
                  ${summaryRow('支付金额', asMoney(storeSummary.paid_amount || 0), escapeHtml)}
                  ${summaryRow('预约总数', String(storeSummary.total_appointments || 0), escapeHtml)}
                  ${summaryRow('已完成预约', String(storeSummary.completed_appointments || 0), escapeHtml)}
                  ${summaryRow('取消预约', String(storeSummary.cancelled_appointments || 0), escapeHtml)}
                  ${summaryRow('有效卡数', String(storeSummary.active_cards || 0), escapeHtml)}
                  ${summaryRow('剩余疗程', String(storeSummary.remaining_sessions || 0), escapeHtml)}
                  ${summaryRow('逾期回访', String(storeSummary.overdue_followups || 0), escapeHtml)}
                </div>

                <h4 style="margin-top:14px;">CRM经营数据</h4>
                <div class="grid-3">
                  ${summaryRow('关联企业数', String(crmSummary.companies_total || 0), escapeHtml)}
                  ${summaryRow('关联联系人数', String(crmSummary.contacts_total || 0), escapeHtml)}
                  ${summaryRow('关联线索数', String(crmSummary.leads_total || 0), escapeHtml)}
                  ${summaryRow('关联商机数', String(crmSummary.deals_total || 0), escapeHtml)}
                  ${summaryRow('赢单数', String(crmSummary.deals_won || 0), escapeHtml)}
                  ${summaryRow('赢单金额', asMoney(crmSummary.deals_won_amount || 0), escapeHtml)}
                  ${summaryRow('跟进总数', String(crmSummary.activities_total || 0), escapeHtml)}
                  ${summaryRow('待办跟进', String(crmSummary.activities_todo || 0), escapeHtml)}
                  ${summaryRow('逾期跟进', String(crmSummary.activities_overdue || 0), escapeHtml)}
                </div>

                <h4 style="margin-top:14px;">映射记录</h4>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>联系人</th><th>企业</th><th>匹配</th><th>状态</th><th>更新时间</th></tr></thead>
                    <tbody>
                      ${
                        mappingRows.length
                          ? mappingRows
                              .map(
                                (row) => `<tr>
                                  <td>${escapeHtml(row.id)}</td>
                                  <td>${escapeHtml(row.crm_contact_id ? `#${row.crm_contact_id} ${row.contact_name || '-'}` : '-')}</td>
                                  <td>${escapeHtml(row.crm_company_id ? `#${row.crm_company_id} ${row.company_name || '-'}` : '-')}</td>
                                  <td>${escapeHtml(zhCrmValue(row.match_rule || '-'))}</td>
                                  <td>${escapeHtml(zhCrmValue(row.status || '-'))}</td>
                                  <td>${escapeHtml(row.updated_at || '-')}</td>
                                </tr>`
                              )
                              .join('')
                          : '<tr><td colspan="6" class="empty">暂无映射记录</td></tr>'
                      }
                    </tbody>
                  </table>
                </div>

                <h4 style="margin-top:14px;">交易闭环（最近）</h4>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>商机</th><th>报价单</th><th>合同</th><th>回款计划</th><th>发票</th></tr></thead>
                    <tbody>
                      <tr>
                        <td>${escapeHtml(String((crmRecent.deals || []).length || 0))}</td>
                        <td>${escapeHtml(String((crmRecent.quotes || []).length || 0))}</td>
                        <td>${escapeHtml(String((crmRecent.contracts || []).length || 0))}</td>
                        <td>${escapeHtml(String((crmRecent.payment_plans || []).length || 0))}</td>
                        <td>${escapeHtml(String((crmRecent.invoices || []).length || 0))}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <h4 style="margin-top:14px;">门店订单（最近10条）</h4>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>订单号</th><th>状态</th><th>应付</th><th>实付</th><th>支付时间</th></tr></thead>
                    <tbody>
                      ${
                        Array.isArray(storeRecent.orders) && storeRecent.orders.length
                          ? storeRecent.orders
                              .map(
                                (row) => `<tr>
                                  <td>${escapeHtml(row.order_no || '-')}</td>
                                  <td>${escapeHtml(zhCrmValue(row.status || '-'))}</td>
                                  <td>${escapeHtml(asMoney(row.payable_amount || 0))}</td>
                                  <td>${escapeHtml(asMoney(row.paid_amount || 0))}</td>
                                  <td>${escapeHtml(row.paid_at || '-')}</td>
                                </tr>`
                              )
                              .join('')
                          : '<tr><td colspan="5" class="empty">暂无订单</td></tr>'
                      }
                    </tbody>
                  </table>
                </div>

                <h4 style="margin-top:14px;">CRM商机（最近20条）</h4>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>商机</th><th>状态</th><th>阶段</th><th>金额</th><th>预期签单</th></tr></thead>
                    <tbody>
                      ${
                        Array.isArray(crmRecent.deals) && crmRecent.deals.length
                          ? crmRecent.deals
                              .map(
                                (row) => `<tr>
                                  <td>${escapeHtml(`#${row.id || 0} ${row.deal_name || '-'}`)}</td>
                                  <td>${escapeHtml(zhCrmValue(row.deal_status || '-'))}</td>
                                  <td>${escapeHtml(zhCrmValue(row.stage_key || '-'))}</td>
                                  <td>${escapeHtml((row.currency_code || 'CNY') + ' ' + asMoney(row.amount || 0))}</td>
                                  <td>${escapeHtml(row.expected_close_date || '-')}</td>
                                </tr>`
                              )
                              .join('')
                          : '<tr><td colspan="5" class="empty">暂无商机</td></tr>'
                      }
                    </tbody>
                  </table>
                </div>
              </div>`
            : '<p class="empty">输入会员ID / 映射ID / CRM联系人ID 后加载客户360</p>'
      }
    </section>
  `;

  const filterForm = $('bridgeFilterForm');
  if (filterForm) {
    filterForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      setFilters('bridge', readFormFilters(filterForm));
      await renderBridge();
    });
  }

  const filterReset = $('bridgeFilterReset');
  if (filterReset) {
    filterReset.addEventListener('click', async () => {
      if (filterForm) {
        filterForm.reset();
      }
      setFilters('bridge', {});
      await renderBridge();
    });
  }

  const linkForm = $('bridgeLinkForm');
  if (linkForm) {
    linkForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(linkForm);
      const body = {
        id: toIntText(fd.get('id')),
        customer_id: toIntText(fd.get('customer_id')),
        crm_contact_id: toIntText(fd.get('crm_contact_id')),
        crm_company_id: toIntText(fd.get('crm_company_id')),
        match_rule: trimText(fd.get('match_rule') || 'manual'),
        status: trimText(fd.get('status') || 'active'),
        note: trimText(fd.get('note') || ''),
      };
      if (!body.id) delete body.id;
      if (!body.crm_contact_id) delete body.crm_contact_id;
      if (!body.crm_company_id) delete body.crm_company_id;

      try {
        await request('POST', '/crm/bridge/links', { body });
        toast('映射已保存');
        ui.lookup = { customer_id: body.customer_id };
        await renderBridge();
      } catch (err) {
        toast(err.message || '保存失败', true);
      }
    });
  }

  const linkClear = $('bridgeLinkClear');
  if (linkClear && linkForm) {
    linkClear.addEventListener('click', () => {
      linkForm.reset();
    });
  }

  const fillForm = (row) => {
    if (!linkForm || !row) return;
    linkForm.id.value = row.id || '';
    linkForm.customer_id.value = row.customer_id || '';
    linkForm.crm_contact_id.value = row.crm_contact_id || '';
    linkForm.crm_company_id.value = row.crm_company_id || '';
    linkForm.match_rule.value = row.match_rule || 'manual';
    linkForm.status.value = row.status || 'active';
    linkForm.note.value = row.note || '';
  };

  el.viewRoot.querySelectorAll('[data-link-fill]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const idx = numberOr(btn.getAttribute('data-link-fill'), -1);
      if (idx < 0 || idx >= links.length) return;
      fillForm(links[idx]);
      toast('已载入映射到表单');
    });
  });

  el.viewRoot.querySelectorAll('[data-link-360]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const idx = numberOr(btn.getAttribute('data-link-360'), -1);
      if (idx < 0 || idx >= links.length) return;
      const row = links[idx] || {};
      ui.lookup = { link_id: String(row.id || '') };
      await renderBridge();
    });
  });

  const lookupForm = $('bridgeLookupForm');
  if (lookupForm) {
    lookupForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      ui.lookup = readFormFilters(lookupForm);
      await renderBridge();
    });
  }

  const lookupReset = $('bridgeLookupReset');
  if (lookupReset) {
    lookupReset.addEventListener('click', async () => {
      if (lookupForm) {
        lookupForm.reset();
      }
      ui.lookup = {};
      await renderBridge();
    });
  }
}
