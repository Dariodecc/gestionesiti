<?php
defined('ABSPATH') || exit;
// An unavailable developer module must not disable forms or the public website.
try {
    foreach (array('developer-principal','developer-permissions','dev-schema','dev-client','mcp-content-model','mcp-integrations','mcp-tools','mcp-oauth','mcp-server','mcp-setup','developer-admin') as $module) {
        $class='SCC_'.str_replace(' ', '_', ucwords(str_replace('-', ' ', $module)));
        if (!class_exists($class)) require_once __DIR__.'/class-scc-'.$module.'.php';
    }
} catch (Throwable $e) {
    add_action('admin_notices', function () {
        if (current_user_can('sitecore_mcp_manage')) echo '<div class="notice notice-error"><p>Modulo Developer non disponibile. Ripristina il pacchetto SiteCore completo prima di attivare MCP.</p></div>';
    });
}
