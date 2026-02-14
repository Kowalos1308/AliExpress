<?php
function ae_parse_input($input) {
    preg_match('/Product ID:\s*(\d+)/i', $input, $p);
    preg_match('/SKU:\s*(\d+)/i', $input, $s);
    return [$p[1] ?? null, $s[1] ?? null];
}

function ae_api_1($productId, $skuId) {
    $url = "https://api-sg.aliexpress.com/sync";
    $timestamp = round(microtime(true) * 1000);
    
    $params = [
        'app_key' => ALI_AFFILIATE_APP_KEY,
        'method' => 'aliexpress.affiliate.product.sku.detail.get',
        'sign_method' => 'sha256',
        'timestamp' => $timestamp,
        'product_id' => $productId,
        'sku_ids' => $skuId,
        'target_language' => 'PL',
        'target_currency' => 'PLN',
        'ship_to_country' => 'PL',
        'need_deliver_info' => 'Yes'
    ];
    
    ksort($params);
    $stringToSign = "";
    foreach ($params as $k => $v) $stringToSign .= $k . $v;
    $sign = strtoupper(hash_hmac('sha256', $stringToSign, ALI_AFFILATE_APP_SECRET));
    $params['sign'] = $sign;
    
    $response = wp_remote_get(add_query_arg($params, $url), ['timeout' => 30, 'sslverify' => false]);
    if (is_wp_error($response)) return ['error' => 'HTTP Error: ' . $response->get_error_message()];
    
    $body = wp_remote_retrieve_body($response);
    return json_decode($body, true);
}

function ae_api_2($productId, $skuId) {
    $url = "https://api-sg.aliexpress.com/sync";
    $timestamp = round(microtime(true) * 1000);
    
    $params = [
        'app_key' => ALI_APP_KEY,
        'method' => 'aliexpress.ds.product.get',
        'session' => ALI_SESSION,
        'sign_method' => 'sha256',
        'timestamp' => $timestamp,
        'product_id' => $productId,
        'target_language' => 'pl',
        'target_currency' => 'PLN',
        'ship_to_country' => 'PL',
        'remove_personal_benefit' => 'false'
    ];
    
    if ($skuId) $params['sku_id'] = $skuId;
    
    ksort($params);
    $stringToSign = "";
    foreach ($params as $k => $v) $stringToSign .= $k . $v;
    $sign = strtoupper(hash_hmac('sha256', $stringToSign, ALI_APP_SECRET));
    $params['sign'] = $sign;
    
    $response = wp_remote_get(add_query_arg($params, $url), ['timeout' => 30, 'sslverify' => false]);
    if (is_wp_error($response)) return ['error' => 'HTTP Error: ' . $response->get_error_message()];
    
    $body = wp_remote_retrieve_body($response);
    return json_decode($body, true);
}

// KLASA PRODUKTU
class AE_Product {
    public $api1_data;
    public $api2_data;
    public $product_id;
    public $sku_id;
    
    public function __construct($productId, $skuId) {
        $this->product_id = $productId;
        $this->sku_id = $skuId;
        $this->api1_data = ae_api_1($productId, $skuId);
        $this->api2_data = ae_api_2($productId, $skuId);
    }
    
    // API1 GETTERS
    public function image_link() {
        return $this->get_api1('ae_item_info.image_link');
    }
    
    public function image_white() {
        return $this->get_api1('ae_item_info.image_white');
    }
    
    public function original_link() {
        return $this->get_api1('ae_item_info.original_link');
    }
    
    public function title() {
        return $this->get_api1('ae_item_info.title');
    }
    
    public function product_score() {
        return $this->get_api1('ae_item_info.product_score');
    }
    
    public function review_number() {
        return $this->get_api1('ae_item_info.review_number');
    }
    
    public function product_category() {
        return $this->get_api1('ae_item_info.product_category');
    }
    
    public function shipping_fees() {
        return $this->get_api1('ae_item_sku_info.traffic_sku_info_list.0.shipping_fees');
    }
    
    public function ship_from_country() {
        return $this->get_api1('ae_item_sku_info.traffic_sku_info_list.0.ship_from_country');
    }
    
    public function sale_price_with_tax() {
        return $this->get_api1('ae_item_sku_info.traffic_sku_info_list.0.sale_price_with_tax');
    }
    
    public function min_delivery_days() {
        return $this->get_api1('ae_item_sku_info.traffic_sku_info_list.0.min_delivery_days');
    }
    
    public function max_delivery_days() {
        return $this->get_api1('ae_item_sku_info.traffic_sku_info_list.0.max_delivery_days');
    }
    
    public function brand() {
        return $this->get_api1('ae_item_info.brand');
    }
    
    // API2 GETTERS
    public function sku_available_stock() {
        return $this->get_api2('ae_item_sku_info_dtos.ae_item_sku_info_d_t_o.0.sku_available_stock');
    }
    
    public function package_width() {
        return $this->get_api2('package_info_dto.package_width');
    }
    
    public function package_height() {
        return $this->get_api2('package_info_dto.package_height');
    }
    
    public function package_length() {
        return $this->get_api2('package_info_dto.package_length');
    }
    
    public function gross_weight() {
        return $this->get_api2('package_info_dto.gross_weight');
    }
    
    public function detail() {
        return $this->get_api2('ae_item_base_info_dto.detail');
    }
    
    public function product_status_type() {
        return $this->get_api2('ae_item_base_info_dto.product_status_type');
    }
    
    public function sales_count() {
        return $this->get_api2('ae_item_base_info_dto.sales_count');
    }
    
    // ATRYBUTY
    public function attributes() {
        $attrs = $this->get_api2('ae_item_properties.ae_item_property', []);
        $result = [];
        
        if (is_array($attrs)) {
            foreach ($attrs as $attr) {
                if (isset($attr['attr_name']) && isset($attr['attr_value'])) {
                    $result[] = [
                        'name' => $attr['attr_name'],
                        'value' => $attr['attr_value']
                    ];
                }
            }
        }
        
        return $result;
    }
    
    public function attribute($index) {
        $attrs = $this->attributes();
        return $attrs[$index] ?? null;
    }
    
    // PRIVATE HELPER
    private function get_api1($path, $default = '') {
        return $this->get_nested_value($this->api1_data, 
            "aliexpress_affiliate_product_sku_detail_get_response.result.result.$path", 
            $default
        );
    }
    
    private function get_api2($path, $default = '') {
        return $this->get_nested_value($this->api2_data, 
            "aliexpress_ds_product_get_response.result.$path", 
            $default
        );
    }
    
    private function get_nested_value($array, $path, $default = '') {
        $keys = explode('.', $path);
        $current = $array;
        
        foreach ($keys as $key) {
            if (is_numeric($key) && isset($current[$key])) {
                $current = $current[$key];
            } elseif (isset($current[$key])) {
                $current = $current[$key];
            } else {
                return $default;
            }
        }
        
        return $current;
    }
}