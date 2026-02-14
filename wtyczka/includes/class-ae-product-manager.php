<?php

if (!defined('ABSPATH')) {
    exit;
}

class AE_Product_Manager
{
    private AE_API $api;

    public function __construct(AE_API $api)
    {
        $this->api = $api;
    }

    public function import_product(string $productId, string $skuId): int
    {
        $data = $this->api->fetch_product_data($productId, $skuId);
        $n = $data['normalized'];

        $postId = wp_insert_post([
            'post_type' => 'product',
            'post_status' => 'draft',
            'post_title' => $n['title'] ?: ('AliExpress ' . $productId),
            'post_content' => $n['detail_text'],
        ]);

        if (!$postId || is_wp_error($postId)) {
            return 0;
        }

        wp_set_object_terms($postId, 'external', 'product_type');

        $product = wc_get_product($postId);
        if (!$product) {
            $product = new WC_Product_External($postId);
        }

        $product->set_product_url($n['original_link']);
        $product->set_button_text(__('Kup na AliExpress', 'ae-woo-moderator'));

        if ($n['sale_price_with_tax'] !== '') {
            $price = $this->normalize_price($n['sale_price_with_tax']);
            $product->set_regular_price($price);
        }

        $product->set_attributes($this->build_wc_attributes($n['attributes'], array_fill(0, count($n['attributes']), true)));
        $product->save();

        $this->save_meta($postId, [
            '_ae_product_id' => $productId,
            '_ae_sku_id' => $skuId,
            '_ae_image_link' => $n['image_link'],
            '_ae_image_white' => $n['image_white'],
            '_ae_original_link' => $n['original_link'],
            '_ae_product_category' => $n['product_category'],
            '_ae_store_name' => $n['store_name'],
            '_ae_product_score' => $n['product_score'],
            '_ae_order_number' => $n['order_number'],
            '_ae_review_number' => $n['review_number'],
            '_ae_shipping_fees' => $n['shipping_fees'],
            '_ae_min_delivery_days' => $n['min_delivery_days'],
            '_ae_max_delivery_days' => $n['max_delivery_days'],
            '_ae_ship_from_country' => $n['ship_from_country'],
            '_ae_raw_attributes' => $n['attributes'],
            '_ae_selected_attributes' => array_fill(0, count($n['attributes']), true),
            '_ae_ai_edited' => '0',
            '_ae_description_clean' => $n['detail_text'],
            '_ae_source' => 'ae_admin_panel',
        ]);

        return $postId;
    }

    public function refresh_product(int $postId): bool
    {
        $productId = (string) get_post_meta($postId, '_ae_product_id', true);
        $skuId = (string) get_post_meta($postId, '_ae_sku_id', true);
        if ($productId === '' || $skuId === '') {
            return false;
        }

        $n = $this->api->fetch_product_data($productId, $skuId)['normalized'];
        $product = wc_get_product($postId);
        if (!$product) {
            return false;
        }

        if ($n['sale_price_with_tax'] !== '') {
            $product->set_regular_price($this->normalize_price($n['sale_price_with_tax']));
        }
        if (!empty($n['original_link'])) {
            $product->set_product_url($n['original_link']);
        }

        $selected = get_post_meta($postId, '_ae_selected_attributes', true);
        $selected = is_array($selected) ? $selected : [];
        $product->set_attributes($this->build_wc_attributes($n['attributes'], $selected));
        $product->save();

        $this->save_meta($postId, [
            '_ae_store_name' => $n['store_name'],
            '_ae_product_score' => $n['product_score'],
            '_ae_order_number' => $n['order_number'],
            '_ae_review_number' => $n['review_number'],
            '_ae_shipping_fees' => $n['shipping_fees'],
            '_ae_min_delivery_days' => $n['min_delivery_days'],
            '_ae_max_delivery_days' => $n['max_delivery_days'],
            '_ae_ship_from_country' => $n['ship_from_country'],
            '_ae_original_link' => $n['original_link'],
            '_ae_raw_attributes' => $n['attributes'],
        ]);

        return true;
    }

    public function save_product_form(int $postId, array $payload): void
    {
        wp_update_post([
            'ID' => $postId,
            'post_title' => sanitize_text_field($payload['title'] ?? ''),
            'post_content' => wp_kses_post($payload['description'] ?? ''),
        ]);

        $product = wc_get_product($postId);
        if (!$product) {
            return;
        }

        $product->set_regular_price($this->normalize_price((string) ($payload['price'] ?? '')));
        $product->set_product_url(esc_url_raw($payload['original_link'] ?? ''));
        $product->save();

        $catIds = array_map('intval', $payload['categories'] ?? []);
        wp_set_object_terms($postId, $catIds, 'product_cat');

        $rawAttributes = get_post_meta($postId, '_ae_raw_attributes', true);
        $rawAttributes = is_array($rawAttributes) ? $rawAttributes : [];
        $selected = array_map('intval', $payload['selected_attributes'] ?? []);

        $selectedMap = [];
        foreach ($rawAttributes as $i => $row) {
            $selectedMap[$i] = in_array($i, $selected, true);
        }

        update_post_meta($postId, '_ae_selected_attributes', $selectedMap);
        $product->set_attributes($this->build_wc_attributes($rawAttributes, $selectedMap));
        $product->save();

        $this->save_meta($postId, [
            '_ae_image_choice' => sanitize_text_field($payload['image_choice'] ?? 'normal'),
            '_ae_image_link' => esc_url_raw($payload['image_link'] ?? ''),
            '_ae_image_white' => esc_url_raw($payload['image_white'] ?? ''),
            '_ae_product_category' => sanitize_text_field($payload['ae_category'] ?? ''),
            '_ae_ship_from_country' => sanitize_text_field($payload['ship_from_country'] ?? ''),
            '_ae_min_delivery_days' => sanitize_text_field($payload['min_delivery_days'] ?? ''),
            '_ae_max_delivery_days' => sanitize_text_field($payload['max_delivery_days'] ?? ''),
            '_ae_shipping_fees' => sanitize_text_field($payload['shipping_fees'] ?? ''),
            '_ae_product_score' => sanitize_text_field($payload['product_score'] ?? ''),
            '_ae_review_number' => sanitize_text_field($payload['review_number'] ?? ''),
            '_ae_order_number' => sanitize_text_field($payload['order_number'] ?? ''),
            '_ae_store_name' => sanitize_text_field($payload['store_name'] ?? ''),
        ]);
    }

    private function save_meta(int $postId, array $meta): void
    {
        foreach ($meta as $key => $value) {
            update_post_meta($postId, $key, $value);
        }
    }

    private function build_wc_attributes(array $attributes, array $selected): array
    {
        $built = [];

        foreach ($attributes as $index => $attribute) {
            if (!(bool) ($selected[$index] ?? false)) {
                continue;
            }

            $name = sanitize_text_field($attribute['name'] ?? 'Atrybut');
            $value = sanitize_text_field($attribute['value'] ?? '');
            if ($value === '') {
                continue;
            }

            $wcAttribute = new WC_Product_Attribute();
            $wcAttribute->set_id(0);
            $wcAttribute->set_name($name);
            $wcAttribute->set_options([$value]);
            $wcAttribute->set_position($index);
            $wcAttribute->set_visible(true);
            $wcAttribute->set_variation(false);
            $built[] = $wcAttribute;
        }

        return $built;
    }

    private function normalize_price(string $price): string
    {
        $normalized = preg_replace('/[^0-9\.,]/', '', $price);
        if ($normalized === null) {
            return '';
        }

        $normalized = str_replace(',', '.', $normalized);
        return $normalized;
    }
}
