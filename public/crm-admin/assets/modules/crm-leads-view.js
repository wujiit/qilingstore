let runtimeCtx = null;

export function setRenderLeadsContext(ctx) {
  runtimeCtx = ctx || null;
}

export async function renderLeads() {
  const ctx = runtimeCtx || {};
  const {
    screenTitle,
    uiState,
    request,
    listQuery,
    hasPermission,
    selectedCount,
    el,
    escapeHtml,
    optionSelected,
    rowActions,
    isRowSelected,
    selectedRows,
    setSelectedRow,
    ensureSelectedMap,
    clearSelectedRows,
    $,
    setFilters,
    readFormFilters,
    requestDownload,
    toast,
    openEntityDetail,
    bindPager,
    pagerHtml,
    zhCrmValue,
    zhCrmStatus,
    zhCrmIntent,
    zhCrmScope,
    loadCustomFields,
    loadFormConfig,
    sortCustomFields,
    customFieldInputsHtml,
    collectCustomFieldValues,
  } = ctx;
    screenTitle('线索池', '线索归属、跟进、转化为企业/联系人/商机');
    const ui = uiState('leads');
    const canEdit = hasPermission('crm.leads.edit');
    const canViewCustomFields = hasPermission('crm.custom_fields.view');
    const canViewFormConfig = hasPermission('crm.form_config.view');
    const [payload, customFieldDefs] = await Promise.all([
      request('GET', '/crm/leads', { query: listQuery('leads') }),
      canEdit && canViewCustomFields
        ? (async () => {
            try {
              const [defs, formConfig] = await Promise.all([
                loadCustomFields('lead'),
                canViewFormConfig ? loadFormConfig('lead') : Promise.resolve(null),
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
    const canConvert = hasPermission('crm.leads.convert');
    const canAssign = hasPermission('crm.leads.assign');
    const canGovernance = hasPermission('crm.governance.manage');
    const canViewRules = hasPermission('crm.assignment_rules.view');
    const canEditRules = hasPermission('crm.assignment_rules.edit');
    const canViewTransferLogs = hasPermission('crm.transfer_logs.view');
    const filters = ui.filters || {};
    const editing = ui.editing && Number(ui.editing.id || 0) > 0 ? ui.editing : null;
    const selectedTotal = selectedCount('leads');

    let ruleRows = [];
    let ruleLoadError = '';
    if (canViewRules) {
      try {
        const rulePayload = await request('GET', '/crm/assignment-rules', { query: { limit: 50 } });
        ruleRows = Array.isArray(rulePayload.data) ? rulePayload.data : [];
      } catch (err) {
        ruleLoadError = err && err.message ? String(err.message) : '加载分配规则失败';
      }
    }

    let transferRows = [];
    let transferLoadError = '';
    if (canViewTransferLogs) {
      try {
        const transferPayload = await request('GET', '/crm/transfer-logs', {
          query: {
            entity_type: 'lead',
            limit: 20,
          },
        });
        transferRows = Array.isArray(transferPayload.data) ? transferPayload.data : [];
      } catch (err) {
        transferLoadError = err && err.message ? String(err.message) : '加载转派记录失败';
      }
    }

    el.viewRoot.innerHTML = `
      ${
        canEdit
          ? `<section class="card">
              <h3>新增线索</h3>
              <form id="leadCreateForm" class="grid-3">
                <label><span>线索名称</span><input name="lead_name" required /></label>
                <label><span>手机号</span><input name="mobile" /></label>
                <label><span>邮箱</span><input name="email" /></label>
                <label><span>企业名称</span><input name="company_name" /></label>
                <label><span>地区</span><input name="country_code" /></label>
                <label><span>来源渠道</span><input name="source_channel" /></label>
                <label><span>意向等级</span><select name="intent_level"><option value="cold">低意向</option><option value="warm" selected>中意向</option><option value="hot">高意向</option></select></label>
                <label><span>状态</span><select name="status"><option value="new" selected>新建</option><option value="contacted">已联系</option><option value="qualified">已确认</option></select></label>
                ${
                  customFieldDefs.length
                    ? `<div style="grid-column:1 / -1;">
                        <span style="display:block;margin-bottom:6px;">自定义字段</span>
                        ${customFieldInputsHtml(customFieldDefs)}
                      </div>`
                    : ''
                }
                <div><button class="btn btn-primary" type="submit">创建线索</button></div>
              </form>
            </section>`
          : ''
      }
      ${
        canEdit && editing
          ? `<section class="card" style="margin-top:12px;">
              <h3>编辑线索 #${escapeHtml(editing.id)}</h3>
              <form id="leadEditForm" class="grid-3">
                <input type="hidden" name="lead_id" value="${escapeHtml(editing.id)}" />
                <label><span>线索名称</span><input name="lead_name" required value="${escapeHtml(editing.lead_name || '')}" /></label>
                <label><span>手机号</span><input name="mobile" value="${escapeHtml(editing.mobile || '')}" /></label>
                <label><span>邮箱</span><input name="email" value="${escapeHtml(editing.email || '')}" /></label>
                <label><span>企业名称</span><input name="company_name" value="${escapeHtml(editing.company_name || '')}" /></label>
                <label><span>地区</span><input name="country_code" value="${escapeHtml(editing.country_code || '')}" /></label>
                <label><span>来源渠道</span><input name="source_channel" value="${escapeHtml(editing.source_channel || '')}" /></label>
                <label><span>意向等级</span>
                  <select name="intent_level">
                    <option value="cold"${optionSelected(editing.intent_level, 'cold')}>低意向</option>
                    <option value="warm"${optionSelected(editing.intent_level, 'warm')}>中意向</option>
                    <option value="hot"${optionSelected(editing.intent_level, 'hot')}>高意向</option>
                  </select>
                </label>
                <label><span>状态</span>
                  <select name="status">
                    <option value="new"${optionSelected(editing.status, 'new')}>新建</option>
                    <option value="contacted"${optionSelected(editing.status, 'contacted')}>已联系</option>
                    <option value="qualified"${optionSelected(editing.status, 'qualified')}>已确认</option>
                    <option value="disqualified"${optionSelected(editing.status, 'disqualified')}>无效</option>
                    <option value="converted"${optionSelected(editing.status, 'converted')}>已转化</option>
                  </select>
                </label>
                <label><span>下次跟进时间</span><input name="next_followup_at" placeholder="2026-03-08 10:00:00" value="${escapeHtml(editing.next_followup_at || '')}" /></label>
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
                  <button class="btn btn-ghost" type="button" id="leadEditCancel">取消</button>
                </div>
              </form>
            </section>`
          : ''
      }
      <section class="card" style="margin-top:${canEdit ? '12px' : '0'};">
        <h3>导出</h3>
        <div class="toolbar">
          <button class="btn btn-ghost" type="button" id="leadExportBtn">导出当前筛选 CSV</button>
          <select id="leadExportHeaderLang">
            <option value="zh" selected>中文表头</option>
            <option value="en">英文表头</option>
          </select>
          <small style="color:#6f8091;">按当前筛选导出，单次最多 5000 条</small>
        </div>
        <p style="margin:10px 0 0;color:#6f8091;font-size:12px;">支持中文/英文表头，包含自定义字段列。</p>
      </section>
      <section class="card" style="margin-top:12px;">
        <h3>线索列表</h3>
        <form id="leadFilterForm" class="toolbar">
          <input name="q" placeholder="搜索线索/手机号/邮箱/企业" value="${escapeHtml(filters.q || '')}" />
          <select name="status">
            <option value="">全部状态</option>
            <option value="new"${optionSelected(filters.status, 'new')}>新建</option>
            <option value="contacted"${optionSelected(filters.status, 'contacted')}>已联系</option>
            <option value="qualified"${optionSelected(filters.status, 'qualified')}>已确认</option>
            <option value="disqualified"${optionSelected(filters.status, 'disqualified')}>无效</option>
            <option value="converted"${optionSelected(filters.status, 'converted')}>已转化</option>
          </select>
          <select name="intent_level">
            <option value="">全部意向</option>
            <option value="cold"${optionSelected(filters.intent_level, 'cold')}>低意向</option>
            <option value="warm"${optionSelected(filters.intent_level, 'warm')}>中意向</option>
            <option value="hot"${optionSelected(filters.intent_level, 'hot')}>高意向</option>
          </select>
          <select name="view">
            <option value="active"${optionSelected(filters.view, 'active')}>仅活跃</option>
            <option value="archived"${optionSelected(filters.view, 'archived')}>仅归档</option>
            <option value="recycle"${optionSelected(filters.view, 'recycle')}>回收站</option>
            <option value="all"${optionSelected(filters.view, 'all')}>全部生命周期</option>
          </select>
          <select name="scope">
            <option value="">我的线索</option>
            <option value="public_pool"${optionSelected(filters.scope, 'public_pool')}>公海线索</option>
            <option value="all"${optionSelected(filters.scope, 'all')}>我的+公海</option>
            <option value="private"${optionSelected(filters.scope, 'private')}>仅私海</option>
          </select>
          <button class="btn btn-primary" type="submit">筛选</button>
          <button class="btn btn-ghost" type="button" id="leadFilterReset">重置</button>
        </form>
        ${
          canEdit
            ? `<div class="batch-bar">
                <small>已选 <b id="leadSelectedCount">${selectedTotal}</b> 条</small>
                <select id="leadBatchStatus">
                  <option value="">批量改状态</option>
                  <option value="new">新建</option>
                  <option value="contacted">已联系</option>
                  <option value="qualified">已确认</option>
                  <option value="disqualified">无效</option>
                  <option value="converted">已转化</option>
                </select>
                <button type="button" class="btn btn-primary" id="leadBatchApplyStatus">执行状态批量</button>
                <input id="leadBatchOwner" type="number" min="1" placeholder="批量改负责人ID" />
                <button type="button" class="btn btn-ghost" id="leadBatchApplyOwner">执行负责人批量</button>
                ${canAssign ? '<button type="button" class="btn btn-ghost" id="leadBatchToPublic">转公海</button>' : ''}
                ${canAssign ? '<button type="button" class="btn btn-ghost" id="leadBatchClaimMine">认领到我</button>' : ''}
                ${canAssign ? '<button type="button" class="btn btn-ghost" id="leadBatchTransferOwner">转派到负责人</button>' : ''}
                ${canGovernance ? '<button type="button" class="btn btn-ghost" id="leadBatchArchive">归档</button>' : ''}
                ${canGovernance ? '<button type="button" class="btn btn-ghost" id="leadBatchUnarchive">取消归档</button>' : ''}
                ${canGovernance ? '<button type="button" class="btn btn-danger" id="leadBatchRecycle">放回收站</button>' : ''}
                ${canGovernance ? '<button type="button" class="btn btn-ghost" id="leadBatchRecover">回收站恢复</button>' : ''}
                <button type="button" class="btn btn-ghost" id="leadBatchClear">清空选择</button>
              </div>`
            : ''
        }
        <div class="table-wrap">
          <table>
            <thead><tr>
              <th>${canEdit ? '<input type="checkbox" id="leadSelectPage" />' : 'ID'}</th><th>线索</th><th>联系方式</th><th>企业</th><th>来源</th><th>意向</th><th>状态</th><th>负责人</th><th>操作</th>
            </tr></thead>
            <tbody>
              ${
                rows.length
                  ? rows
                      .map(
                        (r) => {
                          const buttons = [];
                          buttons.push(`<button class="btn btn-ghost" data-lead-detail="${escapeHtml(r.id)}">详情</button>`);
                          if (canEdit) {
                            buttons.push(`<button class="btn btn-ghost" data-lead-edit="${escapeHtml(r.id)}">编辑</button>`);
                          }
                          if (canAssign) {
                            if (String(r.visibility_scope || 'private') === 'public_pool') {
                              buttons.push(`<button class="btn btn-ghost" data-lead-claim="${escapeHtml(r.id)}">认领</button>`);
                            } else {
                              buttons.push(`<button class="btn btn-ghost" data-lead-public="${escapeHtml(r.id)}">转公海</button>`);
                            }
                          }
                          if ((r.status || '') !== 'converted' && canConvert) {
                            buttons.push(`<button class="btn btn-primary" data-lead-convert="${escapeHtml(r.id)}">转化</button>`);
                          }
                          const actionHtml = rowActions(buttons);
                          const checked = canEdit && isRowSelected('leads', r.id) ? ' checked' : '';
                          return `<tr>
                          <td>${canEdit ? `<input type="checkbox" data-lead-select="${escapeHtml(r.id)}"${checked} />` : escapeHtml(r.id)}</td>
                          <td>${escapeHtml(r.lead_name || '-')}</td>
                          <td>${escapeHtml(r.mobile || '-')}${r.email ? `<br>${escapeHtml(r.email)}` : ''}</td>
                          <td>${escapeHtml(r.company_name || '-')}</td>
                          <td>${escapeHtml(r.source_channel || '-')}</td>
                          <td>${escapeHtml(zhCrmIntent(r.intent_level || '-'))}</td>
                          <td>${escapeHtml(zhCrmStatus(r.status || '-'))}<br><small>${escapeHtml(zhCrmScope(r.visibility_scope || 'private'))}</small></td>
                          <td>${escapeHtml(r.owner_username || '-')}</td>
                          <td>${actionHtml}</td>
                        </tr>`;
                        }
                      )
                      .join('')
                  : `<tr><td colspan="9" class="empty">暂无线索</td></tr>`
              }
            </tbody>
          </table>
        </div>
        ${pagerHtml('leads', pagination)}
      </section>
      ${
        canGovernance || canViewRules || canViewTransferLogs
          ? `<section class="card" style="margin-top:12px;">
              <h3>治理与协作</h3>
              <form id="leadDuplicateForm" class="toolbar">
                <input name="mobile" placeholder="重复检测手机号" />
                <input name="email" placeholder="重复检测邮箱" />
                <button class="btn btn-ghost" type="submit">检测重复</button>
              </form>
              <pre id="leadDuplicateResult" style="margin:10px 0 0;max-height:180px;overflow:auto;background:#f7f9fc;padding:10px;border-radius:8px;">等待检测</pre>
              ${
                canGovernance
                  ? `<form id="leadMergeForm" class="toolbar" style="margin-top:10px;">
                      <input name="primary_id" type="number" min="1" placeholder="主线索ID" required />
                      <input name="merge_ids" placeholder="待合并ID，逗号分隔" required />
                      <select name="strategy">
                        <option value="fill_empty" selected>空值补齐</option>
                        <option value="overwrite">新值覆盖</option>
                      </select>
                      <button class="btn btn-primary" type="submit">执行合并</button>
                    </form>`
                  : ''
              }
              ${
                canViewRules
                  ? `<div style="margin-top:12px;">
                      <h4 style="margin:0 0 8px;">分配规则</h4>
                      ${
                        canEditRules
                          ? `<form id="leadRuleForm" class="toolbar">
                              <input name="rule_name" placeholder="规则名称，如公海轮询" required />
                              <select name="strategy">
                                <option value="round_robin" selected>轮询</option>
                                <option value="random">随机</option>
                              </select>
                              <input name="member_user_ids" placeholder="成员用户ID，逗号分隔" required />
                              <button class="btn btn-primary" type="submit">保存规则</button>
                            </form>`
                          : ''
                      }
                      ${
                        ruleLoadError
                          ? `<p class="empty">规则加载失败：${escapeHtml(ruleLoadError)}</p>`
                          : ruleRows.length
                          ? `<div class="table-wrap"><table>
                              <thead><tr><th>ID</th><th>名称</th><th>策略</th><th>成员</th><th>状态</th><th>操作</th></tr></thead>
                              <tbody>
                                ${ruleRows
                                  .map(
                                    (rule) => `<tr>
                                      <td>${escapeHtml(rule.id || '-')}</td>
                                      <td>${escapeHtml(rule.rule_name || '-')}</td>
                                      <td>${escapeHtml(zhCrmValue(rule.strategy || '-'))}</td>
                                      <td>${escapeHtml((rule.member_user_ids || []).join(','))}</td>
                                      <td>${Number(rule.enabled || 0) === 1 ? '启用' : '停用'}</td>
                                      <td><button class="btn btn-ghost" type="button" data-rule-apply="${escapeHtml(rule.id)}">应用到公海</button></td>
                                    </tr>`
                                  )
                                  .join('')}
                              </tbody>
                            </table></div>`
                          : '<p class="empty">暂无分配规则</p>'
                      }
                    </div>`
                  : ''
              }
              ${
                canViewTransferLogs
                  ? `<div style="margin-top:12px;">
                      <h4 style="margin:0 0 8px;">最近转派记录</h4>
                      ${
                        transferLoadError
                          ? `<p class="empty">转派记录加载失败：${escapeHtml(transferLoadError)}</p>`
                          : transferRows.length
                          ? `<div class="table-wrap"><table>
                              <thead><tr><th>ID</th><th>线索</th><th>动作</th><th>从</th><th>到</th><th>操作人</th><th>时间</th></tr></thead>
                              <tbody>
                                ${transferRows
                                  .map(
                                    (log) => `<tr>
                                      <td>${escapeHtml(log.id || '-')}</td>
                                      <td>#${escapeHtml(log.entity_id || '-')}</td>
                                      <td>${escapeHtml(zhCrmValue(log.action_type || '-'))}</td>
                                      <td>${escapeHtml(log.from_owner_username || log.from_owner_user_id || '-')}</td>
                                      <td>${escapeHtml(log.to_owner_username || log.to_owner_user_id || '-')}</td>
                                      <td>${escapeHtml(log.created_by_username || log.created_by || '-')}</td>
                                      <td>${escapeHtml(log.created_at || '-')}</td>
                                    </tr>`
                                  )
                                  .join('')}
                              </tbody>
                            </table></div>`
                          : '<p class="empty">暂无转派记录</p>'
                      }
                    </div>`
                  : ''
              }
            </section>`
          : ''
      }
    `;

    const form = $('leadCreateForm');
    if (canEdit && form) {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(form);
        const body = Object.fromEntries(fd.entries());
        if (customFieldDefs.length) {
          body.custom_fields = collectCustomFieldValues(form, customFieldDefs);
        }
        try {
          await request('POST', '/crm/leads', { body });
          ui.cursor = 0;
          ui.prev = [];
          ui.editing = null;
          toast('线索创建成功');
          await renderLeads();
        } catch (err) {
          toast(err.message || '创建失败', true);
        }
      });
    }

    const filterForm = $('leadFilterForm');
    if (filterForm) {
      filterForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        setFilters('leads', readFormFilters(filterForm));
        await renderLeads();
      });
    }
    const filterReset = $('leadFilterReset');
    if (filterReset) {
      filterReset.addEventListener('click', async () => {
        if (filterForm) filterForm.reset();
        setFilters('leads', {});
        clearSelectedRows('leads');
        await renderLeads();
      });
    }

    const exportBtn = $('leadExportBtn');
    if (exportBtn) {
      exportBtn.addEventListener('click', async () => {
        try {
          const headerLangEl = $('leadExportHeaderLang');
          const headerLang = headerLangEl ? String(headerLangEl.value || 'zh').trim().toLowerCase() : 'zh';
          const query = {
            ...listQuery('leads'),
            limit: 5000,
            header_lang: headerLang === 'en' ? 'en' : 'zh',
          };
          await requestDownload('/crm/leads/export', {
            query,
            filenamePrefix: 'crm-leads',
          });
          toast('导出已开始');
        } catch (err) {
          toast(err.message || '导出失败', true);
        }
      });
    }

    const selectedCountEl = $('leadSelectedCount');
    const syncLeadSelectedCount = () => {
      if (selectedCountEl) {
        selectedCountEl.textContent = String(selectedCount('leads'));
      }
    };
    syncLeadSelectedCount();

    const selectedLeadIds = () =>
      selectedRows('leads')
        .map((item) => Number(item && item.id ? item.id : 0))
        .filter((id) => id > 0);

    const runLeadLifecycle = async (action, extraBody = {}, successText = '操作完成') => {
      const leadIds = selectedLeadIds();
      if (!leadIds.length) {
        toast('请先勾选线索', true);
        return false;
      }
      try {
        const payload = await request('POST', '/crm/governance/lifecycle', {
          body: {
            entity_type: 'lead',
            action,
            entity_ids: leadIds,
            ...extraBody,
          },
        });
        const summary = payload && payload.summary ? payload.summary : {};
        toast(`${successText}：影响 ${Number(summary.affected || 0)} 条`);
        clearSelectedRows('leads');
        await renderLeads();
        return true;
      } catch (err) {
        toast(err.message || '执行失败', true);
        return false;
      }
    };

    const selectPage = $('leadSelectPage');
    if (canEdit && selectPage) {
      const allChecked = rows.length > 0 && rows.every((row) => isRowSelected('leads', row.id));
      selectPage.checked = allChecked;
      selectPage.addEventListener('change', () => {
        const checked = Boolean(selectPage.checked);
        rows.forEach((row) => setSelectedRow('leads', row, checked));
        el.viewRoot.querySelectorAll('[data-lead-select]').forEach((box) => {
          box.checked = checked;
        });
        syncLeadSelectedCount();
      });
    }

    if (canEdit) {
      el.viewRoot.querySelectorAll('[data-lead-select]').forEach((box) => {
        box.addEventListener('change', () => {
          const id = Number(box.getAttribute('data-lead-select') || 0);
          if (!id) return;
          const row = rows.find((item) => Number(item.id || 0) === id);
          if (!row) return;
          setSelectedRow('leads', row, Boolean(box.checked));
          syncLeadSelectedCount();
          if (selectPage) {
            selectPage.checked = rows.length > 0 && rows.every((item) => isRowSelected('leads', item.id));
          }
        });
      });
    }

    const batchClear = $('leadBatchClear');
    if (canEdit && batchClear) {
      batchClear.addEventListener('click', () => {
        clearSelectedRows('leads');
        el.viewRoot.querySelectorAll('[data-lead-select]').forEach((box) => {
          box.checked = false;
        });
        if (selectPage) selectPage.checked = false;
        syncLeadSelectedCount();
      });
    }

    const batchStatusBtn = $('leadBatchApplyStatus');
    if (canEdit && batchStatusBtn) {
      batchStatusBtn.addEventListener('click', async () => {
        const statusEl = $('leadBatchStatus');
        const status = statusEl ? String(statusEl.value || '').trim() : '';
        if (!status) {
          toast('请选择批量状态', true);
          return;
        }
        const selected = selectedRows('leads');
        if (!selected.length) {
          toast('请先勾选线索', true);
          return;
        }
        const ownerEl = $('leadBatchOwner');
        const ownerId = ownerEl ? Number(ownerEl.value || 0) : 0;
        const leadIds = selected
          .map((item) => Number(item && item.id ? item.id : 0))
          .filter((id) => id > 0);
        if (!leadIds.length) {
          toast('勾选数据无效，请重试', true);
          return;
        }

        const body = {
          lead_ids: leadIds,
          status,
        };
        if (ownerId > 0) {
          body.owner_user_id = ownerId;
        }

        try {
          const result = await request('POST', '/crm/leads/batch-update', { body });
          const s = result && result.summary ? result.summary : {};
          const ok = Number(s.updated || 0);
          const fail = Number(s.skipped_not_found || 0) + Number(s.skipped_forbidden || 0);
          toast(`批量状态完成：成功 ${ok}，失败 ${fail}`, fail > 0);
        } catch (err) {
          toast(err.message || '批量状态失败', true);
          return;
        }

        clearSelectedRows('leads');
        await renderLeads();
      });
    }

    const batchOwnerBtn = $('leadBatchApplyOwner');
    if (canEdit && batchOwnerBtn) {
      batchOwnerBtn.addEventListener('click', async () => {
        const ownerEl = $('leadBatchOwner');
        const ownerId = ownerEl ? Number(ownerEl.value || 0) : 0;
        if (ownerId <= 0) {
          toast('请输入负责人ID', true);
          return;
        }
        const selected = selectedRows('leads');
        if (!selected.length) {
          toast('请先勾选线索', true);
          return;
        }
        const leadIds = selected
          .map((item) => Number(item && item.id ? item.id : 0))
          .filter((id) => id > 0);
        if (!leadIds.length) {
          toast('勾选数据无效，请重试', true);
          return;
        }

        try {
          const result = await request('POST', '/crm/leads/batch-update', {
            body: {
              lead_ids: leadIds,
              owner_user_id: ownerId,
            },
          });
          const s = result && result.summary ? result.summary : {};
          const ok = Number(s.updated || 0);
          const fail = Number(s.skipped_not_found || 0) + Number(s.skipped_forbidden || 0);
          toast(`批量负责人完成：成功 ${ok}，失败 ${fail}`, fail > 0);
        } catch (err) {
          toast(err.message || '批量负责人失败', true);
          return;
        }

        clearSelectedRows('leads');
        await renderLeads();
      });
    }

    const batchToPublicBtn = $('leadBatchToPublic');
    if (canAssign && batchToPublicBtn) {
      batchToPublicBtn.addEventListener('click', async () => {
        await runLeadLifecycle('public_pool', {}, '已转入公海');
      });
    }

    const batchClaimMineBtn = $('leadBatchClaimMine');
    if (canAssign && batchClaimMineBtn) {
      batchClaimMineBtn.addEventListener('click', async () => {
        await runLeadLifecycle('claim', {}, '已认领到我');
      });
    }

    const batchTransferOwnerBtn = $('leadBatchTransferOwner');
    if (canAssign && batchTransferOwnerBtn) {
      batchTransferOwnerBtn.addEventListener('click', async () => {
        const ownerEl = $('leadBatchOwner');
        const ownerId = ownerEl ? Number(ownerEl.value || 0) : 0;
        if (ownerId <= 0) {
          toast('请输入负责人ID', true);
          return;
        }
        await runLeadLifecycle('transfer', { owner_user_id: ownerId }, '转派完成');
      });
    }

    const batchArchiveBtn = $('leadBatchArchive');
    if (canGovernance && batchArchiveBtn) {
      batchArchiveBtn.addEventListener('click', async () => {
        await runLeadLifecycle('archive', {}, '归档完成');
      });
    }

    const batchUnarchiveBtn = $('leadBatchUnarchive');
    if (canGovernance && batchUnarchiveBtn) {
      batchUnarchiveBtn.addEventListener('click', async () => {
        await runLeadLifecycle('unarchive', {}, '取消归档完成');
      });
    }

    const batchRecycleBtn = $('leadBatchRecycle');
    if (canGovernance && batchRecycleBtn) {
      batchRecycleBtn.addEventListener('click', async () => {
        await runLeadLifecycle('delete', {}, '已放入回收站');
      });
    }

    const batchRecoverBtn = $('leadBatchRecover');
    if (canGovernance && batchRecoverBtn) {
      batchRecoverBtn.addEventListener('click', async () => {
        await runLeadLifecycle('recover', {}, '回收站恢复完成');
      });
    }

    if (canEdit) {
      el.viewRoot.querySelectorAll('[data-lead-edit]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const id = Number(btn.getAttribute('data-lead-edit') || 0);
          if (!id) return;
          const row = rows.find((item) => Number(item.id || 0) === id);
          if (!row) return;
          ui.editing = {
            id,
            lead_name: row.lead_name || '',
            mobile: row.mobile || '',
            email: row.email || '',
            company_name: row.company_name || '',
            country_code: row.country_code || '',
            source_channel: row.source_channel || '',
            intent_level: row.intent_level || 'warm',
            status: row.status || 'new',
            next_followup_at: row.next_followup_at || '',
            custom_fields: row.custom_fields && typeof row.custom_fields === 'object' ? { ...row.custom_fields } : {},
          };
          await renderLeads();
        });
      });
    }

    el.viewRoot.querySelectorAll('[data-lead-detail]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.getAttribute('data-lead-detail') || 0);
        if (!id) return;
        const row = rows.find((item) => Number(item.id || 0) === id);
        if (!row) return;
        await openEntityDetail('lead', row);
      });
    });

    if (canAssign) {
      el.viewRoot.querySelectorAll('[data-lead-public]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const id = Number(btn.getAttribute('data-lead-public') || 0);
          if (!id) return;
          try {
            await request('POST', '/crm/governance/lifecycle', {
              body: {
                entity_type: 'lead',
                action: 'public_pool',
                entity_ids: [id],
              },
            });
            toast('线索已转入公海');
            await renderLeads();
          } catch (err) {
            toast(err.message || '操作失败', true);
          }
        });
      });

      el.viewRoot.querySelectorAll('[data-lead-claim]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const id = Number(btn.getAttribute('data-lead-claim') || 0);
          if (!id) return;
          try {
            await request('POST', '/crm/governance/lifecycle', {
              body: {
                entity_type: 'lead',
                action: 'claim',
                entity_ids: [id],
              },
            });
            toast('线索认领成功');
            await renderLeads();
          } catch (err) {
            toast(err.message || '认领失败', true);
          }
        });
      });
    }

    const duplicateForm = $('leadDuplicateForm');
    const duplicateResultEl = $('leadDuplicateResult');
    if (duplicateForm && duplicateResultEl) {
      duplicateForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(duplicateForm);
        const mobile = String(fd.get('mobile') || '').trim();
        const email = String(fd.get('email') || '').trim();
        if (!mobile && !email) {
          toast('请输入手机号或邮箱', true);
          return;
        }
        duplicateResultEl.textContent = '检测中...';
        try {
          const payload = await request('GET', '/crm/governance/duplicates', {
            query: {
              entity_type: 'lead',
              mobile,
              email,
              limit: 50,
            },
          });
          duplicateResultEl.textContent = JSON.stringify(payload, null, 2);
        } catch (err) {
          duplicateResultEl.textContent = err && err.message ? String(err.message) : '检测失败';
        }
      });
    }

    const mergeForm = $('leadMergeForm');
    if (canGovernance && mergeForm) {
      mergeForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(mergeForm);
        const primaryId = Number(fd.get('primary_id') || 0);
        const rawMergeIds = String(fd.get('merge_ids') || '').trim();
        const strategy = String(fd.get('strategy') || 'fill_empty').trim();
        const mergeIds = rawMergeIds
          .split(/[,\s，]+/)
          .map((v) => Number(v))
          .filter((v) => Number.isFinite(v) && v > 0);
        if (!primaryId || !mergeIds.length) {
          toast('请输入主线索ID和待合并ID', true);
          return;
        }
        try {
          await request('POST', '/crm/governance/merge', {
            body: {
              entity_type: 'lead',
              primary_id: primaryId,
              merge_ids: mergeIds,
              strategy,
            },
          });
          toast('线索合并完成');
          await renderLeads();
        } catch (err) {
          toast(err.message || '线索合并失败', true);
        }
      });
    }

    const ruleForm = $('leadRuleForm');
    if (canEditRules && ruleForm) {
      ruleForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(ruleForm);
        const ruleName = String(fd.get('rule_name') || '').trim();
        const strategy = String(fd.get('strategy') || 'round_robin').trim();
        const memberRaw = String(fd.get('member_user_ids') || '').trim();
        const memberIds = memberRaw
          .split(/[,\s，]+/)
          .map((v) => Number(v))
          .filter((v) => Number.isFinite(v) && v > 0);
        if (!ruleName || !memberIds.length) {
          toast('请填写规则名称和成员ID', true);
          return;
        }
        try {
          await request('POST', '/crm/assignment-rules', {
            body: {
              rule_name: ruleName,
              strategy,
              member_user_ids: memberIds,
              enabled: 1,
            },
          });
          toast('分配规则已保存');
          await renderLeads();
        } catch (err) {
          toast(err.message || '保存规则失败', true);
        }
      });
    }

    el.viewRoot.querySelectorAll('[data-rule-apply]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const ruleId = Number(btn.getAttribute('data-rule-apply') || 0);
        if (!ruleId) return;
        const selectedIds = selectedLeadIds();
        const body = {
          rule_id: ruleId,
        };
        if (selectedIds.length) {
          body.lead_ids = selectedIds;
        } else {
          body.limit = 50;
        }
        try {
          const payload = await request('POST', '/crm/assignment-rules/apply', { body });
          const summary = payload && payload.summary ? payload.summary : {};
          toast(`规则应用完成：分配 ${Number(summary.assigned_total || 0)} 条`);
          clearSelectedRows('leads');
          await renderLeads();
        } catch (err) {
          toast(err.message || '规则应用失败', true);
        }
      });
    });

    const editForm = $('leadEditForm');
    if (canEdit && editForm) {
      editForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(editForm);
        const body = Object.fromEntries(fd.entries());
        body.lead_id = Number(body.lead_id || 0);
        if (customFieldDefs.length) {
          body.custom_fields = collectCustomFieldValues(editForm, customFieldDefs);
        }
        if (!body.lead_id) {
          toast('lead_id 无效', true);
          return;
        }
        try {
          await request('POST', '/crm/leads/update', { body });
          ui.editing = null;
          toast('线索已更新');
          await renderLeads();
        } catch (err) {
          toast(err.message || '更新失败', true);
        }
      });
    }

    const editCancel = $('leadEditCancel');
    if (canEdit && editCancel) {
      editCancel.addEventListener('click', async () => {
        ui.editing = null;
        await renderLeads();
      });
    }

    if (canConvert) {
      el.viewRoot.querySelectorAll('[data-lead-convert]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const id = Number(btn.getAttribute('data-lead-convert') || 0);
          if (!id) return;
          try {
            await request('POST', '/crm/leads/convert', { body: { lead_id: id, create_deal: 1 } });
            ui.cursor = 0;
            ui.prev = [];
            const selected = ensureSelectedMap('leads');
            delete selected[String(id)];
            toast('线索已转化');
            await renderLeads();
          } catch (err) {
            toast(err.message || '转化失败', true);
          }
        });
      });
    }

    bindPager('leads', pagination, renderLeads);
  }
