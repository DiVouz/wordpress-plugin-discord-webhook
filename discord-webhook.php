<?php
/*
 * Plugin Name: Discord Webhook
 * Description: A very simple WordPress plugin that sends Discord notifications for login attempts.
 * Version: 1.0
 * Author: DiVouz
 */

if (!defined('ABSPATH')) exit;

class Discord_Webhook {
    private $url;
    private $webhook_option_name = 'discord_webhook_url';

    public function __construct() {
        $this->url = home_url();

        // Hooks
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_login', [$this, 'on_login'], 10, 2);
        add_action('wp_login_failed', [$this, 'on_failed_login']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_settings_link']);
    }

    /* ---------------------- Admin Settings ---------------------- */

    public function add_settings_page() {
        add_options_page(
            'Discord Webhook Settings', 
            'Discord Webhook', 
            'manage_options', 
            'discord-webhook-settings', 
            [$this, 'settings_page_html']
        );
    }

    public function register_settings() {
        register_setting('discord_webhook_options', $this->webhook_option_name);
    }

    public function settings_page_html() {
        ?>
        <div class="wrap">
            <h1>Discord Webhook Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('discord_webhook_options');
                do_settings_sections('discord_webhook_options');
                $webhook_url = esc_attr(get_option($this->webhook_option_name));
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Webhook URL</th>
                        <td><input type="text" name="<?php echo $this->webhook_option_name; ?>" value="<?php echo $webhook_url; ?>" size="60" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function plugin_settings_link($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=discord-webhook-settings') . '">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /* ---------------------- Utility ---------------------- */

    private function get_client_ip() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        return $_SERVER['REMOTE_ADDR'];
    }

    private function send_discord_webhook($message) {
        $discord_webhook_url = get_option($this->webhook_option_name);
        if (empty($discord_webhook_url)) return;

        wp_remote_post($discord_webhook_url, [
            'method'  => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['content' => $message]),
            'timeout' => 2
        ]);
    }

    private function get_user_info($username) {
        $user = get_user_by('login', $username);
        $role = ($user && !empty($user->roles)) ? implode(', ', $user->roles) : '?';
        $first_name = $user ? $user->first_name : '?';
        $last_name  = $user ? $user->last_name : '?';
        return [$role, $first_name, $last_name];
    }

    /* ---------------------- Hooks ---------------------- */

    public function on_login($username) {
        $ip = $this->get_client_ip();
        list($role, $first_name, $last_name) = $this->get_user_info($username);

        $message = sprintf(
            '`[%s]` User `%s` [`%s`] (`%s %s`) logged in from IP `%s`.',
            $this->url, $username, $role, $first_name, $last_name, $ip
        );

        $this->send_discord_webhook($message);
    }

    public function on_failed_login($username) {
        $ip = $this->get_client_ip();
        list($role, $first_name, $last_name) = $this->get_user_info($username);

        $message = sprintf(
            '`[%s]` Failed login attempt for username `%s` [`%s`] (`%s %s`) from IP `%s`.',
            $this->url, $username, $role, $first_name, $last_name, $ip
        );

        $this->send_discord_webhook($message);
    }
}

new Discord_Webhook();
