let runtimeCtx = null;

export function setRenderActivitiesContext(ctx) {
  runtimeCtx = ctx || null;
}

export async function renderActivities() {
  const ctx = runtimeCtx || {};
  const {
    screenTitle,
    uiState,
    request,
    listQuery,
    hasPermission,
    el,
    escapeHtml,
    optionSelected,
    rowActions,
    $,
    setFilters,
    readFormFilters,
    toast,
    bindPager,
    pagerHtml,
    zhCrmEntityType,
    zhCrmActivityType,
    zhCrmStatus,
  } = ctx;
    screenTitle('跟进任务', '通话、邮件、会议与待办协同');
    const ui = uiState('activities');
    const payload = await request('GET', '/crm/activities', { query: listQuery('activities') });
    const rows = Array.isArray(payload.data) ? payload.data : [];
    const pagination = payload && payload.pagination ? payload.pagination : {};
    const canEdit = hasPermission('crm.activities.edit');
    const filters = ui.filters || {};

    el.viewRoot.innerHTML = `
      ${
        canEdit
          ? `<section class="card">
              <h3>新增跟进任务</h3>
              <form id="activityCreateForm" class="grid-3">
                <label><span>实体类型</span><select name="entity_type"><option value="lead">线索</option><option value="contact">联系人</option><option value="company">企业</option><option value="deal">商机</option></select></label>
                <label><span>实体ID</span><input name="entity_id" type="number" min="1" required /></label>
                <label><span>任务类型</span><select name="activity_type"><option value="note">笔记</option><option value="call">电话</option><option value="email">邮件</option><option value="meeting">会议</option><option value="task">任务</option></select></label>
                <label><span>标题</span><input name="subject" /></label>
                <label><span>截止时间</span><input name="due_at" placeholder="2026-03-06 18:00:00" /></label>
                <label><span>状态</span><select name="status"><option value="todo" selected>待办</option><option value="done">已完成</option><option value="cancelled">已取消</option></select></label>
                <label style="grid-column:1 / -1;"><span>内容</span><textarea name="content"></textarea></label>
                <div><button class="btn btn-primary" type="submit">创建任务</button></div>
              </form>
            </section>`
          : ''
      }
      <section class="card" style="margin-top:${canEdit ? '12px' : '0'};">
        <h3>任务列表</h3>
        <form id="activityFilterForm" class="toolbar">
          <select name="status">
            <option value="">全部状态</option>
            <option value="todo"${optionSelected(filters.status, 'todo')}>待办</option>
            <option value="done"${optionSelected(filters.status, 'done')}>已完成</option>
            <option value="cancelled"${optionSelected(filters.status, 'cancelled')}>已取消</option>
          </select>
          <select name="entity_type">
            <option value="">全部实体</option>
            <option value="lead"${optionSelected(filters.entity_type, 'lead')}>线索</option>
            <option value="contact"${optionSelected(filters.entity_type, 'contact')}>联系人</option>
            <option value="company"${optionSelected(filters.entity_type, 'company')}>企业</option>
            <option value="deal"${optionSelected(filters.entity_type, 'deal')}>商机</option>
          </select>
          <input name="entity_id" type="number" min="1" placeholder="实体ID" value="${escapeHtml(filters.entity_id || '')}" />
          <input name="due_from" placeholder="截止起始，如 2026-03-01" value="${escapeHtml(filters.due_from || '')}" />
          <input name="due_to" placeholder="截止结束，如 2026-03-31" value="${escapeHtml(filters.due_to || '')}" />
          <button class="btn btn-primary" type="submit">筛选</button>
          <button class="btn btn-ghost" type="button" id="activityFilterReset">重置</button>
        </form>
        <div class="table-wrap">
          <table>
            <thead><tr>
              <th>ID</th><th>实体</th><th>类型</th><th>标题</th><th>截止</th><th>状态</th><th>负责人</th><th>操作</th>
            </tr></thead>
            <tbody>
              ${
                rows.length
                  ? rows
                      .map(
                        (r) => {
                          const buttons = [];
                          if (canEdit) {
                            if ((r.status || '') !== 'done') {
                              buttons.push(`<button class="btn btn-primary" data-activity-status="${escapeHtml(r.id)}" data-next="done">完成</button>`);
                            }
                            if ((r.status || '') !== 'cancelled') {
                              buttons.push(`<button class="btn btn-danger" data-activity-status="${escapeHtml(r.id)}" data-next="cancelled">取消</button>`);
                            }
                            if ((r.status || '') !== 'todo') {
                              buttons.push(`<button class="btn btn-ghost" data-activity-status="${escapeHtml(r.id)}" data-next="todo">恢复待办</button>`);
                            }
                          }
                          const actionHtml = buttons.length ? rowActions(buttons) : '<span style="color:#6f8091;">只读</span>';
                          return `<tr>
                          <td>${escapeHtml(r.id)}</td>
                          <td>${escapeHtml(zhCrmEntityType(r.entity_type || '-'))}#${escapeHtml(r.entity_id || 0)}</td>
                          <td>${escapeHtml(zhCrmActivityType(r.activity_type || '-'))}</td>
                          <td>${escapeHtml(r.subject || '-')}</td>
                          <td>${escapeHtml(r.due_at || '-')}</td>
                          <td>${escapeHtml(zhCrmStatus(r.status || '-'))}</td>
                          <td>${escapeHtml(r.owner_username || '-')}</td>
                          <td>${actionHtml}</td>
                        </tr>`;
                        }
                      )
                      .join('')
                  : `<tr><td colspan="8" class="empty">暂无任务</td></tr>`
              }
            </tbody>
          </table>
        </div>
        ${pagerHtml('activities', pagination)}
      </section>
    `;

    const form = $('activityCreateForm');
    if (canEdit && form) {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(form);
        const body = Object.fromEntries(fd.entries());
        try {
          await request('POST', '/crm/activities', { body });
          ui.cursor = 0;
          ui.prev = [];
          toast('任务创建成功');
          await renderActivities();
        } catch (err) {
          toast(err.message || '创建失败', true);
        }
      });
    }

    const filterForm = $('activityFilterForm');
    if (filterForm) {
      filterForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        setFilters('activities', readFormFilters(filterForm));
        await renderActivities();
      });
    }
    const filterReset = $('activityFilterReset');
    if (filterReset) {
      filterReset.addEventListener('click', async () => {
        if (filterForm) filterForm.reset();
        setFilters('activities', {});
        await renderActivities();
      });
    }

    if (canEdit) {
      el.viewRoot.querySelectorAll('[data-activity-status]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const id = Number(btn.getAttribute('data-activity-status') || 0);
          const status = String(btn.getAttribute('data-next') || '');
          if (!id || !status) return;
          try {
            await request('POST', '/crm/activities/status', { body: { activity_id: id, status } });
            ui.cursor = 0;
            ui.prev = [];
            toast('任务状态已更新');
            await renderActivities();
          } catch (err) {
            toast(err.message || '更新失败', true);
          }
        });
      });
    }

    bindPager('activities', pagination, renderActivities);
  }
