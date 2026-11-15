<?php
/**
 * Network Admin Settings
 *
 * @package MDS_Release_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('network_admin_menu', [MDS_RM_Network_Admin::class, 'registerMenu']);
add_action('network_admin_edit_save_network_settings', [MDS_RM_Network_Admin::class, 'saveSettings']);

/**
 * Encapsulates network settings registration, rendering, and persistence.
 */
final class MDS_RM_Network_Admin
{
    /**
     * Register the network settings menu.
     */
    public static function registerMenu(): void
    {
        add_submenu_page(
            'settings.php',
            'MDS Release Manager',
            'MDS Release Manager',
            'manage_network_options',
            'mds-release-network-settings',
            [MDS_RM_Network_Admin::class, 'renderSettingsPage']
        );
    }

    /**
     * Render the master hub selector.
     *
     * @param array $sites Network sites.
     * @param int   $masterHubId Master hub site ID.
     * @return string Selector HTML.
     */
    public static function renderMasterHubHtml(array $sites, int $masterHubId): string
    {
        $html = '<select name="mds_rm_master_blog_id">';

        foreach ($sites as $site) {
            $html .= sprintf(
                '<option value="%s" %s>Site #%s - %s</option>',
                esc_attr($site->blog_id),
                selected($masterHubId, (int) $site->blog_id, false),
                esc_html($site->blog_id),
                esc_html(get_blog_details($site->blog_id)->blogname)
            );
        }

        return $html . '</select>';
    }

    /**
     * Render the spoke site checkboxes.
     *
     * @param array $sites Network sites.
     * @param int   $masterHubId Master hub site ID.
     * @return string Checkbox HTML.
     */
    public static function renderSpokesHtml(array $sites, int $masterHubId): string
    {
        if (empty($sites)) {
            return '<p class="description">Single-site installation detected. '
                . 'Hub-and-Spoke broadcasting is not applicable.</p>';
        }

        $spokeSites = array_map('intval', (array) get_site_option('spoke_sites', []));
        $html = '';

        foreach ($sites as $site) {
            $blogId = (int) $site->blog_id;

            if ($blogId === $masterHubId) {
                continue;
            }

            $checked = in_array($blogId, $spokeSites, true) ? 'checked' : '';
            $siteDetails = get_blog_details($blogId);
            $blogName = $siteDetails ? $siteDetails->blogname : 'Site #' . $blogId;

            $html .= '<label style="display:block; margin-bottom: 5px;">';
            $html .= '<input type="checkbox" name="spoke_sites[]" value="'
                . esc_attr($blogId)
                . '" ' . $checked . '> ';
            $html .= esc_html($blogName)
                . ' <span style="color:#777;">('
                . esc_html($site->domain . $site->path)
                . ')</span>';
            $html .= '</label>';
        }

        return $html;
    }

    /**
     * Render the network admin settings page.
     */
    public static function renderSettingsPage(): void
    {
        $masterHubId = (int) get_site_option('mds_rm_master_blog_id', get_main_site_id());
        $sites = function_exists('get_sites') ? get_sites() : [];
        $tokens = self::getSettingsTemplateTokens($sites, $masterHubId);

        echo mds_render_template(
            'templates/network-settings-page.html',
            $tokens
        ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Save submitted network settings and redirect back to the settings page.
     */
    public static function saveSettings(): void
    {
        self::verifySavePermission();
        check_admin_referer('mds_rm_save_network_settings');

        $masterHubId = self::getPostedMasterHubId();
        $spokeSiteIds = self::getPostedSpokeSiteIds($masterHubId);
        self::persistSettings($masterHubId, $spokeSiteIds);
        self::redirectAfterSave();
    }

    /**
     * Build the data passed to the settings template.
     *
     * @param array $sites Network sites.
     * @param int   $masterHubId Master hub site ID.
     * @return array Template tokens.
     */
    private static function getSettingsTemplateTokens(array $sites, int $masterHubId): array
    {
        return [
            'form_action' => esc_url(network_admin_url('edit.php?action=mds_rm_save_network_settings')),
            'nonce_field' => wp_nonce_field('mds_rm_save_network_settings', '_wpnonce', false, false),
            'settings_saved_notice' => self::getSettingsSavedNotice(),
            'master_hub_html' => self::renderMasterHubHtml($sites, $masterHubId),
            'spoke_sites_html' => self::renderSpokesHtml($sites, $masterHubId),
            'wipe_on_uninstall' => '1' === (string) get_site_option('mds_rm_wipe_on_uninstall', '0') ? 'checked' : '',
            'hooks_html' => apply_filters('mds_rm_network_settings_fields', ''),
        ];
    }

    /**
     * Build the success notice after a settings save.
     */
    private static function getSettingsSavedNotice(): string
    {
        if (!isset($_GET['updated']) || 'true' !== sanitize_text_field(wp_unslash($_GET['updated']))) {
            return '';
        }

        return '<div class="notice notice-success is-dismissible"><p>' .
            esc_html__('MDS Release Manager Network Settings have been saved.', 'mds-release-manager') .
            '</p></div>';
    }

    /**
     * Ensure the current user can update network settings.
     */
    private static function verifySavePermission(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('You are not allowed to manage network settings.', 'mds-release-manager'));
        }
    }

    /**
     * Get the submitted master hub site ID.
     */
    private static function getPostedMasterHubId(): int
    {
        if (!isset($_POST['mds_rm_master_blog_id'])) {
            return (int) get_site_option('mds_rm_master_blog_id', get_main_site_id());
        }

        return absint(wp_unslash($_POST['mds_rm_master_blog_id']));
    }

    /**
     * Get submitted spoke IDs, excluding the selected master hub.
     *
     * @param int $masterHubId Master hub site ID.
     * @return array Spoke site IDs.
     */
    private static function getPostedSpokeSiteIds(int $masterHubId): array
    {
        if (!isset($_POST['spoke_sites']) || !is_array($_POST['spoke_sites'])) {
            return [];
        }

        $spokeSiteIds = array_map('absint', wp_unslash($_POST['spoke_sites']));
        $spokeSiteIds = array_unique(array_filter($spokeSiteIds));

        return array_values(array_diff($spokeSiteIds, [$masterHubId]));
    }

    /**
     * Persist network settings.
     *
     * @param int   $masterHubId Master hub site ID.
     * @param array $spokeSiteIds Spoke site IDs.
     */
    private static function persistSettings(int $masterHubId, array $spokeSiteIds): void
    {
        update_site_option('mds_rm_master_blog_id', $masterHubId);
        update_site_option('spoke_sites', $spokeSiteIds);
        update_site_option(
            'mds_rm_wipe_on_uninstall',
            isset($_POST['mds_rm_wipe_on_uninstall']) ? '1' : '0'
        );
    }

    /**
     * Redirect to the network settings page after saving.
     */
    private static function redirectAfterSave(): void
    {
        wp_safe_redirect(network_admin_url('settings.php?page=mds-release-network-settings&updated=true'));
        exit;
    }
}

