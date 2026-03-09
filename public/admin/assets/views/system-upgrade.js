window.__QILING_ADMIN_VIEW_FACTORIES__ = window.__QILING_ADMIN_VIEW_FACTORIES__ || {};
window.__QILING_ADMIN_VIEW_FACTORIES__['system_upgrade'] = function (shared) {
  const {
    el,
    escapeHtml,
    jsonBox,
    bindJsonForm,
    bindCopyUrlButtons,
    request,
    openView,
    state,
    renderPageLinksCard,
  } = shared;

  async function renderSystemUpgrade() {
    let adminEntryPath = 'admin';
    try {
      const settingsRes = await request('GET', '/system/settings');
      const sys = (settingsRes && settingsRes.settings) ? settingsRes.settings : {};
      adminEntryPath = String(sys.admin_entry_path || 'admin').replace(/^\/+/, '') || 'admin';
    } catch (_err) {
    }

    let statusRes = null;
    let statusError = '';
    try {
      statusRes = await request('GET', '/system/upgrade/status');
    } catch (err) {
      statusError = err && err.message ? String(err.message) : '升级状态读取失败';
    }

    const targetVersion = String((statusRes && statusRes.target_version) || (statusRes && statusRes.release_version) || 'unknown');
    const currentVersion = String((statusRes && statusRes.current_version) || 'unknown');
    const schemaRelease = String((statusRes && statusRes.schema_release) || '-');
    const upgradeNeeded = !!(statusRes && statusRes.upgrade_needed);
    const versionUpgradeNeeded = !!(statusRes && statusRes.version_upgrade_needed);

    const pendingMigrations = (statusRes && statusRes.pending_migrations && typeof statusRes.pending_migrations === 'object')
      ? statusRes.pending_migrations
      : {};
    const pendingTablesCount = Number(pendingMigrations.missing_tables_count || 0);
    const pendingColumnsCount = Number(pendingMigrations.missing_columns_count || 0);
    const pendingIndexesCount = Number(pendingMigrations.missing_indexes_count || 0);

    const latestUpgrade = (statusRes && statusRes.latest_upgrade && typeof statusRes.latest_upgrade === 'object')
      ? statusRes.latest_upgrade
      : null;
    const latestSummaryText = latestUpgrade && latestUpgrade.summary
      ? JSON.stringify(latestUpgrade.summary, null, 2)
      : '尚未执行升级';
    const latestOperator = latestUpgrade
      ? (latestUpgrade.executed_by_username || (latestUpgrade.executed_by ? `用户#${latestUpgrade.executed_by}` : '系统'))
      : '-';
    const latestFinishedAt = latestUpgrade ? (latestUpgrade.finished_at || '-') : '-';
    const latestDurationMs = latestUpgrade ? Number(latestUpgrade.duration_ms || 0) : 0;

    const versionDiffText = (currentVersion !== 'unknown' && targetVersion !== 'unknown' && currentVersion !== targetVersion)
      ? `${currentVersion} -> ${targetVersion}`
      : (currentVersion === targetVersion ? `${currentVersion}（已是最新版）` : '-');

    const linksCard = renderPageLinksCard('快捷入口', [
      { label: '普通后台', path: `/${adminEntryPath}` },
      { label: 'CRM 后台', path: '/crm-admin' },
    ]);

    el.viewRoot.innerHTML = `
      <section class="row panel-top">
        <article class="card">
          <h3>版本信息</h3>
          <div class="form-grid">
            <label>
              <span>当前版本</span>
              <input value="${escapeHtml(currentVersion)}" readonly />
            </label>
            <label>
              <span>最新版本</span>
              <input value="${escapeHtml(targetVersion)}" readonly />
            </label>
            <label>
              <span>版本变化</span>
              <input value="${escapeHtml(versionDiffText)}" readonly />
            </label>
            <label>
              <span>Schema 发布号</span>
              <input value="${escapeHtml(schemaRelease)}" readonly />
            </label>
            <label>
              <span>是否建议升级</span>
              <input value="${upgradeNeeded ? '是（建议立即升级）' : '否（已匹配当前版本）'}" readonly />
            </label>
            <label>
              <span>版本差异</span>
              <input value="${versionUpgradeNeeded ? '有新版本可升级' : '无版本差异'}" readonly />
            </label>
          </div>
        </article>
        <article class="card">
          ${linksCard}
          <p class="small-note">建议升级流程：上传新版本代码 -> 进入本页 -> 执行自动升级。</p>
        </article>
      </section>

      <section class="card">
        <h3>系统升级中心</h3>
        <form id="formSystemUpgrade" class="form-grid" data-confirm="确定执行系统升级吗？建议先完成数据库备份。" data-success="升级执行完成。">
          <label>
            <span>最近升级时间</span>
            <input value="${escapeHtml(latestFinishedAt)}" readonly />
          </label>
          <label>
            <span>最近执行人</span>
            <input value="${escapeHtml(String(latestOperator || '-'))}" readonly />
          </label>
          <label>
            <span>待补数据表数量</span>
            <input value="${escapeHtml(String(pendingTablesCount))}" readonly />
          </label>
          <label>
            <span>待补字段数量</span>
            <input value="${escapeHtml(String(pendingColumnsCount))}" readonly />
          </label>
          <label>
            <span>待补索引数量</span>
            <input value="${escapeHtml(String(pendingIndexesCount))}" readonly />
          </label>
          <label>
            <span>最近耗时（毫秒）</span>
            <input value="${escapeHtml(String(latestDurationMs > 0 ? latestDurationMs : '-'))}" readonly />
          </label>
          <button class="btn btn-primary" type="submit"${statusError !== '' ? ' disabled' : ''}>执行自动升级（补表/补字段/补索引）</button>
        </form>
        ${statusError !== '' ? `<p class="small-note">升级状态读取失败：${escapeHtml(statusError)}</p>` : ''}
        <p class="small-note">该操作仅管理员可执行，可重复执行，不会覆盖已有业务数据。</p>
        <pre>${escapeHtml(String(latestSummaryText || ''))}</pre>
      </section>

      <section class="card"><h3>升级返回</h3>${jsonBox('systemUpgradeResult', '等待操作')}</section>
    `;
    bindCopyUrlButtons();

    if (statusError !== '') {
      return;
    }

    bindJsonForm('formSystemUpgrade', 'systemUpgradeResult', async () => {
      const runRes = await request('POST', '/system/upgrade/run');
      try {
        runRes.latest_status = await request('GET', '/system/upgrade/status');
      } catch (_e) {
      }
      window.setTimeout(() => {
        if (state.activeView === 'system_upgrade') {
          openView('system_upgrade');
        }
      }, 600);
      return runRes;
    });
  }

  return renderSystemUpgrade;
};
