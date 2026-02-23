<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use Qiling\Core\Config;
use Qiling\Core\Database;
use Qiling\Core\SystemSettingService;

final class SiteController
{
    public static function home(): void
    {
        $settings = self::loadSettingsSafe();
        self::applySecurityHeaders($settings);

        $clientIp = self::clientIp();
        $allowIps = SystemSettingService::parseAllowIps($settings['front_allow_ips'] ?? '');
        if (!empty($allowIps) && !in_array($clientIp, $allowIps, true)) {
            self::renderSimpleHtml(
                403,
                '访问受限',
                '当前前台仅允许白名单 IP 访问。',
                '如需访问请联系管理员放行你的 IP。'
            );
            return;
        }

        if (($settings['front_site_enabled'] ?? '1') !== '1') {
            $message = trim((string) ($settings['front_maintenance_message'] ?? ''));
            if ($message === '') {
                $message = '系统维护中，请稍后访问。';
            }
            self::renderSimpleHtml(503, '系统维护中', $message, '');
            return;
        }

        $rootPath = self::rootPath();
        $publicDir = dirname(__DIR__, 2) . '/public';
        $cssVersion = self::assetVersion($publicDir . '/assets/site.css');
        $jsVersion = self::assetVersion($publicDir . '/assets/site.js');
        $cssUrl = $rootPath . '/assets/site.css?v=' . $cssVersion;
        $jsUrl = $rootPath . '/assets/site.js?v=' . $jsVersion;
        $title = '启灵医美养生门店系统';
        $adminEntryPath = SystemSettingService::normalizeAdminEntryPath((string) ($settings['admin_entry_path'] ?? 'admin'));
        $customerUrl = $rootPath . '/customer';
        $payUrl = $rootPath . '/pay';
        $adminUrl = $rootPath . '/' . ltrim($adminEntryPath, '/');

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . self::h($title) . '</title>';
        echo '<meta name="description" content="启灵医美养生门店系统：医美与养生门店数字化引擎，覆盖建档、预约、开单、支付、回访、增长全流程。">';
        echo '<meta name="theme-color" content="#f5efe4">';
        echo '<link rel="stylesheet" href="' . self::h($cssUrl) . '">';
        echo '</head><body>';
        echo '<div class="fx fx-a" aria-hidden="true"></div>';
        echo '<div class="fx fx-b" aria-hidden="true"></div>';
        echo '<div class="fx fx-c" aria-hidden="true"></div>';
        echo '<main class="landing">';

        echo '<header class="site-header reveal">';
        echo '<div class="brand"><span class="brand-dot" aria-hidden="true"></span><span>QILING 启灵</span></div>';
        echo '<nav class="site-nav">';
        echo '<a href="#capability">核心能力</a>';
        echo '<a href="#workflow">服务流程</a>';
        echo '<a href="#industry">行业场景</a>';
        echo '<a href="#value">增长价值</a>';
        echo '</nav>';
        echo '<a class="header-cta" href="' . self::h($adminUrl) . '">进入后台</a>';
        echo '</header>';

        echo '<section class="hero reveal">';
        echo '<div class="hero-copy">';
        echo '<p class="eyebrow">QILING MEDSPA SUITE · 线下服务门店经营系统</p>';
        echo '<h1>把门店经营做成“可复制”的标准流程</h1>';
        echo '<p class="lead">启灵将建档、预约、开单、支付、回访和复购运营串成一个统一系统。前台动作更快，管理数据更清，移动端上手也更顺手。</p>';
        echo '<div class="hero-actions">';
        echo '<a class="btn btn-primary" href="' . self::h($customerUrl) . '">会员中心入口</a>';
        echo '<a class="btn btn-soft" href="' . self::h($payUrl) . '">支付页面入口</a>';
        echo '<a class="btn btn-line" href="' . self::h($adminUrl) . '">管理后台登录</a>';
        echo '</div>';
        echo '<p class="hero-note">支持门店生成专属二维码，客户可在手机端直接查看资料、订单与可支付账单。</p>';
        echo '<div class="pill-row">';
        echo '<span class="pill">客户资产沉淀</span>';
        echo '<span class="pill">多角色协同作业</span>';
        echo '<span class="pill">经营数据即时复盘</span>';
        echo '</div>';
        echo '</div>';

        echo '<aside class="hero-stage">';
        echo '<div class="stage-head"><h2>今天就能落地的门店数字化</h2><p>不是“看板式系统”，而是把业务动作真正跑起来。</p></div>';
        echo '<div class="stage-grid">';
        echo '<article class="metric-card" data-tilt><small>客户资产</small><b>建档 + 会员 + 交易</b><span>关键信息统一沉淀，后续触达更精准。</span></article>';
        echo '<article class="metric-card" data-tilt><small>服务流程</small><b>预约 -> 到店 -> 开单 -> 回访</b><span>全链路打通，减少人工传递与遗漏。</span></article>';
        echo '<article class="metric-card" data-tilt><small>经营复盘</small><b>门店 / 员工 / 报表联动</b><span>每天都能看到真实产出与增长变化。</span></article>';
        echo '</div>';
        echo '</aside>';
        echo '</section>';

        echo '<section id="capability" class="block reveal">';
        echo '<div class="block-head"><h2>核心能力矩阵</h2><p>前台执行、后台管理、客户体验三端一体，业务不再割裂。</p></div>';
        echo '<div class="cap-grid">';
        echo '<article class="cap-card"><h3>客户与会员中台</h3><p>客户档案、标签、余额、次卡、券包统一管理，形成可持续经营资产。</p></article>';
        echo '<article class="cap-card"><h3>预约与履约协同</h3><p>排班、到店、开单、扣减在一个流程内完成，门店执行更稳定。</p></article>';
        echo '<article class="cap-card"><h3>支付与账务闭环</h3><p>线上线下支付联动，订单状态实时同步，财务数据更透明。</p></article>';
        echo '<article class="cap-card"><h3>回访与增长运营</h3><p>自动生成回访任务，配合消息触达机制，持续提高复购与活跃。</p></article>';
        echo '</div>';
        echo '</section>';

        echo '<section id="workflow" class="block reveal">';
        echo '<div class="block-head"><h2>服务流程可视化</h2><p>把一线动作变成可执行、可追踪、可复盘的标准流程。</p></div>';
        echo '<div class="workflow-grid">';
        echo '<article class="workflow-step"><span>01</span><h3>客户建档</h3><p>来源、标签、基础信息完整录入，形成后续服务基础。</p></article>';
        echo '<article class="workflow-step"><span>02</span><h3>预约排程</h3><p>按门店与员工排班，冲突检测自动识别，减少沟通成本。</p></article>';
        echo '<article class="workflow-step"><span>03</span><h3>到店开单</h3><p>服务项目、次卡核销、优惠抵扣、收款同步完成。</p></article>';
        echo '<article class="workflow-step"><span>04</span><h3>回访运营</h3><p>按计划触达与跟进，沉淀复购线索，持续放大客户价值。</p></article>';
        echo '</div>';
        echo '</section>';

        echo '<section id="industry" class="block reveal">';
        echo '<div class="block-head"><h2>行业化场景方案</h2><p>基于同一底座，适配不同门店的服务节奏与经营侧重点。</p></div>';
        echo '<div class="industry-grid">';
        echo '<article class="industry-card"><h3>医美门店</h3><p>咨询建档、项目疗程、复诊提醒、会员复购运营一体化。</p></article>';
        echo '<article class="industry-card"><h3>养生会所</h3><p>次卡与疗程管理、技师排班、客户追踪、到期激活自动化。</p></article>';
        echo '<article class="industry-card"><h3>轻零售门店</h3><p>会员储值与券包策略结合，提升到店转化与复购效率。</p></article>';
        echo '<article class="industry-card"><h3>服务型连锁门店</h3><p>标准化流程、角色协作、跨门店数据复盘，提升复制能力。</p></article>';
        echo '</div>';
        echo '</section>';

        echo '<section id="value" class="block value-block reveal">';
        echo '<div class="value-panel">';
        echo '<h2>不仅是管理系统，更是门店增长基础设施</h2>';
        echo '<p>启灵以“业务优先、体验优先、可扩展优先”为原则，持续迭代门店数字化能力，让团队可以稳定执行，管理层可以快速决策。</p>';
        echo '<div class="value-kpis">';
        echo '<article><b>多门店协同</b><span>流程统一，数据口径统一</span></article>';
        echo '<article><b>移动端优先</b><span>高频操作在手机端也顺畅</span></article>';
        echo '<article><b>审计可追溯</b><span>关键动作全量记录</span></article>';
        echo '</div>';
        echo '<div class="value-points">';
        echo '<span>可扩展架构</span>';
        echo '<span>审计级数据追踪</span>';
        echo '<span>多角色协同体验</span>';
        echo '<span>移动端高频操作优化</span>';
        echo '</div>';
        echo '</div>';
        echo '</section>';

        echo '<footer class="site-foot reveal">';
        echo '<p>© ' . gmdate('Y') . ' QILING 启灵医美养生门店系统</p>';
        echo '<p>聚焦线下服务门店，打造可持续增长的数字化经营底座</p>';
        echo '</footer>';
        echo '</main>';
        echo '<script src="' . self::h($jsUrl) . '"></script>';
        echo '</body></html>';
    }

    public static function adminEntry(): void
    {
        $settings = self::loadSettingsSafe();
        self::applySecurityHeaders($settings);

        $adminPath = SystemSettingService::normalizeAdminEntryPath((string) ($settings['admin_entry_path'] ?? 'admin'));
        $current = self::requestPath();
        $isDefaultAdminPath = ($current === '/admin' || $current === '/admin/index.html');

        if ($adminPath !== 'admin' && $isDefaultAdminPath) {
            self::renderSimpleHtml(404, '页面不存在', '请求的页面不存在。', '');
            return;
        }

        $rootPath = self::rootPath();
        $publicDir = dirname(__DIR__, 2) . '/public';
        $cssVersion = self::assetVersion($publicDir . '/admin/assets/admin.css');
        $jsVersion = self::assetVersion($publicDir . '/admin/assets/admin.js');
        $cssUrl = $rootPath . '/admin/assets/admin.css?v=' . $cssVersion;
        $jsUrl = $rootPath . '/admin/assets/admin.js?v=' . $jsVersion;
        $rootPathJson = json_encode($rootPath, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $entryPathJson = json_encode($adminPath, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        echo '<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<title>启灵医美养生门店 - 管理后台</title>';
        echo '<link rel="stylesheet" href="' . self::h($cssUrl) . '">';
        echo '</head><body>';
        echo '<div class="ambient ambient-a"></div><div class="ambient ambient-b"></div><div class="ambient ambient-c"></div>';
        echo '<div id="toastContainer" class="toast-container" aria-live="polite"></div>';
        echo '<main id="loginScreen" class="screen login-screen"><section class="login-card">';
        echo '<p class="chip">QILING · ADMIN</p><h1>启灵医美养生门店</h1><p class="sub">现代化门店运营后台</p>';
        echo '<form id="loginForm" class="form-grid">';
        echo '<label><span>账号</span><input type="text" id="loginUsername" placeholder="admin" required></label>';
        echo '<label><span>密码</span><input type="password" id="loginPassword" placeholder="请输入密码" required></label>';
        echo '<button type="submit" class="btn btn-primary" id="loginBtn">登录后台</button>';
        echo '</form><p class="hint">请使用安装时设置的管理员账号登录</p></section></main>';
        echo '<main id="appScreen" class="screen app-screen hidden"><aside class="sidebar"><div class="brand"><p class="chip">QILING</p><h2>医美养生后台</h2></div>';
        echo '<nav id="navList" class="nav-list"></nav><div class="sidebar-footer"><small>v1 控制台</small></div></aside>';
        echo '<section class="workspace"><header class="topbar"><div><h1 id="viewTitle">控制台</h1><p id="viewSubtitle">实时连接业务 API</p></div>';
        echo '<div class="user-box"><div><p id="userName">-</p><small id="userMeta">-</small></div><button id="logoutBtn" class="btn btn-ghost">退出</button></div>';
        echo '</header><section id="viewRoot" class="view-root"></section></section></main>';
        echo '<script>window.__QILING_ROOT_PATH__=' . ($rootPathJson === false ? '""' : $rootPathJson) . ';window.__QILING_ADMIN_ENTRY_PATH__=' . ($entryPathJson === false ? '"admin"' : $entryPathJson) . ';</script>';
        echo '<script src="' . self::h($jsUrl) . '"></script>';
        echo '</body></html>';
    }

    public static function customerEntry(): void
    {
        $settings = self::loadSettingsSafe();
        self::applySecurityHeaders($settings);

        $rootPath = self::rootPath();
        $publicDir = dirname(__DIR__, 2) . '/public';
        $cssVersion = self::assetVersion($publicDir . '/customer/assets/customer.css');
        $jsVersion = self::assetVersion($publicDir . '/customer/assets/customer.js');
        $cssUrl = $rootPath . '/customer/assets/customer.css?v=' . $cssVersion;
        $jsUrl = $rootPath . '/customer/assets/customer.js?v=' . $jsVersion;
        $rootPathJson = json_encode($rootPath, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');

        echo '<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo '<title>启灵会员中心</title>';
        echo '<link rel="stylesheet" href="' . self::h($cssUrl) . '">';
        echo '</head><body>';
        echo '<div id="toastContainer" class="toast-container" aria-live="polite"></div>';
        echo '<main id="entryScreen" class="screen entry-screen hidden">';
        echo '<section class="entry-card"><p class="tag">QILING MEMBER</p><h1>启灵会员中心</h1>';
        echo '<p class="sub">请使用门店提供的二维码进入，或粘贴访问口令</p>';
        echo '<form id="entryForm" class="form-col"><input id="entryToken" type="text" placeholder="输入访问口令" required>';
        echo '<button id="entryBtn" class="btn primary" type="submit">进入我的资料</button></form>';
        echo '<p class="hint">为了保护隐私，门店内部备注不会在本页面展示。</p></section></main>';
        echo '<main id="appScreen" class="screen app-screen hidden">';
        echo '<header class="topbar"><div><h2>我的会员中心</h2><p id="metaLine">正在加载...</p></div>';
        echo '<div class="top-actions"><button id="btnReload" class="btn light" type="button">刷新</button>';
        echo '<button id="btnLogout" class="btn light" type="button">退出</button></div></header>';
        echo '<section class="grid kpi-grid" id="kpiGrid"></section>';
        echo '<section class="panel" id="profilePanel"></section>';
        echo '<section class="panel"><h3>会员卡</h3><div id="memberCardTable" class="empty">暂无数据</div></section>';
        echo '<section class="panel"><h3>优惠券</h3><div id="couponTable" class="empty">暂无数据</div></section>';
        echo '<section class="panel"><h3>消费记录</h3><div id="consumeTable" class="empty">暂无数据</div></section>';
        echo '<section class="panel"><h3>订单记录</h3><div id="orderTable" class="empty">暂无数据</div></section>';
        echo '<section class="panel"><h3>在线支付</h3><div id="paymentPanel" class="empty">暂无可支付订单</div></section>';
        echo '</main>';
        echo '<script>window.__QILING_ROOT_PATH__=' . ($rootPathJson === false ? '""' : $rootPathJson) . ';</script>';
        echo '<script src="' . self::h($jsUrl) . '"></script>';
        echo '</body></html>';
    }

    public static function paymentEntry(): void
    {
        $settings = self::loadSettingsSafe();
        self::applySecurityHeaders($settings);

        $rootPath = self::rootPath();
        $publicDir = dirname(__DIR__, 2) . '/public';
        $cssVersion = self::assetVersion($publicDir . '/pay/assets/pay.css');
        $jsVersion = self::assetVersion($publicDir . '/pay/assets/pay.js');
        $cssUrl = $rootPath . '/pay/assets/pay.css?v=' . $cssVersion;
        $jsUrl = $rootPath . '/pay/assets/pay.js?v=' . $jsVersion;
        $rootPathJson = json_encode($rootPath, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        echo '<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo '<title>启灵支付页</title>';
        echo '<link rel="stylesheet" href="' . self::h($cssUrl) . '">';
        echo '</head><body>';
        echo '<div id="toastContainer" class="toast-container" aria-live="polite"></div>';
        echo '<main class="pay-shell">';
        echo '<header class="pay-topbar"><div><h1>启灵支付页</h1><p>订单号、金额、二维码实时更新，支持多订单并行展示</p></div><button id="btnRefresh" class="btn light" type="button">刷新</button></header>';
        echo '<section class="panel"><h3>支付链接管理</h3><p class="hint">支持单个或多个支付链接（ticket）。多个可用英文逗号或换行分隔。</p>';
        echo '<form id="ticketForm" class="ticket-form"><textarea id="ticketInput" placeholder="粘贴支付链接中的 ticket"></textarea><button class="btn primary" type="submit">加载支付单</button></form></section>';
        echo '<section class="panel"><h3>支付单列表</h3><div id="payList" class="empty">暂无支付单</div></section>';
        echo '</main>';
        echo '<script>window.__QILING_ROOT_PATH__=' . ($rootPathJson === false ? '""' : $rootPathJson) . ';</script>';
        echo '<script src="' . self::h($jsUrl) . '"></script>';
        echo '</body></html>';
    }

    public static function resolveAdminEntryPathSafe(): string
    {
        try {
            return SystemSettingService::adminEntryPath(Database::pdo());
        } catch (\Throwable) {
            return 'admin';
        }
    }

    /**
     * @return array<string, string>
     */
    private static function loadSettingsSafe(): array
    {
        try {
            return SystemSettingService::all(Database::pdo());
        } catch (\Throwable) {
            return SystemSettingService::defaults();
        }
    }

    /**
     * @param array<string, string> $settings
     */
    private static function applySecurityHeaders(array $settings): void
    {
        if (($settings['security_headers_enabled'] ?? '1') !== '1') {
            return;
        }

        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'");
    }

    private static function rootPath(): string
    {
        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $base = str_replace('\\', '/', dirname($scriptName));
        if ($base === '/' || $base === '.') {
            return '';
        }
        return rtrim($base, '/');
    }

    private static function requestPath(): string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }
        return rtrim($path, '/') ?: '/';
    }

    private static function clientIp(): string
    {
        if (self::trustProxyHeaders()) {
            $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwarded !== '') {
                $parts = explode(',', $forwarded);
                $ip = trim((string) ($parts[0] ?? ''));
                if ($ip !== '') {
                    return $ip;
                }
            }

            $real = (string) ($_SERVER['HTTP_X_REAL_IP'] ?? '');
            if ($real !== '') {
                return trim($real);
            }
        }

        return trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    private static function trustProxyHeaders(): bool
    {
        $raw = strtolower(trim((string) Config::get('TRUST_PROXY_HEADERS', 'false')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private static function renderSimpleHtml(int $status, string $title, string $message, string $extra): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');

        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . self::h($title) . '</title>';
        echo '<style>body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f5f7fb;color:#17202a}';
        echo '.wrap{min-height:100vh;display:grid;place-items:center;padding:20px}.box{max-width:680px;background:#fff;border:1px solid #e6edf5;border-radius:14px;padding:24px}';
        echo 'h1{margin:0 0 10px;font-size:24px}p{margin:0 0 8px;color:#425466;line-height:1.7}</style></head><body>';
        echo '<main class="wrap"><section class="box"><h1>' . self::h($title) . '</h1>';
        echo '<p>' . self::h($message) . '</p>';
        if ($extra !== '') {
            echo '<p>' . self::h($extra) . '</p>';
        }
        echo '</section></main></body></html>';
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function assetVersion(string $path): string
    {
        $mtime = @filemtime($path);
        if (is_int($mtime) && $mtime > 0) {
            return (string) $mtime;
        }
        return '1';
    }
}
