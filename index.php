
/*
Plugin Name: Advanced Timed Access Password Sender
Version: 2.0
Description: Email-based password access request with admin approval, configurable settings, email templates, expiry, and logs.
Author: YourName
*/

if (!defined('ABSPATH')) exit;

class AdvancedTimedAccessPasswordSender {

    private $option_key = 'atap_settings';
    private $requests_key = 'atap_requests';

    public function __construct() {
        add_action('init', [$this, 'maybe_rotate_password']);
        add_shortcode('atap_request_form', [$this, 'render_request_form']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'handle_admin_actions']);
    }

    // Load settings or default
    private function get_settings() {
        $defaults = [
            'page_id' => 26,
            'admin_email' => get_option('admin_email'),
            'password_length' => 8,
            'password_rotate_hours' => 72,
            'request_expire_hours' => 48,
            'email_subject_request' => 'New Access Request',
            'email_body_request' => 'User requested access: {email}. Approve here: {admin_link}',
            'email_subject_approved' => 'Your Access is Approved',
            'email_body_approved' => 'Your password is: {password}. Access link: {page_link}',
            'email_subject_rejected' => 'Your Access Request was Rejected',
            'email_body_rejected' => 'Sorry, your access request was rejected by admin.',
        ];
        return wp_parse_args(get_option($this->option_key, []), $defaults);
    }

    private function update_settings($data) {
        update_option($this->option_key, $data);
    }

    // Auto rotate password
    public function maybe_rotate_password() {
        $settings = $this->get_settings();
        $last = get_option('atap_last_password_change');
        $interval = intval($settings['password_rotate_hours']) * HOUR_IN_SECONDS;
        $page_id = intval($settings['page_id']);

        if (!$last || (time() - $last >= $interval)) {
            $post = get_post($page_id);
            if ($post && $post->post_status === 'publish') {
                $new_pass = wp_generate_password(intval($settings['password_length']), false);
                wp_update_post(['ID' => $page_id, 'post_password' => $new_pass]);
                update_option('atap_current_password', $new_pass);
                update_option('atap_last_password_change', time());
            }
        }

        // Clean expired requests
        $this->cleanup_expired_requests();
    }

    // Clean expired requests
    private function cleanup_expired_requests() {
        $settings = $this->get_settings();
        $expire = intval($settings['request_expire_hours']) * HOUR_IN_SECONDS;
        $requests = get_option($this->requests_key, []);

        $changed = false;
        foreach ($requests as $id => $req) {
            if ($req['status'] === 'pending' && (time() - $req['time']) > $expire) {
                $requests[$id]['status'] = 'expired';
                $changed = true;
            }
        }
        if ($changed) update_option($this->requests_key, $requests);
    }

    // Render frontend form
    public function render_request_form() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atap_email'])) {
            return $this->handle_request_submission($_POST['atap_email']);
        }

        ob_start();
        ?>
        <form method="post" style="max-width:320px;">
            <input type="email" name="atap_email" required placeholder="Enter your email" style="width:100% !important;padding:8px;margin-bottom:8px;">
            <button type="submit" style="width:100%;padding:10px;background:#adced7;color:#000;border:none;cursor:pointer;">Request Access</button>
        </form>
        <?php
        return ob_get_clean();
    }

    private function handle_request_submission($email_raw) {
        $email = sanitize_email($email_raw);
        if (!is_email($email)) {
            return '<div style="color:red;">Invalid email address.</div>';
        }

        $requests = get_option($this->requests_key, []);

        // Check duplicate pending request for same email
        foreach ($requests as $req) {
            if ($req['email'] === $email && $req['status'] === 'pending') {
                return '<div style="color:orange;">You already have a pending request. Please wait for approval.</div>';
            }
        }

        $id = uniqid('atap_', true);
        $requests[$id] = [
            'email' => $email,
            'time' => time(),
            'status' => 'pending',
        ];
        update_option($this->requests_key, $requests);

        // Send notification email to admin
        $settings = $this->get_settings();
        $admin_email = $settings['admin_email'];
        $admin_link = admin_url('admin.php?page=atap_requests');

        $subject = $settings['email_subject_request'];
        $body = str_replace(
            ['{email}', '{admin_link}'],
            [esc_html($email), '<a href="'.esc_url($admin_link).'" target="_blank">Access Requests</a>'],
            $settings['email_body_request']
        );

        wp_mail(
            $admin_email,
            $subject,
            $body,
            ['Content-Type: text/html; charset=UTF-8']
        );

        return '<div style="color:green;">Request submitted successfully. Please wait for admin approval.</div>';
    }

    // Admin menu
    public function admin_menu() {
        add_menu_page(
            'Access Requests',
            'Access Requests',
            'manage_options',
            'atap_requests',
            [$this, 'render_admin_requests_page'],
            'dashicons-email-alt2',
            76
        );

        add_submenu_page(
            'atap_requests',
            'Settings',
            'Settings',
            'manage_options',
            'atap_settings',
            [$this, 'render_admin_settings_page']
        );
    }

    // Handle admin POST for approve/reject and save settings
    public function handle_admin_actions() {
        if (!current_user_can('manage_options')) return;

        // Approve request
        if (isset($_POST['approve_request_id']) && check_admin_referer('atap_approve_action', 'atap_nonce')) {
            $this->approve_request(sanitize_text_field($_POST['approve_request_id']));
        }

        // Reject request
        if (isset($_POST['reject_request_id']) && check_admin_referer('atap_reject_action', 'atap_nonce')) {
            $this->reject_request(sanitize_text_field($_POST['reject_request_id']));
        }

        // Save settings
        if (isset($_POST['atap_save_settings']) && check_admin_referer('atap_save_settings_action', 'atap_settings_nonce')) {
            $settings = [
                'page_id' => intval($_POST['page_id']),
                'admin_email' => sanitize_email($_POST['admin_email']),
                'password_length' => max(4, intval($_POST['password_length'])),
                'password_rotate_hours' => max(1, intval($_POST['password_rotate_hours'])),
                'request_expire_hours' => max(1, intval($_POST['request_expire_hours'])),
                'email_subject_request' => sanitize_text_field($_POST['email_subject_request']),
                'email_body_request' => wp_kses_post($_POST['email_body_request']),
                'email_subject_approved' => sanitize_text_field($_POST['email_subject_approved']),
                'email_body_approved' => wp_kses_post($_POST['email_body_approved']),
                'email_subject_rejected' => sanitize_text_field($_POST['email_subject_rejected']),
                'email_body_rejected' => wp_kses_post($_POST['email_body_rejected']),
            ];
            $this->update_settings($settings);
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
            });
        }
    }

    // Approve a request
    private function approve_request($id) {
        $requests = get_option($this->requests_key, []);
        if (!isset($requests[$id]) || $requests[$id]['status'] !== 'pending') {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Invalid or already processed request.</p></div>';
            });
            return;
        }

        $settings = $this->get_settings();
        $page_id = intval($settings['page_id']);
        $password = get_option('atap_current_password');
        $page_link = get_permalink($page_id);

        $email = $requests[$id]['email'];

        // Send approval email
        $subject = $settings['email_subject_approved'];
        $body = str_replace(
            ['{password}', '{page_link}'],
            [esc_html($password), '<a href="'.esc_url($page_link).'" target="_blank">'.esc_html($page_link).'</a>'],
            $settings['email_body_approved']
        );

        wp_mail(
            $email,
            $subject,
            $body,
            ['Content-Type: text/html; charset=UTF-8']
        );

        // Mark approved
        $requests[$id]['status'] = 'approved';
        $requests[$id]['processed_time'] = time();
        update_option($this->requests_key, $requests);

        add_action('admin_notices', function() use ($email) {
            echo '<div class="notice notice-success is-dismissible"><p>Approved request for: ' . esc_html($email) . '</p></div>';
        });
    }

    // Reject a request
    private function reject_request($id) {
        $requests = get_option($this->requests_key, []);
        if (!isset($requests[$id]) || $requests[$id]['status'] !== 'pending') {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Invalid or already processed request.</p></div>';
            });
            return;
        }

        $settings = $this->get_settings();
        $email = $requests[$id]['email'];

        $subject = $settings['email_subject_rejected'];
        $body = $settings['email_body_rejected'];

        wp_mail(
            $email,
            $subject,
            $body,
            ['Content-Type: text/html; charset=UTF-8']
        );

        $requests[$id]['status'] = 'rejected';
        $requests[$id]['processed_time'] = time();
        update_option($this->requests_key, $requests);

        add_action('admin_notices', function() use ($email) {
            echo '<div class="notice notice-warning is-dismissible"><p>Rejected request for: ' . esc_html($email) . '</p></div>';
        });
    }

    // Render admin requests page
    public function render_admin_requests_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $requests = get_option($this->requests_key, []);
        ?>
        <div class="wrap">
            <h1>Access Requests</h1>
            <table class="widefat fixed" cellspacing="0" style="max-width:800px;">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Requested At</th>
                        <th>Status</th>
                        <th>Processed At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)) : ?>
                        <tr><td colspan="5">No requests found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($requests as $id => $r): ?>
                            <tr>
                                <td><?php echo esc_html($r['email']); ?></td>
                                <td><?php echo esc_html(date('Y-m-d H:i:s', $r['time'])); ?></td>
                                <td><?php echo esc_html(ucfirst($r['status'])); ?></td>
                                <td><?php echo isset($r['processed_time']) ? esc_html(date('Y-m-d H:i:s', $r['processed_time'])) : '-'; ?></td>
                                <td>
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <form method="post" style="display:inline-block;">
                                            <?php wp_nonce_field('atap_approve_action', 'atap_nonce'); ?>
                                            <button type="submit" name="approve_request_id" value="<?php echo esc_attr($id); ?>" class="button button-primary" onclick="return confirm('Approve this request?')">Approve</button>
                                        </form>
                                        <form method="post" style="display:inline-block;">
                                            <?php wp_nonce_field('atap_reject_action', 'atap_nonce'); ?>
                                            <button type="submit" name="reject_request_id" value="<?php echo esc_attr($id); ?>" class="button button-secondary" onclick="return confirm('Reject this request?')">Reject</button>
                                        </form>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // Render admin settings page
    public function render_admin_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        $s = $this->get_settings();
        ?>
        <div class="wrap">
            <h1>Timed Access Password Settings</h1>
            <form method="post">
                <?php wp_nonce_field('atap_save_settings_action', 'atap_settings_nonce'); ?>
                <table class="form-table" style="max-width:600px;">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="page_id">Protected Page ID</label></th>
                            <td><input name="page_id" type="number" id="page_id" value="<?php echo esc_attr($s['page_id']); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="admin_email">Admin Email</label></th>
                            <td><input name="admin_email" type="email" id="admin_email" value="<?php echo esc_attr($s['admin_email']); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="password_length">Password Length</label></th>
                            <td><input name="password_length" type="number" id="password_length" min="4" max="32" value="<?php echo esc_attr($s['password_length']); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="password_rotate_hours">Password Rotate Interval (hours)</label></th>
                            <td><input name="password_rotate_hours" type="number" id="password_rotate_hours" min="1" value="<?php echo esc_attr($s['password_rotate_hours']); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="request_expire_hours">Request Expiry Time (hours)</label></th>
                            <td><input name="request_expire_hours" type="number" id="request_expire_hours" min="1" value="<?php echo esc_attr($s['request_expire_hours']); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_subject_request">Email Subject - New Request</label></th>
                            <td><input name="email_subject_request" type="text" id="email_subject_request" value="<?php echo esc_attr($s['email_subject_request']); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_body_request">Email Body - New Request</label></th>
                            <td>
                                <textarea name="email_body_request" id="email_body_request" rows="4" style="width:100%;" required><?php echo esc_textarea($s['email_body_request']); ?></textarea>
                                <small>Use placeholders: {email}, {admin_link}</small>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_subject_approved">Email Subject - Approved</label></th>
                            <td><input name="email_subject_approved" type="text" id="email_subject_approved" value="<?php echo esc_attr($s['email_subject_approved']); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_body_approved">Email Body - Approved</label></th>
                            <td>
                                <textarea name="email_body_approved" id="email_body_approved" rows="4" style="width:100%;" required><?php echo esc_textarea($s['email_body_approved']); ?></textarea>
                                <small>Use placeholders: {password}, {page_link}</small>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_subject_rejected">Email Subject - Rejected</label></th>
                            <td><input name="email_subject_rejected" type="text" id="email_subject_rejected" value="<?php echo esc_attr($s['email_subject_rejected']); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_body_rejected">Email Body - Rejected</label></th>
                            <td>
                                <textarea name="email_body_rejected" id="email_body_rejected" rows="4" style="width:100%;" required><?php echo esc_textarea($s['email_body_rejected']); ?></textarea>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p>
                    <input type="submit" name="atap_save_settings" class="button button-primary" value="Save Settings">
                </p>
            </form>
        </div>
        <?php
    }
}

new AdvancedTimedAccessPasswordSender();
