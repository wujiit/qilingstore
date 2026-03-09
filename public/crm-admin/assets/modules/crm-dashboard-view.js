let runtimeCtx = null;

export function setRenderDashboardContext(ctx) {
  runtimeCtx = ctx || null;
}

export async function renderDashboard() {
  const ctx = runtimeCtx || {};
  const {
    screenTitle,
    request,
    el,
    escapeHtml,
    asMoney,
  } = ctx;
    screenTitle('CRM 总览', '线索、商机、任务与成交概览');
    const payload = await request('GET', '/crm/dashboard');
    const s = (payload && payload.summary) || {};
    el.viewRoot.innerHTML = `
      <div class="kpi-grid">
        <article class="kpi"><small>线索总数</small><b>${escapeHtml(s.leads_total || 0)}</b></article>
        <article class="kpi"><small>新线索</small><b>${escapeHtml(s.leads_new || 0)}</b></article>
        <article class="kpi"><small>商机总数</small><b>${escapeHtml(s.deals_total || 0)}</b></article>
        <article class="kpi"><small>进行中商机</small><b>${escapeHtml(s.deals_open || 0)}</b></article>
        <article class="kpi"><small>赢单金额</small><b>${escapeHtml(asMoney(s.deals_won_amount))}</b></article>
      </div>
      <div class="grid-3" style="margin-top:12px;">
        <section class="card"><h3>线索进度</h3>
          <p>已确认需求：<b>${escapeHtml(s.leads_qualified || 0)}</b></p>
          <p>已转化：<b>${escapeHtml(s.leads_converted || 0)}</b></p>
        </section>
        <section class="card"><h3>客户资产</h3>
          <p>企业：<b>${escapeHtml(s.companies_total || 0)}</b></p>
          <p>联系人：<b>${escapeHtml(s.contacts_total || 0)}</b></p>
        </section>
        <section class="card"><h3>跟进任务</h3>
          <p>待办：<b>${escapeHtml(s.activities_todo || 0)}</b></p>
          <p>超期：<b>${escapeHtml(s.activities_overdue || 0)}</b></p>
        </section>
      </div>
    `;
  }

