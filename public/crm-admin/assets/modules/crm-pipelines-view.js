let runtimeCtx = null;

export function setRenderPipelinesContext(ctx) {
  runtimeCtx = ctx || null;
}

export async function renderPipelines() {
  const ctx = runtimeCtx || {};
  const {
    screenTitle,
    request,
    hasPermission,
    el,
    escapeHtml,
    zhCrmStatus,
    toast,
    $,
  } = ctx;
    screenTitle('销售管道', '可配置阶段，支持二开扩展');
    const payload = await request('GET', '/crm/pipelines');
    const rows = Array.isArray(payload.data) ? payload.data : [];
    const canManage = hasPermission('crm.pipelines.manage');

    el.viewRoot.innerHTML = `
      ${
        canManage
          ? `<section class="card">
              <h3>新增 / 更新管道</h3>
              <form id="pipelineForm" class="grid-2">
                <label><span>管道编码</span><input name="pipeline_key" placeholder="default" required /></label>
                <label><span>管道名称</span><input name="pipeline_name" placeholder="默认销售管道" required /></label>
                <label style="grid-column:1 / -1;"><span>阶段 JSON</span><textarea name="stages_json" placeholder='[{\"key\":\"new\",\"name\":\"新建线索\",\"sort\":10}]'></textarea></label>
                <div><button class="btn btn-primary" type="submit">保存管道</button></div>
              </form>
            </section>`
          : ''
      }
      <section class="card" style="margin-top:${canManage ? '12px' : '0'};">
        <h3>当前管道</h3>
        <div class="table-wrap">
          <table>
            <thead><tr><th>ID</th><th>编码</th><th>名称</th><th>阶段</th><th>系统</th><th>状态</th></tr></thead>
            <tbody>
              ${
                rows.length
                  ? rows
                      .map(
                        (r) => `<tr>
                          <td>${escapeHtml(r.id)}</td>
                          <td>${escapeHtml(r.pipeline_key || '-')}</td>
                          <td>${escapeHtml(r.pipeline_name || '-')}</td>
                          <td>${escapeHtml((Array.isArray(r.stages) ? r.stages : []).map((s) => `${s.key}:${s.name}`).join(' | ') || '-')}</td>
                          <td>${Number(r.is_system || 0) === 1 ? '是' : '否'}</td>
                          <td>${escapeHtml(zhCrmStatus(r.status || '-'))}</td>
                        </tr>`
                      )
                      .join('')
                  : `<tr><td colspan="6" class="empty">暂无管道</td></tr>`
              }
            </tbody>
          </table>
        </div>
      </section>
    `;

    const form = $('pipelineForm');
    if (canManage && form) {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(form);
        const raw = Object.fromEntries(fd.entries());
        let stages = [];
        try {
          stages = JSON.parse(String(raw.stages_json || '[]'));
        } catch (err) {
          toast('阶段 JSON 格式错误', true);
          return;
        }
        try {
          await request('POST', '/crm/pipelines', {
            body: {
              pipeline_key: raw.pipeline_key,
              pipeline_name: raw.pipeline_name,
              stages,
              status: 'active',
            },
          });
          toast('管道已保存');
          await renderPipelines();
        } catch (err) {
          toast(err.message || '保存失败', true);
        }
      });
    }
  }
