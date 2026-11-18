<?php
/**
 * WordPress administration interface.
 *
 * @package SkyrocketCookieConsent
 */

defined('ABSPATH') || exit;

/** Provides the complete settings interface for cookie consent. */
final class Skyrocket_Cookie_Consent_Admin
{
    private Skyrocket_Cookie_Consent_Config $config;

    private const STRING_FIELDS = [
        'popup_title' => 'Popup title',
        'popup_description' => 'Popup description',
        'cookie_detail' => 'Cookie detail heading',
        'cookie' => 'Cookie column heading',
        'duration' => 'Duration column heading',
        'about' => 'About column heading',
        'btn_save' => 'Save button',
        'btn_accept' => 'Accept all button',
        'btn_accept_no_choice' => 'Accept button (no choice)',
    ];

    public function __construct(Skyrocket_Cookie_Consent_Config $config)
    {
        $this->config = $config;
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_skyrocket_cookie_consent_save', [$this, 'handle_save']);
    }

    public function add_menu(): void
    {
        add_options_page(
            __('Skyrocket Cookie Consent', 'skyrocket-cookie-consent'),
            __('Skyrocket Cookie Consent', 'skyrocket-cookie-consent'),
            'manage_options',
            'skyrocket-cookie-consent',
            [$this, 'render_page']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'settings_page_skyrocket-cookie-consent') {
            return;
        }
        $css = SKYROCKET_COOKIE_CONSENT_DIR . 'assets/css/admin.css';
        $js = SKYROCKET_COOKIE_CONSENT_DIR . 'assets/js/admin.js';
        if (file_exists($css)) {
            wp_enqueue_style('skyrocket-cookie-consent-admin', SKYROCKET_COOKIE_CONSENT_URL . 'assets/css/admin.css', [], filemtime($css));
        }
        if (file_exists($js)) {
            wp_enqueue_script('skyrocket-cookie-consent-admin', SKYROCKET_COOKIE_CONSENT_URL . 'assets/js/admin.js', [], filemtime($js), true);
        }
    }

    /** Validate and process an admin form. */
    public function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage cookie consent settings.', 'skyrocket-cookie-consent'));
        }
        check_admin_referer('skyrocket_cookie_consent_save');

        $settings = $this->config->get_settings();
        $action = isset($_POST['consent_action']) ? sanitize_key(wp_unslash($_POST['consent_action'])) : '';
        $tab = 'general';
        $language = $settings['default_language'];

        switch ($action) {
            case 'set_default':
                $code = $this->posted_text('language_code');
                if (isset($settings['languages'][$code])) {
                    $settings['default_language'] = $code;
                }
                break;

            case 'save_language':
                $language = $this->posted_text('language_code');
                $this->save_language($settings, $language);
                $tab = 'languages';
                break;

            case 'add_language':
                $language = $this->add_language($settings);
                $tab = 'languages';
                break;

            case 'delete_language':
                $language = $this->posted_text('language_code');
                $this->delete_language($settings, $language);
                $tab = 'languages';
                break;

            case 'save_categories':
                $this->save_categories($settings);
                $tab = 'categories';
                break;

            case 'add_category':
                $this->add_category($settings);
                $tab = 'categories';
                break;

            case 'delete_category':
                $this->delete_category($settings);
                $tab = 'categories';
                break;

            case 'save_cookie':
                $this->save_cookie($settings);
                $tab = 'cookies';
                break;

            case 'add_cookie':
                $this->add_cookie($settings);
                $tab = 'cookies';
                break;

            case 'delete_cookie':
                $this->delete_cookie($settings);
                $tab = 'cookies';
                break;
        }

        $this->config->save_settings($settings);
        $args = ['page' => 'skyrocket-cookie-consent', 'tab' => $tab, 'updated' => '1'];
        if ($tab === 'languages' && $language !== '') {
            $args['lang'] = $language;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
        exit;
    }

    private function save_language(array &$settings, string $code): void
    {
        if (!isset($settings['languages'][$code])) {
            return;
        }
        $settings['languages'][$code]['label'] = $this->posted_text('language_label');
        foreach (self::STRING_FIELDS as $field => $label) {
            $settings['languages'][$code]['strings'][$field] = $this->posted_text($field, true);
        }
    }

    private function add_language(array &$settings): string
    {
        $code = strtolower(str_replace('_', '-', $this->posted_text('new_language_code')));
        $label = $this->posted_text('new_language_label');
        if (!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $code) || isset($settings['languages'][$code])) {
            return $settings['default_language'];
        }

        $default = $settings['default_language'];
        $settings['languages'][$code] = [
            'label' => $label !== '' ? $label : strtoupper($code),
            'strings' => $settings['languages'][$default]['strings'],
        ];
        foreach ($settings['categories'] as &$category) {
            $category['translations'][$code] = $category['translations'][$default] ?? ['title' => '', 'description' => ''];
            foreach ($category['cookies'] as &$cookie) {
                $cookie['translations'][$code] = $cookie['translations'][$default] ?? ['description' => ''];
            }
            unset($cookie);
        }
        unset($category);
        return $code;
    }

    private function delete_language(array &$settings, string $code): void
    {
        if ($code === $settings['default_language'] || !isset($settings['languages'][$code]) || count($settings['languages']) < 2) {
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
        $posted_required = isset($_POST['required']) && is_array($_POST['required']) ? wp_unslash($_POST['required']) : [];
        foreach ($settings['categories'] as $id => &$category) {
            $category['required'] = !empty($posted_required[$id]);
            foreach ($settings['languages'] as $code => $language) {
                $key = $this->field_key($id, $code);
                $category['translations'][$code] = [
                    'title' => $this->posted_text('cat_title_' . $key),
                    'description' => $this->posted_text('cat_description_' . $key, true),
                ];
            }
        }
        unset($category);
    }

    private function add_category(array &$settings): void
    {
        $id = sanitize_key($this->posted_text('new_category_id'));
        if ($id === '' || isset($settings['categories'][$id])) {
            return;
        }
        $settings['categories'][$id] = ['required' => false, 'translations' => [], 'cookies' => []];
        foreach ($settings['languages'] as $code => $language) {
            $settings['categories'][$id]['translations'][$code] = [
                'title' => $code === $settings['default_language'] ? $this->posted_text('new_category_title') : '',
                'description' => '',
            ];
        }
    }

    private function delete_category(array &$settings): void
    {
        $id = $this->posted_text('category_id');
        if ($id === 'strictly_necessary' || !isset($settings['categories'][$id])) {
            return;
        }
        // Do not discard a site's cookie inventory accidentally.
        if (!empty($settings['categories'][$id]['cookies'])) {
            return;
        }
        unset($settings['categories'][$id]);
    }

    private function add_cookie(array &$settings): void
    {
        $category_id = $this->posted_text('category_id');
        $name = $this->posted_text('new_cookie_name');
        if ($name === '' || !isset($settings['categories'][$category_id])) {
            return;
        }
        $settings['categories'][$category_id]['cookies'][] = [
            'id' => 'cookie_' . strtolower(wp_generate_password(12, false, false)),
            'name' => $name,
            'expiry' => $this->posted_text('new_cookie_expiry'),
            'translations' => array_fill_keys(array_keys($settings['languages']), ['description' => '']),
        ];
    }

    private function save_cookie(array &$settings): void
    {
        $category_id = $this->posted_text('category_id');
        $cookie_id = $this->posted_text('cookie_id');
        if (!isset($settings['categories'][$category_id])) {
            return;
        }
        foreach ($settings['categories'][$category_id]['cookies'] as &$cookie) {
            if (($cookie['id'] ?? '') !== $cookie_id) {
                continue;
            }
            $cookie['name'] = $this->posted_text('cookie_name');
            $cookie['expiry'] = $this->posted_text('cookie_expiry');
            foreach ($settings['languages'] as $code => $language) {
                $cookie['translations'][$code] = ['description' => $this->posted_text('cookie_description_' . $code, true)];
            }
            break;
        }
        unset($cookie);
    }

    private function delete_cookie(array &$settings): void
    {
        $category_id = $this->posted_text('category_id');
        $cookie_id = $this->posted_text('cookie_id');
        if (!isset($settings['categories'][$category_id])) {
            return;
        }
        $settings['categories'][$category_id]['cookies'] = array_values(array_filter(
            $settings['categories'][$category_id]['cookies'],
            static function ($cookie) use ($cookie_id) {
                return ($cookie['id'] ?? '') !== $cookie_id;
            }
        ));
    }

    private function posted_text(string $key, bool $textarea = false): string
    {
        if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) {
            return '';
        }
        $value = wp_unslash((string) $_POST[$key]);
        return $textarea ? sanitize_textarea_field($value) : sanitize_text_field($value);
    }

    private function field_key(string $first, string $second = ''): string
    {
        return substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', $first . '_' . $second), 0, 90);
    }

    private function form_start(string $action, array $extra = []): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="scc-admin-form">
            <?php wp_nonce_field('skyrocket_cookie_consent_save'); ?>
            <input type="hidden" name="action" value="skyrocket_cookie_consent_save">
            <input type="hidden" name="consent_action" value="<?php echo esc_attr($action); ?>">
            <?php foreach ($extra as $name => $value) : ?>
                <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
            <?php endforeach; ?>
        <?php
    }

    private function form_end(string $label, string $style = 'primary'): void
    {
        submit_button($label, $style, 'submit', false);
        echo '</form>';
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = $this->config->get_settings();
        $languages = $settings['languages'];
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
        $tabs = ['general' => __('General', 'skyrocket-cookie-consent'), 'languages' => __('Languages', 'skyrocket-cookie-consent'), 'categories' => __('Categories', 'skyrocket-cookie-consent'), 'cookies' => __('Cookie inventory', 'skyrocket-cookie-consent')];
        if (!isset($tabs[$tab])) {
            $tab = 'general';
        }
        $selected = isset($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : $settings['default_language'];
        if (!isset($languages[$selected])) {
            $selected = $settings['default_language'];
        }
        ?>
        <div class="wrap scc-admin">
            <h1><?php esc_html_e('Skyrocket Cookie Consent', 'skyrocket-cookie-consent'); ?></h1>
            <?php if (isset($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'skyrocket-cookie-consent'); ?></p></div>
            <?php endif; ?>
            <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Cookie consent settings', 'skyrocket-cookie-consent'); ?>">
                <?php foreach ($tabs as $key => $label) : ?>
                    <a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page' => 'skyrocket-cookie-consent', 'tab' => $key], admin_url('options-general.php'))); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($tab === 'general') : ?>
                <section class="scc-panel">
                    <h2><?php esc_html_e('General settings', 'skyrocket-cookie-consent'); ?></h2>
                    <p><?php esc_html_e('Choose the default language used when no suitable browser or WordPress language is available.', 'skyrocket-cookie-consent'); ?></p>
                    <?php $this->form_start('set_default'); ?>
                        <table class="form-table" role="presentation"><tr>
                            <th scope="row"><label for="scc-default-language"><?php esc_html_e('Default language', 'skyrocket-cookie-consent'); ?></label></th>
                            <td><select id="scc-default-language" name="language_code">
                                <?php foreach ($languages as $code => $language) : ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected($settings['default_language'], $code); ?>><?php echo esc_html($language['label'] . ' (' . $code . ')'); ?></option>
                                <?php endforeach; ?>
                            </select></td>
                        </tr></table>
                    <?php $this->form_end(__('Save general settings', 'skyrocket-cookie-consent')); ?>
                    <hr>
                    <h2><?php esc_html_e('Configuration storage', 'skyrocket-cookie-consent'); ?></h2>
                    <p><?php esc_html_e('All settings are managed here and stored in one WordPress option. Cookie definitions are site-specific and are never loaded from a bundled JSON file.', 'skyrocket-cookie-consent'); ?></p>
                    <p><code>skyrocket_cookie_consent_settings</code></p>
                    <p class="description"><?php esc_html_e('Frontend configuration endpoint:', 'skyrocket-cookie-consent'); ?> <code><?php echo esc_html(rest_url('skyrocket-cookie-consent/v1/config')); ?></code></p>
                </section>
            <?php elseif ($tab === 'languages') : ?>
                <section class="scc-panel">
                    <h2><?php esc_html_e('Languages and popup text', 'skyrocket-cookie-consent'); ?></h2>
                    <p><?php esc_html_e('Manage the translated interface labels and messages used by the consent popup.', 'skyrocket-cookie-consent'); ?></p>
                    <form method="get" action="<?php echo esc_url(admin_url('options-general.php')); ?>" class="scc-language-selector">
                        <input type="hidden" name="page" value="skyrocket-cookie-consent"><input type="hidden" name="tab" value="languages">
                        <label for="scc-language"><strong><?php esc_html_e('Edit language', 'skyrocket-cookie-consent'); ?></strong></label>
                        <select id="scc-language" name="lang" onchange="this.form.submit()">
                            <?php foreach ($languages as $code => $language) : ?><option value="<?php echo esc_attr($code); ?>" <?php selected($selected, $code); ?>><?php echo esc_html($language['label'] . ' (' . $code . ')'); ?></option><?php endforeach; ?>
                        </select>
                    </form>
                    <?php $language = $languages[$selected]; ?>
                    <?php $this->form_start('save_language', ['language_code' => $selected]); ?>
                        <table class="form-table" role="presentation">
                            <tr><th scope="row"><label for="scc-language-label"><?php esc_html_e('Language name', 'skyrocket-cookie-consent'); ?></label></th><td><input id="scc-language-label" class="regular-text" name="language_label" value="<?php echo esc_attr($language['label']); ?>" required></td></tr>
                            <?php foreach (self::STRING_FIELDS as $field => $label) : ?>
                                <tr><th scope="row"><label for="scc-<?php echo esc_attr($field); ?>"><?php echo esc_html($label); ?></label></th><td>
                                    <?php if (in_array($field, ['popup_description'], true)) : ?>
                                        <textarea class="large-text" rows="3" id="scc-<?php echo esc_attr($field); ?>" name="<?php echo esc_attr($field); ?>"><?php echo esc_textarea($language['strings'][$field] ?? ''); ?></textarea>
                                    <?php else : ?>
                                        <input class="regular-text" id="scc-<?php echo esc_attr($field); ?>" name="<?php echo esc_attr($field); ?>" value="<?php echo esc_attr($language['strings'][$field] ?? ''); ?>">
                                    <?php endif; ?>
                                </td></tr>
                            <?php endforeach; ?>
                        </table>
                    <?php $this->form_end(__('Save language', 'skyrocket-cookie-consent')); ?>

                    <hr><h3><?php esc_html_e('Add language', 'skyrocket-cookie-consent'); ?></h3>
                    <p class="description"><?php esc_html_e('A new language starts with copies of the default language text and translations. Edit them above after adding it.', 'skyrocket-cookie-consent'); ?></p>
                    <?php $this->form_start('add_language'); ?>
                        <label><?php esc_html_e('Language code', 'skyrocket-cookie-consent'); ?> <input name="new_language_code" placeholder="fr or en-gb" required></label>
                        <label><?php esc_html_e('Language name', 'skyrocket-cookie-consent'); ?> <input name="new_language_label" placeholder="French" required></label>
                        <?php $this->form_end(__('Add language', 'skyrocket-cookie-consent'), 'secondary'); ?>

                    <?php if (count($languages) > 1) : ?>
                        <hr><h3><?php esc_html_e('Delete language', 'skyrocket-cookie-consent'); ?></h3>
                        <?php $this->form_start('delete_language'); ?>
                            <select name="language_code">
                                <?php foreach ($languages as $code => $language) : if ($code === $settings['default_language']) { continue; } ?>
                                    <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($language['label'] . ' (' . $code . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php $this->form_end(__('Delete language', 'skyrocket-cookie-consent'), 'delete'); ?>
                        <p class="description"><?php esc_html_e('The default language cannot be deleted. Set another language as default first.', 'skyrocket-cookie-consent'); ?></p>
                    <?php endif; ?>
                </section>
            <?php elseif ($tab === 'categories') : ?>
                <section class="scc-panel">
                    <h2><?php esc_html_e('Cookie categories', 'skyrocket-cookie-consent'); ?></h2>
                    <p><?php esc_html_e('Category IDs are permanent identifiers used to associate cookie inventory and saved consent. Changing a displayed title does not change the category ID.', 'skyrocket-cookie-consent'); ?></p>
                    <?php $this->form_start('save_categories'); ?>
                        <?php foreach ($settings['categories'] as $id => $category) : $key = $this->field_key($id); ?>
                            <div class="scc-card">
                                <div class="scc-card-heading"><h3><?php echo esc_html($id); ?></h3><span class="scc-id"><?php echo esc_html($id); ?></span></div>
                                <label class="scc-required"><input type="checkbox" name="required[<?php echo esc_attr($id); ?>]" value="1" <?php checked(!empty($category['required'])); ?>> <?php esc_html_e('Required category (always enabled)', 'skyrocket-cookie-consent'); ?></label>
                                <?php foreach ($languages as $code => $language) : $translation = $category['translations'][$code] ?? []; $field = $this->field_key($id, $code); ?>
                                    <div class="scc-translation">
                                        <h4><?php echo esc_html($language['label'] . ' (' . $code . ')'); ?></h4>
                                        <p><label><?php esc_html_e('Title', 'skyrocket-cookie-consent'); ?><input class="regular-text" name="cat_title_<?php echo esc_attr($field); ?>" value="<?php echo esc_attr($translation['title'] ?? ''); ?>"></label></p>
                                        <p><label><?php esc_html_e('Description', 'skyrocket-cookie-consent'); ?><textarea class="large-text" rows="2" name="cat_description_<?php echo esc_attr($field); ?>"><?php echo esc_textarea($translation['description'] ?? ''); ?></textarea></label></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php $this->form_end(__('Save categories', 'skyrocket-cookie-consent')); ?>
                    <?php foreach ($settings['categories'] as $id => $category) : if ($id === 'strictly_necessary') { continue; } ?>
                        <?php $this->form_start('delete_category', ['category_id' => $id]); ?>
                            <button class="button-link-delete scc-delete-category" type="submit" data-confirm="<?php echo esc_attr__('Delete this category? Categories containing cookies cannot be deleted.', 'skyrocket-cookie-consent'); ?>"><?php echo esc_html(sprintf(__('Delete category: %s', 'skyrocket-cookie-consent'), $id)); ?></button>
                        </form>
                    <?php endforeach; ?>
                    <hr><h3><?php esc_html_e('Add category', 'skyrocket-cookie-consent'); ?></h3>
                    <?php $this->form_start('add_category'); ?>
                        <label><?php esc_html_e('Category ID', 'skyrocket-cookie-consent'); ?> <input name="new_category_id" placeholder="preferences" required></label>
                        <label><?php esc_html_e('Default-language title', 'skyrocket-cookie-consent'); ?> <input name="new_category_title" placeholder="Preferences" required></label>
                        <?php $this->form_end(__('Add category', 'skyrocket-cookie-consent'), 'secondary'); ?>
                </section>
            <?php elseif ($tab === 'cookies') : ?>
                <section class="scc-panel">
                    <h2><?php esc_html_e('Site-specific cookie inventory', 'skyrocket-cookie-consent'); ?></h2>
                    <p><?php esc_html_e('Add the cookies actually used by this website. Cookie names, expiry values and descriptions are managed here; none are preloaded from a generic template.', 'skyrocket-cookie-consent'); ?></p>
                    <?php foreach ($settings['categories'] as $category_id => $category) : ?>
                        <div class="scc-card">
                            <h3><?php echo esc_html($category['translations'][$settings['default_language']]['title'] ?? $category_id); ?> <code><?php echo esc_html($category_id); ?></code></h3>
                            <?php if (empty($category['cookies'])) : ?><p class="description"><?php esc_html_e('No cookies have been added to this category.', 'skyrocket-cookie-consent'); ?></p><?php endif; ?>
                            <?php foreach ($category['cookies'] as $cookie) : ?>
                                <?php $this->form_start('save_cookie', ['category_id' => $category_id, 'cookie_id' => $cookie['id']]); ?>
                                    <div class="scc-cookie-editor">
                                        <div class="scc-cookie-fields">
                                            <label><?php esc_html_e('Cookie name', 'skyrocket-cookie-consent'); ?><input class="regular-text" name="cookie_name" value="<?php echo esc_attr($cookie['name']); ?>" required></label>
                                            <label><?php esc_html_e('Expiry / duration', 'skyrocket-cookie-consent'); ?><input class="regular-text" name="cookie_expiry" value="<?php echo esc_attr($cookie['expiry']); ?>" placeholder="Session, 1 year, etc."></label>
                                        </div>
                                        <?php foreach ($languages as $code => $language) : $translation = $cookie['translations'][$code] ?? []; ?>
                                            <label class="scc-cookie-description"><?php echo esc_html(sprintf(__('Description (%s)', 'skyrocket-cookie-consent'), $language['label'])); ?><textarea class="large-text" rows="2" name="cookie_description_<?php echo esc_attr($code); ?>"><?php echo esc_textarea($translation['description'] ?? ''); ?></textarea></label>
                                        <?php endforeach; ?>
                                        <div class="scc-cookie-actions"><?php submit_button(__('Save cookie', 'skyrocket-cookie-consent'), 'secondary', 'submit', false); ?></div>
                                    </div>
                                </form>
                                <?php $this->form_start('delete_cookie', ['category_id' => $category_id, 'cookie_id' => $cookie['id']]); ?>
                                    <p><button class="button-link-delete" type="submit" data-confirm="<?php echo esc_attr__('Delete this cookie definition?', 'skyrocket-cookie-consent'); ?>"><?php esc_html_e('Delete cookie', 'skyrocket-cookie-consent'); ?></button></p>
                                </form>
                            <?php endforeach; ?>
                            <hr><h4><?php esc_html_e('Add cookie to this category', 'skyrocket-cookie-consent'); ?></h4>
                            <?php $this->form_start('add_cookie', ['category_id' => $category_id]); ?>
                                <label><?php esc_html_e('Cookie name', 'skyrocket-cookie-consent'); ?> <input name="new_cookie_name" placeholder="_example" required></label>
                                <label><?php esc_html_e('Expiry / duration', 'skyrocket-cookie-consent'); ?> <input name="new_cookie_expiry" placeholder="1 year"></label>
                                <?php $this->form_end(__('Add cookie', 'skyrocket-cookie-consent'), 'secondary'); ?>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        </div>
        <?php
    }
}
