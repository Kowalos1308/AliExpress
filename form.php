<?php
// Dodajemy na początku pliku
require_once plugin_dir_path(__FILE__) . 'form-functions.php';
require_once plugin_dir_path(__FILE__) . 'form-ai.php'; // DODAJ TE LINIĘ

function ae_render_edit_form($product) {
    // AUTOMATYCZNIE FORMATUJEMY OPIS
    $clean_description = ae_clean_description($product->detail());
    
    // Inicjalizujemy edytor
    wp_enqueue_editor();
    
    ?>
    <h2>✏️ Edycja produktu</h2>
    
    <form method="post">
        <?php wp_nonce_field('ae_edit_product', 'ae_edit_nonce'); ?>
        <input type="hidden" name="product_id" value="<?php echo esc_attr($product->product_id); ?>">
        <input type="hidden" name="sku_id" value="<?php echo esc_attr($product->sku_id); ?>">
        
        <!-- INFORMACJE PODSTAWOWE -->
        <h3>📋 Informacje podstawowe</h3>
        <p>Tytuł: <input type="text" name="title" value="<?php echo esc_attr($product->title()); ?>" style="width:500px;"></p>
        <p>Cena: <input type="text" name="sale_price_with_tax" value="<?php echo esc_attr($product->sale_price_with_tax()); ?>"> PLN</p>
        <p>Marka: <input type="text" name="brand" value="<?php echo esc_attr($product->brand()); ?>"></p>
        <h3>🏷️ Kategorie produktu</h3>
<?php
// Pobierz aktualne kategorie z produktu
$current_category = $product->product_category();
if (!empty($current_category)) {
    list($main_id, $sub_id) = ae_get_category_id_by_full_path($current_category);
} else {
    $main_id = 0;
    $sub_id = 0;
}

// Pobierz główne kategorie
$main_categories = ae_get_main_categories();
?>
<p>
    <strong>GŁÓWNA KATEGORIA:</strong><br>
    <select name="main_category" id="main_category" style="width:400px;">
        <option value="0">-- Wybierz kategorię główną --</option>
        <?php foreach ($main_categories as $id => $name): ?>
            <option value="<?php echo $id; ?>" <?php selected($main_id, $id); ?>><?php echo esc_html($name); ?></option>
        <?php endforeach; ?>
    </select>
</p>

<p>
    <strong>PODKATEGORIA:</strong><br>
    <select name="subcategory" id="subcategory" style="width:400px;" <?php echo empty($main_id) ? 'disabled' : ''; ?>>
        <option value="0">-- Najpierw wybierz kategorię główną --</option>
        <?php if ($main_id > 0): ?>
            <?php 
            $subcategories = ae_get_subcategories($main_id);
            foreach ($subcategories as $id => $name): ?>
                <option value="<?php echo $id; ?>" <?php selected($sub_id, $id); ?>><?php echo esc_html($name); ?></option>
            <?php endforeach; ?>
        <?php endif; ?>
    </select>
</p>

<p>
    <small>Wyświetlana kategoria: <span id="display_category"><?php echo esc_html($current_category); ?></span></small>
</p>

<input type="hidden" name="product_category" id="product_category" value="<?php echo esc_attr($current_category); ?>">

<script>
jQuery(function($) {
    // Obsługa zmiany kategorii głównej
    $('#main_category').on('change', function() {
        var mainId = $(this).val();
        var $subSelect = $('#subcategory');
        
        if (mainId == '0') {
            $subSelect.prop('disabled', true).html('<option value="0">-- Najpierw wybierz kategorię główną --</option>');
            updateCategoryDisplay();
            return;
        }
        
        // Pobierz podkategorie via AJAX
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ae_get_subcategories',
                nonce: '<?php echo wp_create_nonce("ae_categories_nonce"); ?>',
                parent_id: mainId
            },
            beforeSend: function() {
                $subSelect.html('<option value="0">Ładowanie...</option>').prop('disabled', false);
            },
            success: function(response) {
                if (response.success && response.data) {
                    var options = '<option value="0">-- Wybierz podkategorię --</option>';
                    $.each(response.data, function(id, name) {
                        options += '<option value="' + id + '">' + name + '</option>';
                    });
                    $subSelect.html(options);
                } else {
                    $subSelect.html('<option value="0">Brak podkategorii</option>');
                }
                updateCategoryDisplay();
            },
            error: function() {
                $subSelect.html('<option value="0">Błąd ładowania</option>');
                updateCategoryDisplay();
            }
        });
    });
    
    // Obsługa zmiany podkategorii
    $('#subcategory').on('change', updateCategoryDisplay);
    
    function updateCategoryDisplay() {
        var mainId = $('#main_category').val();
        var subId = $('#subcategory').val();
        var mainName = $('#main_category option:selected').text();
        var subName = $('#subcategory option:selected').text();
        
        var display = '';
        var hiddenValue = '';
        
        if (mainId == '0') {
            display = 'Nie wybrano';
            hiddenValue = '';
        } else if (subId == '0') {
            display = mainName;
            hiddenValue = mainName;
        } else {
            display = mainName + ' → ' + subName;
            hiddenValue = mainName + ' → ' + subName;
        }
        
        $('#display_category').text(display);
        $('#product_category').val(hiddenValue);
    }
});
</script>
        <p>Status produktu: <input type="text" name="product_status_type" value="<?php echo esc_attr($product->product_status_type()); ?>"></p>
        
        <!-- ZDJĘCIA I LINKI -->
        <h3>🖼️ Zdjęcia i linki</h3>
        <p>Główne zdjęcie: <input type="text" name="image_link" value="<?php echo esc_attr($product->image_link()); ?>" style="width:500px;"></p>
        <p>Zdjęcie białe tło: <input type="text" name="image_white" value="<?php echo esc_attr($product->image_white()); ?>" style="width:500px;"></p>
        <p>Link AliExpress: <input type="text" name="original_link" value="<?php echo esc_attr($product->original_link()); ?>" style="width:500px;"></p>
        
        <!-- DOSTAWA -->
        <h3>🚚 Dostawa</h3>
        <p>Kraj wysyłki: <input type="text" name="ship_from_country" value="<?php echo esc_attr($product->ship_from_country()); ?>"></p>
        <p>Koszty wysyłki: <input type="text" name="shipping_fees" value="<?php echo esc_attr($product->shipping_fees()); ?>"> PLN</p>
        <p>Min dni dostawy: <input type="text" name="min_delivery_days" value="<?php echo esc_attr($product->min_delivery_days()); ?>" size="5"></p>
        <p>Max dni dostawy: <input type="text" name="max_delivery_days" value="<?php echo esc_attr($product->max_delivery_days()); ?>" size="5"></p>
        
        <!-- DOSTĘPNOŚĆ I STAN -->
        <h3>📦 Dostępność i stan</h3>
        <p>Dostępny stock: <input type="text" name="sku_available_stock" value="<?php echo esc_attr($product->sku_available_stock()); ?>"> szt.</p>
        <p>Wymiary opakowania: 
            <input type="text" name="package_width" value="<?php echo esc_attr($product->package_width()); ?>" size="5"> × 
            <input type="text" name="package_height" value="<?php echo esc_attr($product->package_height()); ?>" size="5"> × 
            <input type="text" name="package_length" value="<?php echo esc_attr($product->package_length()); ?>" size="5"> cm
        </p>
        <p>Waga brutto: <input type="text" name="gross_weight" value="<?php echo esc_attr($product->gross_weight()); ?>"> kg</p>
        
        <!-- STATYSTYKI -->
        <h3>📈 Statystyki</h3>
        <p>Ocena produktu: <input type="text" name="product_score" value="<?php echo esc_attr($product->product_score()); ?>" size="5"> /5</p>
        <p>Liczba opinii: <input type="text" name="review_number" value="<?php echo esc_attr($product->review_number()); ?>" size="10"></p>
        <p>Sprzedane sztuki: <input type="text" name="sales_count" value="<?php echo esc_attr($product->sales_count()); ?>" size="10"></p>
        
        <!-- ATRYBUTY -->
        <h3>🏷️ Atrybuty produktu</h3>
        <?php
        $attributes = $product->attributes();
        if (!empty($attributes)) {
            echo '<table border="1" cellpadding="5" style="border-collapse: collapse;">';
            echo '<tr><th>Import?</th><th>Nazwa atrybutu</th><th>Wartość</th></tr>';
            
            foreach ($attributes as $index => $attr) {
                echo '<tr>';
                echo '<td><input type="checkbox" name="import_attr[]" value="' . $index . '" checked></td>';
                echo '<td><input type="text" name="attr_name_' . $index . '" value="' . esc_attr($attr['name']) . '" style="width:200px;"></td>';
                echo '<td><input type="text" name="attr_value_' . $index . '" value="' . esc_attr($attr['value']) . '" style="width:300px;"></td>';
                echo '</tr>';
            }
            
            echo '</table>';
        } else {
            echo '<p>Brak atrybutów</p>';
        }
        ?>
        
        <!-- OPIS - Z DOMYŚLNYM EDYTOREM WORDPRESSA -->
        <h3>📝 Opis produktu (automatycznie wyczyszczony)</h3>
        
        <?php
        // Używamy domyślnego edytora WordPressa
        wp_editor(
            $clean_description,          // Treść
            'ae_product_description',    // ID edytora
            array(
                'textarea_name' => 'detail',  // Nazwa pola formularza
                'textarea_rows' => 15,        // Wysokość
                'teeny' => false,             // Pełny edytor (nie uproszczony)
                'media_buttons' => false,     // Bez przycisku dodawania mediów
                'tinymce' => true,            // Włącz tryb wizualny
                'quicktags' => true           // Włącz szybkie tagi
            )
        );
        ?>
        
        <!-- DODAJ PRZYCISK AI TUTAJ --> 
        <?php echo ae_add_ai_button_to_form($product->product_id); ?>
        
        <!-- PRZYCISKI -->
        <h3>🎯 Akcje</h3>
        <p>
            <button type="submit" name="save_action" value="save" class="button">💾 Zapisz dane</button>
            <button type="submit" name="save_action" value="import" class="button button-primary">🚀 Importuj do WooCommerce</button>
        </p>
    </form>
    <?php
    
    // Sprawdzamy czy formularz został wysłany
    if (isset($_POST['ae_edit_nonce']) && wp_verify_nonce($_POST['ae_edit_nonce'], 'ae_edit_product')) {
        ae_process_edit_form();
    }
}

function ae_process_edit_form() {
    $saved_data = ae_save_edited_data($_POST);
    
    echo '<h3>✅ Dane zapisane pomyślnie!</h3>';
    echo '<p>ID produktu: ' . esc_html($saved_data['product_id']) . '</p>';
    echo '<p>SKU: ' . esc_html($saved_data['sku_id']) . '</p>';
    
    echo '<h4>Zapisane wartości:</h4>';
    echo '<ul>';
    echo '<li>Tytuł: ' . esc_html($saved_data['edited_data']['title']) . '</li>';
    echo '<li>Cena: ' . esc_html($saved_data['edited_data']['sale_price_with_tax']) . ' PLN</li>';
    echo '<li>Marka: ' . esc_html($saved_data['edited_data']['brand']) . '</li>';
    echo '<li>Atrybuty: ' . count($saved_data['edited_data']['attributes']) . ' zapisanych</li>';
    echo '</ul>';
    
    if ($_POST['save_action'] === 'import') {
        echo '<p><strong>⚠️ Funkcja importu do WooCommerce będzie dostępna wkrótce</strong></p>';
    }
    
    echo '<p><a href="' . admin_url('admin.php?page=aliexpress-importer') . '" class="button">🔄 Wróć do formularza</a></p>';
}