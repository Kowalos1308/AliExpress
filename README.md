# AliExpress Woo Product Moderator

Minimalna wtyczka WordPress/WooCommerce do:

1. Dodawania produktu z AliExpress po `Product ID` i `SKU`.
2. Moderacji produktów dodanych przez panel.
3. Edycji produktu (własny formularz) + redakcja AI przez Groq.

## Konfiguracja

Dodaj stałe np. w `wp-config.php`:

```php
define('ALI_AFFILIATE_APP_KEY', '...');
define('ALI_AFFILATE_APP_SECRET', '...');

define('ALI_APP_KEY', '...');
define('ALI_APP_SECRET', '...');
define('ALI_SESSION', '...');

define('GROQ_API_KEY', '...');
```

## Działanie

- API #1: `aliexpress.affiliate.product.sku.detail.get`
- API #2: `aliexpress.ds.product.get`
- Nowe produkty są tworzone jako `product` z typem `external` i statusem `draft`.
- W meta zapisywane są dane moderacyjne i surowe atrybuty AliExpress.
- Ekran moderacji ma podstawowe filtry i akcję odświeżenia.

## Instalacja

1. Umieść plik `aliexpress-woo-moderator.php` w katalogu wtyczek WordPress.
2. Aktywuj wtyczkę.
3. Wejdź w panel admina -> `AliExpress`.
