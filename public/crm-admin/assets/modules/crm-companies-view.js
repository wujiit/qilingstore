let runtimeCtx = null;

export function setRenderCompaniesContext(ctx) {
  runtimeCtx = ctx || null;
}

export async function renderCompanies() {
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
    openEntityDetail,
    bindPager,
    pagerHtml,
    zhCompanyType,
    zhCrmStatus,
    loadCustomFields,
    loadFormConfig,
    sortCustomFields,
    customFieldInputsHtml,
    collectCustomFieldValues,
  } = ctx;
    screenTitle('企业管理', 'CRM 独立企业档案（不与门店会员冲突）');
    const ui = uiState('companies');
    const canEdit = hasPermission('crm.companies.edit');
    const canViewCustomFields = hasPermission('crm.custom_fields.view');
    const canViewFormConfig = hasPermission('crm.form_config.view');
    const [payload, customFieldDefs] = await Promise.all([
      request('GET', '/crm/companies', { query: listQuery('companies') }),
      canEdit && canViewCustomFields
        ? (async () => {
            try {
              const [defs, formConfig] = await Promise.all([
                loadCustomFields('company'),
                canViewFormConfig ? loadFormConfig('company') : Promise.resolve(null),
              ]);
              const layout = formConfig && formConfig.status !== 'inactive' && Array.isArray(formConfig.layout) ? formConfig.layout : [];
              return sortCustomFields(defs, layout);
            } catch (_err) {
              return [];
            }
          })()
        : Promise.resolve([]),
    ]);
    const rows = Array.isArray(payload.data) ? payload.data : [];
    const pagination = payload && payload.pagination ? payload.pagination : {};
    const filters = ui.filters || {};
    const editing = ui.editing && Number(ui.editing.id || 0) > 0 ? ui.editing : null;

    el.viewRoot.innerHTML = `
      ${
        canEdit
          ? `<section class="card">
              <h3>新增企业</h3>
              <form id="companyCreateForm" class="grid-3">
                <label><span>企业名称</span><input name="company_name" required /></label>
                <label><span>类型</span><input name="company_type" placeholder="enterprise/foreign_trade" value="enterprise" /></label>
                <label><span>地区</span><input name="country_code" placeholder="CN/US/SG" /></label>
                <label><span>行业</span><input name="industry" /></label>
                <label><span>官网</span><input name="website" /></label>
                <label><span>来源渠道</span><input name="source_channel" /></label>
                ${
                  customFieldDefs.length
                    ? `<div style="grid-column:1 / -1;">
                        <span style="display:block;margin-bottom:6px;">自定义字段</span>
                        ${customFieldInputsHtml(customFieldDefs)}
                      </div>`
                    : ''
                }
                <div><button class="btn btn-primary" type="submit">创建企业</button></div>
              </form>
            </section>`
          : ''
      }
      ${
        canEdit && editing
          ? `<section class="card" style="margin-top:12px;">
              <h3>编辑企业 #${escapeHtml(editing.id)}</h3>
              <form id="companyEditForm" class="grid-3">
                <input type="hidden" name="company_id" value="${escapeHtml(editing.id)}" />
                <label><span>企业名称</span><input name="company_name" required value="${escapeHtml(editing.company_name || '')}" /></label>
                <label><span>类型</span><input name="company_type" value="${escapeHtml(editing.company_type || 'enterprise')}" /></label>
                <label><span>地区</span><input name="country_code" value="${escapeHtml(editing.country_code || '')}" /></label>
                <label><span>行业</span><input name="industry" value="${escapeHtml(editing.industry || '')}" /></label>
                <label><span>官网</span><input name="website" value="${escapeHtml(editing.website || '')}" /></label>
                <label><span>来源渠道</span><input name="source_channel" value="${escapeHtml(editing.source_channel || '')}" /></label>
                <label><span>状态</span>
                  <select name="status">
                    <option value="active"${optionSelected(editing.status, 'active')}>启用</option>
                    <option value="inactive"${optionSelected(editing.status, 'inactive')}>停用</option>
                  </select>
                </label>
                ${
                  customFieldDefs.length
                    ? `<div style="grid-column:1 / -1;">
                        <span style="display:block;margin-bottom:6px;">自定义字段</span>
                        ${customFieldInputsHtml(customFieldDefs, editing.custom_fields || {})}
                      </div>`
                    : ''
                }
                <div>
                  <button class="btn btn-primary" type="submit">保存修改</button>
                  <button class="btn btn-ghost" type="button" id="companyEditCancel">取消</button>
                </div>
              </form>
            </section>`
          : ''
      }
      <section class="card" style="margin-top:${canEdit ? '12px' : '0'};">
        <h3>企业列表</h3>
        <form id="companyFilterForm" class="toolbar">
          <input name="q" placeholder="搜索企业/官网/行业" value="${escapeHtml(filters.q || '')}" />
          <select name="status">
            <option value="">全部状态</option>
            <option value="active"${optionSelected(filters.status, 'active')}>启用</option>
            <option value="inactive"${optionSelected(filters.status, 'inactive')}>停用</option>
          </select>
          <select name="view">
            <option value="active"${optionSelected(filters.view, 'active')}>仅活跃</option>
            <option value="archived"${optionSelected(filters.view, 'archived')}>仅归档</option>
            <option value="recycle"${optionSelected(filters.view, 'recycle')}>回收站</option>
            <option value="all"${optionSelected(filters.view, 'all')}>全部生命周期</option>
          </select>
          <input name="company_type" placeholder="类型，如 enterprise" value="${escapeHtml(filters.company_type || '')}" />
          <input name="country_code" placeholder="地区代码，如 CN" value="${escapeHtml(filters.country_code || '')}" />
          <button class="btn btn-primary" type="submit">筛选</button>
          <button class="btn btn-ghost" type="button" id="companyFilterReset">重置</button>
        </form>
        <div class="table-wrap">
          <table>
            <thead><tr>
              <th>ID</th><th>企业</th><th>类型</th><th>地区</th><th>行业</th><th>负责人</th><th>状态</th><th>操作</th>
            </tr></thead>
            <tbody>
              ${
                rows.length
                  ? rows
                      .map(
                        (r) => `<tr>
                          <td>${escapeHtml(r.id)}</td>
                          <td>${escapeHtml(r.company_name || '-')}</td>
                          <td>${escapeHtml(zhCompanyType(r.company_type || '-'))}</td>
                          <td>${escapeHtml(r.country_code || '-')}</td>
                          <td>${escapeHtml(r.industry || '-')}</td>
                          <td>${escapeHtml(r.owner_username || '-')}</td>
                          <td>${escapeHtml(zhCrmStatus(r.status || '-'))}</td>
                          <td>
                            ${
                              (() => {
                                const buttons = [`<button class="btn btn-ghost" data-company-detail="${escapeHtml(r.id)}">详情</button>`];
                                if (canEdit) {
                                  buttons.push(`<button class="btn btn-ghost" data-company-edit="${escapeHtml(r.id)}">编辑</button>`);
                                }
                                return rowActions(buttons);
                              })()
                            }
                          </td>
                        </tr>`
                      )
                      .join('')
                  : `<tr><td colspan="8" class="empty">暂无企业数据</td></tr>`
              }
            </tbody>
          </table>
        </div>
        ${pagerHtml('companies', pagination)}
      </section>
    `;

    const form = $('companyCreateForm');
    if (canEdit && form) {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(form);
        const body = Object.fromEntries(fd.entries());
        if (customFieldDefs.length) {
          body.custom_fields = collectCustomFieldValues(form, customFieldDefs);
        }
        try {
          await request('POST', '/crm/companies', { body });
          ui.cursor = 0;
          ui.prev = [];
          toast('企业创建成功');
          await renderCompanies();
        } catch (err) {
          toast(err.message || '创建失败', true);
        }
      });
    }

    const filterForm = $('companyFilterForm');
    if (filterForm) {
      filterForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        setFilters('companies', readFormFilters(filterForm));
        await renderCompanies();
      });
    }
    const filterReset = $('companyFilterReset');
    if (filterReset) {
      filterReset.addEventListener('click', async () => {
        if (filterForm) filterForm.reset();
        setFilters('companies', {});
        await renderCompanies();
      });
    }

    if (canEdit) {
      el.viewRoot.querySelectorAll('[data-company-edit]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const id = Number(btn.getAttribute('data-company-edit') || 0);
          if (!id) return;
          const row = rows.find((item) => Number(item.id || 0) === id);
          if (!row) return;
          ui.editing = {
            id,
            company_name: row.company_name || '',
            company_type: row.company_type || 'enterprise',
            country_code: row.country_code || '',
            industry: row.industry || '',
            website: row.website || '',
            source_channel: row.source_channel || '',
            status: row.status || 'active',
            custom_fields: row.custom_fields && typeof row.custom_fields === 'object' ? { ...row.custom_fields } : {},
          };
          await renderCompanies();
        });
      });
    }

    el.viewRoot.querySelectorAll('[data-company-detail]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.getAttribute('data-company-detail') || 0);
        if (!id) return;
        const row = rows.find((item) => Number(item.id || 0) === id);
        if (!row) return;
        await openEntityDetail('company', row);
      });
    });

    const editForm = $('companyEditForm');
    if (canEdit && editForm) {
      editForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(editForm);
        const body = Object.fromEntries(fd.entries());
        body.company_id = Number(body.company_id || 0);
        if (customFieldDefs.length) {
          body.custom_fields = collectCustomFieldValues(editForm, customFieldDefs);
        }
        if (!body.company_id) {
          toast('company_id 无效', true);
          return;
        }
        try {
          await request('POST', '/crm/companies/update', { body });
          ui.editing = null;
          toast('企业已更新');
          await renderCompanies();
        } catch (err) {
          toast(err.message || '更新失败', true);
        }
      });
    }

    const editCancel = $('companyEditCancel');
    if (canEdit && editCancel) {
      editCancel.addEventListener('click', async () => {
        ui.editing = null;
        await renderCompanies();
      });
    }

    bindPager('companies', pagination, renderCompanies);
  }
