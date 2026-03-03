<?php

if (!function_exists('eMCP_settings')) {
    /**
     * @return array<string, mixed>
     */
    function eMCP_settings(): array
    {
        $settings = config('cms.settings.eMCP', []);

        return is_array($settings) ? $settings : [];
    }
}
