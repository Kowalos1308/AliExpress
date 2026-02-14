<?php

if (!defined('ABSPATH')) {
    exit;
}

class AE_Admin
{
    private AE_Product_Manager $manager;
    private AE_API $api;

    public function __construct(AE_Product_Manager $manager, AE_API $api)
    {
        $this->manager = $manager;
        $this->api = $api;

        add_action('admin_menu', [$this, 'register_menu']);
    }

    public function register_menu(): void
    {
        add_menu_page('AliExpress Woo', 'AliExpress Woo', 'manage_woocommerce', 'ae-woo', [$this, 'render_add_page'], 'dashicons-products');
        add_submenu_page('ae-woo', 'Dodaj produkt z Ali', 'Dodaj produkt z Ali', 'manage_woocommerce', 'ae-woo', [$this, 'render_add_page']);
        add_submenu_page('ae-woo', 'Moderuj produkty', 'Moderuj produkty', 'manage_woocommerce', 'ae-woo-moderation', [$this, 'render_moderation_page']);
        add_submenu_page(null, 'Edytuj produkt Ali', 'Edytuj produkt Ali', 'manage_woocommerce', 'ae-woo-edit', [$this, 'render_edit_page']);
    }

    public function render_add_page(): void
    {
        if (isset($_POST['ae_import_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ae_import_nonce'])), 'ae_import')) {
            $parsed = $this->api->parse_input((string) wp_unslash($_POST['ae_input'] ?? ''));
            if (!empty($parsed['product_id']) && !empty($parsed['sku_id'])) {
                $postId = $this->manager->import_product($parsed['product_id'], $parsed['sku_id']);
                if ($postId > 0) {
                    echo '<div class="notice notice-success"><p>Produkt został dodany jako szkic. <a href="' . esc_url(admin_url('admin.php?page=ae-woo-edit&product_id=' . $postId)) . '">Przejdź do edycji</a>.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Nie udało się dodać produktu.</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>Podaj poprawne Product ID i SKU.</p></div>';
            }
        }

        echo '<div class="wrap"><h1>Dodaj produkt z Ali</h1>';
        echo '<form method="post">';
        wp_nonce_field('ae_import', 'ae_import_nonce');
        echo '<p>Wklej dane w jednej linii lub dwóch liniach:</p>';
        echo '<textarea name="ae_input" rows="4" style="width:600px" placeholder="Product ID: 1005005065054764&#10;SKU: 12000031501341897"></textarea>';
        submit_button('Pobierz dane i dodaj produkt');
        echo '</form></div>';
    }

    public function render_moderation_page(): void
    {
        if (isset($_GET['refresh_product'], $_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'ae_refresh')) {
            $postId = (int) $_GET['refresh_product'];
            $ok = $this->manager->refresh_product($postId);
            echo $ok
                ? '<div class="notice notice-success"><p>Produkt został odświeżony.</p></div>'
                : '<div class="notice notice-error"><p>Nie udało się odświeżyć produktu.</p></div>';
        }

        $status = sanitize_text_field($_GET['status'] ?? 'any');
        $category = (int) ($_GET['category'] ?? 0);
        $search = sanitize_text_field($_GET['s'] ?? '');
        $dateFrom = sanitize_text_field($_GET['date_from'] ?? '');
        $dateTo = sanitize_text_field($_GET['date_to'] ?? '');

        $args = [
            'post_type' => 'product',
            'posts_per_page' => 50,
            'meta_key' => '_ae_source',
            'meta_value' => 'ae_admin_panel',
            's' => $search,
        ];

        if ($status !== 'any') {
            $args['post_status'] = $status;
        } else {
            $args['post_status'] = ['publish', 'draft', 'pending', 'private'];
        }

        if ($category > 0) {
            $args['tax_query'] = [[
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => [$category],
            ]];
        }

        if ($dateFrom || $dateTo) {
            $args['date_query'] = [[
                'after' => $dateFrom ?: null,
                'before' => $dateTo ?: null,
                'inclusive' => true,
            ]];
        }

        $products = get_posts($args);

        echo '<div class="wrap"><h1>Moderuj produkty</h1>';
        $this->render_filters($status, $category, $search, $dateFrom, $dateTo);

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>ID</th><th>Tytuł</th><th>Status</th><th>Kategorie</th><th>Tagi</th><th>Atrybuty</th><th>AI</th><th>Akcje</th>';
        echo '</tr></thead><tbody>';

        foreach ($products as $productPost) {
            $hasCats = has_term('', 'product_cat', $productPost->ID) ? 'Tak' : 'Nie';
            $hasTags = has_term('', 'product_tag', $productPost->ID) ? 'Tak' : 'Nie';
            $attr = wc_get_product($productPost->ID);
            $hasAttributes = ($attr && !empty($attr->get_attributes())) ? 'Tak' : 'Nie';
            $aiEdited = get_post_meta($productPost->ID, '_ae_ai_edited', true) === '1' ? 'Tak' : 'Nie';

            $editUrl = admin_url('admin.php?page=ae-woo-edit&product_id=' . $productPost->ID);
            $refreshUrl = wp_nonce_url(admin_url('admin.php?page=ae-woo-moderation&refresh_product=' . $productPost->ID), 'ae_refresh');

            echo '<tr>';
            echo '<td>' . esc_html((string) $productPost->ID) . '</td>';
            echo '<td>' . esc_html($productPost->post_title) . '</td>';
            echo '<td>' . esc_html($productPost->post_status) . '</td>';
            echo '<td>' . esc_html($hasCats) . '</td>';
            echo '<td>' . esc_html($hasTags) . '</td>';
            echo '<td>' . esc_html($hasAttributes) . '</td>';
            echo '<td>' . esc_html($aiEdited) . '</td>';
            echo '<td><a class="button" href="' . esc_url($editUrl) . '">Edytuj produkt</a> <a class="button" href="' . esc_url($refreshUrl) . '">Odśwież produkt</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    public function render_edit_page(): void
    {
        $postId = (int) ($_GET['product_id'] ?? 0);
        $post = get_post($postId);
        if (!$post || $post->post_type !== 'product') {
            echo '<div class="wrap"><h1>Nie znaleziono produktu.</h1></div>';
            return;
        }

        if (isset($_POST['ae_edit_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ae_edit_nonce'])), 'ae_edit_product')) {
            $payload = wp_unslash($_POST);

            if (isset($payload['ae_action']) && $payload['ae_action'] === 'ai_edit') {
                $this->run_ai_edit($postId);
                echo '<div class="notice notice-success"><p>AI zaktualizowało dane produktu.</p></div>';
            } else {
                $this->manager->save_product_form($postId, $payload);
                echo '<div class="notice notice-success"><p>Zapisano zmiany produktu.</p></div>';
            }

            $post = get_post($postId);
        }

        $this->render_edit_form($postId, $post);
    }

    private function run_ai_edit(int $postId): void
    {
        $context = [
            'title' => get_the_title($postId),
            'description' => get_post_field('post_content', $postId),
            'aliexpress_category' => get_post_meta($postId, '_ae_product_category', true),
            'attributes' => get_post_meta($postId, '_ae_raw_attributes', true),
        ];

        $ai = $this->api->send_to_groq($context);
        if (!empty($ai['title'])) {
            wp_update_post(['ID' => $postId, 'post_title' => sanitize_text_field($ai['title'])]);
        }
        if (!empty($ai['description'])) {
            wp_update_post(['ID' => $postId, 'post_content' => wp_kses_post($ai['description'])]);
        }
        if (!empty($ai['translated_attributes']) && is_array($ai['translated_attributes'])) {
            update_post_meta($postId, '_ae_raw_attributes', $ai['translated_attributes']);
        }
        if (!empty($ai['suggested_categories']) && is_array($ai['suggested_categories'])) {
            update_post_meta($postId, '_ae_ai_suggested_categories', $ai['suggested_categories']);
        }
        update_post_meta($postId, '_ae_ai_edited', '1');
    }

    private function render_edit_form(int $postId, WP_Post $post): void
    {
        $price = get_post_meta($postId, '_price', true);
        $storeName = get_post_meta($postId, '_ae_store_name', true);
        $imageLink = get_post_meta($postId, '_ae_image_link', true);
        $imageWhite = get_post_meta($postId, '_ae_image_white', true);
        $imageChoice = get_post_meta($postId, '_ae_image_choice', true) ?: 'normal';
        $aeCategory = get_post_meta($postId, '_ae_product_category', true);
        $attrs = get_post_meta($postId, '_ae_raw_attributes', true);
        $attrs = is_array($attrs) ? $attrs : [];
        $selected = get_post_meta($postId, '_ae_selected_attributes', true);
        $selected = is_array($selected) ? $selected : [];

        echo '<div class="wrap"><h1>Edycja produktu AliExpress #' . esc_html((string) $postId) . '</h1>';
        echo '<form method="post">';
        wp_nonce_field('ae_edit_product', 'ae_edit_nonce');

        echo '<table class="form-table">';
        echo '<tr><th>Tytuł</th><td><input type="text" name="title" class="regular-text" value="' . esc_attr($post->post_title) . '"></td></tr>';
        echo '<tr><th>Cena</th><td><input type="text" name="price" value="' . esc_attr((string) $price) . '"></td></tr>';
        echo '<tr><th>Marka / sklep</th><td><input type="text" name="store_name" value="' . esc_attr((string) $storeName) . '"></td></tr>';

        echo '<tr><th>Zdjęcia</th><td>';
        echo '<label><input type="radio" name="image_choice" value="normal" ' . checked($imageChoice, 'normal', false) . '> Zwykłe</label> ';
        echo '<label><input type="radio" name="image_choice" value="white" ' . checked($imageChoice, 'white', false) . '> White</label><br><br>';
        echo '<input type="url" name="image_link" class="regular-text" value="' . esc_attr((string) $imageLink) . '"><br>';
        echo '<input type="url" name="image_white" class="regular-text" value="' . esc_attr((string) $imageWhite) . '"><br>';
        if ($imageLink) {
            echo '<img src="' . esc_url($imageLink) . '" style="max-width:100px;height:auto;margin-top:8px;"> ';
        }
        if ($imageWhite) {
            echo '<img src="' . esc_url($imageWhite) . '" style="max-width:100px;height:auto;margin-top:8px;">';
        }
        echo '</td></tr>';

        echo '<tr><th>Kategoria AliExpress</th><td><input type="text" name="ae_category" class="regular-text" value="' . esc_attr((string) $aeCategory) . '"></td></tr>';
        echo '<tr><th>Kategorie sklepu</th><td>';
        wp_terms_checklist($postId, ['taxonomy' => 'product_cat', 'selected_cats' => wp_get_post_terms($postId, 'product_cat', ['fields' => 'ids'])]);
        echo '</td></tr>';

        echo '<tr><th>Link zewnętrzny</th><td><input type="url" name="original_link" class="regular-text" value="' . esc_attr((string) get_post_meta($postId, '_ae_original_link', true)) . '"></td></tr>';
        echo '<tr><th>Kraj wysyłki</th><td><input type="text" name="ship_from_country" value="' . esc_attr((string) get_post_meta($postId, '_ae_ship_from_country', true)) . '"></td></tr>';
        echo '<tr><th>Czas dostawy</th><td>min: <input type="text" name="min_delivery_days" value="' . esc_attr((string) get_post_meta($postId, '_ae_min_delivery_days', true)) . '"> max: <input type="text" name="max_delivery_days" value="' . esc_attr((string) get_post_meta($postId, '_ae_max_delivery_days', true)) . '"></td></tr>';
        echo '<tr><th>Koszt dostawy</th><td><input type="text" name="shipping_fees" value="' . esc_attr((string) get_post_meta($postId, '_ae_shipping_fees', true)) . '"></td></tr>';

        echo '<tr><th>Statystyki</th><td>Ocena /5: <input type="text" name="product_score" value="' . esc_attr((string) get_post_meta($postId, '_ae_product_score', true)) . '"> Opinie: <input type="text" name="review_number" value="' . esc_attr((string) get_post_meta($postId, '_ae_review_number', true)) . '"> Sprzedane: <input type="text" name="order_number" value="' . esc_attr((string) get_post_meta($postId, '_ae_order_number', true)) . '"></td></tr>';

        echo '<tr><th>Atrybuty produktu</th><td>';
        echo '<p><button class="button" type="button" onclick="document.querySelectorAll(\'.ae-attr\').forEach(el=>el.checked=true);">Zaznacz wszystkie</button> <button class="button" type="button" onclick="document.querySelectorAll(\'.ae-attr\').forEach(el=>el.checked=false);">Odznacz wszystkie</button></p>';
        echo '<div style="max-height:360px;overflow:auto;border:1px solid #ddd;padding:8px">';
        foreach ($attrs as $i => $attr) {
            $checked = (bool) ($selected[$i] ?? true);
            echo '<label style="display:block;margin-bottom:6px">';
            echo '<input class="ae-attr" type="checkbox" name="selected_attributes[]" value="' . esc_attr((string) $i) . '" ' . checked($checked, true, false) . '> ';
            echo esc_html((string) ($attr['name'] ?? 'Atrybut')) . ': <strong>' . esc_html((string) ($attr['value'] ?? '')) . '</strong>';
            echo '</label>';
        }
        echo '</div></td></tr>';

        echo '<tr><th>Opis</th><td>';
        wp_editor($post->post_content, 'description', ['textarea_name' => 'description', 'textarea_rows' => 10]);
        echo '</td></tr>';
        echo '</table>';

        echo '<input type="hidden" name="ae_action" value="save">';
        submit_button('Zapisz');
        echo '<button class="button button-secondary" name="ae_action" value="ai_edit">Edytuj AI</button>';

        echo '</form></div>';
    }

    private function render_filters(string $status, int $category, string $search, string $dateFrom, string $dateTo): void
    {
        $cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        echo '<form method="get" style="margin:12px 0">';
        echo '<input type="hidden" name="page" value="ae-woo-moderation">';

        echo '<select name="status"><option value="any">Wszystkie statusy</option>';
        foreach (['publish' => 'Opublikowane', 'draft' => 'Szkic', 'pending' => 'Oczekujące', 'private' => 'Prywatne'] as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($status, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> ';

        echo '<select name="category"><option value="0">Wszystkie kategorie</option>';
        foreach ($cats as $catTerm) {
            echo '<option value="' . esc_attr((string) $catTerm->term_id) . '" ' . selected($category, $catTerm->term_id, false) . '>' . esc_html($catTerm->name) . '</option>';
        }
        echo '</select> ';

        echo 'Data od: <input type="date" name="date_from" value="' . esc_attr($dateFrom) . '"> ';
        echo 'do: <input type="date" name="date_to" value="' . esc_attr($dateTo) . '"> ';
        echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Szukaj produktu"> ';
        submit_button('Filtruj', 'secondary', '', false);
        echo '</form>';
    }
}
