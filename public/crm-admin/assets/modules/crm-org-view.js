let runtimeCtx = null;

export function setRenderOrgContext(ctx) {
  runtimeCtx = ctx || null;
}

export async function renderOrg() {
  const ctx = runtimeCtx || {};
  const { screenTitle, request, hasPermission, el, escapeHtml, $, toast, zhCrmStatus, zhCrmMemberRole } = ctx;

  screenTitle('组织协作', '部门/团队/成员可见范围');
  const canEdit = hasPermission('crm.org.edit');

  let contextPayload = {};
  let departments = [];
  let teams = [];
  let members = [];

  try {
    contextPayload = await request('GET', '/crm/org/context');
  } catch (_err) {
    contextPayload = {};
  }
  try {
    const payload = await request('GET', '/crm/org/departments', { query: { limit: 200 } });
    departments = Array.isArray(payload.data) ? payload.data : [];
  } catch (_err) {
    departments = [];
  }
  try {
    const payload = await request('GET', '/crm/org/teams', { query: { limit: 200 } });
    teams = Array.isArray(payload.data) ? payload.data : [];
  } catch (_err) {
    teams = [];
  }
  try {
    const payload = await request('GET', '/crm/org/members', { query: { limit: 500 } });
    members = Array.isArray(payload.data) ? payload.data : [];
  } catch (_err) {
    members = [];
  }

  const scope = contextPayload && contextPayload.scope ? contextPayload.scope : {};
  const teamIds = Array.isArray(scope.team_ids) ? scope.team_ids : [];
  const departmentIds = Array.isArray(scope.department_ids) ? scope.department_ids : [];

  el.viewRoot.innerHTML = `
    <section class="card">
      <h3>我的组织范围</h3>
      <p>团队: ${escapeHtml(teamIds.join(',') || '-')} ｜ 部门: ${escapeHtml(departmentIds.join(',') || '-')}</p>
    </section>

    ${
      canEdit
        ? `<section class="card" style="margin-top:12px;">
            <h3>新增/更新部门</h3>
            <form id="orgDepartmentForm" class="grid-3">
              <label><span>部门ID（更新时填）</span><input name="id" type="number" min="1" /></label>
              <label><span>部门名称</span><input name="department_name" required /></label>
              <label><span>父级部门ID</span><input name="parent_id" type="number" min="1" /></label>
              <label><span>负责人用户ID</span><input name="manager_user_id" type="number" min="1" /></label>
              <label><span>状态</span><select name="status"><option value="active">启用</option><option value="inactive">停用</option></select></label>
              <div><button class="btn btn-primary" type="submit">保存部门</button></div>
            </form>
          </section>
          <section class="card" style="margin-top:12px;">
            <h3>新增/更新团队</h3>
            <form id="orgTeamForm" class="grid-3">
              <label><span>团队ID（更新时填）</span><input name="id" type="number" min="1" /></label>
              <label><span>团队名称</span><input name="team_name" required /></label>
              <label><span>部门ID</span><input name="department_id" type="number" min="1" /></label>
              <label><span>组长用户ID</span><input name="leader_user_id" type="number" min="1" /></label>
              <label><span>状态</span><select name="status"><option value="active">启用</option><option value="inactive">停用</option></select></label>
              <div><button class="btn btn-primary" type="submit">保存团队</button></div>
            </form>
          </section>
          <section class="card" style="margin-top:12px;">
            <h3>团队成员</h3>
            <form id="orgMemberForm" class="grid-3">
              <label><span>团队ID</span><input name="team_id" type="number" min="1" required /></label>
              <label><span>用户ID</span><input name="user_id" type="number" min="1" required /></label>
              <label><span>部门ID（可空）</span><input name="department_id" type="number" min="1" /></label>
              <label><span>角色</span><select name="member_role"><option value="member">成员</option><option value="leader">负责人</option></select></label>
              <label><span>状态</span><select name="status"><option value="active">启用</option><option value="inactive">停用</option></select></label>
              <div><button class="btn btn-primary" type="submit">保存成员</button></div>
            </form>
          </section>`
        : ''
    }

    <section class="card" style="margin-top:12px;">
      <h3>部门列表</h3>
      <div class="table-wrap">
        <table>
          <thead><tr><th>ID</th><th>部门</th><th>负责人</th><th>状态</th></tr></thead>
          <tbody>
            ${
              departments.length
                ? departments
                    .map(
                      (row) => `<tr>
                          <td>${escapeHtml(row.id)}</td>
                          <td>${escapeHtml(row.department_name || '-')}</td>
                          <td>${escapeHtml(row.manager_username || row.manager_user_id || '-')}</td>
                          <td>${escapeHtml(zhCrmStatus(row.status || '-'))}</td>
                        </tr>`
                    )
                    .join('')
                : '<tr><td colspan="4" class="empty">暂无部门</td></tr>'
            }
          </tbody>
        </table>
      </div>
    </section>

    <section class="card" style="margin-top:12px;">
      <h3>团队列表</h3>
      <div class="table-wrap">
        <table>
          <thead><tr><th>ID</th><th>团队</th><th>部门</th><th>组长</th><th>状态</th></tr></thead>
          <tbody>
            ${
              teams.length
                ? teams
                    .map(
                      (row) => `<tr>
                          <td>${escapeHtml(row.id)}</td>
                          <td>${escapeHtml(row.team_name || '-')}</td>
                          <td>${escapeHtml(row.department_name || row.department_id || '-')}</td>
                          <td>${escapeHtml(row.leader_username || row.leader_user_id || '-')}</td>
                          <td>${escapeHtml(zhCrmStatus(row.status || '-'))}</td>
                        </tr>`
                    )
                    .join('')
                : '<tr><td colspan="5" class="empty">暂无团队</td></tr>'
            }
          </tbody>
        </table>
      </div>
    </section>

    <section class="card" style="margin-top:12px;">
      <h3>成员列表</h3>
      <div class="table-wrap">
        <table>
          <thead><tr><th>ID</th><th>团队</th><th>用户</th><th>角色</th><th>状态</th></tr></thead>
          <tbody>
            ${
              members.length
                ? members
                    .map(
                      (row) => `<tr>
                          <td>${escapeHtml(row.id)}</td>
                          <td>${escapeHtml(row.team_name || row.team_id || '-')}</td>
                          <td>${escapeHtml(row.username || row.user_id || '-')}</td>
                          <td>${escapeHtml(zhCrmMemberRole(row.member_role || '-'))}</td>
                          <td>${escapeHtml(zhCrmStatus(row.status || '-'))}</td>
                        </tr>`
                    )
                    .join('')
                : '<tr><td colspan="5" class="empty">暂无成员</td></tr>'
            }
          </tbody>
        </table>
      </div>
    </section>
  `;

  const departmentForm = $('orgDepartmentForm');
  if (canEdit && departmentForm) {
    departmentForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(departmentForm);
      const body = Object.fromEntries(fd.entries());
      body.id = body.id ? Number(body.id) : 0;
      body.parent_id = body.parent_id ? Number(body.parent_id) : 0;
      body.manager_user_id = body.manager_user_id ? Number(body.manager_user_id) : 0;
      try {
        await request('POST', '/crm/org/departments', { body });
        toast('部门已保存');
        await renderOrg();
      } catch (err) {
        toast(err.message || '保存失败', true);
      }
    });
  }

  const teamForm = $('orgTeamForm');
  if (canEdit && teamForm) {
    teamForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(teamForm);
      const body = Object.fromEntries(fd.entries());
      body.id = body.id ? Number(body.id) : 0;
      body.department_id = body.department_id ? Number(body.department_id) : 0;
      body.leader_user_id = body.leader_user_id ? Number(body.leader_user_id) : 0;
      try {
        await request('POST', '/crm/org/teams', { body });
        toast('团队已保存');
        await renderOrg();
      } catch (err) {
        toast(err.message || '保存失败', true);
      }
    });
  }

  const memberForm = $('orgMemberForm');
  if (canEdit && memberForm) {
    memberForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(memberForm);
      const body = Object.fromEntries(fd.entries());
      body.team_id = Number(body.team_id || 0);
      body.user_id = Number(body.user_id || 0);
      body.department_id = body.department_id ? Number(body.department_id) : 0;
      try {
        await request('POST', '/crm/org/members', { body });
        toast('成员已保存');
        await renderOrg();
      } catch (err) {
        toast(err.message || '保存失败', true);
      }
    });
  }
}
