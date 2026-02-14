<?php
/**
 * Plugin Name: AliExpress Woo Moderator
 * Description: Import produktów AliExpress do WooCommerce z moderacją i edycją AI.
 * Version: 1.0.0
 * Author: Codex
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ALI_AFFILIATE_APP_KEY')) {
    define('ALI_AFFILIATE_APP_KEY', '525638');
}
if (!defined('ALI_AFFILATE_APP_SECRET')) {
    define('ALI_AFFILATE_APP_SECRET', 'xBCxCzmFQm4boCZSiZimIXXeH2KnLE5K');
}
if (!defined('ALI_APP_KEY')) {
    define('ALI_APP_KEY', '525642');
}
if (!defined('ALI_APP_SECRET')) {
    define('ALI_APP_SECRET', 'EwpDUCBrgnaiKsMmiUuGsD7oi2DSfFuI');
}
if (!defined('ALI_SESSION')) {
    define('ALI_SESSION', '50000801932gWybqpeBDbP5KwxDogKIWGUvBk5EOSitp1ef8abe9YwPNwyfVctfmPx01');
}
if (!defined('GROQ_API_KEY')) {
    define('GROQ_API_KEY', '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');
}

define('AE_WOO_MODERATOR_PATH', plugin_dir_path(__FILE__));


require_once AE_WOO_MODERATOR_PATH . 'includes/class-ae-api.php';
require_once AE_WOO_MODERATOR_PATH . 'includes/class-ae-product-manager.php';
require_once AE_WOO_MODERATOR_PATH . 'includes/class-ae-admin.php';

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return;
    }

    $api = new AE_API();
    $manager = new AE_Product_Manager($api);
    new AE_Admin($manager, $api);
});
