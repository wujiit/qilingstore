let runtimeCtx = null;

export function setRenderDealsContext(ctx) {
  runtimeCtx = ctx || null;
}

export async function renderDeals() {
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
    toast,
    openEntityDetail,
    bindPager,
    pagerHtml,
    asMoney,
    zhCrmDealStatus,
    zhCrmStage,
    loadCustomFields,
    loadFormConfig,
    sortCustomFields,
    customFieldInputsHtml,
    collectCustomFieldValues,
  } = ctx;
    screenTitle('商机管理', '交易管道、阶段与赢单金额');
    const ui = uiState('deals');
    const canEdit = hasPermission('crm.deals.edit');
    const canViewCustomFields = hasPermission('crm.custom_fields.view');
    const canViewFormConfig = hasPermission('crm.form_config.view');
    const [payload, customFieldDefs] = await Promise.all([
      request('GET', '/crm/deals', { query: listQuery('deals') }),
      canEdit && canViewCustomFields
        ? (async () => {
            try {
              const [defs, formConfig] = await Promise.all([
                loadCustomFields('deal'),
                canViewFormConfig ? loadFormConfig('deal') : Promise.resolve(null),
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
    const viewMode = ui.view_mode === 'kanban' ? 'kanban' : 'table';
    const editing = ui.editing && Number(ui.editing.id || 0) > 0 ? ui.editing : null;
    const selectedTotal = selectedCount('deals');

    const stageSort = {
      new: 10,
      contacted: 20,
      qualified: 30,
      proposal: 40,
      negotiation: 50,
      won: 60,
      lost: 70,
    };

    const stageMap = {};
    rows.forEach((row) => {
      const key = String(row.stage_key || 'unassigned').trim() || 'unassigned';
      if (!stageMap[key]) {
        stageMap[key] = [];
      }
      stageMap[key].push(row);
    });
    const stageKeys = Object.keys(stageMap).sort((a, b) => {
      const wa = Number(stageSort[a] || 999);
      const wb = Number(stageSort[b] || 999);
      if (wa !== wb) return wa - wb;
      return String(a).localeCompare(String(b));
    });

    const boardHtml = stageKeys.length
      ? `<div class="kanban-board">
          ${
            stageKeys
              .map((stage) => {
                const cards = stageMap[stage] || [];
                return `<section class="kanban-col"${canEdit ? ` data-stage-drop="${escapeHtml(stage)}"` : ''}>
                    <h4>${escapeHtml(zhCrmStage(stage))} · ${cards.length}</h4>
                    <div class="kanban-stack">
                      ${
                        cards
                          .map(
                            (r) => `<article class="kanban-card"${canEdit ? ' draggable="true"' : ''} data-deal-drag="${escapeHtml(r.id)}">
                                <b>#${escapeHtml(r.id)} ${escapeHtml(r.deal_name || '-')}</b>
                                <p class="kanban-meta">${escapeHtml(r.company_name || '-')} / ${escapeHtml(r.contact_name || '-')}</p>
                                <p class="kanban-meta">${escapeHtml((r.currency_code || 'CNY') + ' ' + asMoney(r.amount || 0))} · ${escapeHtml(zhCrmDealStatus(r.deal_status || '-'))}</p>
                                <div style="margin-top:6px;">
                                  <button type="button" class="btn btn-ghost" data-deal-detail="${escapeHtml(r.id)}">详情</button>
                                </div>
                              </article>`
                          )
                          .join('')
                      }
                    </div>
                  </section>`;
              })
              .join('')
          }
        </div>`
      : '<p class="empty">暂无商机</p>';

    el.viewRoot.innerHTML = `
      ${
        canEdit
          ? `<section class="card">
              <h3>新增商机</h3>
              <form id="dealCreateForm" class="grid-3">
                <label><span>商机名称</span><input name="deal_name" required /></label>
                <label><span>企业ID</span><input name="company_id" type="number" min="0" /></label>
                <label><span>联系人ID</span><input name="contact_id" type="number" min="0" /></label>
                <label><span>币种</span><input name="currency_code" value="CNY" /></label>
                <label><span>金额</span><input name="amount" type="number" step="0.01" min="0" value="0" /></label>
                <label><span>状态</span><select name="deal_status"><option value="open" selected>进行中</option><option value="won">赢单</option><option value="lost">输单</option></select></label>
                <label><span>管道</span><input name="pipeline_key" value="default" /></label>
                <label><span>阶段</span><input name="stage_key" value="new" /></label>
                ${
                  customFieldDefs.length
                    ? `<div style="grid-column:1 / -1;">
                        <span style="display:block;margin-bottom:6px;">自定义字段</span>
                        ${customFieldInputsHtml(customFieldDefs)}
                      </div>`
                    : ''
                }
                <div><button class="btn btn-primary" type="submit">创建商机</button></div>
              </form>
            </section>`
          : ''
      }
      ${
        canEdit && editing
          ? `<section class="card" style="margin-top:12px;">
              <h3>编辑商机 #${escapeHtml(editing.id)}</h3>
              <form id="dealEditForm" class="grid-3">
                <input type="hidden" name="deal_id" value="${escapeHtml(editing.id)}" />
                <label><span>商机名称</span><input name="deal_name" required value="${escapeHtml(editing.deal_name || '')}" /></label>
                <label><span>企业ID</span><input name="company_id" type="number" min="0" value="${escapeHtml(editing.company_id || '')}" /></label>
                <label><span>联系人ID</span><input name="contact_id" type="number" min="0" value="${escapeHtml(editing.contact_id || '')}" /></label>
                <label><span>线索ID</span><input name="lead_id" type="number" min="0" value="${escapeHtml(editing.lead_id || '')}" /></label>
                <label><span>管道</span><input name="pipeline_key" value="${escapeHtml(editing.pipeline_key || 'default')}" /></label>
                <label><span>阶段</span><input name="stage_key" value="${escapeHtml(editing.stage_key || 'new')}" /></label>
                <label><span>状态</span>
                  <select name="deal_status">
                    <option value="open"${optionSelected(editing.deal_status, 'open')}>进行中</option>
                    <option value="won"${optionSelected(editing.deal_status, 'won')}>赢单</option>
                    <option value="lost"${optionSelected(editing.deal_status, 'lost')}>输单</option>
                  </select>
                </label>
                <label><span>币种</span><input name="currency_code" value="${escapeHtml(editing.currency_code || 'CNY')}" /></label>
                <label><span>金额</span><input name="amount" type="number" step="0.01" min="0" value="${escapeHtml(editing.amount || 0)}" /></label>
                <label><span>预计成交日</span><input name="expected_close_date" placeholder="2026-03-31" value="${escapeHtml(editing.expected_close_date || '')}" /></label>
                <label><span>输单原因</span><input name="lost_reason" value="${escapeHtml(editing.lost_reason || '')}" /></label>
                <label><span>来源渠道</span><input name="source_channel" value="${escapeHtml(editing.source_channel || '')}" /></label>
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
                  <button class="btn btn-ghost" type="button" id="dealEditCancel">取消</button>
                </div>
              </form>
            </section>`
          : ''
      }
      <section class="card" style="margin-top:${canEdit ? '12px' : '0'};">
        <h3>商机列表</h3>
        <form id="dealFilterForm" class="toolbar">
          <input name="q" placeholder="搜索商机/企业/联系人" value="${escapeHtml(filters.q || '')}" />
          <select name="deal_status">
            <option value="">全部状态</option>
            <option value="open"${optionSelected(filters.deal_status, 'open')}>进行中</option>
            <option value="won"${optionSelected(filters.deal_status, 'won')}>赢单</option>
            <option value="lost"${optionSelected(filters.deal_status, 'lost')}>输单</option>
          </select>
          <input name="pipeline_key" placeholder="管道 key，如 default" value="${escapeHtml(filters.pipeline_key || '')}" />
          <input name="stage_key" placeholder="阶段 key，如 qualified" value="${escapeHtml(filters.stage_key || '')}" />
          <button class="btn btn-primary" type="submit">筛选</button>
          <button class="btn btn-ghost" type="button" id="dealFilterReset">重置</button>
        </form>
        ${
          canEdit
            ? `<div class="batch-bar">
                <small>已选 <b id="dealSelectedCount">${selectedTotal}</b> 条</small>
                <select id="dealBatchStatus">
                  <option value="">批量改状态</option>
                  <option value="open">进行中</option>
                  <option value="won">赢单</option>
                  <option value="lost">输单</option>
                </select>
                <button type="button" class="btn btn-primary" id="dealBatchApplyStatus">执行状态批量</button>
                <input id="dealBatchOwner" type="number" min="1" placeholder="批量改负责人ID" />
                <button type="button" class="btn btn-ghost" id="dealBatchApplyOwner">执行负责人批量</button>
                <button type="button" class="btn btn-ghost" id="dealBatchClear">清空选择</button>
              </div>`
            : ''
        }
        <div class="view-switch">
          <button type="button" class="btn btn-ghost${viewMode === 'table' ? ' is-active' : ''}" data-deal-mode="table">表格视图</button>
          <button type="button" class="btn btn-ghost${viewMode === 'kanban' ? ' is-active' : ''}" data-deal-mode="kanban">看板视图</button>
        </div>
        ${
          viewMode === 'kanban'
            ? boardHtml
            : `<div class="table-wrap">
                <table>
                  <thead><tr>
                    <th>${canEdit ? '<input type="checkbox" id="dealSelectPage" />' : 'ID'}</th><th>商机</th><th>企业</th><th>联系人</th><th>阶段</th><th>状态</th><th>金额</th><th>负责人</th><th>操作</th>
                  </tr></thead>
                  <tbody>
                    ${
                      rows.length
                        ? rows
                            .map((r) => {
                              const buttons = [];
                              buttons.push(`<button class="btn btn-ghost" data-deal-detail="${escapeHtml(r.id)}">详情</button>`);
                              if (canEdit) {
                                buttons.push(`<button class="btn btn-ghost" data-deal-edit="${escapeHtml(r.id)}">编辑</button>`);
                                if ((r.deal_status || '') !== 'won') {
                                  buttons.push(`<button class="btn btn-primary" data-deal-status="${escapeHtml(r.id)}" data-next="won">赢单</button>`);
                                }
                                if ((r.deal_status || '') !== 'lost') {
                                  buttons.push(`<button class="btn btn-danger" data-deal-status="${escapeHtml(r.id)}" data-next="lost">输单</button>`);
                                }
                                if ((r.deal_status || '') !== 'open') {
                                  buttons.push(`<button class="btn btn-ghost" data-deal-status="${escapeHtml(r.id)}" data-next="open">重开</button>`);
                                }
                              }
                              const actionHtml = buttons.length ? rowActions(buttons) : '<span style="color:#6f8091;">只读</span>';
                              const checked = canEdit && isRowSelected('deals', r.id) ? ' checked' : '';
                              return `<tr>
                                <td>${canEdit ? `<input type="checkbox" data-deal-select="${escapeHtml(r.id)}"${checked} />` : escapeHtml(r.id)}</td>
                                <td>${escapeHtml(r.deal_name || '-')}</td>
                                <td>${escapeHtml(r.company_name || '-')}</td>
                                <td>${escapeHtml(r.contact_name || '-')}</td>
                                <td>${escapeHtml((zhCrmStage(r.pipeline_key || '-') || '-') + '/' + (zhCrmStage(r.stage_key || '-') || '-'))}</td>
                                <td>${escapeHtml(zhCrmDealStatus(r.deal_status || '-'))}</td>
                                <td>${escapeHtml((r.currency_code || 'CNY') + ' ' + asMoney(r.amount || 0))}</td>
                                <td>${escapeHtml(r.owner_username || '-')}</td>
                                <td>${actionHtml}</td>
                              </tr>`;
                            })
                            .join('')
                        : `<tr><td colspan="9" class="empty">暂无商机</td></tr>`
                    }
                  </tbody>
                </table>
              </div>`
        }
        ${pagerHtml('deals', pagination)}
      </section>
    `;

    const form = $('dealCreateForm');
    if (canEdit && form) {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(form);
        const body = Object.fromEntries(fd.entries());
        if (!body.company_id) delete body.company_id;
        if (!body.contact_id) delete body.contact_id;
        if (customFieldDefs.length) {
          body.custom_fields = collectCustomFieldValues(form, customFieldDefs);
        }
        try {
          await request('POST', '/crm/deals', { body });
          ui.cursor = 0;
          ui.prev = [];
          ui.editing = null;
          toast('商机创建成功');
          await renderDeals();
        } catch (err) {
          toast(err.message || '创建失败', true);
        }
      });
    }

    const filterForm = $('dealFilterForm');
    if (filterForm) {
      filterForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        setFilters('deals', readFormFilters(filterForm));
        await renderDeals();
      });
    }
    const filterReset = $('dealFilterReset');
    if (filterReset) {
      filterReset.addEventListener('click', async () => {
        if (filterForm) filterForm.reset();
        setFilters('deals', {});
        clearSelectedRows('deals');
        await renderDeals();
      });
    }

    const selectedCountEl = $('dealSelectedCount');
    const syncDealSelectedCount = () => {
      if (selectedCountEl) {
        selectedCountEl.textContent = String(selectedCount('deals'));
      }
    };
    syncDealSelectedCount();

    const selectPage = $('dealSelectPage');
    if (canEdit && selectPage) {
      const allChecked = rows.length > 0 && rows.every((row) => isRowSelected('deals', row.id));
      selectPage.checked = allChecked;
      selectPage.addEventListener('change', () => {
        const checked = Boolean(selectPage.checked);
        rows.forEach((row) => setSelectedRow('deals', row, checked));
        el.viewRoot.querySelectorAll('[data-deal-select]').forEach((box) => {
          box.checked = checked;
        });
        syncDealSelectedCount();
      });
    }

    if (canEdit) {
      el.viewRoot.querySelectorAll('[data-deal-select]').forEach((box) => {
        box.addEventListener('change', () => {
          const id = Number(box.getAttribute('data-deal-select') || 0);
          if (!id) return;
          const row = rows.find((item) => Number(item.id || 0) === id);
          if (!row) return;
          setSelectedRow('deals', row, Boolean(box.checked));
          syncDealSelectedCount();
          if (selectPage) {
            selectPage.checked = rows.length > 0 && rows.every((item) => isRowSelected('deals', item.id));
          }
        });
      });
    }

    const batchClear = $('dealBatchClear');
    if (canEdit && batchClear) {
      batchClear.addEventListener('click', () => {
        clearSelectedRows('deals');
        el.viewRoot.querySelectorAll('[data-deal-select]').forEach((box) => {
          box.checked = false;
        });
        if (selectPage) selectPage.checked = false;
        syncDealSelectedCount();
      });
    }

    const batchStatusBtn = $('dealBatchApplyStatus');
    if (canEdit && batchStatusBtn) {
      batchStatusBtn.addEventListener('click', async () => {
        const statusEl = $('dealBatchStatus');
        const status = statusEl ? String(statusEl.value || '').trim() : '';
        if (!status) {
          toast('请选择批量状态', true);
          return;
        }
        const selected = selectedRows('deals');
        if (!selected.length) {
          toast('请先勾选商机', true);
          return;
        }
        const ownerEl = $('dealBatchOwner');
        const ownerId = ownerEl ? Number(ownerEl.value || 0) : 0;
        const dealIds = selected
          .map((item) => Number(item && item.id ? item.id : 0))
          .filter((id) => id > 0);
        if (!dealIds.length) {
          toast('勾选数据无效，请重试', true);
          return;
        }

        const body = {
          deal_ids: dealIds,
          deal_status: status,
        };
        if (ownerId > 0) {
          body.owner_user_id = ownerId;
        }

        try {
          const result = await request('POST', '/crm/deals/batch-update', { body });
          const s = result && result.summary ? result.summary : {};
          const ok = Number(s.updated || 0);
          const fail = Number(s.skipped_not_found || 0) + Number(s.skipped_forbidden || 0);
          toast(`批量状态完成：成功 ${ok}，失败 ${fail}`, fail > 0);
        } catch (err) {
          toast(err.message || '批量状态失败', true);
          return;
        }

        clearSelectedRows('deals');
        await renderDeals();
      });
    }

    const batchOwnerBtn = $('dealBatchApplyOwner');
    if (canEdit && batchOwnerBtn) {
      batchOwnerBtn.addEventListener('click', async () => {
        const ownerEl = $('dealBatchOwner');
        const ownerId = ownerEl ? Number(ownerEl.value || 0) : 0;
        if (ownerId <= 0) {
          toast('请输入负责人ID', true);
          return;
        }
        const selected = selectedRows('deals');
        if (!selected.length) {
          toast('请先勾选商机', true);
          return;
        }
        const dealIds = selected
          .map((item) => Number(item && item.id ? item.id : 0))
          .filter((id) => id > 0);
        if (!dealIds.length) {
          toast('勾选数据无效，请重试', true);
          return;
        }

        try {
          const result = await request('POST', '/crm/deals/batch-update', {
            body: {
              deal_ids: dealIds,
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

        clearSelectedRows('deals');
        await renderDeals();
      });
    }

    el.viewRoot.querySelectorAll('[data-deal-mode]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const mode = String(btn.getAttribute('data-deal-mode') || 'table');
        ui.view_mode = mode === 'kanban' ? 'kanban' : 'table';
        await renderDeals();
      });
    });

    el.viewRoot.querySelectorAll('[data-deal-detail]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.getAttribute('data-deal-detail') || 0);
        if (!id) return;
        const row = rows.find((item) => Number(item.id || 0) === id);
        if (!row) return;
        await openEntityDetail('deal', row);
      });
    });

    if (canEdit) {
      el.viewRoot.querySelectorAll('[data-deal-edit]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const id = Number(btn.getAttribute('data-deal-edit') || 0);
          if (!id) return;
          const row = rows.find((item) => Number(item.id || 0) === id);
          if (!row) return;
          ui.editing = {
            id,
            deal_name: row.deal_name || '',
            company_id: Number(row.company_id || 0) > 0 ? Number(row.company_id) : '',
            contact_id: Number(row.contact_id || 0) > 0 ? Number(row.contact_id) : '',
            lead_id: Number(row.lead_id || 0) > 0 ? Number(row.lead_id) : '',
            pipeline_key: row.pipeline_key || 'default',
            stage_key: row.stage_key || 'new',
            deal_status: row.deal_status || 'open',
            currency_code: row.currency_code || 'CNY',
            amount: Number(row.amount || 0),
            expected_close_date: row.expected_close_date || '',
            lost_reason: row.lost_reason || '',
            source_channel: row.source_channel || '',
            custom_fields: row.custom_fields && typeof row.custom_fields === 'object' ? { ...row.custom_fields } : {},
          };
          await renderDeals();
        });
      });

      el.viewRoot.querySelectorAll('[data-deal-status]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const id = Number(btn.getAttribute('data-deal-status') || 0);
          const status = String(btn.getAttribute('data-next') || '');
          if (!id || !status) return;
          const row = rows.find((item) => Number(item.id || 0) === id);
          if (!row) return;
          const body = {
            deal_id: id,
            deal_name: String(row.deal_name || `商机#${id}`),
            pipeline_key: String(row.pipeline_key || 'default'),
            stage_key: String(row.stage_key || 'new'),
            deal_status: status,
          };
          if (status === 'won') {
            body.won_at = new Date().toISOString().slice(0, 19).replace('T', ' ');
          }
          try {
            await request('POST', '/crm/deals/update', { body });
            ui.cursor = 0;
            ui.prev = [];
            const selected = ensureSelectedMap('deals');
            delete selected[String(id)];
            toast('商机状态已更新');
            await renderDeals();
          } catch (err) {
            toast(err.message || '更新失败', true);
          }
        });
      });
    }

    const editForm = $('dealEditForm');
    if (canEdit && editForm) {
      editForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(editForm);
        const body = Object.fromEntries(fd.entries());
        body.deal_id = Number(body.deal_id || 0);
        body.company_id = body.company_id ? Number(body.company_id) : 0;
        body.contact_id = body.contact_id ? Number(body.contact_id) : 0;
        body.lead_id = body.lead_id ? Number(body.lead_id) : 0;
        if (customFieldDefs.length) {
          body.custom_fields = collectCustomFieldValues(editForm, customFieldDefs);
        }
        if (!body.deal_id) {
          toast('deal_id 无效', true);
          return;
        }
        try {
          await request('POST', '/crm/deals/update', { body });
          ui.editing = null;
          toast('商机已更新');
          await renderDeals();
        } catch (err) {
          toast(err.message || '更新失败', true);
        }
      });
    }

    const editCancel = $('dealEditCancel');
    if (canEdit && editCancel) {
      editCancel.addEventListener('click', async () => {
        ui.editing = null;
        await renderDeals();
      });
    }

    if (viewMode === 'kanban' && canEdit) {
      let dragDealId = 0;
      el.viewRoot.querySelectorAll('[data-deal-drag]').forEach((card) => {
        card.addEventListener('dragstart', (e) => {
          dragDealId = Number(card.getAttribute('data-deal-drag') || 0);
          card.classList.add('dragging');
          if (e.dataTransfer && dragDealId > 0) {
            e.dataTransfer.setData('text/plain', String(dragDealId));
            e.dataTransfer.effectAllowed = 'move';
          }
        });
        card.addEventListener('dragend', () => {
          card.classList.remove('dragging');
          dragDealId = 0;
          el.viewRoot.querySelectorAll('[data-stage-drop]').forEach((col) => col.classList.remove('over'));
        });
      });

      el.viewRoot.querySelectorAll('[data-stage-drop]').forEach((col) => {
        col.addEventListener('dragover', (e) => {
          e.preventDefault();
          col.classList.add('over');
          if (e.dataTransfer) {
            e.dataTransfer.dropEffect = 'move';
          }
        });
        col.addEventListener('dragleave', () => {
          col.classList.remove('over');
        });
        col.addEventListener('drop', async (e) => {
          e.preventDefault();
          col.classList.remove('over');
          const targetStage = String(col.getAttribute('data-stage-drop') || '').trim();
          const dropIdRaw = e.dataTransfer ? e.dataTransfer.getData('text/plain') : '';
          const dealId = Number(dropIdRaw || dragDealId || 0);
          if (!targetStage || !dealId) return;
          const row = rows.find((item) => Number(item.id || 0) === dealId);
          if (!row) return;
          if (String(row.stage_key || '') === targetStage) return;
          try {
            await request('POST', '/crm/deals/update', {
              body: {
                deal_id: dealId,
                deal_name: String(row.deal_name || `商机#${dealId}`),
                pipeline_key: String(row.pipeline_key || 'default'),
                stage_key: targetStage,
                deal_status: String(row.deal_status || 'open'),
              },
            });
            toast(`已移动到阶段 ${targetStage}`);
            await renderDeals();
          } catch (err) {
            toast(err.message || '阶段更新失败', true);
          }
        });
      });
    }

    bindPager('deals', pagination, renderDeals);
  }
