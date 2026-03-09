let runtimeCtx = null;

export function setRenderTradeContext(ctx) {
  runtimeCtx = ctx || null;
}

function toNumber(value, fallback = 0) {
  const num = Number(value);
  return Number.isFinite(num) ? num : fallback;
}

function trimText(value) {
  return String(value == null ? '' : value).trim();
}

function oneLineItem(fd, prefix) {
  const productId = toNumber(fd.get(`${prefix}_product_id`) || 0, 0);
  const itemName = trimText(fd.get(`${prefix}_item_name`) || '');
  const quantity = toNumber(fd.get(`${prefix}_quantity`) || 1, 1);
  const unitPrice = toNumber(fd.get(`${prefix}_unit_price`) || 0, 0);
  const discountRate = toNumber(fd.get(`${prefix}_discount_rate`) || 0, 0);
  const taxRate = toNumber(fd.get(`${prefix}_tax_rate`) || 0, 0);
  const remark = trimText(fd.get(`${prefix}_remark`) || '');

  if (!productId && !itemName) {
    return [];
  }

  return [
    {
      product_id: productId > 0 ? productId : undefined,
      item_name: itemName || undefined,
      quantity,
      unit_price: unitPrice,
      discount_rate: discountRate,
      tax_rate: taxRate,
      remark,
    },
  ];
}

export async function renderTrade() {
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
  } = ctx;

  screenTitle('交易闭环', '产品价格表、报价单、合同、回款计划、开票记录');

  const [productsPayload, quotesPayload, contractsPayload, plansPayload, invoicesPayload] = await Promise.all([
    request('GET', '/crm/trade/products', { query: { limit: 20 } }),
    request('GET', '/crm/trade/quotes', { query: { limit: 20 } }),
    request('GET', '/crm/trade/contracts', { query: { limit: 20 } }),
    request('GET', '/crm/trade/payment-plans', { query: { limit: 20 } }),
    request('GET', '/crm/trade/invoices', { query: { limit: 20 } }),
  ]);

  const products = Array.isArray(productsPayload.data) ? productsPayload.data : [];
  const quotes = Array.isArray(quotesPayload.data) ? quotesPayload.data : [];
  const contracts = Array.isArray(contractsPayload.data) ? contractsPayload.data : [];
  const plans = Array.isArray(plansPayload.data) ? plansPayload.data : [];
  const invoices = Array.isArray(invoicesPayload.data) ? invoicesPayload.data : [];

  el.viewRoot.innerHTML = `
    <section class="card">
      <h3>产品价格表</h3>
      <form id="tradeProductForm" class="grid-3">
        <label><span>产品ID（编辑可填）</span><input name="id" type="number" min="0" /></label>
        <label><span>产品编码</span><input name="product_code" placeholder="不填自动生成" /></label>
        <label><span>产品名称</span><input name="product_name" required /></label>
        <label><span>分类</span><input name="category" placeholder="如：套餐 / 服务 / 耗材" /></label>
        <label><span>币种</span><input name="currency_code" value="CNY" /></label>
        <label><span>标价</span><input name="list_price" type="number" step="0.01" min="0" value="0" /></label>
        <label><span>单位</span><input name="unit" value="项" /></label>
        <label><span>税率(%)</span><input name="tax_rate" type="number" step="0.01" min="0" max="100" value="0" /></label>
        <label><span>状态</span>
          <select name="status">
            <option value="active">启用</option>
            <option value="inactive">停用</option>
          </select>
        </label>
        <label style="grid-column:1 / -1;"><span>描述</span><textarea name="description"></textarea></label>
        <div><button class="btn btn-primary" type="submit">保存产品</button></div>
      </form>
      <div class="table-wrap" style="margin-top:12px;">
        <table>
          <thead><tr><th>ID</th><th>编码</th><th>产品</th><th>分类</th><th>价格</th><th>税率</th><th>状态</th></tr></thead>
          <tbody>
            ${
              products.length
                ? products
                    .map(
                      (row) => `<tr>
                        <td>${escapeHtml(row.id)}</td>
                        <td>${escapeHtml(row.product_code || '-')}</td>
                        <td>${escapeHtml(row.product_name || '-')}</td>
                        <td>${escapeHtml(row.category || '-')}</td>
                        <td>${escapeHtml((row.currency_code || 'CNY') + ' ' + asMoney(row.list_price || 0))}</td>
                        <td>${escapeHtml(String(row.tax_rate || 0))}%</td>
                        <td>${escapeHtml(zhCrmValue(row.status || '-'))}</td>
                      </tr>`
                    )
                    .join('')
                : '<tr><td colspan="7" class="empty">暂无产品</td></tr>'
            }
          </tbody>
        </table>
      </div>
    </section>

    <section class="card" style="margin-top:12px;">
      <h3>报价单</h3>
      <form id="tradeQuoteForm" class="grid-3">
        <label><span>报价ID（编辑可填）</span><input name="id" type="number" min="0" /></label>
        <label><span>商机ID</span><input name="deal_id" type="number" min="1" required /></label>
        <label><span>报价单号</span><input name="quote_no" placeholder="不填自动生成" /></label>
        <label><span>企业ID</span><input name="company_id" type="number" min="0" /></label>
        <label><span>联系人ID</span><input name="contact_id" type="number" min="0" /></label>
        <label><span>负责人ID</span><input name="owner_user_id" type="number" min="0" /></label>
        <label><span>币种</span><input name="currency_code" value="CNY" /></label>
        <label><span>状态</span>
          <select name="status">
            <option value="draft">草稿</option>
            <option value="sent">已发送</option>
            <option value="accepted">已接受</option>
            <option value="rejected">已拒绝</option>
            <option value="expired">已过期</option>
            <option value="cancelled">已作废</option>
          </select>
        </label>
        <label><span>有效期</span><input name="valid_until" placeholder="2026-03-31" /></label>

        <label><span>明细-产品ID</span><input name="quote_product_id" type="number" min="0" /></label>
        <label><span>明细-名称</span><input name="quote_item_name" placeholder="产品名" /></label>
        <label><span>明细-数量</span><input name="quote_quantity" type="number" step="0.01" min="0.01" value="1" /></label>
        <label><span>明细-单价</span><input name="quote_unit_price" type="number" step="0.01" min="0" value="0" /></label>
        <label><span>明细-折扣率%</span><input name="quote_discount_rate" type="number" step="0.01" min="0" max="100" value="0" /></label>
        <label><span>明细-税率%</span><input name="quote_tax_rate" type="number" step="0.01" min="0" max="100" value="0" /></label>
        <label style="grid-column:1 / -1;"><span>备注</span><textarea name="remark"></textarea></label>
        <div><button class="btn btn-primary" type="submit">保存报价单</button></div>
      </form>

      <div class="table-wrap" style="margin-top:12px;">
        <table>
          <thead><tr><th>ID</th><th>报价单号</th><th>商机</th><th>状态</th><th>总金额</th><th>有效期</th></tr></thead>
          <tbody>
            ${
              quotes.length
                ? quotes
                    .map(
                      (row) => `<tr>
                        <td>${escapeHtml(row.id)}</td>
                        <td>${escapeHtml(row.quote_no || '-')}</td>
                        <td>${escapeHtml((row.deal_id || '-') + ' / ' + (row.deal_name || '-'))}</td>
                        <td>${escapeHtml(zhCrmValue(row.status || '-'))}</td>
                        <td>${escapeHtml((row.currency_code || 'CNY') + ' ' + asMoney(row.total_amount || 0))}</td>
                        <td>${escapeHtml(row.valid_until || '-')}</td>
                      </tr>`
                    )
                    .join('')
                : '<tr><td colspan="6" class="empty">暂无报价单</td></tr>'
            }
          </tbody>
        </table>
      </div>
    </section>

    <section class="card" style="margin-top:12px;">
      <h3>合同</h3>
      <form id="tradeContractForm" class="grid-3">
        <label><span>合同ID（编辑可填）</span><input name="id" type="number" min="0" /></label>
        <label><span>商机ID</span><input name="deal_id" type="number" min="1" required /></label>
        <label><span>报价ID</span><input name="quote_id" type="number" min="0" /></label>
        <label><span>合同编号</span><input name="contract_no" placeholder="不填自动生成" /></label>
        <label><span>企业ID</span><input name="company_id" type="number" min="0" /></label>
        <label><span>联系人ID</span><input name="contact_id" type="number" min="0" /></label>
        <label><span>状态</span>
          <select name="status">
            <option value="draft">草稿</option>
            <option value="active">生效中</option>
            <option value="completed">已完成</option>
            <option value="cancelled">已取消</option>
            <option value="expired">已过期</option>
          </select>
        </label>
        <label><span>签署时间</span><input name="signed_at" placeholder="2026-03-08 10:00:00" /></label>
        <label><span>生效时间</span><input name="effective_at" placeholder="2026-03-08 10:00:00" /></label>
        <label><span>到期时间</span><input name="expire_at" placeholder="2027-03-08 10:00:00" /></label>

        <label><span>明细-产品ID</span><input name="contract_product_id" type="number" min="0" /></label>
        <label><span>明细-名称</span><input name="contract_item_name" placeholder="产品名" /></label>
        <label><span>明细-数量</span><input name="contract_quantity" type="number" step="0.01" min="0.01" value="1" /></label>
        <label><span>明细-单价</span><input name="contract_unit_price" type="number" step="0.01" min="0" value="0" /></label>
        <label><span>明细-折扣率%</span><input name="contract_discount_rate" type="number" step="0.01" min="0" max="100" value="0" /></label>
        <label><span>明细-税率%</span><input name="contract_tax_rate" type="number" step="0.01" min="0" max="100" value="0" /></label>
        <label style="grid-column:1 / -1;"><span>备注</span><textarea name="remark"></textarea></label>
        <div><button class="btn btn-primary" type="submit">保存合同</button></div>
      </form>

      <div class="table-wrap" style="margin-top:12px;">
        <table>
          <thead><tr><th>ID</th><th>合同号</th><th>商机</th><th>状态</th><th>总金额</th><th>签署时间</th></tr></thead>
          <tbody>
            ${
              contracts.length
                ? contracts
                    .map(
                      (row) => `<tr>
                        <td>${escapeHtml(row.id)}</td>
                        <td>${escapeHtml(row.contract_no || '-')}</td>
                        <td>${escapeHtml((row.deal_id || '-') + ' / ' + (row.deal_name || '-'))}</td>
                        <td>${escapeHtml(zhCrmValue(row.status || '-'))}</td>
                        <td>${escapeHtml((row.currency_code || 'CNY') + ' ' + asMoney(row.total_amount || 0))}</td>
                        <td>${escapeHtml(row.signed_at || '-')}</td>
                      </tr>`
                    )
                    .join('')
                : '<tr><td colspan="6" class="empty">暂无合同</td></tr>'
            }
          </tbody>
        </table>
      </div>
    </section>

    <section class="card" style="margin-top:12px;">
      <h3>回款计划</h3>
      <form id="tradePlanForm" class="grid-3">
        <label><span>计划ID（编辑可填）</span><input name="id" type="number" min="0" /></label>
        <label><span>合同ID（必填）</span><input name="contract_id" type="number" min="1" required /></label>
        <label><span>商机ID（可空，自动取合同）</span><input name="deal_id" type="number" min="1" /></label>
        <label><span>里程碑名称</span><input name="milestone_name" required /></label>
        <label><span>计划回款日期</span><input name="due_date" placeholder="2026-03-31" /></label>
        <label><span>计划金额</span><input name="amount" type="number" step="0.01" min="0" value="0" /></label>
        <label><span>已回款金额</span><input name="paid_amount" type="number" step="0.01" min="0" value="0" /></label>
        <label><span>状态</span>
          <select name="status">
            <option value="pending">待回款</option>
            <option value="partial">部分回款</option>
            <option value="paid">已回款</option>
            <option value="overdue">逾期</option>
            <option value="cancelled">已取消</option>
          </select>
        </label>
        <label><span>回款时间</span><input name="paid_at" placeholder="2026-03-08 12:00:00" /></label>
        <label style="grid-column:1 / -1;"><span>回款说明</span><textarea name="payment_note"></textarea></label>
        <div><button class="btn btn-primary" type="submit">保存回款计划</button></div>
      </form>

      <div class="table-wrap" style="margin-top:12px;">
        <table>
          <thead><tr><th>ID</th><th>里程碑</th><th>商机</th><th>状态</th><th>应收</th><th>已收</th><th>到期</th></tr></thead>
          <tbody>
            ${
              plans.length
                ? plans
                    .map(
                      (row) => `<tr>
                        <td>${escapeHtml(row.id)}</td>
                        <td>${escapeHtml(row.milestone_name || '-')}</td>
                        <td>${escapeHtml((row.deal_id || '-') + ' / ' + (row.deal_name || '-'))}</td>
                        <td>${escapeHtml(zhCrmValue(row.status || '-'))}</td>
                        <td>${escapeHtml((row.currency_code || 'CNY') + ' ' + asMoney(row.amount || 0))}</td>
                        <td>${escapeHtml((row.currency_code || 'CNY') + ' ' + asMoney(row.paid_amount || 0))}</td>
                        <td>${escapeHtml(row.due_date || '-')}</td>
                      </tr>`
                    )
                    .join('')
                : '<tr><td colspan="7" class="empty">暂无回款计划</td></tr>'
            }
          </tbody>
        </table>
      </div>
    </section>

    <section class="card" style="margin-top:12px;">
      <h3>开票记录</h3>
      <form id="tradeInvoiceForm" class="grid-3">
        <label><span>发票ID（编辑可填）</span><input name="id" type="number" min="0" /></label>
        <label><span>合同ID（必填）</span><input name="contract_id" type="number" min="1" required /></label>
        <label><span>商机ID（可空，自动取合同）</span><input name="deal_id" type="number" min="1" /></label>
        <label><span>企业ID</span><input name="company_id" type="number" min="0" /></label>
        <label><span>发票号</span><input name="invoice_no" placeholder="不填自动生成" /></label>
        <label><span>金额</span><input name="amount" type="number" step="0.01" min="0" value="0" required /></label>
        <label><span>币种</span><input name="currency_code" value="CNY" /></label>
        <label><span>开票日期</span><input name="issue_date" placeholder="2026-03-08" /></label>
        <label><span>应付日期</span><input name="due_date" placeholder="2026-03-31" /></label>
        <label><span>状态</span>
          <select name="status">
            <option value="draft">草稿</option>
            <option value="issued">已开票</option>
            <option value="sent">已寄送</option>
            <option value="paid">已支付</option>
            <option value="overdue">逾期</option>
            <option value="cancelled">已取消</option>
          </select>
        </label>
        <label><span>抬头名称</span><input name="receiver_name" /></label>
        <label><span>税号</span><input name="receiver_tax_no" /></label>
        <label style="grid-column:1 / -1;"><span>备注</span><textarea name="note"></textarea></label>
        <div><button class="btn btn-primary" type="submit">保存开票记录</button></div>
      </form>

      <div class="table-wrap" style="margin-top:12px;">
        <table>
          <thead><tr><th>ID</th><th>发票号</th><th>商机</th><th>状态</th><th>金额</th><th>开票日期</th><th>到期日期</th></tr></thead>
          <tbody>
            ${
              invoices.length
                ? invoices
                    .map(
                      (row) => `<tr>
                        <td>${escapeHtml(row.id)}</td>
                        <td>${escapeHtml(row.invoice_no || '-')}</td>
                        <td>${escapeHtml((row.deal_id || '-') + ' / ' + (row.deal_name || '-'))}</td>
                        <td>${escapeHtml(zhCrmValue(row.status || '-'))}</td>
                        <td>${escapeHtml((row.currency_code || 'CNY') + ' ' + asMoney(row.amount || 0))}</td>
                        <td>${escapeHtml(row.issue_date || '-')}</td>
                        <td>${escapeHtml(row.due_date || '-')}</td>
                      </tr>`
                    )
                    .join('')
                : '<tr><td colspan="7" class="empty">暂无开票记录</td></tr>'
            }
          </tbody>
        </table>
      </div>
    </section>
  `;

  const productForm = $('tradeProductForm');
  if (productForm) {
    productForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(productForm);
      const body = Object.fromEntries(fd.entries());
      if (!body.id) delete body.id;
      if (!body.product_code) delete body.product_code;
      try {
        await request('POST', '/crm/trade/products', { body });
        toast('产品已保存');
        await renderTrade();
      } catch (err) {
        toast(err.message || '保存失败', true);
      }
    });
  }

  const quoteForm = $('tradeQuoteForm');
  if (quoteForm) {
    quoteForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(quoteForm);
      const body = Object.fromEntries(fd.entries());
      if (!body.id) delete body.id;
      if (!body.quote_no) delete body.quote_no;
      if (!body.company_id) delete body.company_id;
      if (!body.contact_id) delete body.contact_id;
      if (!body.owner_user_id) delete body.owner_user_id;
      body.items = oneLineItem(fd, 'quote');
      try {
        await request('POST', '/crm/trade/quotes', { body });
        toast('报价单已保存');
        await renderTrade();
      } catch (err) {
        toast(err.message || '保存失败', true);
      }
    });
  }

  const contractForm = $('tradeContractForm');
  if (contractForm) {
    contractForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(contractForm);
      const body = Object.fromEntries(fd.entries());
      if (!body.id) delete body.id;
      if (!body.contract_no) delete body.contract_no;
      if (!body.quote_id) delete body.quote_id;
      if (!body.company_id) delete body.company_id;
      if (!body.contact_id) delete body.contact_id;
      body.items = oneLineItem(fd, 'contract');
      try {
        await request('POST', '/crm/trade/contracts', { body });
        toast('合同已保存');
        await renderTrade();
      } catch (err) {
        toast(err.message || '保存失败', true);
      }
    });
  }

  const planForm = $('tradePlanForm');
  if (planForm) {
    planForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(planForm).entries());
      if (!body.id) delete body.id;
      try {
        await request('POST', '/crm/trade/payment-plans', { body });
        toast('回款计划已保存');
        await renderTrade();
      } catch (err) {
        toast(err.message || '保存失败', true);
      }
    });
  }

  const invoiceForm = $('tradeInvoiceForm');
  if (invoiceForm) {
    invoiceForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(invoiceForm).entries());
      if (!body.id) delete body.id;
      if (!body.invoice_no) delete body.invoice_no;
      if (!body.company_id) delete body.company_id;
      try {
        await request('POST', '/crm/trade/invoices', { body });
        toast('开票记录已保存');
        await renderTrade();
      } catch (err) {
        toast(err.message || '保存失败', true);
      }
    });
  }
}
