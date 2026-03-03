<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Support;

final class ModelFieldPolicy
{
    /**
     * @var array<string, array<int, string>>
     */
    private const FIELD_ALLOWLISTS = [
        'SiteTemplate' => ['id', 'templatename', 'description', 'editor_type', 'icon', 'category', 'locked'],
        'SiteTmplvar' => ['id', 'name', 'caption', 'description', 'type', 'default_text', 'display', 'elements', 'rank', 'category', 'locked'],
        'SiteTmplvarContentvalue' => ['id', 'contentid', 'tmplvarid', 'value'],
        'SiteSnippet' => ['id', 'name', 'description', 'category', 'locked', 'disabled', 'createdon', 'editedon'],
        'SitePlugin' => ['id', 'name', 'description', 'category', 'locked', 'disabled', 'createdon', 'editedon'],
        'SiteModule' => ['id', 'name', 'description', 'category', 'disabled', 'createdon', 'editedon'],
        'Category' => ['id', 'category'],
        'User' => ['id', 'username', 'isfrontend', 'createdon', 'editedon', 'blocked', 'blockeduntil', 'blockedafter'],
        'UserAttribute' => ['id', 'internalKey', 'fullname', 'email', 'phone', 'mobilephone', 'blocked', 'blockeduntil', 'blockedafter', 'failedlogincount', 'logincount', 'lastlogin'],
        'UserRole' => ['id', 'name', 'description', 'frames', 'home', 'rank', 'locked'],
        'Permissions' => ['id', 'name', 'description'],
        'PermissionsGroups' => ['id', 'name'],
        'RolePermissions' => ['id', 'role_id', 'permission'],
    ];

    /**
     * @var array<int, string>
     */
    private const SENSITIVE_FIELDS = [
        'password',
        'cachepwd',
        'verified_key',
        'refresh_token',
        'access_token',
        'sessionid',
    ];

    /**
     * @return array<string, array<int, string>>
     */
    public static function fieldAllowlists(): array
    {
        return self::FIELD_ALLOWLISTS;
    }

    /**
     * @return array<int, string>
     */
    public static function sensitiveFields(): array
    {
        return self::SENSITIVE_FIELDS;
    }
}
