let runtimeCtx = null;

const REMINDER_TYPES = [
  { value: 'schedule', label: '日程提醒' },
  { value: 'due', label: '到期提醒' },
  { value: 'overdue', label: '逾期提醒' },
];

const DEFAULT_PUSH_SETTINGS = {
  enabled: 0,
  channel_ids: [],
  reminder_types: ['schedule', 'due', 'overdue'],
  title_prefix: '【启灵CRM提醒】',
  template: '类型：{reminder_type_label}\n标题：{title}\n截止：{due_at}\n内容：{content}',
  max_per_run: 50,
  only_created: 1,
};

export function setRenderRemindersContext(ctx) {
  runtimeCtx = ctx || null;
}

function providerZh(provider) {
  const p = String(provider || '').trim().toLowerCase();
  if (p === 'feishu') return '飞书';
  if (p === 'dingtalk') return '钉钉';
  return p || '-';
}

export async function renderReminders() {
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
    $,
    setFilters,
    readFormFilters,
    toast,
    bindPager,
    pagerHtml,
    zhCrmReminderType,
    zhCrmReminderStatus,
  } = ctx;

  screenTitle('提醒中心', '日程提醒、到期提醒、逾期提醒');
  const ui = uiState('reminders');
  const filters = ui.filters || {};
  const canEdit = hasPermission('crm.reminders.edit');

  let summary = { unread_count: 0, read_count: 0, overdue_unread_count: 0 };
  try {
    const payload = await request('GET', '/crm/reminders/summary');
    summary = payload && payload.summary ? payload.summary : summary;
  } catch (_err) {
    summary = { unread_count: 0, read_count: 0, overdue_unread_count: 0 };
  }

  const payload = await request('GET', '/crm/reminders', { query: listQuery('reminders') });
  const rows = Array.isArray(payload.data) ? payload.data : [];
  const pagination = payload && payload.pagination ? payload.pagination : {};

  let rules = [];
  try {
    const rp = await request('GET', '/crm/reminder-rules');
    rules = Array.isArray(rp.data) ? rp.data : [];
  } catch (_err) {
    rules = [];
  }

  let pushSettings = { ...DEFAULT_PUSH_SETTINGS };
  let pushEditable = false;
  let channelOptions = [];
  if (canEdit) {
    try {
      const pp = await request('GET', '/crm/reminders/push-settings');
      pushSettings = { ...DEFAULT_PUSH_SETTINGS, ...(pp && pp.settings ? pp.settings : {}) };
      pushEditable = Number(pp && pp.editable ? pp.editable : 0) === 1;
      channelOptions = Array.isArray(pp && pp.channel_options ? pp.channel_options : [])
        ? pp.channel_options
        : [];
    } catch (_err) {
      pushSettings = { ...DEFAULT_PUSH_SETTINGS };
      pushEditable = false;
      channelOptions = [];
    }
  }

  const selectedChannelIds = new Set(
    Array.isArray(pushSettings.channel_ids)
      ? pushSettings.channel_ids.map((id) => Number(id || 0)).filter((id) => id > 0)
      : []
  );
  const selectedTypes = new Set(
    Array.isArray(pushSettings.reminder_types)
      ? pushSettings.reminder_types.map((item) => String(item || '').trim())
      : ['schedule', 'due', 'overdue']
  );

  const channelHtml = channelOptions.length
    ? channelOptions
        .map((item) => {
          const id = Number(item.id || 0);
          const checked = selectedChannelIds.has(id) ? ' checked' : '';
          const providerText = providerZh(item.provider);
          const name = `${item.channel_name || item.channel_code || `渠道${id}`}（${providerText}）`;
          return `<label class="check-item"><input type="checkbox" name="channel_ids" value="${id}"${checked} /><span>${escapeHtml(
            name
          )}</span></label>`;
        })
        .join('')
    : '<small class="muted">未检测到启用的飞书/钉钉渠道。请先到默认后台配置推送渠道。</small>';

  const typeHtml = REMINDER_TYPES.map((item) => {
    const checked = selectedTypes.has(item.value) ? ' checked' : '';
    return `<label class="check-item"><input type="checkbox" name="reminder_types" value="${item.value}"${checked} /><span>${item.label}</span></label>`;
  }).join('');

  el.viewRoot.innerHTML = `
    <section class="card">
      <h3>提醒统计</h3>
      <div class="stats">
        <div class="stat-card"><small>未读提醒</small><strong>${escapeHtml(summary.unread_count || 0)}</strong></div>
        <div class="stat-card"><small>已读提醒</small><strong>${escapeHtml(summary.read_count || 0)}</strong></div>
        <div class="stat-card"><small>逾期未读</small><strong>${escapeHtml(summary.overdue_unread_count || 0)}</strong></div>
      </div>
    </section>

    <section class="card" style="margin-top:12px;">
      <h3>提醒列表</h3>
        <form id="reminderFilterForm" class="toolbar">
          <select name="status">
            <option value="">全部状态</option>
            <option value="unread"${optionSelected(filters.status, 'unread')}>未读</option>
            <option value="read"${optionSelected(filters.status, 'read')}>已读</option>
          </select>
          <select name="reminder_type">
            <option value="">全部类型</option>
            <option value="schedule"${optionSelected(filters.reminder_type, 'schedule')}>日程提醒</option>
            <option value="due"${optionSelected(filters.reminder_type, 'due')}>到期提醒</option>
            <option value="overdue"${optionSelected(filters.reminder_type, 'overdue')}>逾期提醒</option>
          </select>
        <button class="btn btn-primary" type="submit">筛选</button>
        <button class="btn btn-ghost" type="button" id="reminderFilterReset">重置</button>
        <button class="btn btn-ghost" type="button" id="reminderMarkAllRead">全部标记已读</button>
      </form>
      <div class="table-wrap">
        <table>
          <thead><tr><th>ID</th><th>类型</th><th>标题</th><th>截止时间</th><th>状态</th><th>操作</th></tr></thead>
          <tbody>
            ${
              rows.length
                ? rows
                    .map(
                      (row) => `<tr>
                          <td>${escapeHtml(row.id)}</td>
                          <td>${escapeHtml(zhCrmReminderType(row.reminder_type || '-'))}</td>
                          <td>${escapeHtml(row.title || '-')}</td>
                          <td>${escapeHtml(row.due_at || '-')}</td>
                          <td>${escapeHtml(zhCrmReminderStatus(row.status || '-'))}</td>
                          <td>${
                            String(row.status || '') === 'unread'
                              ? `<button class="btn btn-ghost" type="button" data-reminder-read="${escapeHtml(row.id)}">标记已读</button>`
                              : '<span style="color:#6f8091;">-</span>'
                          }</td>
                        </tr>`
                    )
                    .join('')
                : '<tr><td colspan="6" class="empty">暂无提醒</td></tr>'
            }
          </tbody>
        </table>
      </div>
      ${pagerHtml('reminders', pagination)}
    </section>

    ${
      canEdit
        ? `<section class="card" style="margin-top:12px;">
            <h3>飞书/钉钉提醒推送</h3>
            <form id="reminderPushForm" class="form-grid" style="margin-top:10px;">
              <fieldset style="border:0;padding:0;margin:0;"${pushEditable ? '' : ' disabled'}>
                <div class="grid-3">
                  <label><span>启用推送</span><select name="enabled"><option value="1"${optionSelected(
                    pushSettings.enabled,
                    '1'
                  )}>是</option><option value="0"${optionSelected(pushSettings.enabled, '0')}>否</option></select></label>
                  <label><span>单次推送上限</span><input name="max_per_run" type="number" min="1" max="500" value="${escapeHtml(
                    pushSettings.max_per_run || 50
                  )}" /></label>
                  <label><span>推送策略</span><select name="only_created"><option value="1"${optionSelected(
                    pushSettings.only_created,
                    '1'
                  )}>仅推送本次新生成提醒</option><option value="0"${optionSelected(
                    pushSettings.only_created,
                    '0'
                  )}>匹配到的提醒都推送</option></select></label>
                </div>
                <div style="margin-top:10px;">
                  <span class="muted">推送渠道（多选，留空表示全部启用渠道）</span>
                  <div class="check-grid" style="margin-top:8px;">${channelHtml}</div>
                </div>
                <div style="margin-top:10px;">
                  <span class="muted">提醒类型（多选）</span>
                  <div class="check-grid" style="margin-top:8px;">${typeHtml}</div>
                </div>
                <div class="grid-2" style="margin-top:10px;">
                  <label><span>标题前缀</span><input name="title_prefix" maxlength="40" value="${escapeHtml(
                    pushSettings.title_prefix || '【启灵CRM提醒】'
                  )}" /></label>
                  <label><span>可用变量</span><input value="{prefix} {title} {content} {due_at} {reminder_type_label}" disabled /></label>
                </div>
                <label style="margin-top:10px;"><span>消息模板</span><textarea name="template" placeholder="支持变量：{title} {content} {due_at} {reminder_type_label}">${escapeHtml(
                  pushSettings.template || ''
                )}</textarea></label>
              </fieldset>
              <div class="toolbar" style="margin:0;">
                <button class="btn btn-primary" type="submit"${pushEditable ? '' : ' disabled'}>保存推送设置</button>
                <small style="color:#6f8091;">${pushEditable ? '保存后，点击“立即生成提醒”会自动推送到飞书/钉钉。' : '仅拥有 CRM 全部管理权限的账号可修改该配置。'}</small>
              </div>
            </form>
          </section>

          <section class="card" style="margin-top:12px;">
            <h3>提醒规则</h3>
            <div class="table-wrap">
              <table>
                <thead><tr><th>规则</th><th>类型</th><th>偏移分钟</th><th>启用</th></tr></thead>
                <tbody>
                  ${
                    rules.length
                      ? rules
                          .map(
                            (rule) => `<tr>
                              <td>${escapeHtml(rule.rule_name || rule.rule_code || '-')}</td>
                              <td>${escapeHtml(zhCrmReminderType(rule.remind_type || '-'))}</td>
                              <td>${escapeHtml(rule.offset_minutes || 0)}</td>
                              <td>${Number(rule.enabled || 0) === 1 ? '是' : '否'}</td>
                            </tr>`
                          )
                          .join('')
                      : '<tr><td colspan="4" class="empty">暂无规则</td></tr>'
                  }
                </tbody>
              </table>
            </div>
            <form id="reminderRuleForm" class="grid-3" style="margin-top:10px;">
              <label><span>规则编码</span><input name="rule_code" placeholder="activity_due_0" required /></label>
              <label><span>规则名称</span><input name="rule_name" required /></label>
              <label><span>类型</span><select name="remind_type"><option value="schedule">日程提醒</option><option value="due">到期提醒</option><option value="overdue">逾期提醒</option></select></label>
              <label><span>偏移分钟</span><input name="offset_minutes" type="number" min="0" value="0" /></label>
              <label><span>启用</span><select name="enabled"><option value="1">是</option><option value="0">否</option></select></label>
              <div><button class="btn btn-primary" type="submit">保存规则</button></div>
            </form>
            <div class="toolbar" style="margin-top:10px;">
              <button class="btn btn-primary" type="button" id="reminderRunBtn">立即生成提醒</button>
              <input id="reminderRunLimit" type="number" min="1" max="1000" value="200" />
              <small style="color:#6f8091;">生成后会写入提醒列表，并按推送设置发送飞书/钉钉提醒</small>
            </div>
          </section>`
        : ''
    }
  `;

  const filterForm = $('reminderFilterForm');
  if (filterForm) {
    filterForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      setFilters('reminders', readFormFilters(filterForm));
      await renderReminders();
    });
  }
  const filterReset = $('reminderFilterReset');
  if (filterReset) {
    filterReset.addEventListener('click', async () => {
      if (filterForm) filterForm.reset();
      setFilters('reminders', {});
      await renderReminders();
    });
  }

  el.viewRoot.querySelectorAll('[data-reminder-read]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = Number(btn.getAttribute('data-reminder-read') || 0);
      if (!id) return;
      try {
        await request('POST', '/crm/reminders/read', { body: { notification_ids: [id] } });
        toast('已标记为已读');
        await renderReminders();
      } catch (err) {
        toast(err.message || '操作失败', true);
      }
    });
  });

  const markAllBtn = $('reminderMarkAllRead');
  if (markAllBtn) {
    markAllBtn.addEventListener('click', async () => {
      try {
        await request('POST', '/crm/reminders/read', { body: { mark_all: 1 } });
        toast('已全部标记已读');
        await renderReminders();
      } catch (err) {
        toast(err.message || '操作失败', true);
      }
    });
  }

  const pushForm = $('reminderPushForm');
  if (canEdit && pushForm) {
    pushForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!pushEditable) {
        toast('当前账号无权限修改推送设置', true);
        return;
      }

      const fd = new FormData(pushForm);
      const channelIds = Array.from(pushForm.querySelectorAll('input[name="channel_ids"]:checked'))
        .map((input) => Number(input.value || 0))
        .filter((id) => id > 0);
      const reminderTypes = Array.from(pushForm.querySelectorAll('input[name="reminder_types"]:checked'))
        .map((input) => String(input.value || '').trim())
        .filter((value) => value !== '');

      if (!reminderTypes.length) {
        toast('请至少选择一种提醒类型', true);
        return;
      }

      const maxPerRunRaw = Number(fd.get('max_per_run') || 50);
      const maxPerRun = Number.isFinite(maxPerRunRaw) ? Math.max(1, Math.min(500, maxPerRunRaw)) : 50;

      const body = {
        enabled: Number(fd.get('enabled') || 0) === 1 ? 1 : 0,
        channel_ids: channelIds,
        reminder_types: reminderTypes,
        title_prefix: String(fd.get('title_prefix') || ''),
        template: String(fd.get('template') || ''),
        max_per_run: maxPerRun,
        only_created: Number(fd.get('only_created') || 1) === 1 ? 1 : 0,
      };

      try {
        await request('POST', '/crm/reminders/push-settings', { body });
        toast('推送设置已保存');
        await renderReminders();
      } catch (err) {
        toast(err.message || '保存失败', true);
      }
    });
  }

  const ruleForm = $('reminderRuleForm');
  if (canEdit && ruleForm) {
    ruleForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(ruleForm);
      const body = Object.fromEntries(fd.entries());
      body.offset_minutes = Number(body.offset_minutes || 0);
      body.enabled = Number(body.enabled || 0) === 1 ? 1 : 0;
      try {
        await request('POST', '/crm/reminder-rules', { body });
        toast('规则已保存');
        ruleForm.reset();
        await renderReminders();
      } catch (err) {
        toast(err.message || '保存失败', true);
      }
    });
  }

  const runBtn = $('reminderRunBtn');
  if (canEdit && runBtn) {
    runBtn.addEventListener('click', async () => {
      const limitEl = $('reminderRunLimit');
      const limit = limitEl ? Number(limitEl.value || 200) : 200;
      try {
        const result = await request('POST', '/crm/reminders/run', {
          body: { limit: Number.isFinite(limit) ? limit : 200 },
        });
        const s = result && result.summary ? result.summary : {};
        const push = result && result.push ? result.push : null;
        if (push && Number(push.enabled || 0) === 1) {
          toast(
            `生成完成：新增${Number(s.created || 0)}，重复${Number(
              s.duplicated || 0
            )}，推送${Number(push.notification_sent || 0)}条`
          );
          if (String(push.message || '').trim() !== '') {
            toast(`推送提示：${String(push.message || '')}`, true);
          }
        } else {
          toast(`生成完成：新增${Number(s.created || 0)}，重复${Number(s.duplicated || 0)}`);
        }
        await renderReminders();
      } catch (err) {
        toast(err.message || '执行失败', true);
      }
    });
  }

  bindPager('reminders', pagination, renderReminders);
}
