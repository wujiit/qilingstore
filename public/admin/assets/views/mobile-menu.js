window.__QILING_ADMIN_VIEW_FACTORIES__ = window.__QILING_ADMIN_VIEW_FACTORIES__ || {};
window.__QILING_ADMIN_VIEW_FACTORIES__['mobile_menu'] = function (shared) {
  const {
    el,
    escapeHtml,
    toast,
    jsonBox,
    bindJsonForm,
    getFormValues,
    request,
    setJsonBox,
    window,
    document,
  } = shared;

  async function renderSystemMobileMenu() {
    const settingsRes = await request('GET', '/system/settings');
    const sys = (settingsRes && settingsRes.settings) ? settingsRes.settings : {};
    const mobileRoleMenuJson = (sys.mobile_role_menu_json && String(sys.mobile_role_menu_json).trim() !== '')
      ? String(sys.mobile_role_menu_json)
      : '';

    const TAB_OPTIONS = ['onboard', 'agent', 'records'];
    const TAB_LABELS = {
      onboard: '建档与接待',
      agent: '代客操作',
      records: '记录查询',
    };
    const SUBTAB_OPTIONS = {
      onboard: {
        onboard_form: '建档表单',
        onboard_help: '建档说明',
      },
      agent: {
        consume: '登记消费',
        wallet: '余额调整',
        card: '次卡调整',
        coupon: '发放优惠券',
      },
      records: {
        assets: '资产总览',
        consume: '消费记录',
        orders: '订单记录',
      },
    };
    const ROLE_LABELS = {
      default: '默认角色',
      admin: '管理员',
      manager: '店长',
      consultant: '顾问',
    };

    function defaultRoleMenuMap() {
      const allSubtabs = {
        onboard: ['onboard_form', 'onboard_help'],
        agent: ['consume', 'wallet', 'card', 'coupon'],
        records: ['assets', 'consume', 'orders'],
      };
      const allTabs = ['onboard', 'agent', 'records'];
      return {
        default: { tabs: allTabs.slice(), subtabs: { ...allSubtabs } },
        admin: { tabs: allTabs.slice(), subtabs: { ...allSubtabs } },
        manager: { tabs: allTabs.slice(), subtabs: { ...allSubtabs } },
        consultant: { tabs: allTabs.slice(), subtabs: { ...allSubtabs } },
      };
    }

    function normalizeRoleConfig(config) {
      const cfg = (config && typeof config === 'object') ? config : {};
      const tabsRaw = Array.isArray(cfg.tabs) ? cfg.tabs : [];
      const tabs = tabsRaw
        .map((x) => String(x || '').trim())
        .filter((x, i, arr) => TAB_OPTIONS.includes(x) && arr.indexOf(x) === i);
      const finalTabs = tabs.length > 0 ? tabs : TAB_OPTIONS.slice();

      const subtabsObj = (cfg.subtabs && typeof cfg.subtabs === 'object') ? cfg.subtabs : {};
      const subtabs = {};
      finalTabs.forEach((tab) => {
        const allowed = Object.keys(SUBTAB_OPTIONS[tab] || {});
        const listRaw = Array.isArray(subtabsObj[tab]) ? subtabsObj[tab] : [];
        const list = listRaw
          .map((x) => String(x || '').trim())
          .filter((x, i, arr) => allowed.includes(x) && arr.indexOf(x) === i);
        subtabs[tab] = list.length > 0 ? list : allowed;
      });

      return { tabs: finalTabs, subtabs };
    }

    function normalizeRoleMap(input) {
      const source = (input && typeof input === 'object') ? input : {};
      const out = {};
      Object.entries(source).forEach(([rawRole, config]) => {
        const roleKey = String(rawRole || '').trim().toLowerCase();
        if (!/^[a-z0-9_-]{2,40}$/.test(roleKey)) return;
        out[roleKey] = normalizeRoleConfig(config);
      });
      if (!out.default) out.default = normalizeRoleConfig({});
      return out;
    }

    function parseRoleMap(rawText) {
      const raw = String(rawText || '').trim();
      if (!raw) return defaultRoleMenuMap();
      try {
        const parsed = JSON.parse(raw);
        return normalizeRoleMap(parsed);
      } catch (_e) {
        return defaultRoleMenuMap();
      }
    }

    function roleLabel(roleKey) {
      return ROLE_LABELS[roleKey] || (`自定义角色：${roleKey}`);
    }

    let roleMenuMap = parseRoleMap(mobileRoleMenuJson);
    let activeRole = roleMenuMap.default ? 'default' : (Object.keys(roleMenuMap)[0] || 'default');

    el.viewRoot.innerHTML = `
      <section class="card">
        <h3>移动端员工菜单权限</h3>
        <form id="formMobileMenuSettings" class="form-grid" data-confirm="确定保存移动端菜单权限配置？">
          <section class="mobile-role-builder">
            <div class="mobile-role-toolbar">
              <select id="mobileRoleSelect"></select>
              <button id="btnMobileRoleAdd" class="btn btn-line" type="button">新增角色</button>
              <button id="btnMobileRoleDelete" class="btn btn-line" type="button">删除当前角色</button>
              <button id="btnMobileMenuPreset" class="btn btn-line" type="button">恢复默认菜单</button>
            </div>
            <div id="mobileRoleEditor" class="mobile-role-editor"></div>
            <textarea id="mobileRoleMenuJson" name="mobile_role_menu_json" class="hidden-json">${escapeHtml(JSON.stringify(roleMenuMap, null, 2))}</textarea>
          </section>
          <button class="btn btn-primary" type="submit">保存菜单权限</button>
        </form>
        <p class="small-note">菜单权限按员工角色编码 <code>role_key</code> 生效，支持新增自定义角色。</p>
      </section>

      <section class="card"><h3>操作返回</h3>${jsonBox('mobileMenuResult', '等待操作')}</section>
    `;

    const roleSelect = document.getElementById('mobileRoleSelect');
    const roleEditor = document.getElementById('mobileRoleEditor');
    const roleJsonInput = document.getElementById('mobileRoleMenuJson');
    const btnRoleAdd = document.getElementById('btnMobileRoleAdd');
    const btnRoleDelete = document.getElementById('btnMobileRoleDelete');
    const btnPreset = document.getElementById('btnMobileMenuPreset');

    function ensureRoleConfig(roleKey) {
      if (!roleMenuMap[roleKey]) roleMenuMap[roleKey] = normalizeRoleConfig({});
      roleMenuMap[roleKey] = normalizeRoleConfig(roleMenuMap[roleKey]);
      return roleMenuMap[roleKey];
    }

    function syncRoleMapJson() {
      if (roleJsonInput) {
        roleJsonInput.value = JSON.stringify(normalizeRoleMap(roleMenuMap), null, 2);
      }
    }

    function renderRoleSelect() {
      if (!roleSelect) return;
      const roleKeys = Object.keys(roleMenuMap).sort((a, b) => {
        if (a === 'default') return -1;
        if (b === 'default') return 1;
        return a.localeCompare(b);
      });
      roleSelect.innerHTML = roleKeys
        .map((roleKey) => `<option value="${escapeHtml(roleKey)}"${roleKey === activeRole ? ' selected' : ''}>${escapeHtml(roleLabel(roleKey))}</option>`)
        .join('');
    }

    function renderRoleEditor() {
      if (!roleEditor) return;
      const cfg = ensureRoleConfig(activeRole);
      const tabs = Array.isArray(cfg.tabs) ? cfg.tabs : [];
      const tabHtml = TAB_OPTIONS.map((tab) => {
        const checked = tabs.includes(tab) ? ' checked' : '';
        return `
          <label class="check-line">
            <input type="checkbox" data-menu-tab="${escapeHtml(tab)}"${checked} />
            <span>${escapeHtml(TAB_LABELS[tab] || tab)}</span>
          </label>
        `;
      }).join('');

      const subtabHtml = tabs.map((tab) => {
        const selectedSubtabs = Array.isArray(cfg.subtabs[tab]) ? cfg.subtabs[tab] : [];
        const rowHtml = Object.entries(SUBTAB_OPTIONS[tab] || {}).map(([subtab, subLabel]) => {
          const checked = selectedSubtabs.includes(subtab) ? ' checked' : '';
          return `
            <label class="check-line">
              <input type="checkbox" data-menu-subtab="${escapeHtml(subtab)}" data-parent-tab="${escapeHtml(tab)}"${checked} />
              <span>${escapeHtml(subLabel)}</span>
            </label>
          `;
        }).join('');
        return `
          <section class="mobile-subtab-section">
            <h5>${escapeHtml(TAB_LABELS[tab] || tab)} 子菜单</h5>
            <div class="mobile-subtab-grid">${rowHtml}</div>
          </section>
        `;
      }).join('');

      roleEditor.innerHTML = `
        <section class="mobile-menu-section">
          <h5>主菜单权限</h5>
          <div class="mobile-tab-grid">${tabHtml}</div>
        </section>
        ${subtabHtml || '<div class="empty">至少保留一个主菜单</div>'}
      `;

      roleEditor.querySelectorAll('[data-menu-tab]').forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
          const tab = String(checkbox.getAttribute('data-menu-tab') || '').trim();
          if (!tab || !TAB_OPTIONS.includes(tab)) return;
          const roleCfg = ensureRoleConfig(activeRole);
          let nextTabs = Array.isArray(roleCfg.tabs) ? roleCfg.tabs.slice() : [];
          if (checkbox.checked) {
            if (!nextTabs.includes(tab)) nextTabs.push(tab);
          } else {
            nextTabs = nextTabs.filter((x) => x !== tab);
          }
          if (nextTabs.length === 0) {
            checkbox.checked = true;
            toast('至少保留一个主菜单', 'error');
            return;
          }
          roleCfg.tabs = nextTabs;
          const nextSubtabs = {};
          nextTabs.forEach((keepTab) => {
            const allowed = Object.keys(SUBTAB_OPTIONS[keepTab] || {});
            const current = Array.isArray(roleCfg.subtabs[keepTab]) ? roleCfg.subtabs[keepTab] : [];
            const keep = current.filter((x) => allowed.includes(x));
            nextSubtabs[keepTab] = keep.length > 0 ? keep : allowed;
          });
          roleCfg.subtabs = nextSubtabs;
          roleMenuMap[activeRole] = roleCfg;
          syncRoleMapJson();
          renderRoleEditor();
        });
      });

      roleEditor.querySelectorAll('[data-menu-subtab]').forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
          const subtab = String(checkbox.getAttribute('data-menu-subtab') || '').trim();
          const tab = String(checkbox.getAttribute('data-parent-tab') || '').trim();
          if (!subtab || !tab || !TAB_OPTIONS.includes(tab)) return;
          const roleCfg = ensureRoleConfig(activeRole);
          const allowed = Object.keys(SUBTAB_OPTIONS[tab] || {});
          if (!allowed.includes(subtab)) return;
          const list = Array.isArray(roleCfg.subtabs[tab]) ? roleCfg.subtabs[tab].slice() : allowed.slice();
          let nextList = list;
          if (checkbox.checked) {
            if (!nextList.includes(subtab)) nextList.push(subtab);
          } else {
            nextList = nextList.filter((x) => x !== subtab);
          }
          if (nextList.length === 0) {
            checkbox.checked = true;
            toast('每个主菜单至少保留一个子菜单', 'error');
            return;
          }
          roleCfg.subtabs[tab] = nextList;
          roleMenuMap[activeRole] = roleCfg;
          syncRoleMapJson();
        });
      });
    }

    if (roleSelect) {
      roleSelect.addEventListener('change', () => {
        activeRole = String(roleSelect.value || 'default').trim().toLowerCase() || 'default';
        ensureRoleConfig(activeRole);
        syncRoleMapJson();
        renderRoleEditor();
      });
    }

    if (btnRoleAdd) {
      btnRoleAdd.addEventListener('click', () => {
        const roleKey = String(window.prompt('请输入角色编码（role_key，2-40位：字母/数字/_/-）', '') || '').trim().toLowerCase();
        if (!roleKey) return;
        if (!/^[a-z0-9_-]{2,40}$/.test(roleKey)) {
          toast('角色编码格式不正确，请重新输入', 'error');
          return;
        }
        if (roleMenuMap[roleKey]) {
          activeRole = roleKey;
          renderRoleSelect();
          renderRoleEditor();
          toast('该角色已存在，已切换到该角色', 'info');
          return;
        }
        roleMenuMap[roleKey] = normalizeRoleConfig({});
        activeRole = roleKey;
        syncRoleMapJson();
        renderRoleSelect();
        renderRoleEditor();
        toast('角色已新增', 'ok');
      });
    }

    if (btnRoleDelete) {
      btnRoleDelete.addEventListener('click', () => {
        if (activeRole === 'default') {
          toast('默认角色不能删除', 'error');
          return;
        }
        if (!roleMenuMap[activeRole]) return;
        const yes = window.confirm(`确定删除角色 ${activeRole} 的菜单配置吗？`);
        if (!yes) return;
        delete roleMenuMap[activeRole];
        activeRole = 'default';
        syncRoleMapJson();
        renderRoleSelect();
        renderRoleEditor();
        toast('角色配置已删除', 'ok');
      });
    }

    if (btnPreset) {
      btnPreset.addEventListener('click', () => {
        roleMenuMap = defaultRoleMenuMap();
        activeRole = 'default';
        syncRoleMapJson();
        renderRoleSelect();
        renderRoleEditor();
        toast('已恢复默认菜单模板', 'ok');
      });
    }

    renderRoleSelect();
    syncRoleMapJson();
    renderRoleEditor();

    bindJsonForm('formMobileMenuSettings', 'mobileMenuResult', async (form) => {
      syncRoleMapJson();
      const v = getFormValues(form);
      return request('POST', '/system/settings', {
        body: {
          mobile_role_menu_json: (roleJsonInput && roleJsonInput.value) ? roleJsonInput.value : (v.mobile_role_menu_json || ''),
        },
      });
    });

    setJsonBox('mobileMenuResult', { message: '等待操作' });
  }

  return renderSystemMobileMenu;
};
