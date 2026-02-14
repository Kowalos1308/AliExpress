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
    if (is_wp_error($response)) return ['error' => $response->get_error_message()];
    
    return json_decode(wp_remote_retrieve_body($response), true);
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
    if (is_wp_error($response)) return ['error' => $response->get_error_message()];
    
    return json_decode(wp_remote_retrieve_body($response), true);
}