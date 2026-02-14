<?php
/**
 * Plugin Name: AliExpress DS Importer
 */

if (!defined('ABSPATH')) exit;

define('ALI_APP_KEY', '525642');
define('ALI_APP_SECRET', 'EwpDUCBrgnaiKsMmiUuGsD7oi2DSfFuI');
define('ALI_SESSION', '50000801932gWybqpeBDbP5KwxDogKIWGUvBk5EOSitp1ef8abe9YwPNwyfVctfmPx01');
define('ALI_AFFILIATE_APP_KEY', '525638');
define('ALI_AFFILATE_APP_SECRET', 'xBCxCzmFQm4boCZSiZimIXXeH2KnLE5K');

// Dołącz pliki
require_once plugin_dir_path(__FILE__) . 'functions.php';
require_once plugin_dir_path(__FILE__) . 'admin.php';
require_once plugin_dir_path(__FILE__) . 'form.php';

// Menu admina
add_action('admin_menu', function() {
    add_menu_page('AliExpress Importer', 'AliExpress Importer', 'manage_options', 'aliexpress-importer', 'ae_admin_page');
});