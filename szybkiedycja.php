<?php
/**
 * Plugin Name:       Szybka Edycja Produktów
 * Description:       Panel szybkiej edycji produktów WooCommerce + generowanie opisu AI
 * Version:           1.4.7
 * Author:            Jakub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ==========================================================================
   POMOCNICZE FUNKCJE
   ========================================================================== */

/**
 * Zwraca status flagi opisu: true = "Popraw", false = "OK"
 */
function se_get_description_status( int $product_id ): bool {
    $flag = get_post_meta( $product_id, '_se_description_flag', true );
    return $flag !== '0';
}

/**
 * Zwraca badge HTML dla statusu opisu
 */
function se_get_description_badge( bool $needs_update ): string {
    $class = $needs_update ? 'se-warning-badge' : 'se-ok-badge';
    $text  = $needs_update ? 'Popraw' : 'OK';

    return '<span class="' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
}

/* ==========================================================================
   ZAPIS ZMIAN
   ========================================================================== */

add_action( 'admin_init', function () {
    if ( ! isset( $_POST['se_save'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    check_admin_referer( 'se_edit_product' );

    $product_id = (int) ( $_POST['product_id'] ?? 0 );
    if ( ! $product_id ) {
        return;
    }

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return;
    }

    // Podstawowe pola
    $title = sanitize_text_field( $_POST['title'] ?? $product->get_name() );
    $url   = esc_url_raw( trim( $_POST['product_url'] ?? '' ) );
    $opis  = wp_kses_post( $_POST['opis'] ?? '' );

    wp_update_post( [
        'ID'           => $product_id,
        'post_title'   => $title,
        'post_content' => $opis,
    ] );

    // Flaga opisu
    if ( isset( $_POST['description_needs_update'] ) && $_POST['description_needs_update'] === '1' ) {
        update_post_meta( $product_id, '_se_description_flag', '1' );
    } else {
        update_post_meta( $product_id, '_se_description_flag', '0' );
    }

    // Ceny
    $regular_price = sanitize_text_field( $_POST['regular_price'] ?? '' );
    $sale_price    = sanitize_text_field( $_POST['sale_price'] ?? '' );

    // Zdjęcie główne
    if ( isset( $_POST['featured_id'] ) && is_numeric( $_POST['featured_id'] ) ) {
        set_post_thumbnail( $product_id, (int) $_POST['featured_id'] );
    } else {
        delete_post_thumbnail( $product_id );
    }

    // Galeria
    if ( ! empty( $_POST['gallery_ids'] ) ) {
        $gallery_ids = array_filter( array_map( 'intval', explode( ',', $_POST['gallery_ids'] ) ) );
        update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
    } else {
        delete_post_meta( $product_id, '_product_image_gallery' );
    }

    // Przenoszenie ceny i zmiana typu na external
    $needs_save = false;

    if ( $url ) {
        update_post_meta( $product_id, '_product_url', $url );

        // Pobieranie ceny (simple lub najniższa z variable)
        $current_regular_price = null;
        $current_sale_price    = null;

        if ( $product->is_type( 'variable' ) ) {
            $min_regular = PHP_FLOAT_MAX;
            $min_sale    = PHP_FLOAT_MAX;
            $has_regular = false;

            foreach ( $product->get_children() as $variation_id ) {
                $variation = wc_get_product( $variation_id );
                if ( ! $variation ) {
                    continue;
                }

                $var_regular = $variation->get_regular_price( 'edit' );
                $var_sale    = $variation->get_sale_price( 'edit' );

                if ( $var_regular !== '' ) {
                    $min_regular = min( $min_regular, (float) $var_regular );
                    $has_regular = true;
                }
                if ( $var_sale !== '' ) {
                    $min_sale = min( $min_sale, (float) $var_sale );
                }
            }

            if ( $has_regular ) {
                $current_regular_price = $min_regular !== PHP_FLOAT_MAX ? $min_regular : null;
                $current_sale_price    = $min_sale !== PHP_FLOAT_MAX ? $min_sale : null;
            }

            // Czyszczenie wariantów
            $attrs = $product->get_attributes();
            foreach ( $attrs as $attr ) {
                if ( $attr instanceof WC_Product_Attribute ) {
                    $attr->set_variation( false );
                }
            }
            foreach ( $product->get_children() as $variation_id ) {
                wp_delete_post( $variation_id, true );
            }
            $product->set_default_attributes( [] );
            $product->set_attributes( $attrs );
            $product->save();
            $product = wc_get_product( $product_id ); // odśwież
        } elseif ( $product->is_type( 'simple' ) ) {
            $current_regular_price = $product->get_regular_price();
            $current_sale_price    = $product->get_sale_price();
        }

        // Zmiana na external i ustawienie ceny
        if ( ! $product->is_type( 'external' ) ) {
            wp_set_object_terms( $product_id, 'external', 'product_type' );
            $product = new WC_Product_External( $product_id );
            $needs_save = true;
        }

        $product->set_product_url( $url );
        $needs_save = true;
    } else {
        delete_post_meta( $product_id, '_product_url' );

        if ( $product->is_type( 'external' ) ) {
            wp_set_object_terms( $product_id, 'simple', 'product_type' );
            $product    = new WC_Product_Simple( $product_id );
            $needs_save = true;
        }
        $product->set_product_url( '' );
        $needs_save = true;
    }

    // Ustawianie cen (z POST lub bieżące jeśli zmiana typu)
    if ( $regular_price !== '' ) {
        $product->set_regular_price( $regular_price );
    } elseif ( $current_regular_price !== null ) {
        $product->set_regular_price( $current_regular_price );
    }
    if ( $sale_price !== '' ) {
        $product->set_sale_price( $sale_price );
    } elseif ( $current_sale_price !== null ) {
        $product->set_sale_price( $current_sale_price );
    }

    // Kategorie
    $cat_ids = ! empty( $_POST['tax_input']['product_cat'] ) && is_array( $_POST['tax_input']['product_cat'] )
        ? array_map( 'intval', $_POST['tax_input']['product_cat'] )
        : [];
    wp_set_object_terms( $product_id, $cat_ids, 'product_cat' );

    // Tagi
    if ( isset( $_POST['tags'] ) ) {
        $tag_string = trim( wp_unslash( $_POST['tags'] ) );
        $tag_names  = array_filter( array_map( 'trim', explode( ',', $tag_string ) ) );
        wp_set_object_terms( $product_id, $tag_names, 'product_tag' );
    }

    // Marka
    if ( taxonomy_exists( 'product_brand' ) ) {
        $brand_ids = ! empty( $_POST['brands'] ) && is_array( $_POST['brands'] )
            ? array_map( 'intval', $_POST['brands'] )
            : [];
        wp_set_object_terms( $product_id, $brand_ids, 'product_brand' );
    }

    // Atrybuty
    if ( ! empty( $_POST['attributes'] ) && is_array( $_POST['attributes'] ) ) {
        $attributes = [];
        foreach ( $_POST['attributes'] as $taxonomy => $values ) {
            $values = array_filter( array_map( 'intval', (array) $values ) );
            wp_set_object_terms( $product_id, $values, $taxonomy );

            $attributes[ $taxonomy ] = [
                'name'         => $taxonomy,
                'value'        => '',
                'is_visible'   => 1,
                'is_variation' => 0,
                'is_taxonomy'  => 1,
            ];
        }
        $product->set_attributes( $attributes );
        $needs_save = true;
    }

    if ( $needs_save || $regular_price !== '' || $sale_price !== '' ) {
        $product->save();
    }

    wp_safe_redirect( admin_url( 'admin.php?page=szybka-edycja' ) );
    exit;
} );

/* ==========================================================================
   AJAX – NAPRAWA LINKU ALIEXPRESS
   ========================================================================== */

add_action( 'wp_ajax_se_fix_ali_link', function () {
    check_ajax_referer( 'se_edit_product', 'nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Brak uprawnień' );
    }

    $product_id = (int) ( $_POST['product_id'] ?? 0 );
    $raw_url    = esc_url_raw( $_POST['url'] ?? '' );

    $response = wp_remote_get( $raw_url, [
        'timeout'    => 15,
        'redirection'=> 5,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
    ] );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( 'Błąd połączenia' );
    }

    $effective_url = $response['http_response']->get_response_object()->url;

    if ( preg_match( '/(?:aliexpress\.com\/item\/)(\d+)\.html/i', $effective_url, $matches ) ) {
        $clean_url = 'https://pl.aliexpress.com/item/' . $matches[1] . '.html';
        update_post_meta( $product_id, '_product_url', $clean_url );
        wp_send_json_success( [ 'new_url' => $clean_url ] );
    }

    wp_send_json_error( 'Nie rozpoznano linku AliExpress' );
} );

/* ==========================================================================
   MENU ADMIN
   ========================================================================== */

add_action( 'admin_menu', function () {
    add_menu_page(
        'Szybka Edycja',
        'Szybka Edycja',
        'manage_woocommerce',
        'szybka-edycja',
        'se_lista_produktow',
        'dashicons-edit',
        58
    );
} );

/* ==========================================================================
   LISTA PRODUKTÓW
   ========================================================================== */

function se_lista_produktow() {
    $products        = wc_get_products( [ 'limit' => -1, 'status' => [ 'publish', 'draft', 'pending' ] ] );
    $current_edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;

    // CSS – wydzielony na górze funkcji dla czytelności
    ?>
    <style>
        :root{--bg:#ffffff;--card:#ffffff;--border:#e2e8f0;--text:#1e293b;--text-light:#64748b;--accent:#3b82f6;--accent-hover:#2563eb;--warning-bg:#fef3c7;--warning-text:#d97706;--success-bg:#dcfce7;--success-text:#15803d;--shadow:0 4px 12px rgba(0,0,0,0.08);--radius:10px}
        .wrap{background:var(--bg);padding:32px 24px}
        h1{font-size:2.25rem;font-weight:700;color:var(--text);margin:0 0 32px}
        .widefat{border-collapse:separate;border-spacing:0;background:var(--card);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);border:1px solid var(--border);table-layout:fixed}
        .widefat thead{background:#f8fafc}
        .widefat th{padding:14px 16px;font-weight:600;font-size:0.875rem;color:#475569;text-transform:uppercase;border-bottom:2px solid var(--border)}
        .widefat td{padding:14px 16px;border-bottom:1px solid #f1f5f9;color:var(--text)}
        .widefat tr:hover{background:#f8fafc}
        table.widefat th:nth-child(1),td:nth-child(1){width:3%;min-width:50px}
        table.widefat th:nth-child(2),td:nth-child(2){width:20%;min-width:180px}
        table.widefat th:nth-child(3),td:nth-child(3){width:10%;min-width:100px}
        table.widefat th:nth-child(4),td:nth-child(4){width:10%;min-width:100px}
        table.widefat th:nth-child(5),td:nth-child(5){width:10%;min-width:100px}
        table.widefat th:nth-child(6),td:nth-child(6){width:5%;min-width:120px}
        table.widefat th:nth-child(7),td:nth-child(7){width:15%;min-width:180px;word-break:break-all}
        table.widefat th:nth-child(8),td:nth-child(8){width:20%;min-width:100px;text-align:center}
        table.widefat th:nth-child(9),td:nth-child(9){width:7%;min-width:80px;text-align:center}
        .status-badge-wrapper{text-align:center;margin-bottom:8px}
        .se-warning-badge,.se-ok-badge{padding:5px 14px;border-radius:999px;font-size:0.8rem;font-weight:600;box-shadow:0 1px 3px rgba(0,0,0,0.08)}
        .se-warning-badge{background:var(--warning-bg);color:var(--warning-text)}
        .se-ok-badge{background:var(--success-bg);color:var(--success-text)}
        .se-edit-panel{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid var(--border);margin:24px 0 40px;overflow:hidden}
        .section{padding:32px;background:#fafafa;border-bottom:1px solid #f1f5f9}
        .section:last-child{border-bottom:none}
        .section h2{font-size:1.4rem;font-weight:700;color:var(--text);margin:0 0 24px;padding-bottom:12px;border-bottom:2px solid #e2e8f0}
        .row{margin-bottom:28px}
        .row label{display:block;font-weight:600;margin-bottom:10px;color:#334155}
        .row input[type=text],.row input[type=url]{width:100%;padding:12px 16px;border:1px solid #cbd5e1;border-radius:8px;transition:border-color .2s}
        .row input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.15)}
        .section:has(#opis_produktu){background:#ffffff}
        #ai-generate-btn{background:var(--accent);color:white;border:none;padding:12px 24px;border-radius:8px;font-weight:500}
        #ai-generate-btn:hover{background:var(--accent-hover)}
        .grid-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:32px;padding:0 32px 32px}
        .grid-3{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:32px}
        .popular-tags{margin-top:16px;color:var(--text-light)}
        .tag-pill{background:#e2e8f0;padding:6px 14px;border-radius:999px;margin-right:8px;margin-bottom:8px;cursor:pointer;transition:all .2s}
        .tag-pill:hover{background:#cbd5e1;transform:translateY(-1px)}
        .two-actions{display:flex;gap:20px;justify-content:center;padding:32px;background:#f8fafc;border-top:1px solid var(--border)}
        .button-hero{padding:14px 40px!important;font-size:1.1rem!important;border-radius:10px!important;box-shadow:0 4px 12px rgba(0,0,0,.1)!important;transition:all .25s ease}
        .button-hero:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.15)!important}
        .cat-columns{column-count:4;column-gap:32px}
        .cat-columns ul{margin:0;padding:0;list-style:none}
        .cat-columns li{margin-bottom:8px;position:relative;padding-left:20px}
        .cat-columns li:before{content:'•';position:absolute;left:0;color:#666}
        .cat-columns ul.children{margin-left:20px}
        .cat-columns ul.children li:before{content:'◦'}
        @media(max-width:900px){.section{padding:24px}.two-actions{flex-direction:column;gap:16px}.cat-columns{column-count:2}}
        @media(max-width:600px){.cat-columns{column-count:1}}
    </style>
    <?php

    echo '<div class="wrap"><h1>Szybka edycja produktów</h1>';

    echo '<table class="widefat striped" style="table-layout:fixed;"><thead><tr>';
    echo '<th>ID</th><th>Nazwa</th><th>Kategorie</th><th>Tagi</th><th>Marka</th><th>Atrybuty</th><th>Link</th><th>Opis</th><th>Akcja</th>';
    echo '</tr></thead><tbody>';

    foreach ( $products as $product ) {
        $id = $product->get_id();

        $cats   = wp_get_post_terms( $id, 'product_cat', [ 'fields' => 'names' ] );
        $tags   = wp_get_post_terms( $id, 'product_tag', [ 'fields' => 'names' ] );
        $brands = taxonomy_exists( 'product_brand' ) ? wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'names' ] ) : [];

        $url           = get_post_meta( $id, '_product_url', true );
        $desc_preview  = wp_trim_words( wp_strip_all_tags( $product->get_description() ), 25, '…' );
        $needs_update  = se_get_description_status( $id );

        $cat_warning    = empty( $cats ) || ( count( $cats ) === 1 && stripos( implode( '', $cats ), 'uncategorized' ) !== false );
        $tag_warning    = empty( $tags );
        $brand_warning  = taxonomy_exists( 'product_brand' ) && empty( $brands );

        $is_edit = $current_edit_id === $id;

        echo '<tr>';
        echo '<td>' . esc_html( $id ) . '</td>';
        echo '<td>' . esc_html( $product->get_name() ) . '</td>';

        // Kategorie
        echo '<td><div class="status-badge-wrapper">' . se_get_description_badge( $cat_warning ) . '</div>';
        echo esc_html( implode( ', ', $cats ) ?: '—' ) . '</td>';

        // Tagi
        echo '<td><div class="status-badge-wrapper">' . se_get_description_badge( $tag_warning ) . '</div>';
        echo esc_html( implode( ', ', $tags ) ?: '—' ) . '</td>';

        // Marka
        echo '<td><div class="status-badge-wrapper">' . se_get_description_badge( $brand_warning ) . '</div>';
        echo esc_html( implode( ', ', $brands ) ?: '—' ) . '</td>';

        // Atrybuty
        echo '<td>';
        $attr_str = [];
        foreach ( $product->get_attributes() as $attr ) {
            if ( $attr->is_taxonomy() ) {
                $label = wc_attribute_label( $attr->get_name() );
                $vals  = wc_get_product_terms( $id, $attr->get_name(), [ 'fields' => 'names' ] );
                if ( $vals ) {
                    $attr_str[] = $label . ': ' . implode( ', ', $vals );
                }
            }
        }
        echo esc_html( implode( ' | ', $attr_str ) ?: '—' );
        echo '</td>';

        // Link
        echo '<td>';
        if ( $url ) {
            echo '<a href="' . esc_url( $url ) . '" target="_blank" style="word-break:break-all;">' . esc_html( $url ) . '</a>';
            if ( strpos( $url, 'pl.aliexpress.com' ) === false ) {
                echo '<button type="button" class="fix-link-btn button button-small" data-id="' . esc_attr( $id ) . '" data-url="' . esc_attr( $url ) . '">⚡ Napraw</button>';
            }
        } else {
            echo '—';
        }
        echo '</td>';

        // Opis
        echo '<td><div class="status-badge-wrapper">' . se_get_description_badge( $needs_update ) . '</div>';
        echo '<small>' . esc_html( $desc_preview ) . '</small></td>';

        // Akcja
        echo '<td><a href="' . admin_url( 'admin.php?page=szybka-edycja' . ( $is_edit ? '' : '&edit=' . $id ) ) . '" class="button button-small ' . ( $is_edit ? 'button-primary' : '' ) . '">';
        echo $is_edit ? 'Zamknij' : 'Edytuj';
        echo '</a></td>';

        echo '</tr>';

        if ( $is_edit ) {
            echo '<tr><td colspan="9" style="padding:0;"><div class="se-edit-panel">' . se_render_modern_edit_form( $id ) . '</div></td></tr>';
        }
    }

    echo '</tbody></table></div>';

    // JavaScript (na dole)
    ?>
    <script>
    jQuery(function($){
        $('.fix-link-btn').on('click', function(){
            let $btn = $(this);
            let pid  = $btn.data('id');
            let url  = $btn.data('url');

            $btn.prop('disabled', true).text('Naprawianie...');

            $.post(ajaxurl, {
                action: 'se_fix_ali_link',
                nonce: '<?php echo wp_create_nonce( 'se_edit_product' ); ?>',
                product_id: pid,
                url: url
            }, function(res){
                if (res.success) {
                    $btn.closest('td').find('a').attr('href', res.data.new_url).text(res.data.new_url);
                    $btn.remove();
                } else {
                    alert('Błąd: ' + (res.data || 'nieznany'));
                    $btn.prop('disabled', false).text('⚡ Napraw');
                }
            });
        });

        // Auto-scroll do panelu edycji
        if ($('.se-edit-panel').length) {
            $('html, body').animate({
                scrollTop: $('.se-edit-panel').offset().top - 100
            }, 500);
        }
    });
    </script>
    <?php
}

/* ==========================================================================
   FORMULARZ EDYCJI
   ========================================================================== */

function se_render_modern_edit_form( int $product_id ): string {
    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return '<div class="notice notice-error"><p>Produkt nie istnieje.</p></div>';
    }

    $title            = $product->get_name();
    $url              = get_post_meta( $product_id, '_product_url', true );
    $description      = $product->get_description();
    $needs_update     = se_get_description_status( $product_id );

    $featured_id      = get_post_thumbnail_id( $product_id );
    $gallery_raw      = get_post_meta( $product_id, '_product_image_gallery', true );
    $gallery_ids      = $gallery_raw ? explode( ',', $gallery_raw ) : [];

    $popular_tags     = get_terms( [ 'taxonomy' => 'product_tag', 'orderby' => 'count', 'order' => 'DESC', 'number' => 25, 'hide_empty' => false ] );
    $current_tags_str = implode( ', ', wp_get_post_terms( $product_id, 'product_tag', [ 'fields' => 'names' ] ) );

    $groq_key = get_option( 'groq_api_key', '' );

    ob_start();
    ?>
    <form method="post">
        <?php wp_nonce_field( 'se_edit_product' ); ?>
        <input type="hidden" name="se_save" value="1">
        <input type="hidden" name="product_id" value="<?php echo esc_attr( $product_id ); ?>">

        <div class="section" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:32px;align-items:start;">
            <!-- Podstawowe -->
            <div>
                <h2>Podstawowe informacje</h2>
                <div class="row">
                    <label>Tytuł produktu <span class="req">*</span></label>
                    <input type="text" name="title" value="<?php echo esc_attr( $title ); ?>" required>
                </div>
                <div class="row">
                    <label>Adres URL produktu</label>
                    <input type="url" name="product_url" value="<?php echo esc_attr( $url ); ?>" placeholder="https://www.aliexpress.com/item/...">
                </div>
                <div class="row">
                    <label>Cena regularna</label>
                    <input type="text" name="regular_price" value="<?php echo esc_attr( $product->get_regular_price() ); ?>">
                </div>
                <div class="row">
                    <label>Cena promocyjna</label>
                    <input type="text" name="sale_price" value="<?php echo esc_attr( $product->get_sale_price() ); ?>">
                </div>
            </div>

            <!-- Zdjęcie główne -->
            <div>
                <h2>Zdjęcie główne</h2>
                <input type="hidden" name="featured_id" id="featured_id_<?php echo $product_id; ?>" value="<?php echo esc_attr( $featured_id ); ?>">
                <div id="featured-preview_<?php echo $product_id; ?>" style="text-align:center;">
                    <?php if ( $featured_id ) echo wp_get_attachment_image( $featured_id, 'medium', false, [ 'style' => 'max-width:180px;height:auto;' ] ); else echo '<em>Brak zdjęcia głównego</em>'; ?>
                </div>
                <div style="text-align:center;margin-top:12px;">
                    <button type="button" class="button" id="select-featured_<?php echo $product_id; ?>">Wybierz / Zmień</button>
                    <button type="button" class="button-link remove-featured" id="remove-featured_<?php echo $product_id; ?>" style="<?php echo $featured_id ? '' : 'display:none;'; ?>">Usuń</button>
                </div>
            </div>

            <!-- Galeria -->
            <div>
                <h2>Galeria</h2>
                <input type="hidden" name="gallery_ids" id="gallery_ids_<?php echo $product_id; ?>" value="<?php echo esc_attr( implode( ',', $gallery_ids ) ); ?>">
                <div id="gallery-preview_<?php echo $product_id; ?>" style="min-height:100px;text-align:center;">
                    <?php
                    if ( $gallery_ids ) {
                        foreach ( $gallery_ids as $gid ) {
                            echo wp_get_attachment_image( $gid, 'thumbnail', false, [ 'style' => 'max-width:100px;margin:4px;' ] );
                        }
                    } else {
                        echo '<em>Brak zdjęć w galerii</em>';
                    }
                    ?>
                </div>
                <div style="text-align:center;margin-top:12px;">
                    <button type="button" class="button" id="edit-gallery_<?php echo $product_id; ?>">Dodaj / Edytuj galerię</button>
                </div>
            </div>
        </div>

        <!-- Opis -->
        <div class="section">
            <h2>Opis produktu</h2>
            <?php wp_editor( $description, 'opis_produktu_' . $product_id, [ 'textarea_name' => 'opis', 'textarea_rows' => 16, 'media_buttons' => true ] ); ?>
            <p style="margin:16px 0 12px;">
                <button type="button" id="ai-generate-btn-<?php echo $product_id; ?>" class="button button-secondary">Generuj opis AI (Groq)</button>
                <span id="ai-spinner-<?php echo $product_id; ?>" class="spinner"></span>
                <span id="ai-status-<?php echo $product_id; ?>" style="margin-left:12px;font-style:italic;color:#555;"></span>
            </p>
            <label style="display:block;margin-top:12px;cursor:pointer;">
                <input type="checkbox" name="description_needs_update" value="1" <?php checked( $needs_update ); ?> style="margin-right:8px;">
                Opis wymaga poprawy / rozszerzenia / lepszego SEO
            </label>
            <?php if ( empty( $groq_key ) ) : ?>
                <p style="color:#c00;font-size:13px;margin-top:12px;">Brak klucza Groq API – dodaj w ustawieniach wtyczki.</p>
            <?php endif; ?>
        </div>

        <!-- Tagi, marka, atrybuty -->
        <div class="section grid-3">
            <!-- Tagi -->
            <div>
                <h3>Tagi</h3>
                <input type="text" name="tags" value="<?php echo esc_attr( $current_tags_str ); ?>" placeholder="oddziel przecinkami">
                <?php if ( $popular_tags && ! is_wp_error( $popular_tags ) ) : ?>
                    <div class="popular-tags">
                        <p><strong>Najczęściej używane:</strong></p>
                        <?php foreach ( $popular_tags as $tag ) : ?>
                            <a href="#" class="tag-pill" data-tag="<?php echo esc_attr( $tag->name ); ?>"><?php echo esc_html( $tag->name . ' (' . $tag->count . ')' ); ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Marka -->
            <?php if ( taxonomy_exists( 'product_brand' ) ) : 
                $current_brand = wp_get_post_terms( $product_id, 'product_brand', [ 'fields' => 'ids' ] );
                $current_brand = $current_brand ? reset( $current_brand ) : -1;
            ?>
                <div>
                    <h3>Marka</h3>
                    <?php wp_dropdown_categories( [ 'taxonomy' => 'product_brand', 'name' => 'brands[]', 'selected' => $current_brand, 'show_option_none' => '— brak marki —', 'hide_empty' => false, 'hierarchical' => true ] ); ?>
                </div>
            <?php endif; ?>

            <!-- Atrybuty -->
            <div>
                <h3>Atrybuty</h3>
                <?php
                $attrs = wc_get_attribute_taxonomies();
                if ( $attrs ) {
                    foreach ( $attrs as $attr ) {
                        $tax     = wc_attribute_taxonomy_name( $attr->attribute_name );
                        $terms   = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );
                        if ( empty( $terms ) || is_wp_error( $terms ) ) {
                            continue;
                        }
                        $current = wp_get_post_terms( $product_id, $tax, [ 'fields' => 'ids' ] );
                        echo '<div class="attr-group"><strong>' . esc_html( $attr->attribute_label ) . '</strong>';
                        foreach ( $terms as $term ) {
                            $checked = in_array( $term->term_id, $current ) ? 'checked' : '';
                            echo '<label><input type="checkbox" name="attributes[' . esc_attr( $tax ) . '][]" value="' . $term->term_id . '" ' . $checked . '> ' . esc_html( $term->name ) . '</label>';
                        }
                        echo '</div>';
                    }
                } else {
                    echo '<p style="color:#666;">Brak zdefiniowanych globalnych atrybutów</p>';
                }
                ?>
            </div>
        </div>

        <!-- Kategorie -->
        <div class="section">
            <h2>Kategorie</h2>
            <div class="cat-columns" style="padding:12px;border:1px solid #ddd;border-radius:4px;background:#fdfdfd;">
                <?php
                wp_terms_checklist( $product_id, [
                    'taxonomy'      => 'product_cat',
                    'popular_cats'  => [],
                    'checked_ontop' => false,
                ] );
                ?>
            </div>
        </div>

        <div class="two-actions">
            <button type="submit" class="button button-primary button-hero">Zapisz zmiany</button>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=szybka-edycja' ) ); ?>" class="button button-secondary button-hero">Anuluj / Zamknij</a>
        </div>
    </form>

    <script>
    jQuery(function($){
        const pid = <?php echo $product_id; ?>;

        // Popularne tagi
        $('.tag-pill').on('click', function(e){
            e.preventDefault();
            const tag = $(this).data('tag');
            const $input = $('input[name="tags"]');
            let tags = $input.val() ? $input.val().split(',').map(t => t.trim()) : [];
            if (!tags.includes(tag)) {
                tags.push(tag);
                $input.val(tags.join(', '));
            }
        });

        // AI generowanie
        $('#ai-generate-btn-' + pid).on('click', function(){
            const nazwa = $('input[name="title"]').val().trim();
            const opis  = tinymce.get('opis_produktu_' + pid) ? tinymce.get('opis_produktu_' + pid).getContent() : $('#opis_produktu_' + pid).val();

            if (!nazwa || !opis) {
                alert('Wpisz tytuł i opis!');
                return;
            }

            const $btn     = $(this);
            const $spinner = $('#ai-spinner-' + pid);
            const $status  = $('#ai-status-' + pid);

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $status.text('Generuję opis…').css('color', '#0073aa');

            $.post(ajaxurl, {
                action: 'szybkie_ai_groq_opis',
                nonce:  '<?php echo wp_create_nonce( 'szybkie_ai_groq' ); ?>',
                nazwa:  nazwa,
                opis:   opis
            }, function(res){
                $spinner.removeClass('is-active');
                $btn.prop('disabled', false);

                if (res.success && res.data?.opis) {
                    if (tinymce.get('opis_produktu_' + pid)) {
                        tinymce.get('opis_produktu_' + pid).setContent(res.data.opis);
                    } else {
                        $('#opis_produktu_' + pid).val(res.data.opis);
                    }
                    $status.text('Gotowe! ✓').css('color', 'green');
                    $('input[name="description_needs_update"]').prop('checked', false);
                } else {
                    $status.text(res.data?.error || 'Błąd API').css('color', 'red');
                }
            }).fail(function(){
                $spinner.removeClass('is-active');
                $btn.prop('disabled', false);
                $status.text('Błąd połączenia').css('color', 'red');
            });
        });

        // Media – zdjęcie główne
        $('#select-featured_' + pid).on('click', function(){
            const frame = wp.media({ title: 'Zdjęcie główne', button: { text: 'Użyj' }, multiple: false });
            frame.on('select', function(){
                const att = frame.state().get('selection').first().toJSON();
                $('#featured_id_' + pid).val(att.id);
                $('#featured-preview_' + pid).html('<img src="' + att.url + '" style="max-width:180px;height:auto;">');
                $('#remove-featured_' + pid).show();
            });
            frame.open();
        });

        $('#remove-featured_' + pid).on('click', function(){
            $('#featured_id_' + pid).val('');
            $('#featured-preview_' + pid).html('<em>Brak zdjęcia głównego</em>');
            $(this).hide();
        });

        // Media – galeria
        $('#edit-gallery_' + pid).on('click', function(){
            const current = $('#gallery_ids_' + pid).val() ? $('#gallery_ids_' + pid).val().split(',') : [];
            const frame = wp.media({ title: 'Galeria', button: { text: 'Dodaj' }, multiple: true });

            frame.on('open', function(){
                const sel = frame.state().get('selection');
                current.forEach(id => {
                    const att = wp.media.attachment(id);
                    att.fetch();
                    sel.add(att);
                });
            });

            frame.on('select', function(){
                const sel   = frame.state().get('selection');
                const ids   = [];
                let html    = '';
                sel.each(att => {
                    ids.push(att.id);
                    html += '<img src="' + att.attributes.url + '" style="max-width:100px;margin:4px;">';
                });
                $('#gallery_ids_' + pid).val(ids.join(','));
                $('#gallery-preview_' + pid).html(html || '<em>Brak zdjęć w galerii</em>');
            });

            frame.open();
        });
    });
    </script>
    <?php
    return ob_get_clean();
}