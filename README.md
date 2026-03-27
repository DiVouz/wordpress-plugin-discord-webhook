# wordpress-plugin-discord-webhook

A very simple WordPress plugin that sends Discord notifications for logins and key site actions.

> Vibe Coding Notice:
> Most of this code is generated with AI assistance.
> Use it with caution, review the implementation before production use, and test in a staging environment first.
> You are responsible for validating security, reliability, and compatibility with your WordPress setup.

## Notification Events

- Successful user logins
- Failed login attempts
- Newly published posts/pages
- Edited published posts/pages
- Permanently deleted posts/pages
- Plugins updated
- Themes updated
- Plugin updates available
- Theme updates available

## Settings Features

- Enable or disable each notification event individually
- Enable or disable update available notifications for plugins/themes
- Test button in settings to send a test message and confirm webhook delivery
- Global send interval setting in milliseconds to control minimum delay between webhook deliveries
- Webhook URL validation on save with immediate admin error notice for invalid Discord webhook URLs

## Delivery and Reliability Features

- Persistent internal queue for outgoing notifications
- Queue size cap (500 messages max) to prevent unbounded growth under heavy event bursts
- Automatic retry with exponential backoff for temporary webhook failures (up to 20 attempts)
- Discord rate-limit aware retry timing when HTTP 429 is returned
- FIFO queue processing with microsecond precision for sub-millisecond timing
- Dead-letter queue for repeatedly failing messages with bounded size (500 messages max)
- Async WordPress cron kicks to ensure continuous queue processing

## Security Hardening

- Discord mention-safe delivery (`allowed_mentions` disabled) to prevent unwanted mass pings from user-controlled content
- Trusted-proxy-aware client IP handling to reduce spoofed IP values in login notifications

## Additional Notes

- Notifications are delivered asynchronously using WordPress cron scheduling
- Admin UI strings are translation-ready (text domain: discord-webhook)

## Future TODO

- Queue status panel in settings: Show pending count, oldest queued item age, next send time, and last error for better visibility when Discord rate-limits.
- Message templates with placeholders: Add admin-editable templates using placeholders like {site}, {event}, {user}, {post_title}, {post_url}, {post_id}.
- Uninstall cleanup: Add uninstall logic to remove plugin options, transients, and queue data cleanly when the plugin is removed.
- WP-CLI commands: Add commands like queue status, queue flush, queue retry-failed, and send test for easier operations.
- Wordfence support: Integrate with Wordfence to include captcha score details in login notifications and add notifications for 2FA-related login events.
