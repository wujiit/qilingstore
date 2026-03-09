let runtimeCtx = null;

export function setRenderContactsContext(ctx) {
  runtimeCtx = ctx || null;
}

export async function renderContacts() {
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
    requestDownload,
    toast,
    openEntityDetail,
    bindPager,
    pagerHtml,
    zhCrmStatus,
    loadCustomFields,
    loadFormConfig,
    sortCustomFields,
    customFieldInputsHtml,
    collectCustomFieldValues,
  } = ctx;
    screenTitle('联系人管理', '独立联系人资产，支持外贸字段');
    const ui = uiState('contacts');
    const canEdit = hasPermission('crm.contacts.edit');
    const canViewCustomFields = hasPermission('crm.custom_fields.view');
    const canViewFormConfig = hasPermission('crm.form_config.view');
    const [payload, customFieldDefs] = await Promise.all([
      request('GET', '/crm/contacts', { query: listQuery('contacts') }),
      canEdit && canViewCustomFields
        ? (async () => {
            try {
              const [defs, formConfig] = await Promise.all([
                loadCustomFields('contact'),
                canViewFormConfig ? loadFormConfig('contact') : Promise.resolve(null),
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
              <h3>新增联系人</h3>
              <form id="contactCreateForm" class="grid-3">
                <label><span>联系人</span><input name="contact_name" required /></label>
                <label><span>手机号</span><input name="mobile" /></label>
                <label><span>邮箱</span><input name="email" /></label>
                <label><span>WhatsApp</span><input name="whatsapp" /></label>
                <label><span>地区</span><input name="country_code" /></label>
                <label><span>语言</span><input name="language_code" /></label>
                <label><span>所属企业ID</span><input name="company_id" type="number" min="0" /></label>
                <label><span>来源渠道</span><input name="source_channel" /></label>
                ${
                  customFieldDefs.length
                    ? `<div style="grid-column:1 / -1;">
                        <span style="display:block;margin-bottom:6px;">自定义字段</span>
                        ${customFieldInputsHtml(customFieldDefs)}
                      </div>`
                    : ''
                }
                <div><button class="btn btn-primary" type="submit">创建联系人</button></div>
              </form>
            </section>`
          : ''
      }
      ${
        canEdit && editing
          ? `<section class="card" style="margin-top:12px;">
              <h3>编辑联系人 #${escapeHtml(editing.id)}</h3>
              <form id="contactEditForm" class="grid-3">
                <input type="hidden" name="contact_id" value="${escapeHtml(editing.id)}" />
                <label><span>联系人</span><input name="contact_name" required value="${escapeHtml(editing.contact_name || '')}" /></label>
                <label><span>手机号</span><input name="mobile" value="${escapeHtml(editing.mobile || '')}" /></label>
                <label><span>邮箱</span><input name="email" value="${escapeHtml(editing.email || '')}" /></label>
                <label><span>WhatsApp</span><input name="whatsapp" value="${escapeHtml(editing.whatsapp || '')}" /></label>
                <label><span>地区</span><input name="country_code" value="${escapeHtml(editing.country_code || '')}" /></label>
                <label><span>语言</span><input name="language_code" value="${escapeHtml(editing.language_code || '')}" /></label>
                <label><span>所属企业ID</span><input name="company_id" type="number" min="0" value="${escapeHtml(editing.company_id || '')}" /></label>
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
                  <button class="btn btn-ghost" type="button" id="contactEditCancel">取消</button>
                </div>
              </form>
            </section>`
          : ''
      }
      <section class="card" style="margin-top:${canEdit ? '12px' : '0'};">
        <h3>导出</h3>
        <div class="toolbar">
          <button class="btn btn-ghost" type="button" id="contactExportBtn">导出当前筛选 CSV</button>
          <select id="contactExportHeaderLang">
            <option value="zh" selected>中文表头</option>
            <option value="en">英文表头</option>
          </select>
          <small style="color:#6f8091;">按当前筛选导出，单次最多 5000 条</small>
        </div>
        <p style="margin:10px 0 0;color:#6f8091;font-size:12px;">支持中文/英文表头，包含自定义字段列。</p>
      </section>
      <section class="card" style="margin-top:12px;">
        <h3>联系人列表</h3>
        <form id="contactFilterForm" class="toolbar">
          <input name="q" placeholder="搜索联系人/手机/邮箱" value="${escapeHtml(filters.q || '')}" />
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
          <input name="company_id" type="number" min="1" placeholder="企业ID" value="${escapeHtml(filters.company_id || '')}" />
          <button class="btn btn-primary" type="submit">筛选</button>
          <button class="btn btn-ghost" type="button" id="contactFilterReset">重置</button>
        </form>
        <div class="table-wrap">
          <table>
            <thead><tr>
              <th>ID</th><th>联系人</th><th>手机号</th><th>邮箱</th><th>WhatsApp</th><th>企业</th><th>负责人</th><th>状态</th><th>操作</th>
            </tr></thead>
            <tbody>
              ${
                rows.length
                  ? rows
                      .map(
                        (r) => `<tr>
                          <td>${escapeHtml(r.id)}</td>
                          <td>${escapeHtml(r.contact_name || '-')}</td>
                          <td>${escapeHtml(r.mobile || '-')}</td>
                          <td>${escapeHtml(r.email || '-')}</td>
                          <td>${escapeHtml(r.whatsapp || '-')}</td>
                          <td>${escapeHtml(r.company_name || '-')}</td>
                          <td>${escapeHtml(r.owner_username || '-')}</td>
                          <td>${escapeHtml(zhCrmStatus(r.status || '-'))}</td>
                          <td>
                            ${
                              (() => {
                                const buttons = [`<button class="btn btn-ghost" data-contact-detail="${escapeHtml(r.id)}">详情</button>`];
                                if (canEdit) {
                                  buttons.push(`<button class="btn btn-ghost" data-contact-edit="${escapeHtml(r.id)}">编辑</button>`);
                                }
                                return rowActions(buttons);
                              })()
                            }
                          </td>
                        </tr>`
                      )
                      .join('')
                  : `<tr><td colspan="9" class="empty">暂无联系人</td></tr>`
              }
            </tbody>
          </table>
        </div>
        ${pagerHtml('contacts', pagination)}
      </section>
    `;

    const form = $('contactCreateForm');
    if (canEdit && form) {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(form);
        const body = Object.fromEntries(fd.entries());
        if (!body.company_id) delete body.company_id;
        if (customFieldDefs.length) {
          body.custom_fields = collectCustomFieldValues(form, customFieldDefs);
        }
        try {
          await request('POST', '/crm/contacts', { body });
          ui.cursor = 0;
          ui.prev = [];
          ui.editing = null;
          toast('联系人创建成功');
          await renderContacts();
        } catch (err) {
          toast(err.message || '创建失败', true);
        }
      });
    }

    const filterForm = $('contactFilterForm');
    if (filterForm) {
      filterForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        setFilters('contacts', readFormFilters(filterForm));
        await renderContacts();
      });
    }
    const filterReset = $('contactFilterReset');
    if (filterReset) {
      filterReset.addEventListener('click', async () => {
        if (filterForm) filterForm.reset();
        setFilters('contacts', {});
        await renderContacts();
      });
    }

    const exportBtn = $('contactExportBtn');
    if (exportBtn) {
      exportBtn.addEventListener('click', async () => {
        try {
          const headerLangEl = $('contactExportHeaderLang');
          const headerLang = headerLangEl ? String(headerLangEl.value || 'zh').trim().toLowerCase() : 'zh';
          const query = {
            ...listQuery('contacts'),
            limit: 5000,
            header_lang: headerLang === 'en' ? 'en' : 'zh',
          };
          await requestDownload('/crm/contacts/export', {
            query,
            filenamePrefix: 'crm-contacts',
          });
          toast('导出已开始');
        } catch (err) {
          toast(err.message || '导出失败', true);
        }
      });
    }

    if (canEdit) {
      el.viewRoot.querySelectorAll('[data-contact-edit]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const id = Number(btn.getAttribute('data-contact-edit') || 0);
          if (!id) return;
          const row = rows.find((item) => Number(item.id || 0) === id);
          if (!row) return;
          ui.editing = {
            id,
            contact_name: row.contact_name || '',
            mobile: row.mobile || '',
            email: row.email || '',
            whatsapp: row.whatsapp || '',
            country_code: row.country_code || '',
            language_code: row.language_code || '',
            company_id: Number(row.company_id || 0) > 0 ? Number(row.company_id) : '',
            source_channel: row.source_channel || '',
            status: row.status || 'active',
            custom_fields: row.custom_fields && typeof row.custom_fields === 'object' ? { ...row.custom_fields } : {},
          };
          await renderContacts();
        });
      });
    }

    el.viewRoot.querySelectorAll('[data-contact-detail]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.getAttribute('data-contact-detail') || 0);
        if (!id) return;
        const row = rows.find((item) => Number(item.id || 0) === id);
        if (!row) return;
        await openEntityDetail('contact', row);
      });
    });

    const editForm = $('contactEditForm');
    if (canEdit && editForm) {
      editForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(editForm);
        const body = Object.fromEntries(fd.entries());
        body.contact_id = Number(body.contact_id || 0);
        body.company_id = body.company_id ? Number(body.company_id) : 0;
        if (customFieldDefs.length) {
          body.custom_fields = collectCustomFieldValues(editForm, customFieldDefs);
        }
        if (!body.contact_id) {
          toast('contact_id 无效', true);
          return;
        }
        try {
          await request('POST', '/crm/contacts/update', { body });
          ui.editing = null;
          toast('联系人已更新');
          await renderContacts();
        } catch (err) {
          toast(err.message || '更新失败', true);
        }
      });
    }

    const editCancel = $('contactEditCancel');
    if (canEdit && editCancel) {
      editCancel.addEventListener('click', async () => {
        ui.editing = null;
        await renderContacts();
      });
    }

    bindPager('contacts', pagination, renderContacts);
  }
