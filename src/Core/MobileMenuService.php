<?php

declare(strict_types=1);

namespace Qiling\Core;

final class MobileMenuService
{
    /** @var array<string, array<int, string>> */
    private const SUBTAB_OPTIONS = [
        'onboard' => ['onboard_form', 'onboard_help'],
        'agent' => ['consume', 'wallet', 'card', 'coupon'],
        'records' => ['assets', 'consume', 'orders'],
    ];

    /** @var array<int, string> */
    private const TAB_OPTIONS = ['onboard', 'agent', 'records'];

    /**
     * @param array<string, string> $settings
     * @return array<string, mixed>
     */
    public static function resolveForRole(array $settings, string $roleKey): array
    {
        $map = self::readRoleMap($settings);
        $roleKey = trim(strtolower($roleKey));
        $sourceKey = 'default';

        if ($roleKey !== '' && array_key_exists($roleKey, $map)) {
            $sourceKey = $roleKey;
        }

        /** @var array<string, mixed> $resolved */
        $resolved = $map[$sourceKey];
        $tabs = isset($resolved['tabs']) && is_array($resolved['tabs']) ? $resolved['tabs'] : self::TAB_OPTIONS;
        $subtabs = isset($resolved['subtabs']) && is_array($resolved['subtabs']) ? $resolved['subtabs'] : self::defaultSubtabs();

        return [
            'role_key' => $roleKey,
            'source' => $sourceKey,
            'tabs' => $tabs,
            'subtabs' => $subtabs,
        ];
    }

    /**
     * @param array<string, string> $settings
     * @return array<string, mixed>
     */
    public static function readRoleMap(array $settings): array
    {
        $raw = trim((string) ($settings['mobile_role_menu_json'] ?? ''));
        if ($raw === '') {
            return self::defaultRoleMap();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::defaultRoleMap();
        }

        return self::normalizeRoleMap($decoded);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultRoleMap(): array
    {
        $default = [
            'tabs' => self::TAB_OPTIONS,
            'subtabs' => self::defaultSubtabs(),
        ];

        return [
            'default' => $default,
            'admin' => $default,
            'manager' => $default,
            'consultant' => [
                'tabs' => ['onboard', 'agent', 'records'],
                'subtabs' => self::defaultSubtabs(),
            ],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function defaultSubtabs(): array
    {
        return self::SUBTAB_OPTIONS;
    }

    /**
     * @return array<int, string>
     */
    public static function tabOptions(): array
    {
        return self::TAB_OPTIONS;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function subtabOptions(): array
    {
        return self::SUBTAB_OPTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizeForInput(string $jsonText): array
    {
        $raw = trim($jsonText);
        if ($raw === '') {
            return self::defaultRoleMap();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('mobile_role_menu_json 不是有效 JSON');
        }

        return self::normalizeRoleMap($decoded);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function normalizeRoleMap(array $input): array
    {
        $normalized = [];
        foreach ($input as $roleKey => $config) {
            $role = trim(strtolower((string) $roleKey));
            if ($role === '' || !preg_match('/^[a-z0-9_-]{2,40}$/', $role)) {
                continue;
            }
            if (!is_array($config)) {
                continue;
            }
            $normalized[$role] = self::normalizeRoleConfig($config);
        }

        if (!array_key_exists('default', $normalized)) {
            $normalized['default'] = self::normalizeRoleConfig([]);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function normalizeRoleConfig(array $config): array
    {
        $tabsInput = isset($config['tabs']) && is_array($config['tabs']) ? $config['tabs'] : [];
        $tabs = [];
        foreach ($tabsInput as $tab) {
            $id = trim((string) $tab);
            if (in_array($id, self::TAB_OPTIONS, true) && !in_array($id, $tabs, true)) {
                $tabs[] = $id;
            }
        }
        if (empty($tabs)) {
            $tabs = self::TAB_OPTIONS;
        }

        $subtabsInput = isset($config['subtabs']) && is_array($config['subtabs']) ? $config['subtabs'] : [];
        $subtabs = [];
        foreach ($tabs as $tab) {
            $allowed = self::SUBTAB_OPTIONS[$tab] ?? [];
            $itemsRaw = isset($subtabsInput[$tab]) && is_array($subtabsInput[$tab]) ? $subtabsInput[$tab] : [];
            $items = [];
            foreach ($itemsRaw as $item) {
                $id = trim((string) $item);
                if (in_array($id, $allowed, true) && !in_array($id, $items, true)) {
                    $items[] = $id;
                }
            }
            if (empty($items)) {
                $items = $allowed;
            }
            $subtabs[$tab] = $items;
        }

        return [
            'tabs' => $tabs,
            'subtabs' => $subtabs,
        ];
    }
}

