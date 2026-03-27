<?php
/*
 * Plugin Name: Discord Webhook
 * Description: A very simple WordPress plugin that sends Discord notifications for logins and key site actions.
 * Version: 2.0
 * Author: DiVouz
 * Text Domain: discord-webhook
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

class Discord_Webhook {
    const TEXT_DOMAIN = 'discord-webhook';
    const MAX_QUEUE_SIZE = 500;
    const MAX_FAILED_QUEUE_SIZE = 500;
    const MAX_RETRY_ATTEMPTS = 20;

    private $queue_cron_hook = 'discord_webhook_process_queue';
    private $webhook_option_name = 'discord_webhook_url';
    private $notify_login_option_name = 'discord_webhook_notify_login';
    private $notify_failed_login_option_name = 'discord_webhook_notify_failed_login';
    private $notify_post_published_option_name = 'discord_webhook_notify_post_published';
    private $notify_post_edited_option_name = 'discord_webhook_notify_post_edited';
    private $notify_post_deleted_option_name = 'discord_webhook_notify_post_deleted';
    private $notify_plugin_updated_option_name = 'discord_webhook_notify_plugin_updated';
    private $notify_theme_updated_option_name = 'discord_webhook_notify_theme_updated';
    private $notify_plugin_update_available_option_name = 'discord_webhook_notify_plugin_update_available';
    private $notify_theme_update_available_option_name = 'discord_webhook_notify_theme_update_available';
    private $rate_limit_seconds_option_name = 'discord_webhook_rate_limit_seconds';
    private $queue_option_name = 'discord_webhook_message_queue';
    private $failed_queue_option_name = 'discord_webhook_failed_message_queue';
    private $next_send_at_option_name = 'discord_webhook_next_send_at';
    private $queue_lock_transient_name = 'discord_webhook_queue_processing_lock';
    private $plugin_updates_signature_option_name = 'discord_webhook_plugin_updates_signature';
    private $theme_updates_signature_option_name = 'discord_webhook_theme_updates_signature';

    public function __construct() {
        // Hooks
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_discord_webhook_send_test', [$this, 'handle_send_test_notification']);
        add_action($this->queue_cron_hook, [$this, 'process_message_queue']);
        
        add_action('wp_login', [$this, 'on_login'], 10, 2);
        add_action('wp_login_failed', [$this, 'on_failed_login']);
        add_action('transition_post_status', [$this, 'on_post_published'], 10, 3);
        add_action('post_updated', [$this, 'on_post_edited'], 10, 3);
        add_action('before_delete_post', [$this, 'on_post_deleted'], 10, 2);
        add_action('upgrader_process_complete', [$this, 'on_upgrader_process_complete'], 10, 2);
        add_filter('set_site_transient_update_plugins', [$this, 'on_set_site_transient_update_plugins']);
        add_filter('set_site_transient_update_themes', [$this, 'on_set_site_transient_update_themes']);

        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_settings_link']);
    }

    /* ---------------------- Admin Settings ---------------------- */

    public function load_textdomain() {
        load_plugin_textdomain(self::TEXT_DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function add_settings_page() {
        add_options_page(
            esc_html__('Discord Webhook Settings', self::TEXT_DOMAIN),
            esc_html__('Discord Webhook', self::TEXT_DOMAIN),
            'manage_options', 
            'discord-webhook-settings', 
            [$this, 'settings_page_html']
        );
    }

    public function register_settings() {
        register_setting('discord_webhook_options', $this->webhook_option_name, ['sanitize_callback' => [$this, 'sanitize_webhook_url']]);
        register_setting('discord_webhook_options', $this->notify_login_option_name, ['type' => 'boolean', 'sanitize_callback' => [$this, 'sanitize_checkbox'], 'default' => 1]);
        register_setting('discord_webhook_options', $this->notify_failed_login_option_name, ['type' => 'boolean', 'sanitize_callback' => [$this, 'sanitize_checkbox'], 'default' => 1]);
        register_setting('discord_webhook_options', $this->notify_post_published_option_name, ['type' => 'boolean', 'sanitize_callback' => [$this, 'sanitize_checkbox'], 'default' => 1]);
        register_setting('discord_webhook_options', $this->notify_post_edited_option_name, ['type' => 'boolean', 'sanitize_callback' => [$this, 'sanitize_checkbox'], 'default' => 1]);
        register_setting('discord_webhook_options', $this->notify_post_deleted_option_name, ['type' => 'boolean', 'sanitize_callback' => [$this, 'sanitize_checkbox'], 'default' => 1]);
        register_setting('discord_webhook_options', $this->notify_plugin_updated_option_name, ['type' => 'boolean', 'sanitize_callback' => [$this, 'sanitize_checkbox'], 'default' => 1]);
        register_setting('discord_webhook_options', $this->notify_theme_updated_option_name, ['type' => 'boolean', 'sanitize_callback' => [$this, 'sanitize_checkbox'], 'default' => 1]);
        register_setting('discord_webhook_options', $this->notify_plugin_update_available_option_name, ['type' => 'boolean', 'sanitize_callback' => [$this, 'sanitize_checkbox'], 'default' => 1]);
        register_setting('discord_webhook_options', $this->notify_theme_update_available_option_name, ['type' => 'boolean', 'sanitize_callback' => [$this, 'sanitize_checkbox'], 'default' => 1]);
        register_setting('discord_webhook_options', $this->rate_limit_seconds_option_name, ['type' => 'integer', 'sanitize_callback' => [$this, 'sanitize_rate_limit_milliseconds'], 'default' => 1000]);
    }

    public function sanitize_checkbox($value) {
        return !empty($value) ? 1 : 0;
    }

    public function sanitize_webhook_url($value) {
        $raw_value = trim((string) $value);
        if ($raw_value === '') return '';

        $url = esc_url_raw($raw_value, ['https']);
        if (!$url || !$this->is_valid_discord_webhook_url($url)) {
            add_settings_error(
                'discord_webhook_options',
                'discord_webhook_invalid_url',
                esc_html__('Invalid Discord webhook URL. Use a valid Discord webhook endpoint, for example: https://discord.com/api/webhooks/{id}/{token}', self::TEXT_DOMAIN),
                'error'
            );

            return (string) get_option($this->webhook_option_name, '');
        }

        return $url;
    }

    private function is_valid_discord_webhook_url($url) {
        $pattern = '#^https://(?:canary\.|ptb\.)?(?:discord(?:app)?\.com)/api/webhooks/[0-9]+/[A-Za-z0-9._\-]+/?$#i';
        return (bool) preg_match($pattern, $url);
    }

    public function sanitize_rate_limit_milliseconds($value) {
        $milliseconds = absint($value);
        if ($milliseconds < 50) return 50;
        return min($milliseconds, 60000);
    }

    public function settings_page_html() {
        $test_status = isset($_GET['discord_webhook_test'])
            ? sanitize_text_field(wp_unslash($_GET['discord_webhook_test']))
            : '';

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Discord Webhook Settings', self::TEXT_DOMAIN); ?></h1>
            <?php settings_errors('discord_webhook_options'); ?>
            <?php if ($test_status === 'success'): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Test notification sent successfully.', self::TEXT_DOMAIN); ?></p></div>
            <?php elseif ($test_status === 'failed'): ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Failed to send test notification. Please verify your webhook URL and try again.', self::TEXT_DOMAIN); ?></p></div>
            <?php elseif ($test_status === 'missing_url'): ?>
                <div class="notice notice-warning is-dismissible"><p><?php esc_html_e('Please set a webhook URL before sending a test notification.', self::TEXT_DOMAIN); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
                <?php
                    settings_fields('discord_webhook_options');
                    do_settings_sections('discord_webhook_options');
                    $webhook_url = esc_attr(get_option($this->webhook_option_name));
                    $notify_login = (int) get_option($this->notify_login_option_name, 1);
                    $notify_failed_login = (int) get_option($this->notify_failed_login_option_name, 1);
                    $notify_post_published = (int) get_option($this->notify_post_published_option_name, 1);
                    $notify_post_edited = (int) get_option($this->notify_post_edited_option_name, 1);
                    $notify_post_deleted = (int) get_option($this->notify_post_deleted_option_name, 1);
                    $notify_plugin_updated = (int) get_option($this->notify_plugin_updated_option_name, 1);
                    $notify_theme_updated = (int) get_option($this->notify_theme_updated_option_name, 1);
                    $notify_plugin_update_available = (int) get_option($this->notify_plugin_update_available_option_name, 1);
                    $notify_theme_update_available = (int) get_option($this->notify_theme_update_available_option_name, 1);
                    $rate_limit_seconds = (int) $this->get_rate_limit_milliseconds();
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Webhook URL', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input type="url" name="<?php echo esc_attr($this->webhook_option_name); ?>" value="<?php echo $webhook_url; ?>" size="60" />
                            <p class="description"><?php esc_html_e('Accepted format: https://discord.com/api/webhooks/{id}/{token}', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Global Send Interval (milliseconds)', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input
                                type="number"
                                min="50"
                                max="60000"
                                step="50"
                                name="<?php echo esc_attr($this->rate_limit_seconds_option_name); ?>"
                                value="<?php echo esc_attr($rate_limit_seconds); ?>"
                                class="small-text"
                            />
                            <p class="description"><?php esc_html_e('Minimum delay between webhook deliveries for all notification types. Supports sub-second values (for example: 200ms).', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Send Login Notification', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($this->notify_login_option_name); ?>" value="1" <?php checked($notify_login, 1); ?> />
                                <?php esc_html_e('Notify on successful login', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Send Failed Login Notification', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($this->notify_failed_login_option_name); ?>" value="1" <?php checked($notify_failed_login, 1); ?> />
                                <?php esc_html_e('Notify on failed login attempt', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Send New Post/Page Notification', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($this->notify_post_published_option_name); ?>" value="1" <?php checked($notify_post_published, 1); ?> />
                                <?php esc_html_e('Notify when a post or page is newly published', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Send Post/Page Edited Notification', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($this->notify_post_edited_option_name); ?>" value="1" <?php checked($notify_post_edited, 1); ?> />
                                <?php esc_html_e('Notify when a published post or page is edited', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Send Post/Page Deleted Notification', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($this->notify_post_deleted_option_name); ?>" value="1" <?php checked($notify_post_deleted, 1); ?> />
                                <?php esc_html_e('Notify when a post or page is permanently deleted', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Send Plugin Updated Notification', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($this->notify_plugin_updated_option_name); ?>" value="1" <?php checked($notify_plugin_updated, 1); ?> />
                                <?php esc_html_e('Notify when plugins are updated', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Send Theme Updated Notification', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($this->notify_theme_updated_option_name); ?>" value="1" <?php checked($notify_theme_updated, 1); ?> />
                                <?php esc_html_e('Notify when themes are updated', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Send Plugin Update Available Notification', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($this->notify_plugin_update_available_option_name); ?>" value="1" <?php checked($notify_plugin_update_available, 1); ?> />
                                <?php esc_html_e('Notify when plugin updates become available', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Send Theme Update Available Notification', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($this->notify_theme_update_available_option_name); ?>" value="1" <?php checked($notify_theme_update_available, 1); ?> />
                                <?php esc_html_e('Notify when theme updates become available', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr />
            <h2><?php esc_html_e('Send Test Notification', self::TEXT_DOMAIN); ?></h2>
            <p><?php esc_html_e('Send a test message to confirm your webhook URL is working.', self::TEXT_DOMAIN); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('discord_webhook_send_test', 'discord_webhook_test_nonce'); ?>
                <input type="hidden" name="action" value="discord_webhook_send_test" />
                <?php submit_button(esc_html__('Send Test Message', self::TEXT_DOMAIN), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    public function plugin_settings_link($links) {
        $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=discord-webhook-settings')) . '">' . esc_html__('Settings', self::TEXT_DOMAIN) . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /* ---------------------- Utility ---------------------- */

    private function sanitize_ip($ip) {
        $ip = trim((string) $ip);
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    private function is_trusted_proxy($ip) {
        if ($ip === '127.0.0.1' || $ip === '::1') return true;

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        // Trust local/private network proxies (common reverse-proxy setup).
        return preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $ip) === 1;
    }

    private function get_client_ip() {
        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? $this->sanitize_ip($_SERVER['REMOTE_ADDR']) : '';
        if ($remote_addr === '') return '?';

        if (!$this->is_trusted_proxy($remote_addr)) {
            return $remote_addr;
        }

        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cf_ip = $this->sanitize_ip($_SERVER['HTTP_CF_CONNECTING_IP']);
            if ($cf_ip !== '') return $cf_ip;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded_for = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            foreach ($forwarded_for as $ip) {
                $candidate = $this->sanitize_ip($ip);
                if ($candidate !== '') return $candidate;
            }
        }

        return $remote_addr;
    }

    private function send_discord_webhook($message) {
        $discord_webhook_url = get_option($this->webhook_option_name);
        if (empty($discord_webhook_url)) return false;

        $queued = $this->enqueue_message($message);
        if (!$queued) return false;

        // Try to flush one queued message now; remaining messages are handled by WP-Cron.
        $this->process_message_queue();
        $this->kick_queue_worker();
        return true;
    }

    private function send_discord_webhook_immediately($message) {
        $discord_webhook_url = get_option($this->webhook_option_name);
        if (empty($discord_webhook_url)) return ['success' => false, 'retry_after' => 60];

        $payload = $this->build_discord_embed_payload($message);

        $response = wp_remote_post($discord_webhook_url, [
            'method'  => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
            'timeout' => 8
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'retry_after' => 30,
                'error' => $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code >= 200 && $status_code < 300) {
            return ['success' => true, 'retry_after' => 0, 'error' => ''];
        }

        if ($status_code === 429) {
            return [
                'success' => false,
                'retry_after' => $this->extract_retry_after_seconds($response),
                'error' => 'HTTP 429 rate limited',
            ];
        }

        if ($status_code >= 500) {
            return [
                'success' => false,
                'retry_after' => 30,
                'error' => 'HTTP ' . $status_code,
            ];
        }

        // For non-retriable 4xx responses, keep retrying slowly to avoid dropping notifications.
        return [
            'success' => false,
            'retry_after' => 300,
            'error' => 'HTTP ' . $status_code,
        ];
    }

    private function build_discord_embed_payload($message) {
        $message = trim((string) $message);
        if ($message === '') {
            $message = '(empty notification)';
        }

        // Discord embed description limit is 4096 characters.
        $description = mb_substr($message, 0, 4096);
        if (mb_strlen($message) > 4096) {
            $description .= '...';
        }

        return [
            'embeds' => [[
                'title' => get_bloginfo('name') . ' - ' . get_bloginfo('url'),
                'description' => $description,
                'color' => $this->get_embed_color_from_message($message),
                'timestamp' => gmdate('c'),
            ]],
            'allowed_mentions' => ['parse' => []],
        ];
    }

    private function get_embed_color_from_message($message) {
        $text = strtolower((string) $message);

        if (strpos($text, 'failed login') !== false || strpos($text, 'failed') !== false) {
            return 15158332; // red
        }

        if (strpos($text, 'deleted') !== false) {
            return 10038562; // dark orange
        }

        if (strpos($text, 'updated') !== false || strpos($text, 'edited') !== false) {
            return 3447003; // blue
        }

        if (strpos($text, 'published') !== false || strpos($text, 'logged in') !== false) {
            return 5763719; // green
        }

        return 7506394; // neutral gray-blue
    }

    private function get_rate_limit_milliseconds() {
        $value = (int) get_option($this->rate_limit_seconds_option_name, 1000);
        return max(50, min($value, 60000));
    }

    private function enqueue_message($message) {
        $queue = get_option($this->queue_option_name, []);
        if (!is_array($queue)) $queue = [];

        if (count($queue) >= self::MAX_QUEUE_SIZE) {
            $this->push_failed_queue_item([
                'message' => (string) $message,
                'attempts' => 0,
            ], 'queue_overflow', 'Queue is full');
            return false;
        }

        $queue[] = [
            'message' => (string) $message,
            'attempts' => 0,
            'next_try_at' => microtime(true),
        ];

        $saved = update_option($this->queue_option_name, $queue, false);
        if ($saved || get_option($this->queue_option_name, []) === $queue) {
            $this->schedule_queue_processing(time());
            return true;
        }

        return false;
    }

    private function schedule_queue_processing($timestamp) {
        $now = microtime(true);
        $when = max($now, (float) $timestamp);
        $schedule_at = (int) ceil($when);
        wp_schedule_single_event($schedule_at, $this->queue_cron_hook);
        $this->kick_queue_worker();
    }

    private function kick_queue_worker() {
        $cron_url = add_query_arg('doing_wp_cron', (string) microtime(true), site_url('wp-cron.php'));

        wp_remote_post($cron_url, [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);
    }

    private function extract_retry_after_seconds($response) {
        $header_retry_after = wp_remote_retrieve_header($response, 'retry-after');
        if ($header_retry_after !== '') {
            $parsed = (float) $header_retry_after;
            if ($parsed > 0) {
                return (int) max(1, ceil($parsed));
            }
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['retry_after'])) {
            $parsed = (float) $decoded['retry_after'];
            if ($parsed > 0) {
                if ($parsed > 1000) {
                    return (int) max(1, ceil($parsed / 1000));
                }

                return (int) max(1, ceil($parsed));
            }
        }

        return 5;
    }

    private function push_failed_queue_item($item, $reason, $error) {
        $failed_queue = get_option($this->failed_queue_option_name, []);
        if (!is_array($failed_queue)) $failed_queue = [];

        if (count($failed_queue) >= self::MAX_FAILED_QUEUE_SIZE) {
            array_shift($failed_queue);
        }

        $failed_queue[] = [
            'message' => isset($item['message']) ? (string) $item['message'] : '',
            'attempts' => isset($item['attempts']) ? (int) $item['attempts'] : 0,
            'reason' => sanitize_text_field((string) $reason),
            'error' => sanitize_text_field((string) $error),
            'failed_at' => time(),
        ];

        update_option($this->failed_queue_option_name, $failed_queue, false);
    }

    public function process_message_queue() {
        if (get_transient($this->queue_lock_transient_name)) return;
        set_transient($this->queue_lock_transient_name, 1, 30);

        $max_messages_per_run = 20;
        $processed = 0;

        while ($processed < $max_messages_per_run) {
            $queue = get_option($this->queue_option_name, []);
            if (!is_array($queue) || empty($queue)) {
                break;
            }

            $now = microtime(true);
            $next_send_at = (float) get_option($this->next_send_at_option_name, 0);
            if ($next_send_at > $now) {
                $wait_seconds = $next_send_at - $now;
                if ($wait_seconds < 1) {
                    usleep((int) max(1000, round($wait_seconds * 1000000)));
                    continue;
                }

                $this->schedule_queue_processing($next_send_at);
                break;
            }

            $item = $queue[0];
            $item_next_try_at = isset($item['next_try_at']) ? (float) $item['next_try_at'] : $now;
            if ($item_next_try_at > $now) {
                $wait_seconds = $item_next_try_at - $now;
                if ($wait_seconds < 1) {
                    usleep((int) max(1000, round($wait_seconds * 1000000)));
                    continue;
                }

                $this->schedule_queue_processing($item_next_try_at);
                break;
            }

            $message = isset($item['message']) ? (string) $item['message'] : '';
            if ($message === '') {
                array_shift($queue);
                update_option($this->queue_option_name, $queue, false);
                $processed++;
                continue;
            }

            $result = $this->send_discord_webhook_immediately($message);
            $success = !empty($result['success']);

            if ($success) {
                array_shift($queue);
                update_option($this->queue_option_name, $queue, false);

                $next = microtime(true) + ($this->get_rate_limit_milliseconds() / 1000);
                update_option($this->next_send_at_option_name, $next, false);
                $processed++;

                if (!empty($queue) && $processed >= $max_messages_per_run) {
                    $this->schedule_queue_processing($next);
                }

                continue;
            }

            $attempts = isset($item['attempts']) ? (int) $item['attempts'] + 1 : 1;
            $retry_after = isset($result['retry_after']) ? (int) $result['retry_after'] : 30;
            $last_error = isset($result['error']) ? (string) $result['error'] : 'Unknown error';

            if ($attempts >= self::MAX_RETRY_ATTEMPTS) {
                array_shift($queue);
                update_option($this->queue_option_name, $queue, false);
                $item['attempts'] = $attempts;
                $this->push_failed_queue_item($item, 'max_retries', $last_error);
                $processed++;
                continue;
            }

            $backoff = min(900, (int) pow(2, min($attempts, 10)));
            $delay = max(1, max($retry_after, $backoff));

            $queue[0]['attempts'] = $attempts;
            $queue[0]['next_try_at'] = microtime(true) + $delay;
            update_option($this->queue_option_name, $queue, false);
            $this->schedule_queue_processing($queue[0]['next_try_at']);
            break;
        }

        delete_transient($this->queue_lock_transient_name);
    }

    private function get_user_info($username) {
        $user = get_user_by('login', $username);
        $role = ($user && !empty($user->roles)) ? implode(', ', $user->roles) : '?';
        $first_name = $user ? $user->first_name : '?';
        $last_name  = $user ? $user->last_name : '?';
        return [$role, $first_name, $last_name];
    }

    private function is_notification_enabled($option_name) {
        return (int) get_option($option_name, 1) === 1;
    }

    private function get_post_taxonomy_names($post_id, $taxonomy) {
        $terms = get_the_terms($post_id, $taxonomy);
        if (is_wp_error($terms) || empty($terms)) return 'none';
        return implode(', ', wp_list_pluck($terms, 'name'));
    }

    private function get_post_metadata_lines($post_id, $is_edit = false) {
        $date = $is_edit
            ? get_post_modified_time('Y-m-d H:i:s', false, $post_id)
            : get_post_time('Y-m-d H:i:s', false, $post_id);
        $categories = $this->get_post_taxonomy_names($post_id, 'category');
        $tags = $this->get_post_taxonomy_names($post_id, 'post_tag');

        return sprintf(
            "Date: `%s`\nCategories: `%s`\nTags: `%s`\nPost ID: `%d`",
            $date ? $date : '?',
            $categories,
            $tags,
            $post_id
        );
    }

    private function build_post_notification_message($action, $post_type, $title, $post_url, $author_name, $author_role, $author_first_name, $author_last_name, $actor_name, $actor_role, $actor_first_name, $actor_last_name, $post_meta_lines) {
        $safe_title = $title ? $title : '(no title)';
        $safe_post_url = $post_url ? $post_url : 'N/A';

        return sprintf(
            "Post Notification\nAction: `%s`\nType: `%s`\nAuthor: `%s` [`%s`] (`%s %s`)\nActor: `%s` [`%s`] (`%s %s`)\nTitle: `%s`\nURL: `%s`\n%s",
            $action,
            $post_type,
            $author_name,
            $author_role,
            $author_first_name,
            $author_last_name,
            $actor_name,
            $actor_role,
            $actor_first_name,
            $actor_last_name,
            $safe_title,
            $safe_post_url,
            $post_meta_lines
        );
    }

    private function get_user_profile_info($user_id) {
        if (empty($user_id)) {
            return ['unknown', '?', '?', '?'];
        }

        $user = get_userdata((int) $user_id);
        if (!$user) {
            return ['unknown', '?', '?', '?'];
        }

        $role = !empty($user->roles) ? implode(', ', $user->roles) : '?';
        return [
            $user->user_login,
            $role,
            $user->first_name ? $user->first_name : '?',
            $user->last_name ? $user->last_name : '?',
        ];
    }

    private function get_action_actor_info($fallback_user_id = 0) {
        $actor = wp_get_current_user();

        if ($actor && !empty($actor->ID)) {
            $role = !empty($actor->roles) ? implode(', ', $actor->roles) : '?';
            return [
                $actor->user_login,
                $role,
                $actor->first_name ? $actor->first_name : '?',
                $actor->last_name ? $actor->last_name : '?',
            ];
        }

        if (!empty($fallback_user_id)) {
            $fallback = get_userdata((int) $fallback_user_id);
            if ($fallback) {
                $role = !empty($fallback->roles) ? implode(', ', $fallback->roles) : '?';
                return [
                    $fallback->user_login,
                    $role,
                    $fallback->first_name ? $fallback->first_name : '?',
                    $fallback->last_name ? $fallback->last_name : '?',
                ];
            }
        }

        return ['system', '?', '?', '?'];
    }

    private function format_user_credentials($username, $role, $first_name, $last_name) {
        return sprintf('`%s` [`%s`] (`%s %s`)', $username, $role, $first_name, $last_name);
    }

    private function get_updated_plugin_names($options) {
        $plugin_files = [];
        if (!empty($options['plugins']) && is_array($options['plugins'])) {
            $plugin_files = $options['plugins'];
        } elseif (!empty($options['plugin']) && is_string($options['plugin'])) {
            $plugin_files = [$options['plugin']];
        }

        if (empty($plugin_files)) return [];

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = function_exists('get_plugins') ? get_plugins() : [];
        $names = [];

        foreach ($plugin_files as $plugin_file) {
            $plugin_file = (string) $plugin_file;
            if ($plugin_file === '') continue;

            $plugin_name = isset($all_plugins[$plugin_file]['Name'])
                ? $all_plugins[$plugin_file]['Name']
                : $plugin_file;

            $names[] = sanitize_text_field($plugin_name);
        }

        return array_values(array_unique($names));
    }

    private function get_updated_theme_names($options) {
        $theme_slugs = [];
        if (!empty($options['themes']) && is_array($options['themes'])) {
            $theme_slugs = $options['themes'];
        } elseif (!empty($options['theme']) && is_string($options['theme'])) {
            $theme_slugs = [$options['theme']];
        }

        if (empty($theme_slugs)) return [];

        $names = [];
        foreach ($theme_slugs as $theme_slug) {
            $theme_slug = (string) $theme_slug;
            if ($theme_slug === '') continue;

            $theme = wp_get_theme($theme_slug);
            $theme_name = $theme && $theme->exists() ? $theme->get('Name') : $theme_slug;
            $names[] = sanitize_text_field((string) $theme_name);
        }

        return array_values(array_unique($names));
    }

    private function get_available_plugin_update_names($response) {
        if (!is_array($response) || empty($response)) return [];

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = function_exists('get_plugins') ? get_plugins() : [];
        $names = [];

        foreach ($response as $plugin_file => $plugin_update) {
            $plugin_file = (string) $plugin_file;
            if ($plugin_file === '') continue;

            $plugin_name = isset($all_plugins[$plugin_file]['Name'])
                ? $all_plugins[$plugin_file]['Name']
                : $plugin_file;

            $new_version = (is_object($plugin_update) && !empty($plugin_update->new_version))
                ? (string) $plugin_update->new_version
                : '';

            $label = sanitize_text_field((string) $plugin_name);
            if ($new_version !== '') {
                $label .= ' (' . sanitize_text_field($new_version) . ')';
            }

            $names[] = $label;
        }

        $names = array_values(array_unique($names));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    private function get_available_theme_update_names($response) {
        if (!is_array($response) || empty($response)) return [];

        $names = [];
        foreach ($response as $theme_slug => $theme_update) {
            $theme_slug = (string) $theme_slug;
            if ($theme_slug === '') continue;

            $theme = wp_get_theme($theme_slug);
            $theme_name = $theme && $theme->exists() ? $theme->get('Name') : $theme_slug;

            $new_version = '';
            if (is_array($theme_update) && !empty($theme_update['new_version'])) {
                $new_version = (string) $theme_update['new_version'];
            } elseif (is_object($theme_update) && !empty($theme_update->new_version)) {
                $new_version = (string) $theme_update->new_version;
            }

            $label = sanitize_text_field((string) $theme_name);
            if ($new_version !== '') {
                $label .= ' (' . sanitize_text_field($new_version) . ')';
            }

            $names[] = $label;
        }

        $names = array_values(array_unique($names));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    private function maybe_notify_update_available($items, $signature_option_name, $subject) {
        if (!is_array($items)) $items = [];

        $signature = md5(wp_json_encode($items));
        $previous_signature = (string) get_option($signature_option_name, '');

        if ($signature === $previous_signature) {
            return;
        }

        update_option($signature_option_name, $signature, false);
        if (empty($items)) return;

        $message = sprintf(
            "%s updates available\nAvailable: `%s`",
            $subject,
            implode(', ', $items)
        );

        $this->send_discord_webhook($message);
    }

    /* ---------------------- Hooks ---------------------- */

    public function on_login($username) {
        if (!$this->is_notification_enabled($this->notify_login_option_name)) return;

        $ip = $this->get_client_ip();
        list($role, $first_name, $last_name) = $this->get_user_info($username);
        $user_formatted = $this->format_user_credentials($username, $role, $first_name, $last_name);

        $message = sprintf('User %s logged in from IP `%s`.', $user_formatted, $ip);
        $this->send_discord_webhook($message);
    }

    public function on_failed_login($username) {
        if (!$this->is_notification_enabled($this->notify_failed_login_option_name)) return;

        $ip = $this->get_client_ip();
        list($role, $first_name, $last_name) = $this->get_user_info($username);
        $user_formatted = $this->format_user_credentials($username, $role, $first_name, $last_name);

        $message = sprintf('Failed login attempt for user %s from IP `%s`.', $user_formatted, $ip);
        $this->send_discord_webhook($message);
    }

    public function on_post_published($new_status, $old_status, $post) {
        if (!$this->is_notification_enabled($this->notify_post_published_option_name)) return;

        if (!$post || !isset($post->ID)) return;
        if (wp_is_post_revision($post->ID)) return;
        if ($new_status !== 'publish' || $old_status === 'publish') return;
        if (!in_array($post->post_type, ['post', 'page'], true)) return;

        $title = get_the_title($post->ID);
        $post_url = get_permalink($post->ID);
        list($author_name, $author_role, $author_first_name, $author_last_name) = $this->get_user_profile_info($post->post_author);
        list($actor_name, $actor_role, $actor_first_name, $actor_last_name) = $this->get_action_actor_info();
        $post_meta_lines = $this->get_post_metadata_lines($post->ID);

        $message = $this->build_post_notification_message(
            'published',
            $post->post_type,
            $title,
            $post_url,
            $author_name,
            $author_role,
            $author_first_name,
            $author_last_name,
            $actor_name,
            $actor_role,
            $actor_first_name,
            $actor_last_name,
            $post_meta_lines
        );

        $this->send_discord_webhook($message);
    }

    public function on_post_edited($post_id, $post_after, $post_before) {
        if (!$this->is_notification_enabled($this->notify_post_edited_option_name)) return;

        if (wp_is_post_revision($post_id)) return;
        if (!$post_after || !$post_before) return;
        if (!in_array($post_after->post_type, ['post', 'page'], true)) return;
        if ($post_after->post_status !== 'publish' || $post_before->post_status !== 'publish') return;

        $title = get_the_title($post_id);
        $post_url = get_permalink($post_id);
        list($author_name, $author_role, $author_first_name, $author_last_name) = $this->get_user_profile_info($post_after->post_author);
        list($actor_name, $actor_role, $actor_first_name, $actor_last_name) = $this->get_action_actor_info();
        $post_meta_lines = $this->get_post_metadata_lines($post_id, true);

        $message = $this->build_post_notification_message(
            'edited',
            $post_after->post_type,
            $title,
            $post_url,
            $author_name,
            $author_role,
            $author_first_name,
            $author_last_name,
            $actor_name,
            $actor_role,
            $actor_first_name,
            $actor_last_name,
            $post_meta_lines
        );

        $this->send_discord_webhook($message);
    }

    public function on_post_deleted($post_id, $post) {
        if (!$this->is_notification_enabled($this->notify_post_deleted_option_name)) return;

        if (wp_is_post_revision($post_id)) return;
        if (!$post || !isset($post->post_type)) return;
        if (!in_array($post->post_type, ['post', 'page'], true)) return;

        $title = get_the_title($post_id);
        $post_url = get_permalink($post_id);
        list($author_name, $author_role, $author_first_name, $author_last_name) = $this->get_user_profile_info($post->post_author);
        list($actor_name, $actor_role, $actor_first_name, $actor_last_name) = $this->get_action_actor_info();
        $post_meta_lines = $this->get_post_metadata_lines($post_id, true);

        $message = $this->build_post_notification_message(
            'deleted',
            $post->post_type,
            $title,
            $post_url,
            $author_name,
            $author_role,
            $author_first_name,
            $author_last_name,
            $actor_name,
            $actor_role,
            $actor_first_name,
            $actor_last_name,
            $post_meta_lines
        );

        $this->send_discord_webhook($message);
    }

    public function on_upgrader_process_complete($upgrader_object, $options) {
        if (empty($options['action']) || $options['action'] !== 'update') return;
        if (empty($options['type'])) return;

        list($actor_name, $actor_role, $actor_first_name, $actor_last_name) = $this->get_action_actor_info();
        $actor_formatted = $this->format_user_credentials($actor_name, $actor_role, $actor_first_name, $actor_last_name);

        if ($options['type'] === 'plugin') {
            if (!$this->is_notification_enabled($this->notify_plugin_updated_option_name)) return;

            $plugin_names = $this->get_updated_plugin_names($options);
            if (empty($plugin_names)) return;

            $message = sprintf(
                "Plugins updated by %s\nUpdated: `%s`",
                $actor_formatted,
                implode(', ', $plugin_names)
            );

            $this->send_discord_webhook($message);
            return;
        }

        if ($options['type'] === 'theme') {
            if (!$this->is_notification_enabled($this->notify_theme_updated_option_name)) return;

            $theme_names = $this->get_updated_theme_names($options);
            if (empty($theme_names)) return;

            $message = sprintf(
                "Themes updated by %s\nUpdated: `%s`",
                $actor_formatted,
                implode(', ', $theme_names)
            );

            $this->send_discord_webhook($message);
        }
    }

    public function on_set_site_transient_update_plugins($transient) {
        if (!$this->is_notification_enabled($this->notify_plugin_update_available_option_name)) return $transient;
        if (!is_object($transient)) return $transient;

        $response = isset($transient->response) ? (array) $transient->response : [];
        $items = $this->get_available_plugin_update_names($response);
        $this->maybe_notify_update_available($items, $this->plugin_updates_signature_option_name, 'Plugin');

        return $transient;
    }

    public function on_set_site_transient_update_themes($transient) {
        if (!$this->is_notification_enabled($this->notify_theme_update_available_option_name)) return $transient;
        if (!is_object($transient)) return $transient;

        $response = isset($transient->response) ? (array) $transient->response : [];
        $items = $this->get_available_theme_update_names($response);
        $this->maybe_notify_update_available($items, $this->theme_updates_signature_option_name, 'Theme');

        return $transient;
    }

    public function handle_send_test_notification() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized request.', self::TEXT_DOMAIN));
        }

        check_admin_referer('discord_webhook_send_test', 'discord_webhook_test_nonce');

        $redirect_url = admin_url('options-general.php?page=discord-webhook-settings');
        $webhook_url = trim((string) get_option($this->webhook_option_name, ''));

        if ($webhook_url === '') {
            wp_safe_redirect(add_query_arg('discord_webhook_test', 'missing_url', $redirect_url));
            exit;
        }

        list($actor_name, $actor_role, $actor_first_name, $actor_last_name) = $this->get_action_actor_info();
        $actor_formatted = $this->format_user_credentials($actor_name, $actor_role, $actor_first_name, $actor_last_name);
        $message = sprintf(
            'Test notification sent by %s at `%s`.',
            $actor_formatted,
            current_time('Y-m-d H:i:s')
        );

        $result = $this->send_discord_webhook($message);
        $status = $result ? 'success' : 'failed';

        wp_safe_redirect(add_query_arg('discord_webhook_test', $status, $redirect_url));
        exit;
    }
}

new Discord_Webhook();
