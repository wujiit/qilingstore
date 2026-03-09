let runtimeCtx = null;

export function setRenderAutomationContext(ctx) {
  runtimeCtx = ctx || null;
}

function safeJsonParse(text, fallback) {
  try {
    const parsed = JSON.parse(text);
    return parsed && typeof parsed === 'object' ? parsed : fallback;
  } catch (_err) {
    return fallback;
  }
}

function prettyJson(value) {
  try {
    return JSON.stringify(value || {}, null, 2);
  } catch (_err) {
    return '{}';
  }
}

function trimText(value) {
  return String(value == null ? '' : value).trim();
}

export async function renderAutomation() {
  const ctx = runtimeCtx || {};
  const {
    screenTitle,
    request,
    el,
    escapeHtml,
    optionSelected,
    zhCrmValue,
    $, 
    toast,
    uiState,
    setFilters,
    readFormFilters,
  } = ctx;

  screenTitle('自动化引擎', '状态触发动作：建任务、分配Owner、提醒、Webhook');
  const ui = uiState('automation');
  const filters = ui.filters || {};

  const [rulesPayload, logsPayload] = await Promise.all([
    request('GET', '/crm/automation/rules', {
      query: {
        limit: 200,
        entity_type: filters.entity_type || '',
        enabled: filters.enabled || '',
      },
    }),
    request('GET', '/crm/automation/logs', { query: { limit: 80 } }),
  ]);

  const rules = Array.isArray(rulesPayload.data) ? rulesPayload.data : [];
  const logs = Array.isArray(logsPayload.data) ? logsPayload.data : [];
  const meta = rulesPayload && rulesPayload.meta ? rulesPayload.meta : {};
  const actionTypes = meta && meta.action_types ? meta.action_types : {};

  const ruleOptions = rules
    .map((item) => `<option value="${escapeHtml(item.id)}">#${escapeHtml(item.id)} ${escapeHtml(item.rule_name || '-')}</option>`)
    .join('');

  el.viewRoot.innerHTML = `
    <section class="card">
      <h3>规则筛选</h3>
      <form id="automationFilterForm" class="toolbar">
        <select name="entity_type">
          <option value="">全部实体</option>
          <option value="lead"${optionSelected(filters.entity_type, 'lead')}>线索</option>
          <option value="contact"${optionSelected(filters.entity_type, 'contact')}>联系人</option>
          <option value="company"${optionSelected(filters.entity_type, 'company')}>企业</option>
          <option value="deal"${optionSelected(filters.entity_type, 'deal')}>商机</option>
        </select>
        <select name="enabled">
          <option value="">全部状态</option>
          <option value="1"${optionSelected(filters.enabled, '1')}>启用</option>
          <option value="0"${optionSelected(filters.enabled, '0')}>停用</option>
        </select>
        <button class="btn btn-primary" type="submit">筛选</button>
        <button class="btn btn-ghost" type="button" id="automationFilterReset">重置</button>
      </form>
    </section>

    <section class="card" style="margin-top:12px;">
      <h3>规则配置</h3>
      <form id="automationRuleForm" class="grid-3">
        <label><span>规则ID（编辑可填）</span><input name="id" type="number" min="0" /></label>
        <label><span>规则名称</span><input name="rule_name" required /></label>
        <label><span>排序</span><input name="sort_order" type="number" min="1" max="9999" value="100" /></label>

        <label><span>实体</span>
          <select name="entity_type">
            <option value="lead">线索</option>
            <option value="contact">联系人</option>
            <option value="company">企业</option>
            <option value="deal" selected>商机</option>
          </select>
        </label>

        <label><span>触发字段</span>
          <select name="trigger_field">
            <option value="status">状态(status)</option>
            <option value="deal_status">商机状态(deal_status)</option>
            <option value="stage_key">阶段(stage_key)</option>
            <option value="pipeline_key">管道(pipeline_key)</option>
          </select>
        </label>

        <label><span>启用</span>
          <select name="enabled">
            <option value="1">是</option>
            <option value="0">否</option>
          </select>
        </label>

        <label><span>从(from)</span><input name="trigger_from" placeholder="可留空，代表任意" /></label>
        <label><span>到(to)</span><input name="trigger_to" placeholder="如 won / qualified" required /></label>
        <label><span>动作类型</span>
          <select name="action_type">
            <option value="create_task">创建任务</option>
            <option value="assign_owner">分配Owner</option>
            <option value="create_reminder">创建提醒</option>
            <option value="webhook">Webhook</option>
          </select>
        </label>

        <label style="grid-column:1 / -1;"><span>动作配置(JSON)</span><textarea name="action_config_json" placeholder='{"subject":"线索推进任务","due_in_minutes":1440}'></textarea></label>
        <div>
          <button class="btn btn-primary" type="submit">保存规则</button>
          <button class="btn btn-ghost" type="button" id="automationRuleClear">清空表单</button>
        </div>
      </form>

      <div class="table-wrap" style="margin-top:12px;">
        <table>
          <thead><tr><th>ID</th><th>名称</th><th>触发</th><th>动作</th><th>启用</th><th>操作</th></tr></thead>
          <tbody>
            ${
              rules.length
                ? rules
                    .map(
                      (row, idx) => `<tr>
                        <td>${escapeHtml(row.id)}</td>
                        <td>${escapeHtml(row.rule_name || '-')}</td>
                        <td>${escapeHtml((row.entity_type || '-') + '.' + (row.trigger_field || '-') + ': ' + (row.trigger_from || '*') + ' -> ' + (row.trigger_to || '-'))}</td>
                        <td>${escapeHtml(zhCrmValue(row.action_type || '-') || (actionTypes[row.action_type] || row.action_type || '-'))}</td>
                        <td>${Number(row.enabled || 0) === 1 ? '是' : '否'}</td>
                        <td><button class="btn btn-ghost" type="button" data-rule-fill="${idx}">载入编辑</button></td>
                      </tr>`
                    )
                    .join('')
                : '<tr><td colspan="6" class="empty">暂无自动化规则</td></tr>'
            }
          </tbody>
        </table>
      </div>
    </section>

    <section class="card" style="margin-top:12px;">
      <h3>执行日志</h3>
      <form id="automationLogFilterForm" class="toolbar">
        <select name="rule_id">
          <option value="">全部规则</option>
          ${ruleOptions}
        </select>
        <select name="status">
          <option value="">全部结果</option>
          <option value="success">成功</option>
          <option value="failed">失败</option>
        </select>
        <select name="entity_type">
          <option value="">全部实体</option>
          <option value="lead">线索</option>
          <option value="contact">联系人</option>
          <option value="company">企业</option>
          <option value="deal">商机</option>
        </select>
        <button class="btn btn-primary" type="submit">筛选日志</button>
      </form>
      <div class="table-wrap">
        <table>
          <thead><tr><th>ID</th><th>时间</th><th>规则</th><th>实体</th><th>动作</th><th>结果</th><th>信息</th></tr></thead>
          <tbody>
            ${
              logs.length
                ? logs
                    .map(
                      (row) => `<tr>
                        <td>${escapeHtml(row.id)}</td>
                        <td>${escapeHtml(row.executed_at || row.created_at || '-')}</td>
                        <td>${escapeHtml('#' + (row.rule_id || 0) + ' ' + (row.rule_name || '-'))}</td>
                        <td>${escapeHtml((row.entity_type || '-') + '#' + (row.entity_id || 0))}</td>
                        <td>${escapeHtml(zhCrmValue(row.action_type || '-') || row.action_type || '-')}</td>
                        <td>${escapeHtml(zhCrmValue(row.status || '-'))}</td>
                        <td>${escapeHtml(row.message || '-')}</td>
                      </tr>`
                    )
                    .join('')
                : '<tr><td colspan="7" class="empty">暂无执行日志</td></tr>'
            }
          </tbody>
        </table>
      </div>
    </section>
  `;

  const filterForm = $('automationFilterForm');
  if (filterForm) {
    filterForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      setFilters('automation', readFormFilters(filterForm));
      await renderAutomation();
    });
  }

  const filterReset = $('automationFilterReset');
  if (filterReset) {
    filterReset.addEventListener('click', async () => {
      if (filterForm) {
        filterForm.reset();
      }
      setFilters('automation', {});
      await renderAutomation();
    });
  }

  const ruleForm = $('automationRuleForm');
  const fillRuleForm = (row) => {
    if (!ruleForm || !row) return;
    ruleForm.id.value = row.id || '';
    ruleForm.rule_name.value = row.rule_name || '';
    ruleForm.sort_order.value = row.sort_order || 100;
    ruleForm.entity_type.value = row.entity_type || 'deal';
    ruleForm.trigger_field.value = row.trigger_field || 'status';
    ruleForm.enabled.value = Number(row.enabled || 0) === 1 ? '1' : '0';
    ruleForm.trigger_from.value = row.trigger_from || '';
    ruleForm.trigger_to.value = row.trigger_to || '';
    ruleForm.action_type.value = row.action_type || 'create_task';
    ruleForm.action_config_json.value = prettyJson(row.action_config || {});
  };

  el.viewRoot.querySelectorAll('[data-rule-fill]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const idx = Number(btn.getAttribute('data-rule-fill') || -1);
      if (idx < 0 || idx >= rules.length) return;
      fillRuleForm(rules[idx]);
      toast('已载入规则到表单');
    });
  });

  const ruleClear = $('automationRuleClear');
  if (ruleClear && ruleForm) {
    ruleClear.addEventListener('click', () => {
      ruleForm.reset();
      if (ruleForm.sort_order) ruleForm.sort_order.value = '100';
      if (ruleForm.action_config_json) {
        ruleForm.action_config_json.value = '';
      }
    });
  }

  if (ruleForm) {
    ruleForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(ruleForm);
      const body = {
        id: trimText(fd.get('id') || ''),
        rule_name: trimText(fd.get('rule_name') || ''),
        entity_type: trimText(fd.get('entity_type') || ''),
        trigger_field: trimText(fd.get('trigger_field') || ''),
        trigger_from: trimText(fd.get('trigger_from') || ''),
        trigger_to: trimText(fd.get('trigger_to') || ''),
        action_type: trimText(fd.get('action_type') || ''),
        sort_order: trimText(fd.get('sort_order') || '100'),
        enabled: trimText(fd.get('enabled') || '1'),
      };
      if (!body.id) delete body.id;

      const rawConfig = trimText(fd.get('action_config_json') || '');
      if (rawConfig) {
        const parsed = safeJsonParse(rawConfig, null);
        if (!parsed) {
          toast('动作配置 JSON 格式错误', true);
          return;
        }
        body.action_config = parsed;
      } else {
        body.action_config = {};
      }

      try {
        await request('POST', '/crm/automation/rules', { body });
        toast('规则已保存');
        await renderAutomation();
      } catch (err) {
        toast(err.message || '保存失败', true);
      }
    });
  }

  const logFilterForm = $('automationLogFilterForm');
  if (logFilterForm) {
    logFilterForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(logFilterForm);
      try {
        const payload = await request('GET', '/crm/automation/logs', {
          query: {
            limit: 80,
            rule_id: trimText(fd.get('rule_id') || ''),
            status: trimText(fd.get('status') || ''),
            entity_type: trimText(fd.get('entity_type') || ''),
          },
        });
        const list = Array.isArray(payload.data) ? payload.data : [];
        const tableBody = el.viewRoot.querySelector('section:last-of-type tbody');
        if (!tableBody) return;
        tableBody.innerHTML = list.length
          ? list
              .map(
                (row) => `<tr>
                  <td>${escapeHtml(row.id)}</td>
                  <td>${escapeHtml(row.executed_at || row.created_at || '-')}</td>
                  <td>${escapeHtml('#' + (row.rule_id || 0) + ' ' + (row.rule_name || '-'))}</td>
                  <td>${escapeHtml((row.entity_type || '-') + '#' + (row.entity_id || 0))}</td>
                  <td>${escapeHtml(zhCrmValue(row.action_type || '-') || row.action_type || '-')}</td>
                  <td>${escapeHtml(zhCrmValue(row.status || '-'))}</td>
                  <td>${escapeHtml(row.message || '-')}</td>
                </tr>`
              )
              .join('')
          : '<tr><td colspan="7" class="empty">暂无执行日志</td></tr>';
      } catch (err) {
        toast(err.message || '日志加载失败', true);
      }
    });
  }
}
