<?php
function ae_admin_page() {
    echo '<div class="wrap"><h1>AliExpress Importer</h1>';
    
    echo '<form method="post">';
    wp_nonce_field('ae_action', 'ae_nonce');
    echo '<textarea name="ae_input" rows="4" style="width:500px;" placeholder="Product ID: 100500...&#10;SKU: 120000..."></textarea><br>';
    echo '<button type="submit">Pobierz dane</button>';
    echo '</form>';
    
    if (isset($_POST['ae_nonce']) && wp_verify_nonce($_POST['ae_nonce'], 'ae_action') && !empty($_POST['ae_input'])) {
        [$productId, $skuId] = ae_parse_input($_POST['ae_input']);
        
        if ($productId && $skuId) {
            echo "<p>Product ID: $productId, SKU: $skuId</p>";
            
            echo '<h3>API 1:</h3>';
            $api1 = ae_api_1($productId, $skuId);
            echo '<pre>' . htmlspecialchars(json_encode($api1, JSON_PRETTY_PRINT)) . '</pre>';
            
            echo '<h3>API 2:</h3>';
            $api2 = ae_api_2($productId, $skuId);
            echo '<pre>' . htmlspecialchars(json_encode($api2, JSON_PRETTY_PRINT)) . '</pre>';
        } else {
            echo '<p style="color:red;">Nie znaleziono ID/SKU</p>';
        }
    }
    
    echo '</div>';
}