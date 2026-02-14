<?php

if (!defined('ABSPATH')) {
    exit;
}

class AE_API
{
    private string $endpoint = 'https://api-sg.aliexpress.com/sync';

    public function parse_input(string $input): array
    {
        preg_match('/Product ID:\s*(\d+)/i', $input, $productMatch);
        preg_match('/SKU:\s*(\d+)/i', $input, $skuMatch);

        return [
            'product_id' => $productMatch[1] ?? '',
            'sku_id' => $skuMatch[1] ?? '',
        ];
    }

    public function fetch_product_data(string $productId, string $skuId): array
    {
        $api1 = $this->call_affiliate_api($productId, $skuId);
        $api2 = $this->call_ds_api($productId);

        return [
            'api1_raw' => $api1,
            'api2_raw' => $api2,
            'normalized' => $this->normalize_data($api1, $api2),
        ];
    }

    public function call_affiliate_api(string $productId, string $skuId): array
    {
        $params = [
            'app_key' => ALI_AFFILIATE_APP_KEY,
            'method' => 'aliexpress.affiliate.product.sku.detail.get',
            'sign_method' => 'sha256',
            'timestamp' => (string) round(microtime(true) * 1000),
            'product_id' => $productId,
            'sku_ids' => $skuId,
            'target_language' => 'PL',
            'target_currency' => 'PLN',
            'ship_to_country' => 'PL',
            'need_deliver_info' => 'Yes',
        ];

        return $this->request($params, ALI_AFFILATE_APP_SECRET);
    }

    public function call_ds_api(string $productId): array
    {
        $params = [
            'app_key' => ALI_APP_KEY,
            'method' => 'aliexpress.ds.product.get',
            'session' => ALI_SESSION,
            'sign_method' => 'sha256',
            'timestamp' => (string) round(microtime(true) * 1000),
            'product_id' => $productId,
            'target_language' => 'pl',
            'target_currency' => 'PLN',
            'ship_to_country' => 'PL',
            'remove_personal_benefit' => 'false',
        ];

        return $this->request($params, ALI_APP_SECRET);
    }

    public function send_to_groq(array $context): array
    {
        $prompt = "Jesteś ekspertem ecommerce dla sklepu WooCommerce. "
            . "Na podstawie danych AliExpress wygeneruj wynik po polsku jako czysty JSON bez markdownu. "
            . "Zwróć klucze: title, description, suggested_categories (tablica stringów), translated_attributes (tablica obiektów {name,value}). "
            . "Dane wejściowe: " . wp_json_encode($context, JSON_UNESCAPED_UNICODE);

        $response = wp_remote_post('https://api.groq.com/openai/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . GROQ_API_KEY,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => 'llama-3.3-70b-versatile',
                'messages' => [
                    ['role' => 'system', 'content' => 'Zwracaj wyłącznie poprawny JSON.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.4,
            ]),
        ]);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $content = $body['choices'][0]['message']['content'] ?? '';

        $decoded = json_decode(trim($content), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return [
            'error' => 'Nie udało się sparsować odpowiedzi AI.',
            'raw' => $content,
        ];
    }

    private function request(array $params, string $secret): array
    {
        ksort($params);
        $stringToSign = '';
        foreach ($params as $key => $value) {
            $stringToSign .= $key . $value;
        }

        $params['sign'] = strtoupper(hash_hmac('sha256', $stringToSign, $secret));

        $response = wp_remote_get(add_query_arg($params, $this->endpoint), [
            'timeout' => 45,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($json) ? $json : ['error' => 'Niepoprawna odpowiedź API'];
    }

    private function normalize_data(array $api1, array $api2): array
    {
        $item1 = $this->find_ae_item($api1);
        $item2 = $this->find_ae_item($api2);

        $props = $item2['ae_item_properties'] ?? [];
        $properties = [];
        foreach ($props as $prop) {
            if (!empty($prop['attr_name']) && isset($prop['attr_value'])) {
                $properties[] = [
                    'name' => (string) $prop['attr_name'],
                    'value' => (string) $prop['attr_value'],
                ];
            }
        }

        return [
            'title' => (string) ($item1['title'] ?? ''),
            'image_link' => (string) ($item1['image_link'] ?? ''),
            'image_white' => (string) ($item1['image_white'] ?? ''),
            'original_link' => (string) ($item1['original_link'] ?? ''),
            'product_category' => (string) ($item1['product_category'] ?? ''),
            'sale_price_with_tax' => (string) ($item1['sale_price_with_tax'] ?? ''),
            'store_name' => (string) ($item1['store_name'] ?? ''),
            'product_score' => (string) ($item1['product_score'] ?? ''),
            'order_number' => (string) ($item1['order_number'] ?? ''),
            'review_number' => (string) ($item1['review_number'] ?? ''),
            'shipping_fees' => (string) ($item1['shipping_fees'] ?? ''),
            'min_delivery_days' => (string) ($item1['min_delivery_days'] ?? ''),
            'max_delivery_days' => (string) ($item1['max_delivery_days'] ?? ''),
            'ship_from_country' => (string) ($item1['ship_from_country'] ?? ''),
            'detail_text' => wp_strip_all_tags(html_entity_decode((string) ($item2['detail'] ?? ''), ENT_QUOTES | ENT_HTML5)),
            'attributes' => $properties,
        ];
    }

    private function find_ae_item(array $response): array
    {
        if (isset($response['aliexpress_affiliate_product_sku_detail_get_response']['resp_result']['result']['ae_item_sku_info_dtos'][0]['ae_item_base_info_dto'])) {
            return $response['aliexpress_affiliate_product_sku_detail_get_response']['resp_result']['result']['ae_item_sku_info_dtos'][0]['ae_item_base_info_dto'];
        }

        if (isset($response['result']['ae_item_sku_info_dtos'][0]['ae_item_base_info_dto'])) {
            return $response['result']['ae_item_sku_info_dtos'][0]['ae_item_base_info_dto'];
        }

        if (isset($response['aliexpress_ds_product_get_response']['result'])) {
            return $response['aliexpress_ds_product_get_response']['result'];
        }

        if (isset($response['result'])) {
            return (array) $response['result'];
        }

        return [];
    }
}
