<?php
/**
 * Plik z integracją AI dla formularza edycji produktów AliExpress - UPROSZCZONA WERSJA
 */

// ------------------------------------------------------------------
// FUNKCJE AI
// ------------------------------------------------------------------

// Główna funkcja AI - poprawia istniejące dane
function ae_ai_improve_product($product_data, $store_data) {
    // Budujemy prompt z istniejących danych
    $prompt = ae_build_ai_prompt($product_data, $store_data);
    
    // Wysyłamy do Groq
    $response = ae_send_to_groq($prompt);
    
    // Parsujemy odpowiedź
    return ae_parse_ai_response($response, $product_data);
}

// Pobierz kategorie z hierarchią
function ae_get_categories_hierarchical() {
    $categories = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC'
    ]);
    
    $result = [];
    foreach ($categories as $cat) {
        $path = '';
        if ($cat->parent) {
            $parent = get_term($cat->parent, 'product_cat');
            if ($parent && !is_wp_error($parent)) {
                $path = $parent->name . ' → ';
            }
        }
        $result[] = $path . $cat->name;
    }
    
    return $result;
}

// Budowanie promptu dla AI - POPRAWIONE DLA KATEGORII
function ae_build_ai_prompt($product_data, $store_data) {
    $attributes_text = "";
    if (!empty($product_data['attributes'])) {
        foreach ($product_data['attributes'] as $attr) {
            $attributes_text .= "- {$attr['name']}: {$attr['value']}\n";
        }
    } else {
        $attributes_text = "Brak atrybutów\n";
    }
    
    $full_description = strip_tags($product_data['description_preview']);
    $hierarchical_categories = ae_get_categories_hierarchical();
    
    $prompt = "Jesteś TOP 1 ekspertem SEO i copywriterem w Polsce specjalizującym się w akcesoriach samochodowych z AliExpress.
Twoje zadanie: ZOPTYMALIZUJ dane produktu pod polskie SEO.

PRAWIDŁOWY FORMAT ODPOWIEDZI (NIC WIĘCEJ!):
1. POPRAWIONY TYTUŁ:
[TUTAJ WPISZ TYTUŁ]

2. WYBRANE KATEGORIE (2 POZIOMY):
GŁÓWNA: [TUTAJ WPISZ DOKŁADNĄ NAZWĘ KATEGORII GŁÓWNEJ Z LISTY]
PODKATEGORIA: [TUTAJ WPISZ DOKŁADNĄ NAZWĘ PODKATEGORII Z LISTY]

3. POPRAWIONE ATRYBUTY:
NAZWA_ATRYBUTU: Wartość
NAZWA_ATRYBUTU2: Wartość2

4. ULEPSZONY OPIS:
[TUTAJ WPISZ OPIS W HTML]

==================================================
WAŻNE: ANALIZA KATEGORII Z TWOJEGO SKLEPU
==================================================

Masz dostęp do RZECZYWISTYCH KATEGORII z tego sklepu WordPress. 
ANALIZUJ je uważnie, aby zrozumieć JAKIE PRODUKTY są tutaj sprzedawane.

LISTA KATEGORII Z TWOJEGO SKLEPU (→ oznacza podkategorię):
" . implode("\n", $hierarchical_categories) . "

ANALIZA NA PODSTAWIE KATEGORII:
1. Przestudiuj listę kategorii - zobacz jakie produkty są sprzedawane
2. Zobacz jakie słowa kluczowe są używane w nazwach kategorii
3. Dopasuj produkt do istniejących kategorii
4. Użyj TERMINOLOGII Z KATEGORII w tytule

PRZYKŁAD JAK ANALIZOWAĆ:
Jeśli w kategoriach masz: 'Diagnostyka → Interfejsy diagnostyczne'
To produkt OBD/ELM327 powinien mieć w tytule: 'Interfejs diagnostyczny'

Jeśli w kategoriach masz: 'Multimedia → CarPlay/Android Auto'
To produkt do CarPlay powinien mieć: 'Moduł CarPlay'

Jeśli w kategoriach masz: 'Wyposażenie wnętrza → Dywaniki'
To produkt powinien mieć: 'Dywaniki samochodowe'

==================================================
FORMAT TYTUŁU
==================================================

Struktura: [Rodzaj produktu WEDŁUG KATEGORII] [Marka] [Model] [Specyfikacja]

Jak określić RODZAJ PRODUKTU:
1. Przeanalizuj opis produktu z AliExpress
2. Spójrz na dostępne kategorie w sklepie
3. Znajdź NAJLEPSZE DOPASOWANIE
4. Użyj TERMINU Z KATEGORII

PRZYKŁADY POPRAWNYCH TYTUŁÓW (na podstawie kategorii):
Jeśli kategorie mają: 'Diagnostyka → Interfejsy diagnostyczne'
PRZED: 'Vgate iCar Pro elm327 V2.3 OBD 2 OBD2 Narzędzia diagnostyczne'
PO: 'Interfejs diagnostyczny Vgate iCar Pro V2.3 OBD2'

Jeśli kategorie mają: 'Multimedia → Moduły CarPlay'
PRZED: 'Carplay Android Auto Wireless dla Mercedes'
PO: 'Moduł CarPlay Android Auto bezprzewodowy Mercedes'

Jeśli kategorie mają: 'Wyposażenie wnętrza → Dywaniki'
PRZED: 'Dywaniki samochodowe welurowe dla BMW X5'
PO: 'Dywaniki welurowe BMW X5 X6 X7'

ZASADY TYTUŁU:
- MAX 70 znaków
- BEZ 'AliExpress' i 'najtaniej' w tytule (tylko w opisie!)
- Tłumacz angielskie terminy na polskie
- Usuń powtórzenia
- Zachowaj tylko kluczowe słowa
- UŻYWAJ TERMINOLOGII Z TWOICH KATEGORII

==================================================
DANE WEJŚCIOWE
==================================================

TYTUŁ ORYGINALNY: {$product_data['title']}

KATEGORIA ORYGINALNA: {$product_data['category']}

ATRYBUTY DO POPRAWY:
{$attributes_text}

PEŁNY OPIS Z AliExpress (użyj TEGO do stworzenia nowego opisu):
{$full_description}

==================================================
INSTRUKCJE KROK PO KROKU
==================================================

KROK 1: PRZECZYTAJ LISTĘ KATEGORII
- Zobacz jakie kategorie masz w sklepie
- Zrozum strukturę i terminologię

KROK 2: OKREŚL RODZAJ PRODUKTU
- Na podstawie opisu z AliExpress
- DOPASUJ do istniejących kategorii
- Użyj terminu Z KATEGORII (nie wymyślaj nowego)

KROK 3: STWÓRZ TYTUŁ
- Format: [Rodzaj z kategorii] [Marka] [Model] [Specyfikacja]
- Max 70 znaków
- Naturalny polski język

KROK 4: WYBIERZ KATEGORIE
- Znajdź najlepsze dopasowanie w liście
- Wybierz GŁÓWNĄ i PODKATEGORIĘ
- UŻYJ DOKŁADNEJ NAZWY z listy

KROK 5: POPRAW ATRYBUTY
- Tłumacz chińskie/angielskie nazwy na polski
- Używaj naturalnego języka

KROK 6: NAPISZ OPIS
- HTML, minimum 500 słów
- Użyj struktury poniżej
- SEO: 'AliExpress' 3x, 'najtaniej' 2x

STRUKTURA OPISU:
<h2>[Rodzaj produktu z kategorii] [Marka] - opinie i test</h2>
<p>2-3 akapity wprowadzenia.</p>

<h3>🔧 Specyfikacja techniczna</h3>
<p>Weź wszystkie dane techniczne z oryginalnego opisu.</p>

<h3>⭐ Zalety i korzyści</h3>
<p>Wypunktuj dlaczego warto wybrać akurat ten produkt.</p>

<h3>🚗 Zastosowanie i kompatybilność</h3>
<p>Dla jakich aut/modeli/urządzeń produkt jest przeznaczony.</p>

<h3>📦 Zakup z AliExpress - co warto wiedzieć?</h3>
<p>Informacje o dostawie, gwarancji, zwrotach przy zakupie z AliExpress.</p>

PAMIĘTAJ: Produkt jest z AliExpress - w opisie podkreślaj aspekty: cena vs jakość, czas dostawy, opcje zwrotu, dostępność.

NIE DODAWAJ żadnych 'wezwań do działania', przycisków kup teraz, itp.
Opis ma być czysto informacyjny i SEO.";

    return $prompt;
}

// Wysyłanie do Groq API
function ae_send_to_groq($prompt) {
    $api_key = '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';
    
    if (empty($api_key)) {
        return ['error' => 'Brak klucza API Groq.'];
    }
    
    $url = 'https://api.groq.com/openai/v1/chat/completions';
    
    $body = [
        'model' => 'llama-3.3-70b-versatile',
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Jesteś najlepszym polskim copywriterem SEO. Odpowiadasz TYLKO w podanym formacie, bez dodatkowych komentarzy.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 0.8,
        'max_tokens' => 3000,
        'top_p' => 0.9
    ];
    
    $response = wp_remote_post($url, [
        'timeout' => 45,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($body)
    ]);
    
    if (is_wp_error($response)) {
        return ['error' => 'Błąd połączenia: ' . $response->get_error_message()];
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if ($response_code !== 200) {
        $error_msg = isset($data['error']['message']) ? $data['error']['message'] : 'Nieznany błąd';
        return ['error' => 'Błąd API (' . $response_code . '): ' . $error_msg];
    }
    
    if (!isset($data['choices'][0]['message']['content'])) {
        return ['error' => 'Nieprawidłowa odpowiedź z API'];
    }
    
    return $data['choices'][0]['message']['content'];
}

// Parsowanie odpowiedzi AI - POPRAWIONE DLA KATEGORII
function ae_parse_ai_response($ai_response, $original_data) {
    if (is_array($ai_response) && isset($ai_response['error'])) {
        return $ai_response;
    }
    
    $result = [
        'title' => '',
        'category' => '',
        'main_category' => '',
        'sub_category' => '',
        'attributes' => [],
        'description' => ''
    ];
    
    if (empty($ai_response)) {
        return ['error' => 'Odpowiedź AI jest pusta'];
    }
    
    // DEBUG
    error_log('=== AI RESPONSE START ===');
    error_log(substr($ai_response, 0, 500));
    error_log('=== AI RESPONSE END ===');
    
    $ai_response = str_replace(['```html', '```'], '', $ai_response);
    
    $lines = explode("\n", $ai_response);
    $current_section = '';
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        if (empty($line)) continue;
        
        // 1. TYTUŁ - szukamy "POPRAWIONY TYTUŁ:" lub "1. POPRAWIONY TYTUŁ:"
if (strpos($line, 'POPRAWIONY TYTUŁ') !== false) {
    $current_section = 'title';
    // Usuń prefix i dwukropek
    $title_line = preg_replace('/^(1\.\s*)?POPRAWIONY\s+TYTUŁ:\s*/i', '', $line);
    if (!empty($title_line) && $title_line !== $line) {
        $result['title'] = trim($title_line);
    }
    continue;
}
        
            // Dodatkowe parsowanie jeśli tytuł jest w następnej linii
if ($current_section === 'title' && empty($result['title']) && !empty(trim($line))) {
    $result['title'] = trim($line);
    $current_section = ''; // Reset sekcji po pobraniu tytułu
    continue;
}
        // 2. KATEGORIE - ULEPSZONE PARSOWANIE
        if (preg_match('/^(2\.)?\s*(WYBRANE KATEGORIE|GŁÓWNA:|PODKATEGORIA:)/i', $line)) {
            $current_section = 'category';
            
            if (preg_match('/GŁÓWNA:\s*(.+)/i', $line, $matches)) {
                $result['main_category'] = trim($matches[1]);
                error_log('Znaleziono główną kategorię: ' . $result['main_category']);
            }
            
            if (preg_match('/PODKATEGORIA:\s*(.+)/i', $line, $matches)) {
                $result['sub_category'] = trim($matches[1]);
                error_log('Znaleziono podkategorię: ' . $result['sub_category']);
            }
            continue;
        }
        
        // Jeśli jesteśmy w sekcji kategorii i linia zawiera "GŁÓWNA:" lub "PODKATEGORIA:"
        if ($current_section === 'category') {
            if (preg_match('/GŁÓWNA:\s*(.+)/i', $line, $matches)) {
                $result['main_category'] = trim($matches[1]);
                error_log('Znaleziono główną kategorię (w sekcji): ' . $result['main_category']);
            }
            
            if (preg_match('/PODKATEGORIA:\s*(.+)/i', $line, $matches)) {
                $result['sub_category'] = trim($matches[1]);
                error_log('Znaleziono podkategorię (w sekcji): ' . $result['sub_category']);
            }
        }
        
        // 3. ATRYBUTY
        if (preg_match('/^(3\.)?\s*POPRAWIONE ATRYBUTY:/i', $line)) {
            $current_section = 'attributes';
            continue;
        }
        
        if ($current_section === 'attributes') {
            if (preg_match('/^(4\.)?\s*ULEPSZONY OPIS:/i', $line)) {
                $current_section = 'description';
                continue;
            }
            
            if (strpos($line, ':') !== false) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $name = trim($parts[0]);
                    $value = trim($parts[1]);
                    
                    if (!empty($name) && !empty($value)) {
                        $result['attributes'][] = [
                            'name' => $name,
                            'value' => $value
                        ];
                    }
                }
            }
            continue;
        }
        
        // 4. OPIS
        if (preg_match('/^(4\.)?\s*ULEPSZONY OPIS:/i', $line)) {
            $current_section = 'description';
            $desc = preg_replace('/^(4\.)?\s*ULEPSZONY OPIS:\s*/i', '', $line);
            if (!empty($desc) && $desc !== $line) {
                $result['description'] .= $desc . "\n";
            }
            continue;
        }
        
        if ($current_section === 'description') {
            $result['description'] .= $line . "\n";
        }
    }
    
    // Uzupełnij pełną kategorię jeśli mamy części
    if (!empty($result['main_category']) && !empty($result['sub_category'])) {
        $result['category'] = $result['main_category'] . ' → ' . $result['sub_category'];
    } elseif (!empty($result['main_category'])) {
        $result['category'] = $result['main_category'];
    } elseif (!empty($result['sub_category'])) {
        $result['category'] = $result['sub_category'];
    }
    
    // DEBUG wyników
    error_log('=== PARSED RESULT ===');
    error_log('Title: ' . $result['title']);
    error_log('Main category: ' . $result['main_category']);
    error_log('Sub category: ' . $result['sub_category']);
    error_log('Full category: ' . $result['category']);
    error_log('Attributes count: ' . count($result['attributes']));
    error_log('Description length: ' . strlen($result['description']));
    error_log('=== END PARSED ===');
    
    // Fallback
    if (empty($result['title'])) {
        $result['title'] = $original_data['title'];
    }
    if (empty($result['category']) && empty($result['main_category'])) {
        $result['category'] = $original_data['category'];
    }
    if (empty($result['attributes'])) {
        $result['attributes'] = $original_data['attributes'];
    }
    if (empty($result['description'])) {
        $result['description'] = $original_data['description_preview'];
    }
    
    return $result;
}

// ------------------------------------------------------------------
// AJAX HANDLER - wywołanie AI
// ------------------------------------------------------------------

add_action('wp_ajax_ae_generate_ai', 'ae_ajax_generate_ai');

function ae_ajax_generate_ai() {
    check_ajax_referer('ae_edit_product', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Brak uprawnień');
    }
    
    $product_id = intval($_POST['product_id'] ?? 0);
    $title = sanitize_text_field($_POST['title'] ?? '');
    $category = sanitize_text_field($_POST['category'] ?? '');
    $description = wp_kses_post($_POST['description'] ?? '');
    
    $attributes = [];
    if (isset($_POST['attributes']) && is_array($_POST['attributes'])) {
        foreach ($_POST['attributes'] as $attr) {
            if (isset($attr['name'], $attr['value'])) {
                $attributes[] = [
                    'name' => sanitize_text_field($attr['name']),
                    'value' => sanitize_text_field($attr['value'])
                ];
            }
        }
    }
    
    $product_data = [
        'title' => $title,
        'category' => $category,
        'attributes' => $attributes,
        'description_preview' => strip_tags($description)
    ];
    
    $store_data = [
        'categories' => ae_get_categories_hierarchical()
    ];
    
    $ai_result = ae_ai_improve_product($product_data, $store_data);
    
    if (isset($ai_result['error'])) {
        wp_send_json_error($ai_result['error']);
    }
    
    wp_send_json_success($ai_result);
}

// ------------------------------------------------------------------
// AJAX HANDLER - pobieranie podkategorii
// ------------------------------------------------------------------

add_action('wp_ajax_ae_get_subcategories', 'ae_ajax_get_subcategories');

function ae_ajax_get_subcategories() {
    check_ajax_referer('ae_categories_nonce', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Brak uprawnień');
    }
    
    $parent_id = intval($_POST['parent_id'] ?? 0);
    
    if ($parent_id <= 0) {
        wp_send_json_error('Nieprawidłowy ID kategorii');
    }
    
    // Funkcja ae_get_subcategories powinna być w form-functions.php
    if (!function_exists('ae_get_subcategories')) {
        require_once plugin_dir_path(__FILE__) . 'form-functions.php';
    }
    
    $subcategories = ae_get_subcategories($parent_id);
    
    wp_send_json_success($subcategories);
}

// ------------------------------------------------------------------
// PRZYCISK DO FORMULARZA - POPRAWIONA WERSJA
// ------------------------------------------------------------------

function ae_add_ai_button_to_form($product_id) {
    ob_start();
    ?>
    <div style="margin: 20px 0;">
        <button type="button" id="ae-ai-generate-btn" class="button button-primary">
            Generuj z AI
        </button>
        <span id="ae-ai-spinner" class="spinner" style="display: none;"></span>
        <div id="ae-ai-status" style="margin: 10px 0; display: none;"></div>
    </div>
    
    <script>
    jQuery(function($) {
        // Globalna funkcja aktualizacji wyświetlania
        window.updateCategoryDisplay = function() {
            var mainName = $('#main_category option:selected').text();
            var subName = $('#subcategory option:selected').text();
            
            var display = '';
            var hiddenValue = '';
            
            if ($('#main_category').val() == '0' || $('#main_category').val() == '') {
                display = 'Nie wybrano';
                hiddenValue = '';
            } else if ($('#subcategory').val() == '0' || $('#subcategory').val() == '') {
                display = mainName;
                hiddenValue = mainName;
            } else {
                display = mainName + ' → ' + subName;
                hiddenValue = mainName + ' → ' + subName;
            }
            
            $('#display_category').text(display);
            $('#product_category').val(hiddenValue);
            console.log('Kategoria zaktualizowana:', hiddenValue);
        };
        
        // Funkcja do wybierania kategorii - PROSTSZA WERSJA
        function selectCategoryFromAI(mainCategoryName, subCategoryName) {
            console.log('AI zasugerowało kategorie:', mainCategoryName, subCategoryName);
            
            // 1. Znajdź i wybierz główną kategorię
            if (mainCategoryName && mainCategoryName !== '') {
                var mainSelected = false;
                $('#main_category option').each(function() {
                    if ($(this).text().trim() === mainCategoryName.trim()) {
                        $(this).prop('selected', true);
                        console.log('Wybrano główną kategorię:', mainCategoryName);
                        mainSelected = true;
                        return false;
                    }
                });
                
                if (mainSelected) {
                    // Wywołaj change event aby załadować podkategorie
                    $('#main_category').trigger('change');
                    
                    // Po 1 sekundzie spróbuj wybrać podkategorię
                    setTimeout(function() {
                        if (subCategoryName && subCategoryName !== '') {
                            selectSubcategory(subCategoryName);
                        } else {
                            updateCategoryDisplay();
                        }
                    }, 1000);
                } else {
                    console.log('Nie znaleziono głównej kategorii:', mainCategoryName);
                }
            }
        }
        
        // Funkcja do wybierania podkategorii
        function selectSubcategory(subCategoryName) {
            console.log('Próbuję wybrać podkategorię:', subCategoryName);
            
            if (!subCategoryName || subCategoryName === '') {
                updateCategoryDisplay();
                return;
            }
            
            var found = false;
            var attempts = 0;
            var maxAttempts = 10;
            
            function tryToFindSubcategory() {
                attempts++;
                console.log('Próba #' + attempts + ' znalezienia podkategorii');
                
                $('#subcategory option').each(function() {
                    var optionText = $(this).text().trim();
                    if (optionText === subCategoryName.trim()) {
                        $(this).prop('selected', true);
                        found = true;
                        console.log('Znaleziono i wybrano podkategorię:', subCategoryName);
                        updateCategoryDisplay();
                        return false;
                    }
                });
                
                if (!found && attempts < maxAttempts) {
                    setTimeout(tryToFindSubcategory, 300);
                } else if (!found) {
                    console.log('Nie znaleziono podkategorii po', maxAttempts, 'próbach:', subCategoryName);
                    updateCategoryDisplay();
                }
            }
            
            // Zacznij szukać
            tryToFindSubcategory();
        }
        
        $('#ae-ai-generate-btn').on('click', function() {
            const $btn = $(this);
            const $spinner = $('#ae-ai-spinner');
            const $status = $('#ae-ai-status');
            
            const title = $('input[name="title"]').val().trim();
            if (!title) {
                alert('Wypełnij tytuł produktu');
                return;
            }
            
            $spinner.show().addClass('is-active');
            $status.hide().html('');
            $btn.prop('disabled', true).text('AI pracuje...');
            
            const formData = {
                product_id: <?php echo $product_id; ?>,
                title: title,
                category: $('#product_category').val(),
                description: ''
            };
            
            if (typeof tinymce !== 'undefined' && tinymce.get('ae_product_description')) {
                formData.description = tinymce.get('ae_product_description').getContent();
            } else {
                formData.description = $('#ae_product_description').val();
            }
            
            formData.attributes = [];
            $('input[name^="attr_name_"]').each(function() {
                const name = $(this).val();
                const nameParts = $(this).attr('name').split('_');
                const index = nameParts[nameParts.length - 1];
                const value = $('input[name="attr_value_' + index + '"]').val();
                
                if (name && value) {
                    formData.attributes.push({
                        name: name,
                        value: value
                    });
                }
            });
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ae_generate_ai',
                    nonce: '<?php echo wp_create_nonce("ae_edit_product"); ?>',
                    ...formData
                },
                success: function(response) {
                    $spinner.hide().removeClass('is-active');
                    $btn.prop('disabled', false).text('Generuj z AI');
                    
                    if (response.success && response.data) {
                        const data = response.data;
                        
                        console.log('Odpowiedź AI:', data);
                        
                        // 1. Tytuł
                        if (data.title) {
                            $('input[name="title"]').val(data.title);
                        }
                        
                        // 2. Kategorie - PROSTA IMPLEMENTACJA
                        let mainCategoryName = data.main_category || '';
                        let subCategoryName = data.sub_category || '';
                        
                        // Jeśli AI zwróciło w formacie "GŁÓWNA: XYZ"
                        if (!mainCategoryName && data.category) {
                            if (data.category.includes('GŁÓWNA:')) {
                                const mainMatch = data.category.match(/GŁÓWNA:\s*([^\n]+)/i);
                                if (mainMatch && mainMatch[1]) {
                                    mainCategoryName = mainMatch[1].trim();
                                }
                            }
                            if (data.category.includes('PODKATEGORIA:')) {
                                const subMatch = data.category.match(/PODKATEGORIA:\s*([^\n]+)/i);
                                if (subMatch && subMatch[1]) {
                                    subCategoryName = subMatch[1].trim();
                                }
                            }
                            
                            // Alternatywny format "Kategoria → Podkategoria"
                            if (!mainCategoryName && data.category.includes(' → ')) {
                                const parts = data.category.split(' → ');
                                mainCategoryName = parts[0].trim();
                                if (parts[1]) {
                                    subCategoryName = parts[1].trim();
                                }
                            }
                            
                            // Ostatecznie użyj całego tekstu jako głównej kategorii
                            if (!mainCategoryName) {
                                mainCategoryName = data.category.trim();
                            }
                        }
                        
                        console.log('Po parsowaniu:', mainCategoryName, subCategoryName);
                        
                        // Wybierz kategorie
                        if (mainCategoryName) {
                            selectCategoryFromAI(mainCategoryName, subCategoryName);
                        }
                        
                        // 3. Atrybuty
                        if (data.attributes && data.attributes.length > 0) {
                            // Najpierw wyczyść istniejące atrybuty
                            $('input[name^="attr_name_"]').val('');
                            $('input[name^="attr_value_"]').val('');
                            
                            // Wypełnij nowymi atrybutami
                            data.attributes.forEach(function(attr, index) {
                                const attrIndex = index + 1;
                                const $nameInput = $('input[name="attr_name_' + attrIndex + '"]');
                                const $valueInput = $('input[name="attr_value_' + attrIndex + '"]');
                                
                                if ($nameInput.length) {
                                    $nameInput.val(attr.name || '');
                                }
                                if ($valueInput.length) {
                                    $valueInput.val(attr.value || '');
                                }
                            });
                        }
                        
                        // 4. Opis
                        if (data.description) {
                            if (typeof tinymce !== 'undefined' && tinymce.get('ae_product_description')) {
                                tinymce.get('ae_product_description').setContent(data.description);
                            } else {
                                $('#ae_product_description').val(data.description);
                            }
                        }
                        
                        $status.html('<div style="background: #d4edda; color: #155724; padding: 10px; border: 1px solid #c3e6cb; border-radius: 4px;">' +
                                   '<strong>✅ AI wygenerowało dane!</strong><br>' +
                                   '- Tytuł: ' + (data.title ? '✓' : '✗') + '<br>' +
                                   '- Kategorie: ' + (mainCategoryName ? '✓' : '✗') + '<br>' +
                                   '- Atrybuty: ' + (data.attributes ? data.attributes.length + ' ✓' : '✗') + '<br>' +
                                   '- Opis: ' + (data.description ? '✓' : '✗') +
                                   '</div>').show();
                        
                        // Przewiń do statusu
                        $('html, body').animate({
                            scrollTop: $status.offset().top - 100
                        }, 500);
                        
                    } else {
                        const errorMsg = response.data || 'Nieznany błąd';
                        $status.html('<div style="background: #f8d7da; color: #721c24; padding: 10px; border: 1px solid #f5c6cb; border-radius: 4px;">' +
                                   '<strong>❌ Błąd AI</strong><br>' + errorMsg +
                                   '</div>').show();
                    }
                },
                error: function(xhr, status, error) {
                    $spinner.hide().removeClass('is-active');
                    $btn.prop('disabled', false).text('Generuj z AI');
                    $status.html('<div style="background: #f8d7da; color: #721c24; padding: 10px; border: 1px solid #f5c6cb; border-radius: 4px;">' +
                               '<strong>❌ Błąd połączenia</strong><br>Serwer AI nie odpowiada.' +
                               '</div>').show();
                    console.error('AI AJAX Error:', error);
                },
                timeout: 45000
            });
        });
        
        // Upewnij się, że funkcja updateCategoryDisplay jest dostępna globalnie
        if (typeof window.updateCategoryDisplay !== 'function') {
            window.updateCategoryDisplay = updateCategoryDisplay;
        }
    });
    </script>
    <?php
    
    return ob_get_clean();
}