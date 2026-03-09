let runtimeCtx = null;

export function setRenderCustomizationContext(ctx) {
  runtimeCtx = ctx || null;
}

export async function renderCustomization() {
  const ctx = runtimeCtx || {};
  const { screenTitle, uiState, request, hasPermission, el, escapeHtml, optionSelected, $, toast, zhCrmEntityType, zhCrmStatus } = ctx;

  screenTitle('自定义表单', '自定义字段、布局配置、去重规则');
  const ui = uiState('customization');
  const filters = ui.filters || {};
  const entityType = String(filters.entity_type || 'lead');
  const canEditFields = hasPermission('crm.custom_fields.edit');
  const canEditForm = hasPermission('crm.form_config.edit');
  const canManageDedupe = hasPermission('crm.governance.manage');

  let fields = [];
  let config = null;
  let dedupeRules = [];
  const zhFieldType = (value) => {
    const v = String(value || '').trim();
    const map = {
      text: '单行文本',
      textarea: '多行文本',
      number: '数字',
      date: '日期',
      datetime: '日期时间',
      select: '下拉选择',
      checkbox: '勾选',
      email: '邮箱',
      phone: '手机号',
      url: '链接',
    };
    return map[v] || v || '-';
  };
  try {
    const payload = await request('GET', '/crm/custom-fields', {
      query: { entity_type: entityType, active_only: 0 },
    });
    fields = Array.isArray(payload.data) ? payload.data : [];
  } catch (_err) {
    fields = [];
  }
  try {
    const payload = await request('GET', '/crm/form-config', {
      query: { entity_type: entityType },
    });
    config = payload && payload.config ? payload.config : null;
  } catch (_err) {
    config = null;
  }
  if (canManageDedupe) {
    try {
      const payload = await request('GET', '/crm/dedupe-rules', { query: { limit: 20 } });
      dedupeRules = Array.isArray(payload.data) ? payload.data : [];
    } catch (_err) {
      dedupeRules = [];
    }
  }

  const layoutRows = config && Array.isArray(config.layout) ? config.layout : [];
  const layoutText = layoutRows.join('\n');

  el.viewRoot.innerHTML = `
    <section class="card">
      <h3>对象选择</h3>
      <form id="customizationFilterForm" class="toolbar">
        <select name="entity_type">
          <option value="lead"${optionSelected(entityType, 'lead')}>线索</option>
          <option value="contact"${optionSelected(entityType, 'contact')}>联系人</option>
          <option value="company"${optionSelected(entityType, 'company')}>企业</option>
          <option value="deal"${optionSelected(entityType, 'deal')}>商机</option>
        </select>
        <button class="btn btn-primary" type="submit">切换对象</button>
      </form>
    </section>

    ${
      canEditFields
        ? `<section class="card" style="margin-top:12px;">
            <h3>新增/更新字段</h3>
            <form id="customFieldUpsertForm" class="grid-3">
              <input type="hidden" name="entity_type" value="${escapeHtml(entityType)}" />
              <label><span>字段key</span><input name="field_key" placeholder="如 customer_level" /></label>
              <label><span>字段名称</span><input name="field_label" required /></label>
              <label><span>字段类型</span>
                <select name="field_type">
                  <option value="text">单行文本</option>
                  <option value="textarea">多行文本</option>
                  <option value="number">数字</option>
                  <option value="date">日期</option>
                  <option value="datetime">日期时间</option>
                  <option value="select">下拉选择</option>
                  <option value="checkbox">勾选</option>
                  <option value="email">邮箱</option>
                  <option value="phone">手机号</option>
                  <option value="url">链接</option>
                </select>
              </label>
              <label><span>选项（select）</span><input name="options" placeholder="A,B,C" /></label>
              <label><span>默认值</span><input name="default_value" /></label>
              <label><span>占位符</span><input name="placeholder" /></label>
              <label><span>排序</span><input name="sort_order" type="number" value="100" /></label>
              <label><span>状态</span><select name="status"><option value="active">启用</option><option value="inactive">停用</option></select></label>
              <label><span>必填</span><select name="is_required"><option value="0">否</option><option value="1">是</option></select></label>
              <div><button class="btn btn-primary" type="submit">保存字段</button></div>
            </form>
          </section>`
        : ''
    }

    <section class="card" style="margin-top:12px;">
      <h3>字段列表</h3>
      <div class="table-wrap">
        <table>
          <thead><tr><th>ID</th><th>Key</th><th>名称</th><th>类型</th><th>必填</th><th>排序</th><th>状态</th><th>操作</th></tr></thead>
          <tbody>
            ${
              fields.length
                ? fields
                    .map(
                      (row) => `<tr>
                          <td>${escapeHtml(row.id)}</td>
                          <td>${escapeHtml(row.field_key || '-')}</td>
                          <td>${escapeHtml(row.field_label || '-')}</td>
                          <td>${escapeHtml(zhFieldType(row.field_type || '-'))}</td>
                          <td>${Number(row.is_required || 0) === 1 ? '是' : '否'}</td>
                          <td>${escapeHtml(row.sort_order || 0)}</td>
                          <td>${escapeHtml(zhCrmStatus(row.status || '-'))}</td>
                          <td>
                            ${
                              canEditFields
                                ? `<button class="btn btn-danger" type="button" data-custom-field-delete="${escapeHtml(row.id)}">删除</button>`
                                : '<span style="color:#6f8091;">只读</span>'
                            }
                          </td>
                        </tr>`
                    )
                    .join('')
                : '<tr><td colspan="8" class="empty">暂无字段</td></tr>'
            }
          </tbody>
        </table>
      </div>
    </section>

    ${
      canEditForm
        ? `<section class="card" style="margin-top:12px;">
            <h3>表单布局（按字段key一行一个）</h3>
            <form id="formConfigForm" class="grid-2">
              <input type="hidden" name="entity_type" value="${escapeHtml(entityType)}" />
              <label style="grid-column:1 / -1;"><span>布局</span><textarea name="layout" rows="8" placeholder="field_key_1&#10;field_key_2">${escapeHtml(
                layoutText
              )}</textarea></label>
              <label><span>状态</span><select name="status"><option value="active"${optionSelected(
                config && config.status,
                'active'
              )}>启用</option><option value="inactive"${optionSelected(config && config.status, 'inactive')}>停用</option></select></label>
              <div><button class="btn btn-primary" type="submit">保存布局</button></div>
            </form>
          </section>`
        : ''
    }

    ${
      canManageDedupe
        ? `<section class="card" style="margin-top:12px;">
            <h3>去重规则</h3>
            <div class="table-wrap">
              <table>
                <thead><tr><th>对象</th><th>手机号</th><th>邮箱</th><th>公司</th><th>启用</th><th>操作</th></tr></thead>
                <tbody>
                  ${
                    dedupeRules.length
                      ? dedupeRules
                          .map(
                            (row) => `<tr>
                                <td>${escapeHtml(zhCrmEntityType(row.entity_type || '-'))}</td>
                                <td>${Number(row.match_mobile || 0) === 1 ? '是' : '否'}</td>
                                <td>${Number(row.match_email || 0) === 1 ? '是' : '否'}</td>
                                <td>${Number(row.match_company || 0) === 1 ? '是' : '否'}</td>
                                <td>${Number(row.enabled || 0) === 1 ? '是' : '否'}</td>
                                <td><button class="btn btn-ghost" type="button" data-dedupe-edit="${escapeHtml(
                                  row.entity_type
                                )}" data-dedupe-mobile="${escapeHtml(row.match_mobile)}" data-dedupe-email="${escapeHtml(
                                  row.match_email
                                )}" data-dedupe-company="${escapeHtml(row.match_company)}" data-dedupe-enabled="${escapeHtml(
                                  row.enabled
                                )}">快速应用</button></td>
                              </tr>`
                          )
                          .join('')
                      : '<tr><td colspan="6" class="empty">暂无规则</td></tr>'
                  }
                </tbody>
              </table>
            </div>
          </section>`
        : ''
    }
  `;

  const filterForm = $('customizationFilterForm');
  if (filterForm) {
    filterForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(filterForm);
      ui.filters = { entity_type: String(fd.get('entity_type') || 'lead') };
      await renderCustomization();
    });
  }

  const fieldForm = $('customFieldUpsertForm');
  if (canEditFields && fieldForm) {
    fieldForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(fieldForm);
      const body = Object.fromEntries(fd.entries());
      body.sort_order = Number(body.sort_order || 100);
      body.is_required = Number(body.is_required || 0) === 1 ? 1 : 0;
      try {
        await request('POST', '/crm/custom-fields', { body });
        toast('字段已保存');
        fieldForm.reset();
        await renderCustomization();
      } catch (err) {
        toast(err.message || '保存失败', true);
      }
    });
  }

  if (canEditFields) {
    el.viewRoot.querySelectorAll('[data-custom-field-delete]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const fieldId = Number(btn.getAttribute('data-custom-field-delete') || 0);
        if (!fieldId) return;
        try {
          await request('POST', '/crm/custom-fields/delete', { body: { field_id: fieldId } });
          toast('字段已删除');
          await renderCustomization();
        } catch (err) {
          toast(err.message || '删除失败', true);
        }
      });
    });
  }

  const configForm = $('formConfigForm');
  if (canEditForm && configForm) {
    configForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(configForm);
      const layout = String(fd.get('layout') || '')
        .split(/\r?\n/)
        .map((item) => item.trim())
        .filter((item) => item);
      const body = {
        entity_type: String(fd.get('entity_type') || entityType),
        status: String(fd.get('status') || 'active'),
        layout,
      };
      try {
        await request('POST', '/crm/form-config', { body });
        toast('布局已保存');
      } catch (err) {
        toast(err.message || '保存失败', true);
      }
    });
  }

  if (canManageDedupe) {
    el.viewRoot.querySelectorAll('[data-dedupe-edit]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const entity = String(btn.getAttribute('data-dedupe-edit') || '').trim();
        if (!entity) return;
        const body = {
          entity_type: entity,
          match_mobile: Number(btn.getAttribute('data-dedupe-mobile') || 0) === 1 ? 0 : 1,
          match_email: Number(btn.getAttribute('data-dedupe-email') || 0) === 1 ? 0 : 1,
          match_company: Number(btn.getAttribute('data-dedupe-company') || 0) === 1 ? 0 : 1,
          enabled: Number(btn.getAttribute('data-dedupe-enabled') || 0) === 1 ? 1 : 0,
        };
        try {
          await request('POST', '/crm/dedupe-rules', { body });
          toast('去重规则已更新');
          await renderCustomization();
        } catch (err) {
          toast(err.message || '更新失败', true);
        }
      });
    });
  }
}
