window.__QILING_ADMIN_VIEW_FACTORIES__ = window.__QILING_ADMIN_VIEW_FACTORIES__ || {};
window.__QILING_ADMIN_VIEW_FACTORIES__['system'] = function (shared) {
  const {
    el,
    escapeHtml,
    toInt,
    jsonBox,
    setJsonBox,
    bindJsonForm,
    getFormValues,
    request,
    renderPageLinksCard,
    absolutePageUrl,
    bindCopyUrlButtons,
    toast,
  } = shared;

  async function renderSystemSettings() {
    const settingsRes = await request('GET', '/system/settings');
    const sys = (settingsRes && settingsRes.settings) ? settingsRes.settings : {};
    const derived = (settingsRes && settingsRes.derived) ? settingsRes.derived : {};

    const adminEntryPath = sys.admin_entry_path || 'admin';
    const frontSiteEnabled = toInt(sys.front_site_enabled, 1);
    const securityHeadersEnabled = toInt(sys.security_headers_enabled, 1);
    const frontAllowIps = sys.front_allow_ips || '';
    const frontMaintenanceMessage = sys.front_maintenance_message || '';
    const frontendAssetVersionSeed = String(sys.frontend_asset_version_seed || '').trim();
    const installPath = derived.install_url || '/install.php';

    const systemPageLinks = renderPageLinksCard('前台与后台入口地址', [
      { label: '品牌首页', path: '/' },
      { label: '会员中心', path: '/customer' },
      { label: '支付页面', path: '/pay' },
      { label: '员工移动端', path: '/mobile' },
      { label: '后台入口', path: `/${String(adminEntryPath).replace(/^\/+/, '')}` },
      { label: 'CRM 后台', path: '/crm-admin' },
    ]);

    el.viewRoot.innerHTML = `
      <section class="row panel-top">
        <article class="card">
          <h3>后台入口与前台安全</h3>
          <form id="formSystemSettings" class="form-grid" data-confirm="确定保存系统设置？后台入口修改后将立即生效。">
            <input name="admin_entry_path" placeholder="后台入口路径（不含斜杠）" value="${escapeHtml(adminEntryPath)}" />
            <select name="front_site_enabled">
              <option value="1" ${frontSiteEnabled === 1 ? 'selected' : ''}>前台开放</option>
              <option value="0" ${frontSiteEnabled === 0 ? 'selected' : ''}>前台维护中</option>
            </select>
            <select name="security_headers_enabled">
              <option value="1" ${securityHeadersEnabled === 1 ? 'selected' : ''}>启用安全响应头</option>
              <option value="0" ${securityHeadersEnabled === 0 ? 'selected' : ''}>关闭安全响应头</option>
            </select>
            <textarea name="front_maintenance_message" placeholder="前台维护提示文案">${escapeHtml(frontMaintenanceMessage)}</textarea>
            <textarea name="front_allow_ips" placeholder="前台白名单IP（每行一个，或用逗号分隔）">${escapeHtml(frontAllowIps)}</textarea>
            <button class="btn btn-primary" type="submit">保存系统设置</button>
          </form>
          <p class="small-note">修改后台入口后会立即生效，默认 <code>/admin</code> 将自动返回 404。</p>
        </article>

        <article class="card">
          ${systemPageLinks}
          <p class="small-note">安装向导地址（安装后会自动阻止重复安装）</p>
          <pre>${escapeHtml(absolutePageUrl(installPath))}</pre>
        </article>
      </section>

      <section class="card" style="margin-top:12px;">
        <h3>前端资源刷新</h3>
        <div class="form-grid">
          <label>
            <span>当前资源版本标记</span>
            <input value="${escapeHtml(frontendAssetVersionSeed || '-')}" readonly />
          </label>
        </div>
        <div class="toolbar">
          <button class="btn btn-primary" type="button" id="refreshFrontAssetsBtn">刷新前端资源文件</button>
        </div>
        <p class="small-note">用于强制浏览器加载最新 CSS/JS。代码覆盖更新后，点一次即可让访问端获取新资源。</p>
      </section>

      <section class="card" style="margin-top:12px;">
        <h3>版权与授权</h3>
        <p>本系统代码为开源发布，可用于学习与二次开发。</p>
        <p><b>商用需授权</b>，请在正式商用前联系授权。</p>
        <p>联系方式：微信/QQ <code>19577566</code></p>
      </section>

      <section class="card"><h3>操作返回</h3>${jsonBox('systemResult', '等待操作')}</section>
    `;
    bindCopyUrlButtons();

    bindJsonForm('formSystemSettings', 'systemResult', async (form) => {
      const v = getFormValues(form);
      return request('POST', '/system/settings', {
        body: {
          admin_entry_path: v.admin_entry_path || '',
          front_site_enabled: toInt(v.front_site_enabled, 1),
          security_headers_enabled: toInt(v.security_headers_enabled, 1),
          front_maintenance_message: v.front_maintenance_message || '',
          front_allow_ips: v.front_allow_ips || '',
        },
      });
    });

    const refreshFrontAssetsBtn = document.getElementById('refreshFrontAssetsBtn');
    if (refreshFrontAssetsBtn) {
      refreshFrontAssetsBtn.addEventListener('click', async () => {
        refreshFrontAssetsBtn.disabled = true;
        try {
          const result = await request('POST', '/system/assets/refresh');
          setJsonBox('systemResult', result);
          toast('前端资源版本已刷新', 'ok');
          await renderSystemSettings();
        } catch (err) {
          setJsonBox('systemResult', { message: err && err.message ? err.message : '刷新失败' });
          toast(err && err.message ? err.message : '刷新失败', 'error');
        } finally {
          refreshFrontAssetsBtn.disabled = false;
        }
      });
    }
  }

  return renderSystemSettings;
};
