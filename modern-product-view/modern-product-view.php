<?php
/**
 * Plugin Name: Modern Product View for WooCommerce
 * Description: Dodaje nowoczesny wygląd strony produktu WooCommerce (single product) bez nadpisywania szablonów motywu.
 * Version: 1.0.0
 * Author: Codex
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: modern-product-view
 */

if (!defined('ABSPATH')) {
    exit;
}

final class MPV_Modern_Product_View
{
    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('body_class', [$this, 'add_body_class']);
        add_action('woocommerce_before_single_product_summary', [$this, 'open_gallery_wrap'], 1);
        add_action('woocommerce_before_single_product_summary', [$this, 'close_gallery_wrap'], 50);
        add_action('woocommerce_single_product_summary', [$this, 'open_summary_wrap'], 4);
        add_action('woocommerce_single_product_summary', [$this, 'close_summary_wrap'], 45);
    }

    public function enqueue_assets(): void
    {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        wp_enqueue_style(
            'mpv-modern-product-style',
            plugin_dir_url(__FILE__) . 'assets/modern-product.css',
            [],
            '1.0.0'
        );
    }

    public function add_body_class(array $classes): array
    {
        if (function_exists('is_product') && is_product()) {
            $classes[] = 'mpv-modern-product-page';
        }

        return $classes;
    }

    public function open_gallery_wrap(): void
    {
        echo '<div class="mpv-gallery-wrap">';
    }

    public function close_gallery_wrap(): void
    {
        echo '</div>';
    }

    public function open_summary_wrap(): void
    {
        echo '<div class="mpv-summary-wrap">';
    }

    public function close_summary_wrap(): void
    {
        echo '</div>';
    }
}

new MPV_Modern_Product_View();
