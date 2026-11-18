<?php
/**
 * WordPress administration interface.
 *
 * @package SkyrocketCookieConsent
 */

defined('ABSPATH') || exit;

/**
 * Provides management for languages, shared categories and cookies.
 */
final class Skyrocket_Cookie_Consent_Admin
{
    private Skyrocket_Cookie_Consent_Config $config;

    private const STRING_FIELDS = [
        'popup_title'          => 'Popup title',
        'popup_description'    => 'Popup description',
        'cookie_detail'        => 'Cookie detail heading',
        'cookie'               => 'Cookie column heading',
        'duration'             => 'Duration column heading',
        'about'                => 'About column heading',
        'btn_save'             => 'Save button',
        'btn_accept'           => 'Accept all button',
        'btn_accept_no_choice' => 'Accept button (no choice)',
    ];

    public function __construct(Skyrocket_Cookie_Consent_Config $config)
    {
        $this->config = $config;

        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_skyrocket_cookie_consent_save', [$this, 'handle_save']);
    }

    public function add_menu(): void
    {
        add_menu_page(
            __('Cookie Consent', 'skyrocket-cookie-consent'),
            __('Cookie Consent', 'skyrocket-cookie-consent'),
            'manage_options',
            'skyrocket-cookie-consent',
            [$this, 'render_page'],
            'dashicons-shield',
            81
        );
    }

    /**
     * Process a validated administration form submission.
     */
    public function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage cookie consent settings.', 'skyrocket-cookie-consent'));
        }

        check_admin_referer('skyrocket_cookie_consent_save');

        $settings = $this->config->get_settings();
        $action = isset($_POST['consent_action'])
            ? sanitize_key(wp_unslash($_POST['consent_action']))
            : '';

        switch ($action) {
            case 'save_language':
                $this->save_language($settings);
                break;

            case 'add_language':
                $this->add_language($settings);
                break;

            case 'delete_language':
                $this->delete_language($settings);
                break;

            case 'save_categories':
                $this->save_categories($settings);
                break;

            case 'add_category':
                $this->add_category($settings);
                break;

            case 'delete_category':
                $this->delete_category($settings);
                break;

            case 'add_cookie':
                $this->add_cookie($settings);
                break;

            case 'delete_cookie':
                $this->delete_cookie($settings);
                break;

            case 'set_default':
                $code = $this->posted_text('language_code');
                if (isset($settings['languages'][$code])) {
                    $settings['default_language'] = $code;
                }
                break;
        }

        $this->config->save_settings($settings);

        $redirect = add_query_arg(
            [
                'page'    => 'skyrocket-cookie-consent',
                'updated' => '1',
                'lang'    => isset($_POST['language_code'])
                    ? sanitize_key(wp_unslash($_POST['language_code']))
                    : ($settings['default_language'] ?? 'en'),
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    private function save_language(array &$settings): void
    {
        $code = $this->posted_text('language_code');

        if (!isset($settings['languages'][$code])) {
            return;
        }

        $settings['languages'][$code]['label'] = $this->posted_text('language_label');

        foreach (self::STRING_FIELDS as $field => $label) {
            $settings['languages'][$code]['strings'][$field] = $this->posted_text($field, true);
        }

    }

    private function add_language(array &$settings): void
    {
        $code = strtolower(str_replace('_', '-', $this->posted_text('new_language_code')));
        $label = $this->posted_text('new_language_label');

        if (!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $code) || isset($settings['languages'][$code])) {
            return;
        }

        $settings['languages'][$code] = [
            'label'   => $label ?: strtoupper($code),
            'strings' => $settings['languages'][$settings['default_language']]['strings'],
        ];

        foreach ($settings['categories'] as &$category) {
            $category['translations'][$code] = $category['translations'][$settings['default_language']] ?? [
                'title' => '',
                'description' => '',
            ];

            foreach ($category['cookies'] as &$cookie) {
                $cookie['translations'][$code] = $cookie['translations'][$settings['default_language']] ?? ['description' => ''];
            }
            unset($cookie);
        }
        unset($category);
    }

    private function delete_language(array &$settings): void
    {
        $code = $this->posted_text('language_code');

        if ($code === $settings['default_language'] || !isset($settings['languages'][$code])) {
            return;
        }

        unset($settings['languages'][$code]);

        foreach ($settings['categories'] as &$category) {
            unset($category['translations'][$code]);

            foreach ($category['cookies'] as &$cookie) {
                unset($cookie['translations'][$code]);
            }
            unset($cookie);
        }
        unset($category);
    }

    private function save_categories(array &$settings): void
    {
        foreach ($settings['categories'] as $category_id => &$category) {
            $key = $this->safe_key($category_id);
            $category['required'] = !empty($_POST['required'][$category_id]);

            foreach (array_keys($settings['languages']) as $language_code) {
                $category['translations'][$language_code] = [
                    'title' => $this->posted_text('cat_title_' . $key . '_' . $language_code),
                    'description' => $this->posted_text('cat_description_' . $key . '_' . $language_code, true),
                ];
            }

            foreach ($category['cookies'] as $index => &$cookie) {
                $cookie['name'] = $this->posted_text('cookie_name_' . $key . '_' . $index);
                $cookie['expiry'] = $this->posted_text('cookie_expiry_' . $key . '_' . $index);

                foreach (array_keys($settings['languages']) as $language_code) {
                    $cookie['translations'][$language_code] = [
                        'description' => $this->posted_text('cookie_desc_' . $key . '_' . $index . '_' . $language_code, true),
                    ];
                }
            }
            unset($cookie);
        }
        unset($category);
    }

    private function add_category(array &$settings): void
    {
        $id = sanitize_key($this->posted_text('new_category_id'));

        if ($id === '' || isset($settings['categories'][$id])) {
            return;
        }

        $settings['categories'][$id] = [
            'required' => false,
            'translations' => [],
            'cookies' => [],
        ];

        foreach (array_keys($settings['languages']) as $language_code) {
            $settings['categories'][$id]['translations'][$language_code] = [
                'title' => $language_code === $settings['default_language'] ? $this->posted_text('new_category_title') : '',
                'description' => '',
            ];
        }
    }

    private function delete_category(array &$settings): void
    {
        $id = $this->posted_text('category_id');

        if ($id !== 'strictly_necessary') {
            unset($settings['categories'][$id]);
        }
    }

    private function add_cookie(array &$settings): void
    {
        $category_id = $this->posted_text('category_id');

        if (!isset($settings['categories'][$category_id])) {
            return;
        }

        $cookie_id = 'cookie_' . wp_generate_password(8, false, false);
        $settings['categories'][$category_id]['cookies'][] = [
            'id' => $cookie_id,
            'name' => $this->posted_text('new_cookie_name'),
            'expiry' => $this->posted_text('new_cookie_expiry'),
            'translations' => array_fill_keys(
                array_keys($settings['languages']),
                ['description' => '']
            ),
        ];
    }

    private function delete_cookie(array &$settings): void
    {
        $category_id = $this->posted_text('category_id');
        $index = isset($_POST['cookie_index']) ? absint($_POST['cookie_index']) : -1;

        if (isset($settings['categories'][$category_id]['cookies'][$index])) {
            array_splice($settings['categories'][$category_id]['cookies'], $index, 1);
        }
    }

    private function posted_text(string $key, bool $textarea = false): string
    {
        if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) {
            return '';
        }

        $value = wp_unslash((string) $_POST[$key]);

        return $textarea ? sanitize_textarea_field($value) : sanitize_text_field($value);
    }

    private function safe_key(string $value): string
    {
        return substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', $value), 0, 60);
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->config->get_settings();
        $languages = $settings['languages'];
        $selected = isset($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : $settings['default_language'];

        if (!isset($languages[$selected])) {
            $selected = $settings['default_language'];
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Skyrocket Cookie Consent', 'skyrocket-cookie-consent'); ?></h1>
            <?php if (isset($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'skyrocket-cookie-consent'); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e('Languages and popup text', 'skyrocket-cookie-consent'); ?></h2>
            <p><?php esc_html_e('Manage translated interface text. Categories and cookies are shared across languages; their labels and descriptions are translated separately below.', 'skyrocket-cookie-consent'); ?></p>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="skyrocket-cookie-consent">
                <label for="consent-language"><strong><?php esc_html_e('Edit language', 'skyrocket-cookie-consent'); ?></strong></label>
                <select id="consent-language" name="lang">
                    <?php foreach ($languages as $code => $language) : ?>
                        <option value="<?php echo esc_attr($code); ?>" <?php selected($selected, $code); ?>>
                            <?php echo esc_html($language['label'] . ' (' . $code . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button(__('Load language', 'skyrocket-cookie-consent'), 'secondary', '', false); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('skyrocket_cookie_consent_save'); ?>
                <input type="hidden" name="action" value="skyrocket_cookie_consent_save">
                <input type="hidden" name="consent_action" value="save_language">
                <input type="hidden" name="language_code" value="<?php echo esc_attr($selected); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="language-label"><?php esc_html_e('Language name', 'skyrocket-cookie-consent'); ?></label></th>
                        <td><input class="regular-text" id="language-label" name="language_label" value="<?php echo esc_attr($languages[$selected]['label']); ?>"></td>
                    </tr>
                    <?php foreach (self::STRING_FIELDS as $field => $label) : ?>
                        <tr>
                            <th><label for="<?php echo esc_attr($field); ?>"><?php echo esc_html($label); ?></label></th>
                            <td>
                                <?php if ($field === 'popup_description') : ?>
                                    <textarea class="large-text" rows="3" id="<?php echo esc_attr($field); ?>" name="<?php echo esc_attr($field); ?>"><?php echo esc_textarea($languages[$selected]['strings'][$field] ?? ''); ?></textarea>
                                <?php else : ?>
                                    <input class="regular-text" id="<?php echo esc_attr($field); ?>" name="<?php echo esc_attr($field); ?>" value="<?php echo esc_attr($languages[$selected]['strings'][$field] ?? ''); ?>">
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button(__('Save language text', 'skyrocket-cookie-consent')); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Add language', 'skyrocket-cookie-consent'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('skyrocket_cookie_consent_save'); ?>
                <input type="hidden" name="action" value="skyrocket_cookie_consent_save">
                <input type="hidden" name="consent_action" value="add_language">
                <input name="new_language_code" placeholder="fr" required>
                <input name="new_language_label" placeholder="French" required>
                <?php submit_button(__('Add language', 'skyrocket-cookie-consent'), 'secondary', 'submit', false); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px">
                <?php wp_nonce_field('skyrocket_cookie_consent_save'); ?>
                <input type="hidden" name="action" value="skyrocket_cookie_consent_save">
                <input type="hidden" name="consent_action" value="set_default">
                <label><?php esc_html_e('Default language', 'skyrocket-cookie-consent'); ?>
                    <select name="language_code">
                        <?php foreach ($languages as $code => $language) : ?>
                            <option value="<?php echo esc_attr($code); ?>" <?php selected($settings['default_language'], $code); ?>><?php echo esc_html($language['label'] . ' (' . $code . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php submit_button(__('Set default', 'skyrocket-cookie-consent'), 'secondary', 'submit', false); ?>
            </form>

            <?php if (count($languages) > 1) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px">
                    <?php wp_nonce_field('skyrocket_cookie_consent_save'); ?>
                    <input type="hidden" name="action" value="skyrocket_cookie_consent_save">
                    <input type="hidden" name="consent_action" value="delete_language">
                    <label><?php esc_html_e('Delete language', 'skyrocket-cookie-consent'); ?>
                        <select name="language_code">
                            <?php foreach ($languages as $code => $language) : if ($code === $settings['default_language']) continue; ?>
                                <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($language['label'] . ' (' . $code . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php submit_button(__('Delete language', 'skyrocket-cookie-consent'), 'delete', 'submit', false); ?>
                </form>
            <?php endif; ?>

            <hr>
            <h2><?php esc_html_e('Shared categories and cookies', 'skyrocket-cookie-consent'); ?></h2>
            <p><?php esc_html_e('Category identifiers, required status, cookie names and expiry values are shared. Titles and descriptions are translated for each language.', 'skyrocket-cookie-consent'); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('skyrocket_cookie_consent_save'); ?>
                <input type="hidden" name="action" value="skyrocket_cookie_consent_save">
                <input type="hidden" name="consent_action" value="save_categories">
                <input type="hidden" name="language_code" value="<?php echo esc_attr($selected); ?>">

                <?php foreach ($settings['categories'] as $category_id => $category) : $key = $this->safe_key($category_id); ?>
                    <div class="card" style="max-width:100%;padding:16px;margin:16px 0">
                        <h3><?php echo esc_html($category_id); ?></h3>
                        <?php if ($category_id !== 'strictly_necessary') : ?>
                            <button class="button button-link-delete" type="submit" form="delete-category-<?php echo esc_attr($category_id); ?>"><?php esc_html_e('Delete category', 'skyrocket-cookie-consent'); ?></button>
                        <?php endif; ?>
                        <label>
                            <input type="checkbox" name="required[<?php echo esc_attr($category_id); ?>]" value="1" <?php checked(!empty($category['required'])); ?>>
                            <?php esc_html_e('Required category (always enabled)', 'skyrocket-cookie-consent'); ?>
                        </label>

                        <?php foreach ($languages as $code => $language) :
                            $translation = $category['translations'][$code] ?? [];
                        ?>
                            <h4><?php echo esc_html($language['label'] . ' (' . $code . ')'); ?></h4>
                            <p><label><?php esc_html_e('Title', 'skyrocket-cookie-consent'); ?><br>
                                <input class="regular-text" name="cat_title_<?php echo esc_attr($key . '_' . $code); ?>" value="<?php echo esc_attr($translation['title'] ?? ''); ?>"></label></p>
                            <p><label><?php esc_html_e('Description', 'skyrocket-cookie-consent'); ?><br>
                                <textarea class="large-text" rows="2" name="cat_description_<?php echo esc_attr($key . '_' . $code); ?>"><?php echo esc_textarea($translation['description'] ?? ''); ?></textarea></label></p>
                        <?php endforeach; ?>

                        <h4><?php esc_html_e('Cookies', 'skyrocket-cookie-consent'); ?></h4>
                        <?php foreach (($category['cookies'] ?? []) as $index => $cookie) : ?>
                            <div style="border-top:1px solid #ddd;padding:12px 0">
                                <p>
                                    <label><?php esc_html_e('Cookie name', 'skyrocket-cookie-consent'); ?><br>
                                        <input class="regular-text" name="cookie_name_<?php echo esc_attr($key . '_' . $index); ?>" value="<?php echo esc_attr($cookie['name'] ?? ''); ?>"></label>
                                    <label><?php esc_html_e('Expiry / duration', 'skyrocket-cookie-consent'); ?><br>
                                        <input class="regular-text" name="cookie_expiry_<?php echo esc_attr($key . '_' . $index); ?>" value="<?php echo esc_attr($cookie['expiry'] ?? ''); ?>"></label>
                                </p>
                                <?php foreach ($languages as $code => $language) :
                                    $cookie_translation = $cookie['translations'][$code] ?? [];
                                ?>
                                    <p><label><?php echo esc_html(sprintf(__('Description (%s)', 'skyrocket-cookie-consent'), $language['label'])); ?><br>
                                        <textarea class="large-text" rows="2" name="cookie_desc_<?php echo esc_attr($key . '_' . $index . '_' . $code); ?>"><?php echo esc_textarea($cookie_translation['description'] ?? ''); ?></textarea></label></p>
                                <?php endforeach; ?>
                                <button class="button button-link-delete" type="submit" form="delete-cookie-<?php echo esc_attr($key . '-' . $index); ?>"><?php esc_html_e('Delete cookie', 'skyrocket-cookie-consent'); ?></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <?php submit_button(__('Save categories and cookies', 'skyrocket-cookie-consent')); ?>
            </form>

            <?php foreach ($settings['categories'] as $category_id => $category) : ?>
                <form id="delete-category-<?php echo esc_attr($category_id); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none">
                    <?php wp_nonce_field('skyrocket_cookie_consent_save'); ?>
                    <input type="hidden" name="action" value="skyrocket_cookie_consent_save">
                    <input type="hidden" name="consent_action" value="delete_category">
                    <input type="hidden" name="category_id" value="<?php echo esc_attr($category_id); ?>">
                </form>
                <?php foreach (($category['cookies'] ?? []) as $index => $cookie) : ?>
                    <form id="delete-cookie-<?php echo esc_attr($this->safe_key($category_id) . '-' . $index); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none">
                        <?php wp_nonce_field('skyrocket_cookie_consent_save'); ?>
                        <input type="hidden" name="action" value="skyrocket_cookie_consent_save">
                        <input type="hidden" name="consent_action" value="delete_cookie">
                        <input type="hidden" name="category_id" value="<?php echo esc_attr($category_id); ?>">
                        <input type="hidden" name="cookie_index" value="<?php echo esc_attr((string) $index); ?>">
                    </form>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <h3><?php esc_html_e('Add category', 'skyrocket-cookie-consent'); ?></h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('skyrocket_cookie_consent_save'); ?>
                <input type="hidden" name="action" value="skyrocket_cookie_consent_save">
                <input type="hidden" name="consent_action" value="add_category">
                <input name="new_category_id" placeholder="preferences" required>
                <input name="new_category_title" placeholder="Preferences">
                <?php submit_button(__('Add category', 'skyrocket-cookie-consent'), 'secondary', 'submit', false); ?>
            </form>

            <h3><?php esc_html_e('Add cookie', 'skyrocket-cookie-consent'); ?></h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('skyrocket_cookie_consent_save'); ?>
                <input type="hidden" name="action" value="skyrocket_cookie_consent_save">
                <input type="hidden" name="consent_action" value="add_cookie">
                <select name="category_id">
                    <?php foreach ($settings['categories'] as $category_id => $category) : ?>
                        <option value="<?php echo esc_attr($category_id); ?>"><?php echo esc_html($category_id); ?></option>
                    <?php endforeach; ?>
                </select>
                <input name="new_cookie_name" placeholder="cookie_name" required>
                <input name="new_cookie_expiry" placeholder="1 year">
                <?php submit_button(__('Add cookie', 'skyrocket-cookie-consent'), 'secondary', 'submit', false); ?>
            </form>

            <p class="description"><?php esc_html_e('The public configuration is available at /wp-json/skyrocket-cookie-consent/v1/config.', 'skyrocket-cookie-consent'); ?></p>
        </div>
        <?php
    }
}
