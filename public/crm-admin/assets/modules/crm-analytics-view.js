let runtimeCtx = null;

export function setRenderAnalyticsContext(ctx) {
  runtimeCtx = ctx || null;
}

function trimText(value) {
  return String(value == null ? '' : value).trim();
}

function asPercent(value) {
  const num = Number(value || 0);
  if (!Number.isFinite(num)) return '0%';
  return `${num.toFixed(2)}%`;
}

export async function renderAnalytics() {
  const ctx = runtimeCtx || {};
  const {
    screenTitle,
    request,
    el,
    escapeHtml,
    $, 
    toast,
    asMoney,
    uiState,
    setFilters,
    readFormFilters,
    optionSelected,
  } = ctx;

  screenTitle('漏斗与阶段分析', '阶段转化率、停留时长、渠道/地区/负责人趋势');

  const ui = uiState('analytics');
  const filters = ui.filters || {};
  const query = {
    owner_user_id: trimText(filters.owner_user_id || ''),
    pipeline_key: trimText(filters.pipeline_key || 'default'),
    date_from: trimText(filters.date_from || ''),
    date_to: trimText(filters.date_to || ''),
    dimension: trimText(filters.dimension || 'channel'),
    months: trimText(filters.months || '6'),
  };

  const [funnelRes, durationRes, trendRes] = await Promise.allSettled([
    request('GET', '/crm/dashboard/funnel', {
      query: {
        owner_user_id: query.owner_user_id,
        pipeline_key: query.pipeline_key,
        date_from: query.date_from,
        date_to: query.date_to,
      },
    }),
    request('GET', '/crm/dashboard/stage-duration', {
      query: {
        owner_user_id: query.owner_user_id,
        pipeline_key: query.pipeline_key,
        date_from: query.date_from,
        date_to: query.date_to,
      },
    }),
    request('GET', '/crm/dashboard/trends', {
      query: {
        owner_user_id: query.owner_user_id,
        pipeline_key: query.pipeline_key,
        dimension: query.dimension,
        months: query.months,
      },
    }),
  ]);

  const funnelErr = funnelRes.status === 'rejected' ? (funnelRes.reason && funnelRes.reason.message ? String(funnelRes.reason.message) : '加载失败') : '';
  const durationErr = durationRes.status === 'rejected' ? (durationRes.reason && durationRes.reason.message ? String(durationRes.reason.message) : '加载失败') : '';
  const trendErr = trendRes.status === 'rejected' ? (trendRes.reason && trendRes.reason.message ? String(trendRes.reason.message) : '加载失败') : '';

  const funnel = funnelRes.status === 'fulfilled' ? funnelRes.value : { summary: {}, data: [] };
  const durations = durationRes.status === 'fulfilled' ? durationRes.value : { data: [] };
  const trends = trendRes.status === 'fulfilled' ? trendRes.value : { timeline: [], series: [] };

  const funnelRows = Array.isArray(funnel.data) ? funnel.data : [];
  const durationRows = Array.isArray(durations.data) ? durations.data : [];
  const trendSeries = Array.isArray(trends.series) ? trends.series : [];
  const timeline = Array.isArray(trends.timeline) ? trends.timeline : [];

  el.viewRoot.innerHTML = `
    <section class="card">
      <h3>分析筛选</h3>
      <form id="analyticsFilterForm" class="toolbar">
        <input name="owner_user_id" placeholder="负责人ID（可空）" value="${escapeHtml(query.owner_user_id)}" />
        <input name="pipeline_key" placeholder="管道key，默认default" value="${escapeHtml(query.pipeline_key)}" />
        <input name="date_from" placeholder="开始日期 2026-03-01" value="${escapeHtml(query.date_from)}" />
        <input name="date_to" placeholder="结束日期 2026-03-31" value="${escapeHtml(query.date_to)}" />
        <select name="dimension">
          <option value="channel"${optionSelected(query.dimension, 'channel')}>按渠道</option>
          <option value="region"${optionSelected(query.dimension, 'region')}>按地区</option>
          <option value="owner"${optionSelected(query.dimension, 'owner')}>按负责人</option>
        </select>
        <input name="months" type="number" min="1" max="24" value="${escapeHtml(query.months)}" />
        <button class="btn btn-primary" type="submit">刷新分析</button>
        <button class="btn btn-ghost" type="button" id="analyticsFilterReset">重置</button>
      </form>
    </section>

    <section class="card" style="margin-top:12px;">
      <h3>漏斗转化率</h3>
      ${
        funnelErr
          ? `<p class="empty">${escapeHtml(funnelErr)}</p>`
          : `<div class="toolbar" style="margin-bottom:8px;">
              <small>商机总数：<b>${escapeHtml((funnel.summary && funnel.summary.deals_total) || 0)}</b></small>
              <small>赢单：<b>${escapeHtml((funnel.summary && funnel.summary.won_count) || 0)}</b></small>
              <small>输单：<b>${escapeHtml((funnel.summary && funnel.summary.lost_count) || 0)}</b></small>
              <small>赢单金额：<b>${escapeHtml(asMoney((funnel.summary && funnel.summary.won_amount) || 0))}</b></small>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>阶段</th><th>当前数</th><th>进入数</th><th>推进数</th><th>转化率</th></tr></thead>
                <tbody>
                  ${
                    funnelRows.length
                      ? funnelRows
                          .map(
                            (row) => `<tr>
                              <td>${escapeHtml(row.stage_name || row.stage_key || '-')}</td>
                              <td>${escapeHtml(row.current_count || 0)}</td>
                              <td>${escapeHtml(row.entered_count || 0)}</td>
                              <td>${escapeHtml(row.progressed_count || 0)}</td>
                              <td>${escapeHtml(asPercent(row.conversion_rate || 0))}</td>
                            </tr>`
                          )
                          .join('')
                      : '<tr><td colspan="5" class="empty">暂无漏斗数据</td></tr>'
                  }
                </tbody>
              </table>
            </div>`
      }
    </section>

    <section class="card" style="margin-top:12px;">
      <h3>阶段停留时长</h3>
      ${
        durationErr
          ? `<p class="empty">${escapeHtml(durationErr)}</p>`
          : `<div class="table-wrap">
              <table>
                <thead><tr><th>阶段</th><th>流转次数</th><th>平均停留(小时)</th><th>平均停留(秒)</th><th>累计停留(小时)</th></tr></thead>
                <tbody>
                  ${
                    durationRows.length
                      ? durationRows
                          .map(
                            (row) => `<tr>
                              <td>${escapeHtml(row.stage_name || row.stage_key || '-')}</td>
                              <td>${escapeHtml(row.transition_count || 0)}</td>
                              <td>${escapeHtml(row.avg_duration_hours || 0)}</td>
                              <td>${escapeHtml(row.avg_duration_seconds || 0)}</td>
                              <td>${escapeHtml(row.total_duration_hours || 0)}</td>
                            </tr>`
                          )
                          .join('')
                      : '<tr><td colspan="5" class="empty">暂无阶段时长数据</td></tr>'
                  }
                </tbody>
              </table>
            </div>`
      }
    </section>

    <section class="card" style="margin-top:12px;">
      <h3>趋势分析（${escapeHtml(query.dimension === 'region' ? '地区' : query.dimension === 'owner' ? '负责人' : '渠道')}）</h3>
      ${
        trendErr
          ? `<p class="empty">${escapeHtml(trendErr)}</p>`
          : `<p class="muted">时间轴：${escapeHtml(timeline.join(' / ') || '-')}</p>
            <div class="table-wrap">
              <table>
                <thead><tr><th>维度</th><th>商机总数</th><th>赢单数</th><th>赢单金额</th><th>月度明细</th></tr></thead>
                <tbody>
                  ${
                    trendSeries.length
                      ? trendSeries
                          .map((series) => {
                            const points = Array.isArray(series.points) ? series.points : [];
                            const pointText = points
                              .map((p) => `${p.month}: ${asMoney(p.won_amount || 0)}`)
                              .join(' | ');
                            return `<tr>
                              <td>${escapeHtml(series.dimension_label || series.dimension_key || '-')}</td>
                              <td>${escapeHtml(series.total_deals || 0)}</td>
                              <td>${escapeHtml(series.total_won_count || 0)}</td>
                              <td>${escapeHtml(asMoney(series.total_won_amount || 0))}</td>
                              <td>${escapeHtml(pointText || '-')}</td>
                            </tr>`;
                          })
                          .join('')
                      : '<tr><td colspan="5" class="empty">暂无趋势数据</td></tr>'
                  }
                </tbody>
              </table>
            </div>`
      }
    </section>
  `;

  const filterForm = $('analyticsFilterForm');
  if (filterForm) {
    filterForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      setFilters('analytics', readFormFilters(filterForm));
      await renderAnalytics();
    });
  }

  const filterReset = $('analyticsFilterReset');
  if (filterReset) {
    filterReset.addEventListener('click', async () => {
      if (filterForm) {
        filterForm.reset();
      }
      setFilters('analytics', {
        pipeline_key: 'default',
        dimension: 'channel',
        months: '6',
      });
      await renderAnalytics();
    });
  }

  if (funnelErr || durationErr || trendErr) {
    toast('部分分析接口加载失败，请检查权限或后端日志', true);
  }
}
