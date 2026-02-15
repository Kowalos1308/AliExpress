<?php
/**
 * Plugin Name: AliExpress Woo Product Moderator
 * Description: Dodawanie i moderacja produktów WooCommerce na podstawie API AliExpress (Affiliate + DS) z opcjonalną redakcją AI (Groq).
 * Version: 0.1.0
 * Author: Codex
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('AEWPM_API_BASE')) {
    define('AEWPM_API_BASE', 'https://api-sg.aliexpress.com/sync');
}

class AEWPM_Plugin {
    const META_SOURCE = '_aewpm_source';
    const META_PRODUCT_ID = '_aewpm_product_id';
    const META_SKU_ID = '_aewpm_sku_id';
    const META_ORIGINAL_LINK = '_aewpm_original_link';
    const META_IMAGE_WHITE = '_aewpm_image_white';
    const META_STORE_NAME = '_aewpm_store_name';
    const META_PRODUCT_SCORE = '_aewpm_product_score';
    const META_ORDER_NUMBER = '_aewpm_order_number';
    const META_REVIEW_NUMBER = '_aewpm_review_number';
    const META_SHIPPING_FEES = '_aewpm_shipping_fees';
    const META_MIN_DAYS = '_aewpm_min_delivery_days';
    const META_MAX_DAYS = '_aewpm_max_delivery_days';
    const META_SHIP_FROM = '_aewpm_ship_from_country';
    const META_AE_CATEGORY = '_aewpm_ae_category';
    const META_AI_EDITED = '_aewpm_ai_edited';
    const META_ATTRS_RAW = '_aewpm_attrs_raw';
    const META_DESC_RAW = '_aewpm_desc_raw';

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_aewpm_add_product', [$this, 'handle_add_product']);
        add_action('admin_post_aewpm_refresh_product', [$this, 'handle_refresh_product']);
        add_action('admin_post_aewpm_save_product', [$this, 'handle_save_product']);
        add_action('admin_post_aewpm_ai_edit_product', [$this, 'handle_ai_edit_product']);
    }

    public function admin_menu() {
        add_menu_page('AliExpress', 'AliExpress', 'manage_woocommerce', 'aewpm_add', [$this, 'render_add_page'], 'dashicons-cart', 56);
        add_submenu_page('aewpm_add', 'Dodaj produkt z Ali', 'Dodaj produkt z Ali', 'manage_woocommerce', 'aewpm_add', [$this, 'render_add_page']);
        add_submenu_page('aewpm_add', 'Moderuj produkty', 'Moderuj produkty', 'manage_woocommerce', 'aewpm_moderate', [$this, 'render_moderate_page']);
        add_submenu_page(null, 'Edytuj produkt Ali', 'Edytuj produkt Ali', 'manage_woocommerce', 'aewpm_edit', [$this, 'render_edit_page']);
    }

    private function parse_product_line($line) {
        $productId = '';
        $skuId = '';
        if (preg_match('/Product\s*ID\s*:\s*(\d+)/i', $line, $m)) {
            $productId = sanitize_text_field($m[1]);
        }
        if (preg_match('/SKU\s*:\s*(\d+)/i', $line, $m)) {
            $skuId = sanitize_text_field($m[1]);
        }
        return [$productId, $skuId];
    }

    private function get_timestamp_ms() {
        return (string) round(microtime(true) * 1000);
    }

    private function build_sign($secret, $params) {
        ksort($params);
        $base = $secret;
        foreach ($params as $k => $v) {
            if ($k === 'sign' || $v === null || $v === '') {
                continue;
            }
            $base .= $k . $v;
        }
        $base .= $secret;
        return strtoupper(hash('sha256', $base));
    }

    private function call_aliexpress($params) {
        $url = AEWPM_API_BASE;
        $response = wp_remote_post($url, [
            'timeout' => 30,
            'body' => $params,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return new WP_Error('aewpm_invalid_json', 'Niepoprawna odpowiedź API: ' . $body);
        }

        return $json;
    }

    private function get_affiliate_data($productId, $skuId) {
        if (!defined('ALI_AFFILIATE_APP_KEY') || !defined('ALI_AFFILATE_APP_SECRET')) {
            return new WP_Error('aewpm_cfg', 'Brak stałych ALI_AFFILIATE_APP_KEY / ALI_AFFILATE_APP_SECRET.');
        }

        $params = [
            'app_key' => ALI_AFFILIATE_APP_KEY,
            'method' => 'aliexpress.affiliate.product.sku.detail.get',
            'sign_method' => 'sha256',
            'timestamp' => $this->get_timestamp_ms(),
            'product_id' => $productId,
            'sku_ids' => $skuId,
            'target_language' => 'PL',
            'target_currency' => 'PLN',
            'ship_to_country' => 'PL',
            'need_deliver_info' => 'Yes',
        ];
        $params['sign'] = $this->build_sign(ALI_AFFILATE_APP_SECRET, $params);

        return $this->call_aliexpress($params);
    }

    private function get_ds_data($productId) {
        if (!defined('ALI_APP_KEY') || !defined('ALI_APP_SECRET') || !defined('ALI_SESSION')) {
            return new WP_Error('aewpm_cfg', 'Brak stałych ALI_APP_KEY / ALI_APP_SECRET / ALI_SESSION.');
        }

        $params = [
            'app_key' => ALI_APP_KEY,
            'method' => 'aliexpress.ds.product.get',
            'session' => ALI_SESSION,
            'sign_method' => 'sha256',
            'timestamp' => $this->get_timestamp_ms(),
            'product_id' => $productId,
            'target_language' => 'pl',
            'target_currency' => 'PLN',
            'ship_to_country' => 'PL',
            'remove_personal_benefit' => 'false',
        ];
        $params['sign'] = $this->build_sign(ALI_APP_SECRET, $params);

        return $this->call_aliexpress($params);
    }

    private function get_nested($arr, $path, $default = null) {
        $ref = $arr;
        foreach ($path as $p) {
            if (!is_array($ref) || !array_key_exists($p, $ref)) {
                return $default;
            }
            $ref = $ref[$p];
        }
        return $ref;
    }

    private function normalize_api_payload($affData, $dsData) {
        $aff = $this->get_nested($affData, ['aliexpress_affiliate_product_sku_detail_get_response', 'resp_result', 'result', 'products', 0], []);
        if (empty($aff)) {
            $aff = $this->get_nested($affData, ['result', 'products', 0], []);
        }

        $ds = $this->get_nested($dsData, ['aliexpress_ds_product_get_response', 'result'], []);
        if (empty($ds)) {
            $ds = $this->get_nested($dsData, ['result'], []);
        }

        $detail = isset($ds['detail']) ? wp_strip_all_tags($ds['detail']) : '';
        $rawAttrs = isset($ds['ae_item_properties']) && is_array($ds['ae_item_properties']) ? $ds['ae_item_properties'] : [];
        $mappedAttrs = [];
        foreach ($rawAttrs as $a) {
            if (!empty($a['attr_name']) && isset($a['attr_value'])) {
                $mappedAttrs[] = [
                    'name' => sanitize_text_field($a['attr_name']),
                    'value' => sanitize_text_field((string) $a['attr_value']),
                ];
            }
        }

        return [
            'title' => $aff['title'] ?? '',
            'image_link' => $aff['image_link'] ?? '',
            'image_white' => $aff['image_white'] ?? '',
            'original_link' => $aff['original_link'] ?? '',
            'product_category' => $aff['product_category'] ?? '',
            'sale_price_with_tax' => $aff['sale_price_with_tax'] ?? '',
            'store_name' => $aff['store_name'] ?? '',
            'product_score' => $aff['product_score'] ?? '',
            'order_number' => $aff['order_number'] ?? '',
            'review_number' => $aff['review_number'] ?? '',
            'shipping_fees' => $aff['shipping_fees'] ?? '',
            'min_delivery_days' => $aff['min_delivery_days'] ?? '',
            'max_delivery_days' => $aff['max_delivery_days'] ?? '',
            'ship_from_country' => $aff['ship_from_country'] ?? '',
            'detail' => $detail,
            'raw_attrs' => $rawAttrs,
            'attrs' => $mappedAttrs,
        ];
    }

    private function create_external_product($productId, $skuId, $payload) {
        $postId = wp_insert_post([
            'post_type' => 'product',
            'post_status' => 'draft',
            'post_title' => sanitize_text_field($payload['title']),
            'post_content' => wp_kses_post(wpautop($payload['detail'])),
        ]);
        if (is_wp_error($postId)) {
            return $postId;
        }

        wp_set_object_terms($postId, 'external', 'product_type');
        update_post_meta($postId, '_product_url', esc_url_raw($payload['original_link']));
        update_post_meta($postId, '_regular_price', wc_format_decimal($payload['sale_price_with_tax']));
        update_post_meta($postId, '_price', wc_format_decimal($payload['sale_price_with_tax']));

        update_post_meta($postId, self::META_SOURCE, 'aliexpress');
        update_post_meta($postId, self::META_PRODUCT_ID, sanitize_text_field($productId));
        update_post_meta($postId, self::META_SKU_ID, sanitize_text_field($skuId));
        update_post_meta($postId, self::META_ORIGINAL_LINK, esc_url_raw($payload['original_link']));
        update_post_meta($postId, self::META_IMAGE_WHITE, esc_url_raw($payload['image_white']));
        update_post_meta($postId, self::META_STORE_NAME, sanitize_text_field($payload['store_name']));
        update_post_meta($postId, self::META_PRODUCT_SCORE, sanitize_text_field($payload['product_score']));
        update_post_meta($postId, self::META_ORDER_NUMBER, sanitize_text_field($payload['order_number']));
        update_post_meta($postId, self::META_REVIEW_NUMBER, sanitize_text_field($payload['review_number']));
        update_post_meta($postId, self::META_SHIPPING_FEES, sanitize_text_field($payload['shipping_fees']));
        update_post_meta($postId, self::META_MIN_DAYS, sanitize_text_field($payload['min_delivery_days']));
        update_post_meta($postId, self::META_MAX_DAYS, sanitize_text_field($payload['max_delivery_days']));
        update_post_meta($postId, self::META_SHIP_FROM, sanitize_text_field($payload['ship_from_country']));
        update_post_meta($postId, self::META_AE_CATEGORY, sanitize_text_field($payload['product_category']));
        update_post_meta($postId, self::META_ATTRS_RAW, wp_json_encode($payload['raw_attrs'], JSON_UNESCAPED_UNICODE));
        update_post_meta($postId, self::META_DESC_RAW, wp_kses_post($payload['detail']));
        update_post_meta($postId, self::META_AI_EDITED, '0');

        if (!empty($payload['image_link'])) {
            update_post_meta($postId, '_aewpm_image_link', esc_url_raw($payload['image_link']));
        }

        return $postId;
    }

    public function handle_add_product() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Brak uprawnień.');
        }
        check_admin_referer('aewpm_add_product');

        $line = isset($_POST['line']) ? wp_unslash($_POST['line']) : '';
        [$productId, $skuId] = $this->parse_product_line($line);
        if (!$productId || !$skuId) {
            wp_safe_redirect(add_query_arg('aewpm_msg', rawurlencode('Niepoprawny format. Użyj: Product ID: ... SKU: ...'), admin_url('admin.php?page=aewpm_add')));
            exit;
        }

        $affData = $this->get_affiliate_data($productId, $skuId);
        if (is_wp_error($affData)) {
            wp_safe_redirect(add_query_arg('aewpm_msg', rawurlencode($affData->get_error_message()), admin_url('admin.php?page=aewpm_add')));
            exit;
        }
        $dsData = $this->get_ds_data($productId);
        if (is_wp_error($dsData)) {
            wp_safe_redirect(add_query_arg('aewpm_msg', rawurlencode($dsData->get_error_message()), admin_url('admin.php?page=aewpm_add')));
            exit;
        }

        $payload = $this->normalize_api_payload($affData, $dsData);
        $postId = $this->create_external_product($productId, $skuId, $payload);

        if (is_wp_error($postId)) {
            wp_safe_redirect(add_query_arg('aewpm_msg', rawurlencode($postId->get_error_message()), admin_url('admin.php?page=aewpm_add')));
            exit;
        }

        wp_safe_redirect(add_query_arg(['page' => 'aewpm_edit', 'product_id' => $postId, 'aewpm_msg' => rawurlencode('Produkt dodany.')], admin_url('admin.php')));
        exit;
    }

    public function handle_refresh_product() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Brak uprawnień.');
        }
        check_admin_referer('aewpm_refresh_product');
        $postId = absint($_POST['product_id'] ?? 0);
        $productId = get_post_meta($postId, self::META_PRODUCT_ID, true);
        $skuId = get_post_meta($postId, self::META_SKU_ID, true);

        if (!$postId || !$productId || !$skuId) {
            wp_safe_redirect(add_query_arg('aewpm_msg', rawurlencode('Brak danych produktu.'), admin_url('admin.php?page=aewpm_moderate')));
            exit;
        }

        $affData = $this->get_affiliate_data($productId, $skuId);
        $dsData = $this->get_ds_data($productId);
        if (is_wp_error($affData) || is_wp_error($dsData)) {
            $err = is_wp_error($affData) ? $affData->get_error_message() : $dsData->get_error_message();
            wp_safe_redirect(add_query_arg('aewpm_msg', rawurlencode($err), admin_url('admin.php?page=aewpm_moderate')));
            exit;
        }

        $payload = $this->normalize_api_payload($affData, $dsData);

        // Refresh without overriding title/description/category/images.
        update_post_meta($postId, '_regular_price', wc_format_decimal($payload['sale_price_with_tax']));
        update_post_meta($postId, '_price', wc_format_decimal($payload['sale_price_with_tax']));
        update_post_meta($postId, self::META_STORE_NAME, sanitize_text_field($payload['store_name']));
        update_post_meta($postId, self::META_PRODUCT_SCORE, sanitize_text_field($payload['product_score']));
        update_post_meta($postId, self::META_ORDER_NUMBER, sanitize_text_field($payload['order_number']));
        update_post_meta($postId, self::META_REVIEW_NUMBER, sanitize_text_field($payload['review_number']));
        update_post_meta($postId, self::META_SHIPPING_FEES, sanitize_text_field($payload['shipping_fees']));
        update_post_meta($postId, self::META_MIN_DAYS, sanitize_text_field($payload['min_delivery_days']));
        update_post_meta($postId, self::META_MAX_DAYS, sanitize_text_field($payload['max_delivery_days']));
        update_post_meta($postId, self::META_SHIP_FROM, sanitize_text_field($payload['ship_from_country']));
        update_post_meta($postId, self::META_ATTRS_RAW, wp_json_encode($payload['raw_attrs'], JSON_UNESCAPED_UNICODE));

        wp_safe_redirect(add_query_arg('aewpm_msg', rawurlencode('Odświeżono dane produktu.'), admin_url('admin.php?page=aewpm_moderate')));
        exit;
    }

    private function maybe_set_attributes($postId, $attrsRaw, $selectedIdx) {
        $productAttrs = [];
        foreach ($attrsRaw as $idx => $attr) {
            if (!in_array((string) $idx, $selectedIdx, true)) {
                continue;
            }
            $name = sanitize_text_field($attr['attr_name'] ?? '');
            $value = sanitize_text_field($attr['attr_value'] ?? '');
            if (!$name || !$value) {
                continue;
            }
            $productAttrs[sanitize_title($name)] = [
                'name' => $name,
                'value' => $value,
                'position' => count($productAttrs),
                'is_visible' => 1,
                'is_variation' => 0,
                'is_taxonomy' => 0,
            ];
        }

        update_post_meta($postId, '_product_attributes', $productAttrs);
    }

    public function handle_save_product() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Brak uprawnień.');
        }
        check_admin_referer('aewpm_save_product');

        $postId = absint($_POST['product_id'] ?? 0);
        if (!$postId) {
            wp_safe_redirect(admin_url('admin.php?page=aewpm_moderate'));
            exit;
        }

        wp_update_post([
            'ID' => $postId,
            'post_title' => sanitize_text_field($_POST['title'] ?? ''),
            'post_content' => wp_kses_post($_POST['description'] ?? ''),
        ]);

        update_post_meta($postId, '_regular_price', wc_format_decimal($_POST['price'] ?? ''));
        update_post_meta($postId, '_price', wc_format_decimal($_POST['price'] ?? ''));
        update_post_meta($postId, self::META_STORE_NAME, sanitize_text_field($_POST['brand'] ?? ''));
        update_post_meta($postId, self::META_IMAGE_WHITE, esc_url_raw($_POST['image_white'] ?? ''));
        update_post_meta($postId, '_aewpm_image_link', esc_url_raw($_POST['image_link'] ?? ''));
        update_post_meta($postId, self::META_AE_CATEGORY, sanitize_text_field($_POST['ae_category'] ?? ''));
        update_post_meta($postId, self::META_SHIP_FROM, sanitize_text_field($_POST['ship_from'] ?? ''));
        update_post_meta($postId, self::META_MIN_DAYS, sanitize_text_field($_POST['min_days'] ?? ''));
        update_post_meta($postId, self::META_MAX_DAYS, sanitize_text_field($_POST['max_days'] ?? ''));
        update_post_meta($postId, self::META_SHIPPING_FEES, sanitize_text_field($_POST['shipping_fees'] ?? ''));

        if (!empty($_POST['category_id'])) {
            wp_set_object_terms($postId, [absint($_POST['category_id'])], 'product_cat', false);
        }

        $attrsRaw = json_decode((string) get_post_meta($postId, self::META_ATTRS_RAW, true), true);
        if (!is_array($attrsRaw)) {
            $attrsRaw = [];
        }
        $selected = isset($_POST['selected_attrs']) && is_array($_POST['selected_attrs'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['selected_attrs']))
            : [];
        $this->maybe_set_attributes($postId, $attrsRaw, $selected);

        wp_safe_redirect(add_query_arg(['page' => 'aewpm_edit', 'product_id' => $postId, 'aewpm_msg' => rawurlencode('Zapisano zmiany.')], admin_url('admin.php')));
        exit;
    }

    private function ae_send_to_groq($prompt) {
        if (!defined('GROQ_API_KEY') || !GROQ_API_KEY) {
            return new WP_Error('aewpm_groq_missing', 'Brak GROQ_API_KEY.');
        }

        $response = wp_remote_post('https://api.groq.com/openai/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . GROQ_API_KEY,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 40,
            'body' => wp_json_encode([
                'model' => 'llama-3.3-70b-versatile',
                'temperature' => 0.4,
                'messages' => [
                    ['role' => 'system', 'content' => 'Jesteś redaktorem e-commerce PL dla WooCommerce. Zwracaj tylko poprawny JSON.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $content = $body['choices'][0]['message']['content'] ?? '';
        $json = json_decode($content, true);
        if (!is_array($json)) {
            return new WP_Error('aewpm_groq_json', 'AI zwróciło niepoprawny JSON.');
        }
        return $json;
    }

    public function handle_ai_edit_product() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Brak uprawnień.');
        }
        check_admin_referer('aewpm_ai_edit_product');

        $postId = absint($_POST['product_id'] ?? 0);
        $post = get_post($postId);
        if (!$post) {
            wp_safe_redirect(admin_url('admin.php?page=aewpm_moderate'));
            exit;
        }

        $attrsRaw = json_decode((string) get_post_meta($postId, self::META_ATTRS_RAW, true), true);
        if (!is_array($attrsRaw)) {
            $attrsRaw = [];
        }

        $prompt = "Przeredaguj produkt do sklepu WooCommerce w języku polskim.\n" .
            "Dane wejściowe JSON:\n" . wp_json_encode([
                'title' => $post->post_title,
                'description' => wp_strip_all_tags($post->post_content),
                'ae_category' => get_post_meta($postId, self::META_AE_CATEGORY, true),
                'attrs' => $attrsRaw,
            ], JSON_UNESCAPED_UNICODE) .
            "\nWymagany format wyjścia JSON: {title, description, suggested_category, attrs:[{attr_name, attr_value}]}";

        $ai = $this->ae_send_to_groq($prompt);
        if (is_wp_error($ai)) {
            wp_safe_redirect(add_query_arg(['page' => 'aewpm_edit', 'product_id' => $postId, 'aewpm_msg' => rawurlencode($ai->get_error_message())], admin_url('admin.php')));
            exit;
        }

        wp_update_post([
            'ID' => $postId,
            'post_title' => sanitize_text_field($ai['title'] ?? $post->post_title),
            'post_content' => wp_kses_post($ai['description'] ?? $post->post_content),
        ]);

        if (!empty($ai['suggested_category'])) {
            update_post_meta($postId, self::META_AE_CATEGORY, sanitize_text_field($ai['suggested_category']));
        }
        if (!empty($ai['attrs']) && is_array($ai['attrs'])) {
            $repacked = [];
            foreach ($ai['attrs'] as $a) {
                $repacked[] = [
                    'attr_name' => sanitize_text_field($a['attr_name'] ?? ''),
                    'attr_value' => sanitize_text_field($a['attr_value'] ?? ''),
                ];
            }
            update_post_meta($postId, self::META_ATTRS_RAW, wp_json_encode($repacked, JSON_UNESCAPED_UNICODE));
        }
        update_post_meta($postId, self::META_AI_EDITED, '1');

        wp_safe_redirect(add_query_arg(['page' => 'aewpm_edit', 'product_id' => $postId, 'aewpm_msg' => rawurlencode('AI zredagowało produkt.')], admin_url('admin.php')));
        exit;
    }

    public function render_add_page() {
        ?>
        <div class="wrap">
            <h1>Dodaj produkt z Ali</h1>
            <?php if (!empty($_GET['aewpm_msg'])) : ?>
                <div class="notice notice-info"><p><?php echo esc_html(wp_unslash($_GET['aewpm_msg'])); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('aewpm_add_product'); ?>
                <input type="hidden" name="action" value="aewpm_add_product"/>
                <p>Wklej w jednej linii, np.: <code>Product ID: 1005005065054764 SKU: 12000031501341897</code></p>
                <textarea name="line" rows="3" style="width:100%;max-width:900px;"></textarea>
                <p><button class="button button-primary" type="submit">Pobierz i dodaj produkt</button></p>
            </form>
        </div>
        <?php
    }

    public function render_moderate_page() {
        $status = sanitize_text_field($_GET['status'] ?? 'any');
        $category = absint($_GET['category'] ?? 0);
        $search = sanitize_text_field($_GET['s'] ?? '');

        $args = [
            'post_type' => 'product',
            'posts_per_page' => 50,
            'post_status' => $status === 'any' ? ['draft', 'publish', 'pending', 'private'] : $status,
            's' => $search,
            'meta_query' => [[
                'key' => self::META_SOURCE,
                'value' => 'aliexpress',
            ]],
        ];
        if ($category) {
            $args['tax_query'] = [[
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => [$category],
            ]];
        }
        $q = new WP_Query($args);
        ?>
        <div class="wrap">
            <h1>Moderuj produkty</h1>
            <?php if (!empty($_GET['aewpm_msg'])) : ?>
                <div class="notice notice-info"><p><?php echo esc_html(wp_unslash($_GET['aewpm_msg'])); ?></p></div>
            <?php endif; ?>
            <form method="get" action="">
                <input type="hidden" name="page" value="aewpm_moderate"/>
                <select name="status">
                    <option value="any" <?php selected($status, 'any'); ?>>Wszystkie statusy</option>
                    <option value="draft" <?php selected($status, 'draft'); ?>>Nieopublikowane</option>
                    <option value="publish" <?php selected($status, 'publish'); ?>>Opublikowane</option>
                </select>
                <?php wp_dropdown_categories([
                    'taxonomy' => 'product_cat',
                    'name' => 'category',
                    'show_option_all' => 'Wszystkie kategorie',
                    'hide_empty' => false,
                    'selected' => $category,
                ]); ?>
                <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Szukaj"/>
                <button class="button">Filtruj</button>
            </form>
            <table class="widefat striped" style="margin-top:16px;">
                <thead><tr>
                    <th>ID</th><th>Tytuł</th><th>Status</th><th>Kategorie</th><th>Tagi</th><th>Atrybuty</th><th>AI</th><th>Akcje</th>
                </tr></thead>
                <tbody>
                <?php if ($q->have_posts()) : while ($q->have_posts()) : $q->the_post();
                    $pid = get_the_ID();
                    $terms = wp_get_post_terms($pid, 'product_cat');
                    $tags = wp_get_post_terms($pid, 'product_tag');
                    $attrs = get_post_meta($pid, '_product_attributes', true);
                    ?>
                    <tr>
                        <td><?php echo esc_html($pid); ?></td>
                        <td><?php echo esc_html(get_the_title()); ?></td>
                        <td><?php echo esc_html(get_post_status($pid)); ?></td>
                        <td><?php echo !empty($terms) ? '✅' : '❌'; ?></td>
                        <td><?php echo !empty($tags) ? '✅' : '❌'; ?></td>
                        <td><?php echo !empty($attrs) ? '✅' : '❌'; ?></td>
                        <td><?php echo get_post_meta($pid, self::META_AI_EDITED, true) === '1' ? '✅' : '❌'; ?></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'aewpm_edit', 'product_id' => $pid], admin_url('admin.php'))); ?>">Edytuj</a>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                <?php wp_nonce_field('aewpm_refresh_product'); ?>
                                <input type="hidden" name="action" value="aewpm_refresh_product"/>
                                <input type="hidden" name="product_id" value="<?php echo esc_attr($pid); ?>"/>
                                <button class="button button-small" type="submit">Odśwież</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; else : ?>
                    <tr><td colspan="8">Brak produktów.</td></tr>
                <?php endif; wp_reset_postdata(); ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_edit_page() {
        $postId = absint($_GET['product_id'] ?? 0);
        $post = get_post($postId);
        if (!$post || $post->post_type !== 'product') {
            echo '<div class="wrap"><h1>Nie znaleziono produktu.</h1></div>';
            return;
        }

        $attrsRaw = json_decode((string) get_post_meta($postId, self::META_ATTRS_RAW, true), true);
        if (!is_array($attrsRaw)) {
            $attrsRaw = [];
        }

        ?>
        <div class="wrap">
            <h1>Edytuj produkt Ali #<?php echo esc_html($postId); ?></h1>
            <?php if (!empty($_GET['aewpm_msg'])) : ?>
                <div class="notice notice-success"><p><?php echo esc_html(wp_unslash($_GET['aewpm_msg'])); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('aewpm_save_product'); ?>
                <input type="hidden" name="action" value="aewpm_save_product"/>
                <input type="hidden" name="product_id" value="<?php echo esc_attr($postId); ?>"/>

                <h2>Podstawowe</h2>
                <p><label>Tytuł<br><input type="text" name="title" value="<?php echo esc_attr($post->post_title); ?>" style="width:100%;max-width:900px;"></label></p>
                <p><label>Cena<br><input type="text" name="price" value="<?php echo esc_attr(get_post_meta($postId, '_price', true)); ?>"></label></p>
                <p><label>Marka / sklep<br><input type="text" name="brand" value="<?php echo esc_attr(get_post_meta($postId, self::META_STORE_NAME, true)); ?>" style="width:100%;max-width:400px;"></label></p>

                <h2>Zdjęcia</h2>
                <?php $img = get_post_meta($postId, '_aewpm_image_link', true); $white = get_post_meta($postId, self::META_IMAGE_WHITE, true); ?>
                <p><label>Image link<br><input type="url" name="image_link" value="<?php echo esc_attr($img); ?>" style="width:100%;max-width:900px;"></label></p>
                <p><label>Image white<br><input type="url" name="image_white" value="<?php echo esc_attr($white); ?>" style="width:100%;max-width:900px;"></label></p>
                <div style="display:flex; gap:20px;">
                    <?php if ($img) : ?><img src="<?php echo esc_url($img); ?>" alt="img" style="max-width:180px;height:auto;"><?php endif; ?>
                    <?php if ($white) : ?><img src="<?php echo esc_url($white); ?>" alt="img white" style="max-width:180px;height:auto;"><?php endif; ?>
                </div>

                <h2>Kategorie</h2>
                <p><label>Kategoria AliExpress<br><input type="text" name="ae_category" value="<?php echo esc_attr(get_post_meta($postId, self::META_AE_CATEGORY, true)); ?>" style="width:100%;max-width:900px;"></label></p>
                <p>Wybierz kategorię sklepu:</p>
                <?php
                wp_dropdown_categories([
                    'taxonomy' => 'product_cat',
                    'name' => 'category_id',
                    'show_option_none' => '-- wybierz --',
                    'hide_empty' => false,
                ]);
                ?>

                <h2>Dostawa</h2>
                <p>Kraj wysyłki: <input type="text" name="ship_from" value="<?php echo esc_attr(get_post_meta($postId, self::META_SHIP_FROM, true)); ?>"></p>
                <p>Min dni: <input type="text" name="min_days" value="<?php echo esc_attr(get_post_meta($postId, self::META_MIN_DAYS, true)); ?>"> Max dni: <input type="text" name="max_days" value="<?php echo esc_attr(get_post_meta($postId, self::META_MAX_DAYS, true)); ?>"></p>
                <p>Koszty dostawy: <input type="text" name="shipping_fees" value="<?php echo esc_attr(get_post_meta($postId, self::META_SHIPPING_FEES, true)); ?>"></p>

                <h2>Statystyki</h2>
                <p>Ocena: <?php echo esc_html(get_post_meta($postId, self::META_PRODUCT_SCORE, true)); ?>/5<br>
                Opinie: <?php echo esc_html(get_post_meta($postId, self::META_REVIEW_NUMBER, true)); ?><br>
                Sprzedane: <?php echo esc_html(get_post_meta($postId, self::META_ORDER_NUMBER, true)); ?></p>

                <h2>Opis</h2>
                <textarea name="description" rows="8" style="width:100%;max-width:900px;"><?php echo esc_textarea($post->post_content); ?></textarea>

                <h2>Atrybuty produktu</h2>
                <p>
                    <button type="button" class="button" onclick="document.querySelectorAll('.aewpm-attr').forEach(el=>el.checked=true)">Zaznacz wszystkie</button>
                    <button type="button" class="button" onclick="document.querySelectorAll('.aewpm-attr').forEach(el=>el.checked=false)">Odznacz wszystkie</button>
                </p>
                <div style="max-height:320px; overflow:auto; border:1px solid #ddd; padding:12px;">
                <?php foreach ($attrsRaw as $idx => $a) : ?>
                    <label style="display:block;margin-bottom:8px;">
                        <input class="aewpm-attr" type="checkbox" name="selected_attrs[]" value="<?php echo esc_attr($idx); ?>" checked>
                        <strong><?php echo esc_html($a['attr_name'] ?? ''); ?></strong>: <?php echo esc_html($a['attr_value'] ?? ''); ?>
                    </label>
                <?php endforeach; ?>
                </div>

                <p style="margin-top:16px;">
                    <button class="button button-primary" type="submit">Zapisz</button>
                </p>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('aewpm_ai_edit_product'); ?>
                <input type="hidden" name="action" value="aewpm_ai_edit_product"/>
                <input type="hidden" name="product_id" value="<?php echo esc_attr($postId); ?>"/>
                <button class="button">Edytuj AI</button>
            </form>
        </div>
        <?php
    }
}

new AEWPM_Plugin();
