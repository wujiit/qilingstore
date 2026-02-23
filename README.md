# 启灵医美养生门店系统（独立 PHP 版）

核心定位：轻医美/养生门店的门店、员工、客户档案管理，并提供 WordPress 用户同步 API。

## 版权与授权声明

本项目采用 **双重授权模式（Dual License）**：

1. **开源学习与非商业用途**
   采用 **GNU AGPLv3** (或 GNU GPLv3) 许可证。您可以自由地阅读、修改和分发本代码用于个人学习、学术研究或开源项目，但前提是您的衍生项目也必须遵循 AGPLv3/GPLv3 协议完全开源。

2. **商业用途**
   本项目的 AGPLv3/GPLv3 协议**不适用于任何闭源的商业行为**（包括但不限于：作为闭源产品出售、作为SaaS服务的底层系统等且拒绝开源自身代码）。如果您希望将本系统用于商业盈利目的而不愿开源您的代码，**请务必联系作者购买商业授权版本**。

未经购买商业授权而将本项目用于闭源商业用途的，我们将保留追究法律责任的权利。


## 技术栈
- PHP 8.2
- MariaDB 10.11
- Apache
- Docker Compose 一键部署

## 网页安装向导
推荐安装方式：直接访问安装向导：

- `/install.php`

安装向导支持：
- 环境检测（PHP版本、扩展、目录可写、关键文件可读）
- 输入数据库地址/库名/账号/密码
- 输入管理员账号信息
- 自动创建数据库与表、初始化默认数据、写入 `.env`

性能升级说明：
- 已新增运营报表相关复合索引（订单、支付、客户、预约、次卡流水等）。
- 老系统升级建议优先使用命令行安装脚本：`php scripts/install.php`（会自动补齐缺失索引与表结构）。
- 提供执行计划检查脚本：`scripts/explain_reports.sql`（用于 EXPLAIN 级别调优）。

注意：
- 安装成功后会自动生成安装锁文件：`storage/install.lock`，默认禁止再次网页安装。
- 若确需重装，请先手工删除 `storage/install.lock` 后再访问 `install.php`。

## 前台首页
安装完成后，根路径 `/` 会显示系统介绍首页。

可在后台「高级模块 -> 系统安全设置」控制：
- 前台是否开放
- 前台维护文案
- 前台 IP 白名单
- 是否启用安全响应头

## 后台入口
安装完成后默认访问：

- `/admin/`

说明：
- 这是前后端分离式管理台：页面在入口地址，数据通过 `/api/v1/*` 获取。
- 首次登录使用安装向导中你设置的管理员账号密码。
- 可在后台「高级模块 -> 系统安全设置」自定义后台入口路径（例如改成 `/qiling-center-2026`）。
- 入口变更后，默认 `/admin` 会被隐藏（返回 404）。
- 后台面板已覆盖：经营总览、主数据、业务运营、代客操作、积分营销、支付打印、回访推送、高级模块（转赠/开单/开单礼/提成/WP同步/Cron/用户端二维码与口令管理）、报表中心、API调试台。

### Nginx 规则（建议）
确保站点 `root` 指向 `public/`，并加入：

```nginx
location = /admin {
    rewrite ^ /index.php last;
}
location = /admin/ {
    rewrite ^ /index.php last;
}
location = /admin/index.html {
    rewrite ^ /index.php last;
}
location = /pay {
    try_files /pay/index.html /index.php?$query_string;
}
location = /pay/ {
    try_files /pay/index.html /index.php?$query_string;
}

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

```

## 默认管理员
默认读取 `.env`：
- `INSTALL_ADMIN_USERNAME`（默认 `admin`）
- `INSTALL_ADMIN_PASSWORD`（建议安装后置空）
- 默认角色：`admin`（全局门店权限）
- `CRON_SHARED_KEY`：外部定时任务访问密钥（安装向导会自动生成）
- `TRUST_PROXY_HEADERS`：是否信任 `X-Forwarded-For/X-Real-IP`（默认 `false`，仅在反向代理场景下建议开启）

## 登录安全与密码找回
- 登录防暴力破解：
  - `LOGIN_MAX_FAILED_ATTEMPTS`：连续失败上限（默认 `5`）
  - `LOGIN_LOCK_SECONDS`：达到上限后锁定秒数（默认 `900` 秒）
- 员工账号不提供自助找回密码；只能由管理员在后台“账号用户”中重置密码。
- 管理员忘记密码时，使用命令行恢复（不会开放公开找回接口）：
```bash
php scripts/reset-admin-password.php --list
php scripts/reset-admin-password.php --username=admin
php scripts/reset-admin-password.php --username=admin --password='新密码至少6位'
```
- 恢复脚本会自动清空该账号登录失败次数和锁定状态，并输出临时密码（如未手动指定）。

## 移动端入口（员工操作）
- 地址：`/mobile/`
- 适用：员工手机端快速完成客户建档、代客操作、消费记录查询
- 支持按 `role_key` 配置移动端菜单（系统设置中维护 `mobile_role_menu_json`）
- 功能聚焦：
  - 客户建档（含赠送余额）
  - 代客登记消费（扣余额/扣券/扣次）
  - 余额调整、次卡调整、发券
  - 客户消费记录与订单记录查看
- 菜单配置示例：
```json
{
  "default": {
    "tabs": ["onboard", "agent", "records"],
    "subtabs": {
      "onboard": ["onboard_form", "onboard_help"],
      "agent": ["consume", "wallet", "card", "coupon"],
      "records": ["assets", "consume", "orders"]
    }
  },
  "consultant": {
    "tabs": ["agent", "records"],
    "subtabs": {
      "agent": ["consume", "card"],
      "records": ["assets", "consume"]
    }
  }
}
```

## 用户端入口（客户扫码）
- 地址：`/customer/`
- 适用：客户扫码后查看自己的基础资料、会员卡、优惠券、消费记录、订单记录
- 隐私限制：
  - 门店内部备注（`notes`）不会返回给用户端
  - 用户端仅展示客户可见字段
- 扫码方式：
  - 后台菜单：`用户端入口`
  - 使用“生成客户扫码链接”创建专属链接与二维码
  - 客户扫码即可进入自己的资料页
- 后台口令管理：
  - 管理员可直接“重置客户访问口令”（用户忘记口令时使用）
  - 管理员可按客户解锁口令失败锁定（不会误伤同 IP 其他用户）

## 前台支付页（/pay）
- 入口：`GET /pay` 或 `GET /pay/index.html`
- 用途：展示在线支付单（订单号、金额、支付二维码、状态）
- 支持多支付单：URL 可带 `ticket` 或 `tickets`（逗号分隔），例如 `/pay/?tickets=t1,t2,t3`
- 数据接口：
  - `GET /api/v1/payments/public/status?ticket=...`
  - `POST /api/v1/payments/public/statuses`（批量；支持 `sync_pending=1` 自动补偿查单）

## 在线支付配置（支付宝 + 微信）
优先推荐在后台配置：`收银与退款 -> 支付配置`（管理员权限）。

也可在 `.env` 中预置默认值：
- `ALIPAY_ENABLED` / `WECHAT_ENABLED`：是否启用
- `ALIPAY_APP_ID`、`ALIPAY_PRIVATE_KEY`、`ALIPAY_PUBLIC_KEY`
- `ALIPAY_WEB_ENABLED`、`ALIPAY_F2F_ENABLED`、`ALIPAY_H5_ENABLED`、`ALIPAY_APP_ENABLED`
- `ALIPAY_NOTIFY_URL`（可留空，默认 `{APP_URL}/api/v1/payments/alipay/notify`）
- `ALIPAY_RETURN_URL`（可留空）
- `WECHAT_APP_ID`、`WECHAT_MCH_ID`、`WECHAT_SECRET`、`WECHAT_API_KEY`
- `WECHAT_JSAPI_ENABLED`、`WECHAT_H5_ENABLED`
- `WECHAT_NOTIFY_URL`（可留空，默认 `{APP_URL}/api/v1/payments/wechat/notify`）
- `WECHAT_ORDERQUERY_URL`、`WECHAT_CLOSEORDER_URL`、`WECHAT_REFUND_URL`（通常用默认值）
- `WECHAT_REFUND_NOTIFY_URL`（可选）
- `WECHAT_CERT_PATH`、`WECHAT_KEY_PATH`（微信退款必填，双向证书）
- `WECHAT_CERT_PASSPHRASE`（可选）

说明：
- 当前微信支付实现采用 `API v2`（统一下单 + XML 回调签名）。
- 线上环境建议使用 HTTPS 公网地址作为回调地址。
- 支付宝密钥支持两种格式：完整 PEM（含 `BEGIN/END`）或单行密钥字符串；`.env` 中可用 `\n` 表示换行。
- 微信退款支持两种证书方式：后台直接粘贴 PEM 内容，或 `.env` 指定 `WECHAT_CERT_PATH/WECHAT_KEY_PATH` 文件路径。
- 支付场景：支付宝支持 `auto/f2f/page(web)/wap(h5)/app`；微信支持 `native/jsapi/h5`。
- 支付宝 `auto`：优先尝试当面付（`f2f`），若账号无当面付权限会自动回退到网页支付（`page`）。

## API 列表
### 公共接口
- `GET /health`
- `POST /api/v1/auth/login`
- `GET /api/v1/customer-portal/overview`（客户扫码入口）
- `POST /api/v1/customer-portal/token/rotate`（客户自助修改访问口令，4-6位数字）
- `POST /api/v1/customer-portal/appointments/create`（客户在线预约）
- `POST /api/v1/customer-portal/payments/create`（用户端在线支付下单）
- `POST /api/v1/customer-portal/payments/sync`（用户端在线支付状态同步）
- `POST /api/v1/customer-portal/payments/sync-pending`（用户端批量同步待支付单）
- `POST /api/v1/payments/alipay/notify`
- `POST /api/v1/payments/wechat/notify`
- `GET|POST /api/v1/cron/followup/generate`（外部定时任务）
- `GET|POST /api/v1/cron/followup/notify`（外部定时任务）
- `GET|POST /api/v1/cron/followup/run`（外部定时任务：生成+推送）

### 登录后接口（Bearer Token）
- `GET /api/v1/auth/me`
- `GET /api/v1/mobile/menu`
- `GET /api/v1/customer-portal/tokens`
- `POST /api/v1/customer-portal/tokens/create`
- `POST /api/v1/customer-portal/tokens/reset`
- `POST /api/v1/customer-portal/tokens/revoke`
- `GET /api/v1/customer-portal/guards`
- `POST /api/v1/customer-portal/guards/unlock`
- `GET /api/v1/dashboard/summary`
- `GET /api/v1/stores`
- `POST /api/v1/stores`
- `GET /api/v1/staff`
- `POST /api/v1/staff`
- `GET /api/v1/customers`
- `POST /api/v1/customers`
- `GET /api/v1/services`
- `POST /api/v1/services`
- `GET /api/v1/service-categories`
- `POST /api/v1/service-categories`
- `POST /api/v1/service-categories/update`
- `GET /api/v1/service-packages`
- `POST /api/v1/service-packages`
- `GET /api/v1/member-cards`
- `POST /api/v1/member-cards`
- `POST /api/v1/member-cards/consume`
- `GET /api/v1/member-card-logs`
- `GET /api/v1/orders`
- `POST /api/v1/orders`
- `POST /api/v1/orders/pay`
- `GET /api/v1/order-items`
- `GET /api/v1/order-payments`
- `GET /api/v1/payments/config`
- `POST /api/v1/payments/config`
- `POST /api/v1/payments/online/create`
- `POST /api/v1/payments/online/create-dual-qr`
- `GET /api/v1/payments/online/status`
- `GET /api/v1/payments/public/status`
- `POST /api/v1/payments/public/statuses`
- `POST /api/v1/payments/online/query`
- `POST /api/v1/payments/online/close`
- `POST /api/v1/payments/online/refund`
- `GET /api/v1/payments/online/refunds`
- `GET /api/v1/admin/customers/search`
- `POST /api/v1/admin/customers/onboard`
- `POST /api/v1/admin/customers/consume-record`
- `POST /api/v1/admin/customers/consume-record-adjust`
- `POST /api/v1/admin/customers/wallet-adjust`
- `POST /api/v1/admin/customers/coupon-adjust`
- `POST /api/v1/admin/member-cards/adjust`
- `POST /api/v1/admin/member-cards/manual-consume`
- `POST /api/v1/admin/appointment-consumes/adjust`
- `GET /api/v1/appointments`
- `POST /api/v1/appointments`
- `POST /api/v1/appointments/status`
- `GET /api/v1/followup/plans`
- `POST /api/v1/followup/plans`
- `GET /api/v1/followup/tasks`
- `POST /api/v1/followup/tasks/status`
- `POST /api/v1/followup/generate`
- `POST /api/v1/followup/notify`
- `GET /api/v1/push/channels`
- `POST /api/v1/push/channels`
- `POST /api/v1/push/test`
- `GET /api/v1/push/logs`
- `GET /api/v1/commission/rules`
- `POST /api/v1/commission/rules`
- `GET /api/v1/performance/staff`
- `GET /api/v1/reports/operation-overview`
- `GET /api/v1/reports/revenue-trend`
- `GET /api/v1/reports/channel-stats`
- `GET /api/v1/reports/service-top`
- `GET /api/v1/reports/payment-methods`
- `GET /api/v1/reports/store-daily`
- `GET /api/v1/reports/customer-repurchase`
- `GET /api/v1/customer-grades`
- `POST /api/v1/customer-grades`
- `GET /api/v1/customer-points/account`
- `GET /api/v1/customer-points/logs`
- `POST /api/v1/customer-points/change`
- `GET /api/v1/open-gifts`
- `POST /api/v1/open-gifts`
- `POST /api/v1/open-gifts/trigger`
- `GET /api/v1/coupon-groups`
- `POST /api/v1/coupon-groups`
- `GET /api/v1/coupon-group-sends`
- `POST /api/v1/coupon-groups/send`
- `GET /api/v1/coupon-transfers`
- `POST /api/v1/coupons/transfer`
- `GET /api/v1/member-card-transfers`
- `POST /api/v1/member-cards/transfer`
- `GET /api/v1/printers`
- `POST /api/v1/printers`
- `GET /api/v1/print-jobs`
- `POST /api/v1/print-jobs`
- `POST /api/v1/print-jobs/dispatch`
- `POST /api/v1/wp/users/sync`
- `GET /api/v1/wp/users`
- `GET /api/v1/system/settings`（admin）
- `POST /api/v1/system/settings`（admin）

## 门店数据隔离（v1）
- `admin` 角色：可跨门店查看和操作数据。
- 非 `admin` 角色：自动限制在本人 `staff_store_id` 门店内，不能跨店查询/写入。
- 支持隔离的核心模块：门店、员工、客户、服务/套餐、次卡、预约、订单、在线支付、回访、提成、报表、后台手工纠偏。
- 建议前端普通账号不再传 `store_id`，由后端按登录用户自动归属门店。

## 外部定时任务（第三方监控平台）
适合 UptimeRobot / Better Stack / cron-job.org 这类“定时访问 URL”的平台。

鉴权方式（默认仅请求头）：
- Header：`X-QILING-CRON-KEY: 你的CRON_SHARED_KEY`
- 若确需兼容 Query，请在 `.env` 设置 `CRON_ALLOW_QUERY_KEY=true`

推荐直接用 `run`（一次完成“生成回访任务 + 推送到钉钉/飞书”）：

```bash
curl -X POST "http://localhost:8088/api/v1/cron/followup/run" \
  -H "X-QILING-CRON-KEY: 你的CRON_SHARED_KEY"
```

可选参数：
- `store_id`：只跑某门店
- `generate_limit`：生成阶段扫描上限（默认 200）
- `notify_limit`：推送阶段上限（默认 100）
- `channel_ids`：指定推送通道列表（如 `[1,2]`，会同时推送到多个启用通道）
- `channel_id`：指定单个通道（兼容旧参数）
- `retry_failed=1`：重试失败推送

示例（每5分钟访问一次）：

```bash
curl -X POST "http://localhost:8088/api/v1/cron/followup/run" \
  -H "Content-Type: application/json" \
  -H "X-QILING-CRON-KEY: 你的CRON_SHARED_KEY" \
  -d '{"store_id":1,"generate_limit":200,"notify_limit":100,"channel_ids":[1,2]}'
```

### WordPress 对接接口（共享密钥）
- `POST /api/v1/wp/users/sync`
- Header: `X-QILING-WP-SECRET: {WP_SYNC_SHARED_SECRET}`
- Header: `X-QILING-WP-TS: {当前Unix时间戳}`
- Header: `X-QILING-WP-SIGN: {hash_hmac_sha256(ts + "." + body, WP_SYNC_SHARED_SECRET)}`

## 登录示例
```bash
curl -X POST http://localhost:8088/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"你的管理员账号","password":"你的管理员密码"}'
```

## WordPress 用户同步示例
```bash
body='{
  "users": [
    {
      "wp_user_id": 1,
      "username": "summer",
      "email": "summer@example.com",
      "display_name": "Summer",
      "roles": ["administrator"],
      "meta": {"phone":"13800000000"}
    }
  ]
}'
ts=$(date +%s)
sign=$(printf "%s.%s" "$ts" "$body" | openssl dgst -sha256 -hmac "你的WP同步密钥" -hex | awk '{print $2}')

curl -X POST http://localhost:8088/api/v1/wp/users/sync \
  -H 'Content-Type: application/json' \
  -H 'X-QILING-WP-SECRET: 你的WP同步密钥' \
  -H "X-QILING-WP-TS: ${ts}" \
  -H "X-QILING-WP-SIGN: ${sign}" \
  -d "$body"
```

## 新模块调用示例

### 1. 新建服务项目
```bash
curl -X POST http://localhost:8088/api/v1/services \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "store_id": 1,
    "service_name": "补水护理",
    "category": "面部护理",
    "supports_online_booking": 1,
    "duration_minutes": 60,
    "list_price": 299
  }'
```

### 2. 新建疗程套餐
```bash
curl -X POST http://localhost:8088/api/v1/service-packages \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "store_id": 1,
    "package_name": "补水护理10次卡",
    "service_id": 1,
    "total_sessions": 10,
    "sale_price": 1999,
    "valid_days": 365
  }'
```

### 3. 开卡与核销
```bash
# 开卡
curl -X POST http://localhost:8088/api/v1/member-cards \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_id": 1,
    "package_id": 1
  }'

# 核销1次
curl -X POST http://localhost:8088/api/v1/member-cards/consume \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "member_card_id": 1,
    "consume_sessions": 1,
    "note": "到店护理核销"
  }'
```

### 4. 创建预约（带冲突检测）
```bash
curl -X POST http://localhost:8088/api/v1/appointments \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "store_id": 1,
    "customer_id": 1,
    "staff_id": 1,
    "service_id": 1,
    "start_at": "2026-02-21 10:00:00",
    "end_at": "2026-02-21 11:00:00",
    "source_channel": "微信",
    "notes": "首次体验"
  }'
```
说明：若已配置并启用钉钉/飞书推送通道，后台新建预约会自动推送预约通知。

### 5. 预约完成并自动核销次卡
```bash
curl -X POST http://localhost:8088/api/v1/appointments/status \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "appointment_id": 1,
    "status": "completed",
    "member_card_id": 1,
    "consume_sessions": 1,
    "consume_note": "预约完成自动核销"
  }'
```

### 6. 取消预约并自动回退已核销次数
```bash
curl -X POST http://localhost:8088/api/v1/appointments/status \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "appointment_id": 1,
    "status": "cancelled"
  }'
```
说明：当该预约之前通过 `completed + member_card_id` 触发过核销时，改为 `cancelled/no_show/booked` 会自动回退次卡次数，且同一预约只回退一次。

### 7. 配置默认回访计划（D+1/3/7）
```bash
curl -X POST http://localhost:8088/api/v1/followup/plans \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "store_id": 0,
    "trigger_type": "appointment_completed",
    "plan_name": "默认回访计划",
    "schedule_days": [1, 3, 7],
    "enabled": 1
  }'
```

### 8. 手动补生成回访任务（批量）
```bash
curl -X POST http://localhost:8088/api/v1/followup/generate \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"limit": 200}'
```

### 9. 配置消息推送通道（钉钉/飞书群机器人）
```bash
curl -X POST http://localhost:8088/api/v1/push/channels \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "channel_name": "运营群钉钉",
    "provider": "dingtalk",
    "webhook_url": "https://oapi.dingtalk.com/robot/send?access_token=xxxx",
    "security_mode": "sign",
    "secret": "SECxxxx",
    "keyword": "启灵",
    "enabled": 1
  }'
```

### 10. 发送推送测试消息
```bash
curl -X POST http://localhost:8088/api/v1/push/test \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "channel_id": 1,
    "message": "这是一条测试消息"
  }'
```

### 11. 发送“到期回访任务”提醒
```bash
curl -X POST http://localhost:8088/api/v1/followup/notify \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "store_id": 1,
    "channel_ids": [1, 2],
    "limit": 100,
    "retry_failed": 0
  }'
```
说明：`retry_failed=1` 会重试 `failed` 与 `sending` 状态任务（用于恢复中断任务）。
说明：可不传 `channel_ids`，系统会自动推送到全部已启用渠道（钉钉/飞书可双通道同时推送）。
说明：客户端 `POST /api/v1/customer-portal/appointments/create` 创建在线预约时，也会自动推送“新预约通知”到所有启用通道。

### 12. 开单（含订单明细）
```bash
curl -X POST http://localhost:8088/api/v1/orders \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "store_id": 1,
    "customer_id": 1,
    "appointment_id": 1,
    "order_discount_amount": 50,
    "coupon_amount": 20,
    "items": [
      {
        "item_type": "service",
        "item_ref_id": 1,
        "qty": 1,
        "unit_price": 299,
        "discount_amount": 0,
        "staff_id": 1
      }
    ]
  }'
```

### 13. 收款
```bash
curl -X POST http://localhost:8088/api/v1/orders/pay \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "order_id": 1,
    "pay_method": "wechat",
    "amount": 229
  }'
```

### 13.1 在线支付下单（支付宝/微信）
```bash
# 支付宝自动场景（推荐：优先当面付，失败回退网页支付）
curl -X POST http://localhost:8088/api/v1/payments/online/create \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "order_id": 1,
    "channel": "alipay",
    "scene": "auto"
  }'

# 支付宝当面付（返回二维码链接 qr_code）
curl -X POST http://localhost:8088/api/v1/payments/online/create \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "order_id": 1,
    "channel": "alipay",
    "scene": "f2f"
  }'

# 支付宝网页支付（返回 pay_url）
curl -X POST http://localhost:8088/api/v1/payments/online/create \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "order_id": 1,
    "channel": "alipay",
    "scene": "page"
  }'

# 微信 Native 扫码支付（返回二维码链接 qr_code）
curl -X POST http://localhost:8088/api/v1/payments/online/create \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "order_id": 1,
    "channel": "wechat",
    "scene": "native"
  }'

# 同一订单一键生成“支付宝 + 微信”双码
curl -X POST http://localhost:8088/api/v1/payments/online/create-dual-qr \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "order_id": 1,
    "alipay_scene": "auto"
  }'

# 查询在线支付状态（前端可轮询）
curl "http://localhost:8088/api/v1/payments/online/status?payment_no=QLON260220123456" \
  -H "Authorization: Bearer $TOKEN"
```
说明：
- 支付宝支持 `auto/f2f/page/wap`。
- 微信支持 `native/jsapi/h5`，`jsapi` 需额外传 `openid`。
- 创建在线支付后返回 `cashier_url`（例如：`/pay/?ticket=...`），可直接用于前台支付页展示订单号、金额、二维码与实时状态。
- 平台回调地址：
  - 支付宝：`POST /api/v1/payments/alipay/notify`
  - 微信：`POST /api/v1/payments/wechat/notify`

### 13.2 在线支付状态同步 / 关单 / 退款
```bash
# 主动向网关查询并同步（若已支付会自动入账）
curl -X POST http://localhost:8088/api/v1/payments/online/query \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "payment_no": "QLON260220123456"
  }'

# 关闭未支付单（释放二维码）
curl -X POST http://localhost:8088/api/v1/payments/online/close \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "payment_no": "QLON260220123456"
  }'

# 发起退款（refund_amount 不传则默认全额可退）
curl -X POST http://localhost:8088/api/v1/payments/online/refund \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "payment_no": "QLON260220123456",
    "refund_amount": 100,
    "reason": "客户临时取消项目"
  }'

# 查看退款记录
curl "http://localhost:8088/api/v1/payments/online/refunds?payment_no=QLON260220123456" \
  -H "Authorization: Bearer $TOKEN"
```
说明：
- 退款会同步回冲订单 `paid_amount`、订单状态和客户累计消费。
- 微信退款要求已配置双向证书路径。

### 14. 提成规则 + 员工业绩
```bash
# 新增提成规则
curl -X POST http://localhost:8088/api/v1/commission/rules \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "store_id": 1,
    "rule_name": "服务项目默认10%",
    "target_type": "service",
    "rate_percent": 10,
    "enabled": 1
  }'

# 查看员工业绩
curl "http://localhost:8088/api/v1/performance/staff?date_from=2026-02-01&date_to=2026-02-28&store_id=1" \
  -H "Authorization: Bearer $TOKEN"
```

### 15. 门店日报 + 客户复购报表
```bash
curl "http://localhost:8088/api/v1/reports/store-daily?date_from=2026-02-01&date_to=2026-02-28&store_id=1" \
  -H "Authorization: Bearer $TOKEN"

curl "http://localhost:8088/api/v1/reports/customer-repurchase?date_from=2025-01-01&date_to=2026-02-28&store_id=1&min_orders=2" \
  -H "Authorization: Bearer $TOKEN"
```

### 16. 后台手工纠偏（管理员）
```bash
# 按客户编号/手机号/卡号检索
curl "http://localhost:8088/api/v1/admin/customers/search?keyword=13800000000" \
  -H "Authorization: Bearer $TOKEN"

# 手工调整会员卡（set_remaining 或 delta_sessions）
curl -X POST http://localhost:8088/api/v1/admin/member-cards/adjust \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "card_no": "QLMC2602201234",
    "customer_mobile": "13800000000",
    "mode": "delta_sessions",
    "value": 2,
    "note": "门店前台手工补次"
  }'

# 手工补录消费（可绑定 appointment_id）
curl -X POST http://localhost:8088/api/v1/admin/member-cards/manual-consume \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "card_no": "QLMC2602201234",
    "consume_sessions": 1,
    "appointment_id": 1,
    "note": "客户到店线下核销补录"
  }'

# 修正已有预约消费记录
curl -X POST http://localhost:8088/api/v1/admin/appointment-consumes/adjust \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "appointment_id": 1,
    "consume_sessions": 2,
    "note": "原登记次数有误，后台修正"
  }'
```
说明：以上接口仅 `manager/admin` 可调用，所有动作会写入 `qiling_member_card_logs` 与 `qiling_audit_logs`。

### 17. 管理员建档 + 赠送余额/会员卡/优惠券
```bash
curl -X POST http://localhost:8088/api/v1/admin/customers/onboard \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "customer": {
      "name": "张女士",
      "mobile": "13800000000",
      "store_id": 1,
      "source_channel": "门店自然到访",
      "skin_type": "干性",
      "notes": "对香精敏感"
    },
    "gift_balance": 200,
    "gift_member_cards": [
      {
        "package_id": 1,
        "total_sessions": 3,
        "valid_days": 180,
        "note": "新客体验赠卡"
      }
    ],
    "gift_coupons": [
      {
        "coupon_name": "到店立减券",
        "coupon_type": "cash",
        "face_value": 50,
        "min_spend": 199,
        "count": 2,
        "expire_at": "2026-12-31 23:59:59"
      }
    ]
  }'
```

### 18. 管理员后台登记消费并自动扣减余额/优惠券/次卡
```bash
curl -X POST http://localhost:8088/api/v1/admin/customers/consume-record \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_mobile": "13800000000",
    "store_id": 1,
    "consume_amount": 399,
    "deduct_balance_amount": 100,
    "coupon_usages": [
      {"coupon_code": "QLCP2602201234", "use_count": 1}
    ],
    "member_card_usages": [
      {"card_no": "QLMC2602201234", "consume_sessions": 1}
    ],
    "note": "后台代客结算"
  }'
```

### 19. 管理员手工修正消费记录
```bash
curl -X POST http://localhost:8088/api/v1/admin/customers/consume-record-adjust \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "consume_no": "QLCR2602201234",
    "consume_amount": 299,
    "deduct_balance_amount": 80,
    "deduct_coupon_amount": 30,
    "deduct_member_card_sessions": 1,
    "note": "后台纠偏：原消费金额录入有误"
  }'
```

### 20. 管理员手工调整余额（按客户 ID/编号/手机号）
```bash
# 模式1：增减（delta）
curl -X POST http://localhost:8088/api/v1/admin/customers/wallet-adjust \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_mobile": "13800000000",
    "mode": "delta",
    "change_type": "deduct",
    "amount": 50,
    "note": "客户线下补差价"
  }'

# 模式2：直接改成指定余额（set_balance）
curl -X POST http://localhost:8088/api/v1/admin/customers/wallet-adjust \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_no": "QLC2602201234",
    "mode": "set_balance",
    "amount": 300,
    "note": "后台纠偏：余额修正"
  }'
```

### 21. 管理员手工发券/改券（按客户 ID/编号/手机号）
```bash
# 发新券（grant）
curl -X POST http://localhost:8088/api/v1/admin/customers/coupon-adjust \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_mobile": "13800000000",
    "mode": "grant",
    "coupon_name": "护理加赠券",
    "coupon_type": "cash",
    "face_value": 30,
    "min_spend": 199,
    "count": 2,
    "expire_at": "2026-12-31 23:59:59",
    "note": "老客回访补券"
  }'

# 改已有券剩余次数（set_remaining / delta_count）
curl -X POST http://localhost:8088/api/v1/admin/customers/coupon-adjust \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_mobile": "13800000000",
    "mode": "delta_count",
    "coupon_code": "QLCP260220123456",
    "delta_count": -1,
    "note": "后台代客核销"
  }'
```
说明：以上管理员接口会写入审计日志；其中余额调整写入 `qiling_wallet_logs`，优惠券调整写入 `qiling_coupon_logs`，会员卡相关写入 `qiling_member_card_logs`。

### 22. 会员等级与积分
```bash
# 新增/更新会员等级
curl -X POST http://localhost:8088/api/v1/customer-grades \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "store_id": 1,
    "grade_name": "金卡",
    "threshold_points": 1000,
    "discount_rate": 95,
    "enabled": 1
  }'

# 手工调整积分
curl -X POST http://localhost:8088/api/v1/customer-points/change \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_mobile": "13800000000",
    "delta_points": 100,
    "change_type": "manual_adjust",
    "note": "活动补积分"
  }'
```

### 23. 开门礼规则与触发
```bash
# 配置开门礼（建档触发）
curl -X POST http://localhost:8088/api/v1/open-gifts \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "store_id": 1,
    "trigger_type": "onboard",
    "gift_name": "新客建档礼",
    "enabled": 1,
    "items": [
      {"item_type": "points", "points_value": 200},
      {"item_type": "coupon", "coupon_name": "新客到店券", "coupon_type": "cash", "face_value": 50, "min_spend": 199, "remain_count": 1, "expire_days": 30}
    ]
  }'

# 手工触发开门礼
curl -X POST http://localhost:8088/api/v1/open-gifts/trigger \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "trigger_type": "manual",
    "customer_mobile": "13800000000",
    "store_id": 1
  }'
```

### 24. 券包批量发放
```bash
# 创建券包
curl -X POST http://localhost:8088/api/v1/coupon-groups \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "store_id": 1,
    "group_name": "38节到店券包",
    "coupon_name": "到店立减30",
    "coupon_type": "cash",
    "face_value": 30,
    "min_spend": 199,
    "per_user_limit": 1,
    "total_limit": 500,
    "expire_days": 20
  }'

# 批量发放（按手机号）
curl -X POST http://localhost:8088/api/v1/coupon-groups/send \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "group_id": 1,
    "customer_mobiles": ["13800000000", "13900000000"]
  }'
```

### 25. 转赠（优惠券/次卡）
```bash
# 优惠券转赠
curl -X POST http://localhost:8088/api/v1/coupons/transfer \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "coupon_code": "QLCP260220123456",
    "to_customer_mobile": "13900000000",
    "note": "后台代客转赠"
  }'

# 次卡转赠
curl -X POST http://localhost:8088/api/v1/member-cards/transfer \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "card_no": "QLMC2602201234",
    "to_customer_mobile": "13900000000",
    "note": "后台代客转赠"
  }'
```

### 26. 小票打印
```bash
# 配置打印机
curl -X POST http://localhost:8088/api/v1/printers \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "store_id": 1,
    "printer_name": "前台小票机",
    "provider": "manual",
    "enabled": 1
  }'

# 派发待打印任务
curl -X POST http://localhost:8088/api/v1/print-jobs/dispatch \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "store_id": 1,
    "limit": 50
  }'
```

## WordPress 端调用示例（可放在主题或自定义插件）
```php
$payload = [
    'wp_user_id'   => $user->ID,
    'username'     => $user->user_login,
    'email'        => $user->user_email,
    'display_name' => $user->display_name,
    'roles'        => $user->roles,
    'meta'         => [
        'phone' => get_user_meta($user->ID, 'phone', true),
    ],
];

$body = wp_json_encode($payload);
$ts = (string) time();
$sign = hash_hmac('sha256', $ts . '.' . $body, '你的共享密钥');

wp_remote_post('http://你的服务器:8088/api/v1/wp/users/sync', [
    'headers' => [
        'Content-Type'       => 'application/json',
        'X-QILING-WP-SECRET' => '你的共享密钥',
        'X-QILING-WP-TS'     => $ts,
        'X-QILING-WP-SIGN'   => $sign,
    ],
    'body'    => $body,
    'timeout' => 15,
]);
```

用户端访问口令默认开启防暴力策略，可通过 `.env` 调整：
- `PORTAL_TOKEN_MAX_FAILED_ATTEMPTS`：同一访问口令连续失败上限（默认 `8`）
- `PORTAL_TOKEN_LOCK_SECONDS`：达到上限后的锁定秒数（默认 `900`）
- `PORTAL_TOKEN_RATE_LIMIT_WINDOW_SECONDS`：速率统计窗口（默认 `60`）
- `PORTAL_TOKEN_RATE_LIMIT_MAX_REQUESTS`：窗口内同 IP 最大请求数（默认 `30`）

策略说明：
- 失败锁定按“访问口令”维度隔离，避免同出口 IP 用户互相影响。
- 速率限制按 IP 生效，用于防刷请求。

后台口令管理接口示例（需 Bearer Token）：
```bash
# 管理员重置客户访问口令（会自动作废该客户已有激活口令）
curl -X POST http://localhost:8088/api/v1/customer-portal/tokens/reset \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_mobile": "13900000000",
    "new_token": "123456",
    "expire_days": 365,
    "note": "管理员代重置"
  }'

# 查询当前锁定记录（可按客户筛选）
curl "http://localhost:8088/api/v1/customer-portal/guards?locked_only=1&limit=80" \
  -H "Authorization: Bearer $TOKEN"

# 按客户解锁口令锁定
curl -X POST http://localhost:8088/api/v1/customer-portal/guards/unlock \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_mobile": "13900000000"
  }'
```

可选兼容项：
- `CRON_ALLOW_QUERY_KEY=false`（默认）：关闭 `?key=` 传参，只允许请求头 `X-QILING-CRON-KEY`
- `WP_SYNC_REQUIRE_SIGNATURE=true`（默认）：要求 WP 同步请求必须带时间戳签名
- `WP_SYNC_SIGNATURE_TTL_SECONDS=300`：WP 同步签名有效期（秒）

## 目录说明
- `public/` 入口与路由
- `public/install.php` 网页安装向导
- `src/` 核心代码
- `sql/schema.sql` 表结构

## 已实现模块
- 认证登录（token）
- 门店管理 API
- 员工管理 API
- 客户档案 API（含标签）
- 服务项目 API
- 疗程套餐 API
- 会员次卡开卡/核销流水 API
- 开单收款与订单明细 API
- 在线支付（下单/回调/状态同步/关单/退款）
- 后台手工纠偏（按会员编号/手机号/卡号修改会员卡与消费记录）
- 管理员建档与代客操作（赠送余额/赠送次卡/赠送优惠券/后台消费扣减）
- 管理员手工调账（客户余额/优惠券发放与次数调整）
- 消费记录手工修正（支持按消费单号纠偏并同步累计消费）
- 预约排班 API（含员工时间冲突检测）
- 自动核销与自动回退（基于预约状态）
- 回访计划与回访任务中心（D+1/3/7 自动生成）
- 消息推送中心（钉钉/飞书群机器人、测试发送、到期回访通知、新预约通知、推送日志）
- 外部监控平台定时任务（URL 触发回访生成/推送）
- 提成规则 v1 与员工业绩统计 API
- 门店日报与客户复购报表 API
- 门店数据隔离 v1（按账号门店自动限制）
- 会员等级与积分（自动累积 + 手工调整 + 等级映射）
- 开门礼（建档/首单触发，支持积分与优惠券）
- 券包管理（批量发券，含每人限领与总量控制）
- 转赠中心（优惠券转赠、次卡转赠）
- 小票打印（打印机管理、打印任务、订单收款自动创建小票）
- WordPress 用户同步 API
- 审计日志表