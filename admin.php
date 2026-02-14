<?php
function ae_admin_page() {
    echo '<div class="wrap"><h1>AliExpress Importer</h1>';
    
    echo '<form method="post">';
    wp_nonce_field('ae_action', 'ae_nonce');
    echo '<textarea name="ae_input" rows="4" style="width:500px; padding:10px;" placeholder="Product ID: 100500...&#10;SKU: 120000..."></textarea><br><br>';
    echo '<button type="submit" class="button button-primary">Pobierz dane</button>';
    echo '</form><hr>';
    
    if (isset($_POST['ae_nonce']) && wp_verify_nonce($_POST['ae_nonce'], 'ae_action') && !empty($_POST['ae_input'])) {
        [$productId, $skuId] = ae_parse_input($_POST['ae_input']);
        
        if ($productId && $skuId) {
            // Tworzymy obiekt produktu
            $product = new AE_Product($productId, $skuId);
            
            // Od razu pokazujemy formularz edycji
            ae_render_edit_form($product);
            
            // Opcjonalnie debug
            if (isset($_GET['debug'])) {
                echo '<h3>Debug API:</h3>';
                echo '<pre>' . htmlspecialchars(json_encode($product->api1_data, JSON_PRETTY_PRINT)) . '</pre>';
                echo '<pre>' . htmlspecialchars(json_encode($product->api2_data, JSON_PRETTY_PRINT)) . '</pre>';
            }
            
        } else {
            echo '<p style="color:red;">Nie znaleziono ID/SKU</p>';
        }
    }
    
    echo '</div>';
}