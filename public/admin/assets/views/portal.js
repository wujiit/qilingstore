window.__QILING_ADMIN_VIEW_FACTORIES__ = window.__QILING_ADMIN_VIEW_FACTORIES__ || {};
window.__QILING_ADMIN_VIEW_FACTORIES__['portal'] = function (shared) {
  const {
    PATHNAME,
    ROOT_PATH,
    API_PREFIX,
    TOKEN_KEY,
    el,
    state,
    SOURCE_CHANNEL_OPTIONS,
    SOURCE_CHANNEL_ALIAS,
    SERVICE_CATEGORY_OPTIONS,
    MOBILE_VALUE_FIELDS,
    escapeHtml,
    renderSourceChannelOptionTags,
    renderSourceChannelDatalist,
    renderSourceChannelField,
    normalizeSourceChannel,
    normalizeMobileValue,
    bindSourceChannelAssist,
    normalizeServiceCategory,
    mergeServiceCategories,
    renderServiceCategoryOptionTags,
    renderServiceCategoryDatalist,
    renderServiceCategoryField,
    bindServiceCategoryAssist,
    applyStoreDefault,
    storeOptionLabel,
    renderStoreOptionTags,
    renderStoreDatalist,
    renderStoreField,
    normalizeStoreId,
    bindStoreAssist,
    toast,
    setLoading,
    renderEmpty,
    parseDateTimeInput,
    parseListInput,
    parseCsvLines,
    parseJsonText,
    zhStatus,
    zhEnabled,
    zhRole,
    zhPayMethod,
    zhCouponType,
    zhProvider,
    zhSecurityMode,
    zhTriggerType,
    zhBusinessType,
    zhActionType,
    zhChangeType,
    zhTriggerSource,
    zhTargetType,
    visibleNavItems,
    getSubTab,
    renderSubTabNav,
    subTabClass,
    bindSubTabNav,
    zhErrorMessage,
    table,
    getFormValues,
    jsonBox,
    setJsonBox,
    toInt,
    toFloat,
    formatMoney,
    formatPercent,
    formatNumber,
    dateInputValue,
    pickData,
    endpoint,
    appPath,
    appBaseUrl,
    absolutePageUrl,
    renderPageLinksCard,
    bindCopyUrlButtons,
    request,
    renderNav,
    tryAuthMe,
    showLogin,
    showApp,
    logout,
    bindJsonForm,
    openView,
    window,
    document,
    localStorage,
    Event,
    URL,
  } = shared;
  async function renderPortal() {
    const customTokenPattern = /^[A-Za-z0-9_-]{16,64}$/;
    const tabKey = 'portal';
    const tabFallback = 'links';
    const tabHeader = renderSubTabNav(tabKey, [
      { id: 'links', title: '客户扫码入口', subtitle: '生成/重置访问口令，查询并处理锁定记录' },
    ]);
    const initRes = await request('GET', '/customer-portal/tokens', {
      query: { status: 'active', limit: 80 },
    });
    let currentRows = pickData(initRes);
    let currentGuardRows = [];
    try {
      const initGuardRes = await request('GET', '/customer-portal/guards', {
        query: { locked_only: 1, limit: 80 },
      });
      currentGuardRows = pickData(initGuardRes);
    } catch (_err) {
      currentGuardRows = [];
    }
    const portalPageLinks = renderPageLinksCard('客户与收银入口地址', [
      { label: '会员中心入口', path: '/customer' },
      { label: '支付页面入口', path: '/pay' },
      { label: '品牌首页', path: '/' },
    ]);

    const renderTokenTable = (rows) => {
      if (!Array.isArray(rows) || rows.length === 0) {
        return renderEmpty('暂无扫码入口记录');
      }

      const body = rows.map((r) => `
        <tr>
          <td>${escapeHtml(r.id)}</td>
          <td>${escapeHtml(r.customer_name || '-')}<br /><small>${escapeHtml(r.customer_no || '-')} / ${escapeHtml(r.customer_mobile || '-')}</small></td>
          <td>${escapeHtml(r.store_name || `门店#${r.store_id || 0}`)}</td>
          <td>${escapeHtml(zhStatus(r.status || '-'))}</td>
          <td>${escapeHtml(r.expire_at || '-')}</td>
          <td>${escapeHtml(r.use_count || 0)} / ${escapeHtml(r.last_used_at || '-')}</td>
          <td>${escapeHtml(r.note || '-')}</td>
          <td>${escapeHtml(r.created_at || '-')}</td>
          <td><button type="button" class="btn btn-danger portal-revoke-btn" data-token-id="${escapeHtml(r.id)}">作废</button></td>
        </tr>
      `).join('');

      return `
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>客户</th>
                <th>门店</th>
                <th>状态</th>
                <th>到期时间</th>
                <th>使用情况</th>
                <th>备注</th>
                <th>创建时间</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>${body}</tbody>
          </table>
        </div>
      `;
    };

    const renderGuardTable = (rows) => {
      if (!Array.isArray(rows) || rows.length === 0) {
        return renderEmpty('暂无命中的访问口令锁定记录');
      }

      const body = rows.map((r) => `
        <tr>
          <td>${escapeHtml(r.customer_name || '-')}<br /><small>${escapeHtml(r.customer_no || '-')} / ${escapeHtml(r.customer_mobile || '-')}</small></td>
          <td>${escapeHtml(r.store_name || `门店#${r.store_id || 0}`)}</td>
          <td>#${escapeHtml(r.token_id || 0)} / ${escapeHtml(r.token_prefix || '-')}</td>
          <td>${escapeHtml(zhStatus(r.token_status || '-'))}</td>
          <td>${escapeHtml(r.fail_count || 0)}</td>
          <td>${escapeHtml(r.first_failed_at || '-')}</td>
          <td>${escapeHtml(r.locked_until || '-')}</td>
          <td>${escapeHtml(r.updated_at || '-')}</td>
          <td><button type="button" class="btn btn-danger portal-guard-unlock-btn" data-customer-id="${escapeHtml(r.customer_id || 0)}" data-customer-name="${escapeHtml(r.customer_name || '')}">解锁</button></td>
        </tr>
      `).join('');

      return `
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>客户</th>
                <th>门店</th>
                <th>口令</th>
                <th>口令状态</th>
                <th>失败次数</th>
                <th>首次失败时间</th>
                <th>锁定截止时间</th>
                <th>最近更新时间</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>${body}</tbody>
          </table>
        </div>
      `;
    };

    el.viewRoot.innerHTML = `
      ${tabHeader}

      <section class="row${subTabClass(tabKey, 'links', tabFallback)}">
        <article class="card">
          <h3>生成客户扫码链接</h3>
          <form id="formPortalCreate" class="form-grid">
            <input name="customer_id" placeholder="客户ID（可空）" />
            <input name="customer_no" placeholder="会员编号（可空）" />
            <input name="customer_mobile" placeholder="客户手机号（可空）" />
            <input name="expire_days" placeholder="有效天数（默认365）" value="365" />
            <input name="note" placeholder="备注（如 首次建档二维码）" />
            <button class="btn btn-primary" type="submit">生成扫码链接</button>
          </form>
          <div id="portalQrPreview" class="portal-qr-preview">
            <div class="empty">生成后将显示二维码预览和访问链接</div>
          </div>
        </article>

        <article class="card">
          <h3>查询入口记录</h3>
          <form id="formPortalQuery" class="form-grid">
            <input name="customer_id" placeholder="客户ID（可空）" />
            <input name="customer_no" placeholder="会员编号（可空）" />
            <input name="customer_mobile" placeholder="手机号（可空）" />
            <select name="status">
              <option value=\"\">全部状态</option>
              <option value=\"active\" selected>仅启用</option>
              <option value=\"revoked\">已作废</option>
              <option value=\"expired\">已过期（历史）</option>
            </select>
            <input name="limit" placeholder="查询条数（默认80）" value="80" />
            <button class="btn btn-line" type="submit">查询入口记录</button>
          </form>
          <p class="small-note">建议每位客户单独生成入口，客户更换手机或入口泄露时可一键作废后重建。</p>
        </article>
      </section>

      <section class="row${subTabClass(tabKey, 'links', tabFallback)}">
        <article class="card">
          <h3>管理员重置客户访问口令</h3>
          <form id="formPortalReset" class="form-grid">
            <input name="customer_id" placeholder="客户ID（可空）" />
            <input name="customer_no" placeholder="会员编号（可空）" />
            <input name="customer_mobile" placeholder="客户手机号（可空）" />
            <input name="new_token" placeholder="新口令（16-64位，字母/数字/_/-，可空则自动生成）" minlength="16" maxlength="64" pattern="[A-Za-z0-9_-]{16,64}" autocomplete="off" spellcheck="false" />
            <input name="expire_days" placeholder="有效天数（默认365）" value="365" />
            <input name="note" placeholder="备注（如 管理员代重置）" />
            <button class="btn btn-primary" type="submit">重置口令并作废旧口令</button>
          </form>
          <div id="portalResetPreview" class="portal-qr-preview">
            <div class="empty">重置后将显示新口令与二维码</div>
          </div>
        </article>

        <article class="card">
          <h3>访问口令锁定管理（按客户）</h3>
          <form id="formPortalUnlock" class="form-grid">
            <input name="customer_id" placeholder="客户ID（可空）" />
            <input name="customer_no" placeholder="会员编号（可空）" />
            <input name="customer_mobile" placeholder="客户手机号（可空）" />
            <button class="btn btn-danger" type="submit">手动按客户解锁</button>
          </form>
          <form id="formPortalGuardQuery" class="form-grid">
            <input name="customer_id" placeholder="客户ID（可空）" />
            <input name="customer_no" placeholder="会员编号（可空）" />
            <input name="customer_mobile" placeholder="客户手机号（可空）" />
            <select name="locked_only">
              <option value="1" selected>仅当前锁定</option>
              <option value="0">全部记录</option>
            </select>
            <input name="limit" placeholder="查询条数（默认80）" value="80" />
            <button class="btn btn-line" type="submit">刷新锁定列表</button>
          </form>
          <p class="small-note">口令失败锁定按客户口令隔离，不再按IP误伤同网用户。</p>
        </article>
      </section>

      <section class="card${subTabClass(tabKey, 'links', tabFallback)}">
        <h3>扫码入口记录</h3>
        <div id="portalTokenTable">${renderTokenTable(currentRows)}</div>
      </section>

      <section class="card${subTabClass(tabKey, 'links', tabFallback)}">
        <h3>访问口令锁定记录</h3>
        <div id="portalGuardTable">${renderGuardTable(currentGuardRows)}</div>
      </section>

      <section class="card${subTabClass(tabKey, 'links', tabFallback)}">
        ${portalPageLinks}
      </section>

      <section class="card"><h3>操作返回</h3>${jsonBox('portalResult', '等待操作')}</section>
    `;

    bindSubTabNav(tabKey, tabFallback);
    bindCopyUrlButtons();

    const queryForm = document.getElementById('formPortalQuery');
    const createForm = document.getElementById('formPortalCreate');
    const resetForm = document.getElementById('formPortalReset');
    const resetPreview = document.getElementById('portalResetPreview');
    const unlockForm = document.getElementById('formPortalUnlock');
    const guardQueryForm = document.getElementById('formPortalGuardQuery');
    const tableBox = document.getElementById('portalTokenTable');
    const guardTableBox = document.getElementById('portalGuardTable');
    const qrPreview = document.getElementById('portalQrPreview');

    const tableRefresh = (rows) => {
      currentRows = Array.isArray(rows) ? rows : [];
      if (tableBox) {
        tableBox.innerHTML = renderTokenTable(currentRows);
      }
      bindRevokeButtons();
    };

    const guardTableRefresh = (rows) => {
      currentGuardRows = Array.isArray(rows) ? rows : [];
      if (guardTableBox) {
        guardTableBox.innerHTML = renderGuardTable(currentGuardRows);
      }
      bindGuardUnlockButtons();
    };

    const readQuery = () => {
      if (!queryForm) {
        return { status: 'active', limit: 80 };
      }
      const v = getFormValues(queryForm);
      return {
        customer_id: toInt(v.customer_id, 0),
        customer_no: v.customer_no || '',
        customer_mobile: v.customer_mobile || '',
        status: v.status || '',
        limit: toInt(v.limit, 80),
      };
    };

    const readGuardQuery = () => {
      if (!guardQueryForm) {
        return { locked_only: 1, limit: 80 };
      }
      const v = getFormValues(guardQueryForm);
      return {
        customer_id: toInt(v.customer_id, 0),
        customer_no: v.customer_no || '',
        customer_mobile: v.customer_mobile || '',
        locked_only: toInt(v.locked_only, 1),
        limit: toInt(v.limit, 80),
      };
    };

    const loadRows = async () => {
      const res = await request('GET', '/customer-portal/tokens', { query: readQuery() });
      const rows = pickData(res);
      tableRefresh(rows);
      setJsonBox('portalResult', res);
      toast('入口记录已刷新', 'ok');
    };

    const loadGuards = async (showToast = true) => {
      const res = await request('GET', '/customer-portal/guards', { query: readGuardQuery() });
      const rows = pickData(res);
      guardTableRefresh(rows);
      setJsonBox('portalResult', res);
      if (showToast) {
        toast('锁定列表已刷新', 'ok');
      }
    };

    const bindRevokeButtons = () => {
      el.viewRoot.querySelectorAll('.portal-revoke-btn').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const tokenId = toInt(btn.getAttribute('data-token-id'), 0);
          if (tokenId <= 0) return;
          if (!window.confirm(`确认作废入口令牌 #${tokenId} ?`)) return;
          try {
            const res = await request('POST', '/customer-portal/tokens/revoke', {
              body: { token_id: tokenId },
            });
            setJsonBox('portalResult', res);
            await loadRows();
          } catch (err) {
            setJsonBox('portalResult', { message: err.message });
            toast(err.message, 'error');
          }
        });
      });
    };

    const bindGuardUnlockButtons = () => {
      el.viewRoot.querySelectorAll('.portal-guard-unlock-btn').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const customerId = toInt(btn.getAttribute('data-customer-id'), 0);
          const customerName = String(btn.getAttribute('data-customer-name') || '').trim();
          if (customerId <= 0) return;
          if (!window.confirm(`确认解锁客户 ${customerName || `#${customerId}`} 的访问口令？`)) return;
          try {
            const res = await request('POST', '/customer-portal/guards/unlock', {
              body: { customer_id: customerId },
            });
            setJsonBox('portalResult', res);
            await loadGuards(false);
            toast('客户口令锁定已解锁', 'ok');
          } catch (err) {
            setJsonBox('portalResult', { message: err.message });
            toast(err.message, 'error');
          }
        });
      });
    };

    bindRevokeButtons();
    bindGuardUnlockButtons();

    if (queryForm) {
      queryForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
          await loadRows();
        } catch (err) {
          setJsonBox('portalResult', { message: err.message });
          toast(err.message, 'error');
        }
      });
    }

    if (guardQueryForm) {
      guardQueryForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
          await loadGuards();
        } catch (err) {
          setJsonBox('portalResult', { message: err.message });
          toast(err.message, 'error');
        }
      });
    }

    if (unlockForm) {
      unlockForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const v = getFormValues(unlockForm);
        const customerId = toInt(v.customer_id, 0);
        const customerNo = String(v.customer_no || '').trim();
        const customerMobile = String(v.customer_mobile || '').trim();
        if (customerId <= 0 && !customerNo && !customerMobile) {
          toast('请输入客户ID、会员编号或手机号', 'error');
          return;
        }
        try {
          const res = await request('POST', '/customer-portal/guards/unlock', {
            body: {
              customer_id: customerId,
              customer_no: customerNo,
              customer_mobile: customerMobile,
            },
          });
          setJsonBox('portalResult', res);
          await loadGuards(false);
          toast('客户口令锁定已解锁', 'ok');
        } catch (err) {
          setJsonBox('portalResult', { message: err.message });
          toast(err.message, 'error');
        }
      });
    }

    if (createForm) {
      createForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const v = getFormValues(createForm);
        try {
          const res = await request('POST', '/customer-portal/tokens/create', {
            body: {
              customer_id: toInt(v.customer_id, 0),
              customer_no: v.customer_no || '',
              customer_mobile: v.customer_mobile || '',
              expire_days: toInt(v.expire_days, 365),
              note: v.note || '',
            },
          });
          setJsonBox('portalResult', res);
          if (qrPreview) {
            qrPreview.innerHTML = `
              <div class="portal-link-box">
                <p><b>客户：</b>${escapeHtml((res.customer && res.customer.name) ? res.customer.name : '-')}</p>
                <p><b>链接：</b><a href="${escapeHtml(res.portal_url || '#')}" target="_blank" rel="noopener noreferrer">${escapeHtml(res.portal_url || '-')}</a></p>
                <p><button type="button" class="btn btn-line" id="btnPortalCopyLink">复制链接</button></p>
                ${String(res.qr_code_url || '').trim() !== ''
                  ? `<img src="${escapeHtml(res.qr_code_url || '')}" alt="客户扫码二维码" />`
                  : '<p class="hint">二维码预览已关闭，可直接复制链接发送给客户。</p>'}
              </div>
            `;
            const copyBtn = document.getElementById('btnPortalCopyLink');
            if (copyBtn) {
              copyBtn.addEventListener('click', async () => {
                const text = String(res.portal_url || '').trim();
                if (!text) return;
                try {
                  await navigator.clipboard.writeText(text);
                  toast('链接已复制', 'ok');
                } catch (_e) {
                  window.prompt('复制失败，请手动复制以下链接：', text);
                }
              });
            }
          }
          await loadRows();
        } catch (err) {
          setJsonBox('portalResult', { message: err.message });
          toast(err.message, 'error');
        }
      });
    }

    if (resetForm) {
      resetForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const v = getFormValues(resetForm);
        const customToken = String(v.new_token || '').trim();
        if (customToken !== '' && !customTokenPattern.test(customToken)) {
          toast('新口令需为16-64位，仅支持字母、数字、下划线和中划线', 'error');
          return;
        }
        try {
          const res = await request('POST', '/customer-portal/tokens/reset', {
            body: {
              customer_id: toInt(v.customer_id, 0),
              customer_no: v.customer_no || '',
              customer_mobile: v.customer_mobile || '',
              new_token: customToken,
              expire_days: toInt(v.expire_days, 365),
              note: v.note || '',
            },
          });
          setJsonBox('portalResult', res);
          if (resetPreview) {
            resetPreview.innerHTML = `
              <div class="portal-link-box">
                <p><b>客户：</b>${escapeHtml((res.customer && res.customer.name) ? res.customer.name : '-')}</p>
                <p><b>新口令：</b>${escapeHtml(res.token || (res.token_info && res.token_info.token_prefix) || '-')}</p>
                <p><b>已作废旧口令：</b>${escapeHtml(res.revoked_count || 0)} 个</p>
                <p><b>链接：</b><a href="${escapeHtml(res.portal_url || '#')}" target="_blank" rel="noopener noreferrer">${escapeHtml(res.portal_url || '-')}</a></p>
                <p><button type="button" class="btn btn-line" id="btnPortalCopyResetLink">复制链接</button></p>
                ${String(res.qr_code_url || '').trim() !== ''
                  ? `<img src="${escapeHtml(res.qr_code_url || '')}" alt="重置后二维码" />`
                  : '<p class="hint">二维码预览已关闭，可直接复制链接发送给客户。</p>'}
              </div>
            `;
            const copyBtn = document.getElementById('btnPortalCopyResetLink');
            if (copyBtn) {
              copyBtn.addEventListener('click', async () => {
                const text = String(res.portal_url || '').trim();
                if (!text) return;
                try {
                  await navigator.clipboard.writeText(text);
                  toast('链接已复制', 'ok');
                } catch (_e) {
                  window.prompt('复制失败，请手动复制以下链接：', text);
                }
              });
            }
          }
          await loadRows();
        } catch (err) {
          setJsonBox('portalResult', { message: err.message });
          toast(err.message, 'error');
        }
      });
    }
  }


  return renderPortal;
};
