<?php
/**
 * Funkcje do przetwarzania danych dla formularza
 */

// FUNKCJA DO CZYSZCZENIA OPISU - ZOSTAWIA <h1-h6> i <p>
function ae_clean_description($html) {
    if (empty($html)) return '';
    
    // 1. ZOSTAW TYLKO DOZWOLONE TAGI: h1-h6, p, br, strong, b, em, i, ul, ol, li
    $allowed_tags = '<h1><h2><h3><h4><h5><h6><p><br><strong><b><em><i><ul><ol><li>';
    $html = strip_tags($html, $allowed_tags);
    
    // 2. USUŃ WSZYSTKIE DIV-y i SPAN-y (zachowując ich treść)
    // Zamień <div> i </div> na puste stringi
    $html = preg_replace('/<div[^>]*>/i', '', $html);
    $html = preg_replace('/<\/div>/i', '', $html);
    $html = preg_replace('/<span[^>]*>/i', '', $html);
    $html = preg_replace('/<\/span>/i', '', $html);
    
    // 3. USUŃ WSZYSTKIE style="..." class="..." align="..." itp.
    $html = preg_replace('/ style="[^"]*"/i', '', $html);
    $html = preg_replace('/ class="[^"]*"/i', '', $html);
    $html = preg_replace('/ align="[^"]*"/i', '', $html);
    $html = preg_replace('/ width="[^"]*"/i', '', $html);
    $html = preg_replace('/ height="[^"]*"/i', '', $html);
    
    // 4. ZAMIEŃ &nbsp; NA ZWYKŁE SPACJE
    $html = str_replace('&nbsp;', ' ', $html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // 5. USUŃ PUSTE TAGI (które mogą powstać po usunięciu zawartości)
    $html = preg_replace('/<h[1-6]>\s*<\/h[1-6]>/i', '', $html);
    $html = preg_replace('/<p>\s*<\/p>/i', '', $html);
    $html = preg_replace('/<strong>\s*<\/strong>/i', '', $html);
    $html = preg_replace('/<b>\s*<\/b>/i', '', $html);
    $html = preg_replace('/<em>\s*<\/em>/i', '', $html);
    $html = preg_replace('/<i>\s*<\/i>/i', '', $html);
    $html = preg_replace('/<li>\s*<\/li>/i', '', $html);
    
    // 6. USUŃ WIELOKROTNE SPACJE I ENTERY
    $html = preg_replace('/\s+/', ' ', $html);
    $html = preg_replace('/\n\s*\n+/', "\n", $html);
    
    // 7. NAPRAW FORMATOWANIE - zamień wielokrotne <br> na pojedyncze
    $html = preg_replace('/(<br\s*\/?>\s*){2,}/i', '<br>', $html);
    
    // 8. DODAJ BRAKUJĄCE ZAMKNIĘCIE TAGÓW (jeśli brakuje)
    $html = force_balance_tags($html);
    
    return trim($html);
}

// Funkcja do zapisywania edytowanych danych (bez zmian)
function ae_save_edited_data($post_data) {
    $product_id = sanitize_text_field($post_data['product_id']);
    $sku_id = sanitize_text_field($post_data['sku_id']);
    
    $edited_data = [
        'title' => sanitize_text_field($post_data['title'] ?? ''),
        'image_link' => esc_url_raw($post_data['image_link'] ?? ''),
        'image_white' => esc_url_raw($post_data['image_white'] ?? ''),
        'original_link' => esc_url_raw($post_data['original_link'] ?? ''),
        'product_category' => sanitize_text_field($post_data['product_category'] ?? ''),
        'shipping_fees' => floatval($post_data['shipping_fees'] ?? 0),
        'ship_from_country' => sanitize_text_field($post_data['ship_from_country'] ?? ''),
        'sale_price_with_tax' => floatval($post_data['sale_price_with_tax'] ?? 0),
        'min_delivery_days' => intval($post_data['min_delivery_days'] ?? 0),
        'max_delivery_days' => intval($post_data['max_delivery_days'] ?? 0),
        'brand' => sanitize_text_field($post_data['brand'] ?? ''),
        'product_score' => floatval($post_data['product_score'] ?? 0),
        'review_number' => intval($post_data['review_number'] ?? 0),
        'sku_available_stock' => intval($post_data['sku_available_stock'] ?? 0),
        'package_width' => intval($post_data['package_width'] ?? 0),
        'package_height' => intval($post_data['package_height'] ?? 0),
        'package_length' => intval($post_data['package_length'] ?? 0),
        'gross_weight' => floatval($post_data['gross_weight'] ?? 0),
        'detail' => wp_kses_post($post_data['detail'] ?? ''),
        'product_status_type' => sanitize_text_field($post_data['product_status_type'] ?? ''),
        'sales_count' => sanitize_text_field($post_data['sales_count'] ?? ''),
        'attributes' => []
    ];
    
    // Zbieramy atrybuty
    if (isset($post_data['import_attr']) && is_array($post_data['import_attr'])) {
        foreach ($post_data['import_attr'] as $index) {
            $attr_name = sanitize_text_field($post_data['attr_name_' . $index] ?? '');
            $attr_value = sanitize_text_field($post_data['attr_value_' . $index] ?? '');
            
            if (!empty($attr_name) && !empty($attr_value)) {
                $edited_data['attributes'][] = [
                    'name' => $attr_name,
                    'value' => $attr_value
                ];
            }
        }
    }
    
    // Zapisujemy dane
    $save_data = [
        'product_id' => $product_id,
        'sku_id' => $sku_id,
        'edited_data' => $edited_data,
        'timestamp' => time()
    ];
    
    update_option('ae_edited_product_' . get_current_user_id(), $save_data, 3600);
    
    return $save_data;
}

// Pobierz główne kategorie (bez rodzica) - ZMIENIONE: hide_empty => true
function ae_get_main_categories() {
    $categories = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,  // ZMIENIONE z false na true
        'parent' => 0,
        'orderby' => 'name',
        'order' => 'ASC'
    ]);
    
    $result = [];
    if (!is_wp_error($categories)) {
        foreach ($categories as $cat) {
            $result[$cat->term_id] = $cat->name;
        }
    }
    
    return $result;
}

// Pobierz podkategorie dla danej kategorii głównej - ZMIENIONE: hide_empty => true
function ae_get_subcategories($parent_id) {
    $categories = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,  // ZMIENIONE z false na true
        'parent' => $parent_id,
        'orderby' => 'name',
        'order' => 'ASC'
    ]);
    
    $result = [];
    if (!is_wp_error($categories)) {
        foreach ($categories as $cat) {
            $result[$cat->term_id] = $cat->name;
        }
    }
    
    return $result;
}

// Dodaj nową funkcję - szukanie kategorii po nazwie
function ae_find_category_by_name($category_name) {
    $terms = get_terms([
        'taxonomy' => 'product_cat',
        'name' => $category_name,
        'hide_empty' => false,
        'number' => 1
    ]);
    
    if (!is_wp_error($terms) && !empty($terms)) {
        return $terms[0]->term_id;
    }
    
    // Spróbuj szukać częściowej nazwy
    $terms = get_terms([
        'taxonomy' => 'product_cat',
        'name__like' => $category_name,
        'hide_empty' => false,
        'number' => 5
    ]);
    
    if (!is_wp_error($terms) && !empty($terms)) {
        foreach ($terms as $term) {
            if (stripos($term->name, $category_name) !== false) {
                return $term->term_id;
            }
        }
    }
    
    return 0;
}

// Konwertuj ID kategorii na nazwę
function ae_get_category_name_by_id($category_id) {
    $term = get_term($category_id, 'product_cat');
    if ($term && !is_wp_error($term)) {
        return $term->name;
    }
    return '';
}

// Konwertuj nazwę kategorii na ID (na podstawie pełnej ścieżki "Główna → Podkategoria")
function ae_get_category_id_by_full_path($full_path) {
    if (empty($full_path)) {
        return [0, 0];
    }
    
    // Jeśli jest separator →
    if (strpos($full_path, ' → ') !== false) {
        $parts = explode(' → ', $full_path);
        $main_name = trim($parts[0]);
        $sub_name = trim($parts[1]);
        
        // Znajdź główną kategorię
        $main_term = get_term_by('name', $main_name, 'product_cat');
        if (!$main_term || is_wp_error($main_term)) {
            return [0, 0];
        }
        
        // Znajdź podkategorię
        $sub_term = get_term_by('name', $sub_name, 'product_cat');
        if ($sub_term && !is_wp_error($sub_term) && $sub_term->parent == $main_term->term_id) {
            return [$main_term->term_id, $sub_term->term_id];
        }
        
        return [$main_term->term_id, 0];
    } else {
        // Tylko główna kategoria
        $main_term = get_term_by('name', $full_path, 'product_cat');
        if ($main_term && !is_wp_error($main_term)) {
            return [$main_term->term_id, 0];
        }
    }
    
    return [0, 0];
}