<?php
/**
 * Astra Child Theme functions and definitions
 *
 * @package Astra Child
 * @since   1.0.0
 */

/**
 * 1. Enqueue child-theme stylesheet,
 *    hide all default out-of-stock labels,
 *    and add a CSS pseudo-element for single-product "SOLD OUT"
 */
define( 'CHILD_THEME_ASTRA_CHILD_VERSION', '1.0.0' );
add_action( 'wp_enqueue_scripts', function() {
    // 1a) Enqueue your child-theme CSS
    wp_enqueue_style(
        'astra-child-css',
        get_stylesheet_directory_uri() . '/style.css',
        array( 'astra-theme-css' ),
        CHILD_THEME_ASTRA_CHILD_VERSION
    );

    // 1b) Inline CSS:
    //    - Hide any built-in out-of-stock spans/paragraphs
    //    - Make the single-product gallery wrapper positioned
    //    - Use ::before to inject "SOLD OUT" inside that wrapper
    wp_add_inline_style( 'astra-child-css', '
        /* –– Hide all native "out of stock" text/badges –– */
        .woocommerce span.stock.out-of-stock,
        .woocommerce p.stock.out-of-stock,
        .ast-woocommerce-loop-product__stock-status,
        [class*="stock"][class*="out-of-stock"] {
            display: none !important;
        }

        /* –– Ensure the gallery is a positioning context –– */
        .single-product .woocommerce-product-gallery {
            position: relative !important;
        }

        /* –– Inject a purple "SOLD OUT" inside the gallery on single pages –– */
        body.single-product .product.outofstock .woocommerce-product-gallery::before {
            content: "SOLD OUT";
            position: absolute;
            top: 10px;
            left: 10px;
            z-index: 9999;
            display: inline-block;
            padding: 6px 10px;
            background-color: #7A72B2;
            color: #ffffff;
            text-transform: uppercase;
            font-weight: 700;
            font-size: 12px;
            border-radius: 0;
            line-height: 1;
        }
        
        /* Force quantity input to 1 */
        input[name="quantity"] { 
            display: none !important; 
            value: 1 !important; 
        }
    ' );
}, 15 );

/**
 * 2. Force search to only return products (optional)
 */
add_filter( 'get_search_form', function( $form ) {
    return str_replace(
        '-1">',
        '-1"/><input type="hidden" name="post_type" value="product"/>',
        $form
    );
});

/**
 * 3. Remove the default "Sale!" badge
 */
remove_action( 'woocommerce_before_shop_loop_item_title',   'woocommerce_show_product_loop_sale_flash', 10 );
remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash',      10 );

/**
 * 6. Add SOLD OUT box after price on single product pages (but NOT in any loops)
 */
add_filter('woocommerce_get_price_html', 'add_sold_out_text_after_price', 10, 2);
function add_sold_out_text_after_price($price_html, $product) {
    // Only show on single product pages AND definitely not in any loops
    if (!$product->is_in_stock() && 
        is_product() && 
        !wc_get_loop_prop('is_shortcode') && 
        !woocommerce_product_loop_start(false) &&
        !is_shop() && 
        !is_product_category() && 
        !is_product_tag()) {
        
        return $price_html . '<div style="
            margin-top: 20px;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
            padding-top: 20px;
        "><div style="
            background-color: #000000;
            color: #ffffff;
            font-weight: 500;
            text-transform: uppercase;
            font-size: 14px;
            padding: 8px 15px;
            text-align: center;
            display: block;
            width: 100%;
            border: none;
            cursor: default;
            letter-spacing: 0.5px;
            line-height: 1.5;
        ">SOLD OUT</div></div>';
    }
    return $price_html;
}

/**
 * 7. FIXED CUSTOM CART INTEGRATION
 */

// Remove default Astra cart and other cart systems
add_action('init', function() {
    remove_action('astra_masthead_content', 'astra_header_cart_action', 11);
    remove_action('wp_footer', 'astra_cart_in_header_markup');
});

// Enqueue custom cart styles and scripts with proper dependencies
function custom_cart_enqueue_scripts() {
    // Ensure WooCommerce scripts are loaded
    if (function_exists('is_woocommerce')) {
        wp_enqueue_script('wc-add-to-cart');
        wp_enqueue_script('wc-cart-fragments');
        wp_enqueue_script('wc-add-to-cart-variation');
    }
    
    wp_enqueue_script('custom-cart-js', get_stylesheet_directory_uri() . '/js/custom-cart.js', array('jquery', 'wc-cart-fragments', 'wc-add-to-cart'), '1.0.5', true);
    wp_enqueue_style('custom-cart-css', get_stylesheet_directory_uri() . '/css/custom-cart.css', array(), '1.0.5');
    
    // Localize script for AJAX
    wp_localize_script('custom-cart-js', 'custom_cart_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('custom_cart_nonce'),
        'wc_ajax_url' => WC_AJAX::get_endpoint('%%endpoint%%')
    ));
    
    // Also localize WooCommerce add to cart params if not already done
    if (!wp_script_is('wc-add-to-cart-params', 'done')) {
        wp_localize_script('custom-cart-js', 'wc_add_to_cart_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'wc_ajax_url' => WC_AJAX::get_endpoint('%%endpoint%%'),
            'i18n_view_cart' => esc_attr__('View cart', 'woocommerce'),
            'cart_url' => apply_filters('woocommerce_add_to_cart_redirect', wc_get_cart_url(), null),
            'is_cart' => is_cart(),
            'cart_redirect_after_add' => get_option('woocommerce_cart_redirect_after_add')
        ));
    }
}
add_action('wp_enqueue_scripts', 'custom_cart_enqueue_scripts');

// Add cart trigger in header using JavaScript injection (more reliable)
add_action('wp_footer', function() {
    $cart_count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Find header and inject cart trigger
        var $header = $('.site-header, .ast-header, header, .main-header').first();
        
        if ($header.length && !$('#custom-cart-trigger').length) {
            $header.css('position', 'relative');
            
            var cartHtml = `
                <div class="custom-cart-wrapper" style="position: absolute; top: 50%; right: 20px; transform: translateY(-50%); z-index: 999;">
                    <div class="custom-cart-trigger" id="custom-cart-trigger">
                        <svg version="1.1" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 122.9 107.5" fill="currentColor">
                            <g><path d="M3.9,7.9C1.8,7.9,0,6.1,0,3.9C0,1.8,1.8,0,3.9,0h10.2c0.1,0,0.3,0,0.4,0c3.6,0.1,6.8,0.8,9.5,2.5c3,1.9,5.2,4.8,6.4,9.1 c0,0.1,0,0.2,0.1,0.3l1,4H119c2.2,0,3.9,1.8,3.9,3.9c0,0.4-0.1,0.8-0.2,1.2l-10.2,41.1c-0.4,1.8-2,3-3.8,3v0H44.7 c1.4,5.2,2.8,8,4.7,9.3c2.3,1.5,6.3,1.6,13,1.5h0.1v0h45.2c2.2,0,3.9,1.8,3.9,3.9c0,2.2-1.8,3.9-3.9,3.9H62.5v0 c-8.3,0.1-13.4-0.1-17.5-2.8c-4.2-2.8-6.4-7.6-8.6-16.3l0,0L23,13.9c0-0.1,0-0.1-0.1-0.2c-0.6-2.2-1.6-3.7-3-4.5 c-1.4-0.9-3.3-1.3-5.5-1.3c-0.1,0-0.2,0-0.3,0H3.9L3.9,7.9z M96,88.3c5.3,0,9.6,4.3,9.6,9.6c0,5.3-4.3,9.6-9.6,9.6 c-5.3,0-9.6-4.3-9.6-9.6C86.4,92.6,90.7,88.3,96,88.3L96,88.3z M53.9,88.3c5.3,0,9.6,4.3,9.6,9.6c0,5.3-4.3,9.6-9.6,9.6 c-5.3,0-9.6-4.3-9.6-9.6C44.3,92.6,48.6,88.3,53.9,88.3L53.9,88.3z M33.7,23.7l8.9,33.5h63.1l8.3-33.5H33.7L33.7,23.7z"/></g>
                        </svg>
                        <span class="cart-count" id="cart-count" style="<?php echo $cart_count > 0 ? 'display: flex;' : 'display: none;'; ?>"><?php echo $cart_count; ?></span>
                    </div>
                </div>
            `;
            
            $header.append(cartHtml);
        }
    });
    </script>
    <?php
}, 5);

// Add cart HTML to footer with improved structure
function add_custom_cart_html() {
    ?>
    <!-- Custom Cart Overlay -->
    <div id="custom-cart-overlay" class="custom-cart-overlay">
        <div id="custom-cart-panel" class="custom-cart-panel">
            <div class="cart-header">
                <h3>Cart <span id="header-cart-count-wrapper"></span></h3>
                <button class="close-cart" id="close-cart-btn">&times;</button>
            </div>
            <div class="custom-cart-content" id="custom-cart-content">
                <div class="rewards-bar">
                    <div class="rewards-message"><span class="price-bold">£450</span> away from free London Sneaks clothing</div>
                    <div class="rewards-progress">
                        <div class="rewards-line inactive"></div>
                        <div class="reward-item">
                            <div class="reward-circle active">£0</div>
                            <div class="reward-text">Earn Rewards</div>
                        </div>
                        <div class="reward-item">
                            <div class="reward-circle inactive">£450</div>
                            <div class="reward-text">Free Goodies</div>
                        </div>
                    </div>
                </div>
                <div class="empty-cart">
                    <p>Your cart is empty</p>
                    <button class="return-shop-btn" id="return-shop-btn">Return to Shop</button>
                </div>
            </div>
        </div>
    </div>
    <?php
}
add_action('wp_footer', 'add_custom_cart_html');

// AJAX handlers for cart operations - UPDATED WITH COUPON SUPPORT
function get_custom_cart_data() {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'custom_cart_nonce')) {
        wp_send_json_error('Invalid nonce');
        return;
    }
    
    $cart_items = array();
    $applied_coupons = array();
    
    if (WC()->cart) {
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $product_id = $cart_item['product_id'];
            $variation_id = $cart_item['variation_id'];
            
            // Get product image with comprehensive fallbacks and debugging
            $image_url = '';
            
            // Get the actual product for this cart item
            $cart_product = $cart_item['data'];
            $main_product_id = $cart_item['product_id'];
            $variation_id = $cart_item['variation_id'];
            
            // Method 1: Try variation image if it's a variation
            if ($variation_id > 0) {
                $variation_product = wc_get_product($variation_id);
                if ($variation_product) {
                    $var_image_id = $variation_product->get_image_id();
                    if ($var_image_id) {
                        $image_url = wp_get_attachment_image_url($var_image_id, 'woocommerce_thumbnail');
                    }
                }
            }
            
            // Method 2: Try current product image
            if (!$image_url && $cart_product) {
                $prod_image_id = $cart_product->get_image_id();
                if ($prod_image_id) {
                    $image_url = wp_get_attachment_image_url($prod_image_id, 'woocommerce_thumbnail');
                }
            }
            
            // Method 3: Try main product image
            if (!$image_url && $main_product_id) {
                $main_product = wc_get_product($main_product_id);
                if ($main_product) {
                    $main_image_id = $main_product->get_image_id();
                    if ($main_image_id) {
                        $image_url = wp_get_attachment_image_url($main_image_id, 'woocommerce_thumbnail');
                    }
                }
            }
            
            // Method 4: Try getting featured image from post
            if (!$image_url && $main_product_id) {
                $featured_image_id = get_post_thumbnail_id($main_product_id);
                if ($featured_image_id) {
                    $image_url = wp_get_attachment_image_url($featured_image_id, 'woocommerce_thumbnail');
                }
            }
            
            // Method 5: Try different image sizes
            if (!$image_url && $main_product_id) {
                $main_product = wc_get_product($main_product_id);
                if ($main_product && $main_product->get_image_id()) {
                    $sizes = array('thumbnail', 'medium', 'woocommerce_gallery_thumbnail');
                    foreach ($sizes as $size) {
                        $image_url = wp_get_attachment_image_url($main_product->get_image_id(), $size);
                        if ($image_url) break;
                    }
                }
            }
            
            // Final fallback
            if (!$image_url || empty($image_url)) {
                $image_url = 'https://via.placeholder.com/60x60/f3f4f6/9ca3af?text=No+Image';
            }
            
            // Get variation info
            $variation_text = '';
            if ($variation_id && !empty($cart_item['variation'])) {
                $variation_attributes = array();
                foreach ($cart_item['variation'] as $name => $value) {
                    $taxonomy = str_replace('attribute_', '', $name);
                    $term = get_term_by('slug', $value, $taxonomy);
                    $label = $term ? $term->name : $value;
                    $variation_attributes[] = $label;
                }
                $variation_text = implode(', ', $variation_attributes);
            }
            
            $cart_items[] = array(
                'key' => $cart_item_key,
                'name' => $product->get_name(),
                'price' => wc_price($product->get_price()),
                'image' => $image_url,
                'variation' => $variation_text,
                'quantity' => $cart_item['quantity'],
                'total' => wc_price($cart_item['line_total'])
            );
        }
        
        // Get applied coupons
        $coupons = WC()->cart->get_applied_coupons();
        foreach ($coupons as $coupon_code) {
            $coupon = new WC_Coupon($coupon_code);
            $discount_amount = WC()->cart->get_coupon_discount_amount($coupon_code, WC()->cart->display_cart_ex_tax);
            
            // If the above doesn't work, try getting the discount from totals
            if ($discount_amount == 0) {
                $discount_amount = WC()->cart->get_coupon_discount_amount($coupon_code);
            }
            
            // Final fallback - calculate from cart totals
            if ($discount_amount == 0) {
                $cart_subtotal = WC()->cart->get_subtotal();
                $cart_total = WC()->cart->get_total('');
                $total_discount = $cart_subtotal - floatval($cart_total);
                if ($total_discount > 0) {
                    $discount_amount = $total_discount;
                }
            }
            
            $applied_coupons[] = array(
                'code' => strtoupper($coupon_code),
                'discount' => $discount_amount,
                'discount_formatted' => wc_price($discount_amount)
            );
        }
    }
    
    wp_send_json_success(array(
        'cart_count' => WC()->cart ? WC()->cart->get_cart_contents_count() : 0,
        'cart_total' => WC()->cart ? WC()->cart->get_cart_total() : wc_price(0),
        'cart_subtotal' => WC()->cart ? WC()->cart->get_cart_subtotal() : wc_price(0),
        'cart_items' => $cart_items,
        'applied_coupons' => $applied_coupons,
        'checkout_url' => 'https://londonsneaksltd.co.uk/checkout/'
    ));
}
add_action('wp_ajax_get_custom_cart_data', 'get_custom_cart_data');
add_action('wp_ajax_nopriv_get_custom_cart_data', 'get_custom_cart_data');

function custom_remove_from_cart() {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'custom_cart_nonce')) {
        wp_send_json_error('Invalid nonce');
        return;
    }
    
    $cart_item_key = sanitize_text_field($_POST['cart_item_key']);
    
    if (WC()->cart && WC()->cart->remove_cart_item($cart_item_key)) {
        // Get updated cart data
        $cart_items = array();
        foreach (WC()->cart->get_cart() as $key => $item) {
            $product = $item['data'];
            $image_id = $product->get_image_id();
            $image_url = wp_get_attachment_image_url($image_id, 'thumbnail');
            if (!$image_url) {
                $image_url = wc_placeholder_img_src('thumbnail');
            }
            
            $variation_text = '';
            if ($item['variation_id'] && !empty($item['variation'])) {
                $variation_attributes = array();
                foreach ($item['variation'] as $name => $value) {
                    $taxonomy = str_replace('attribute_', '', $name);
                    $term = get_term_by('slug', $value, $taxonomy);
                    $label = $term ? $term->name : $value;
                    $variation_attributes[] = $label;
                }
                $variation_text = implode(', ', $variation_attributes);
            }
            
            $cart_items[] = array(
                'key' => $key,
                'name' => $product->get_name(),
                'price' => wc_price($product->get_price()),
                'image' => $image_url,
                'variation' => $variation_text,
                'quantity' => $item['quantity'],
                'total' => wc_price($item['line_total'])
            );
        }
        
        wp_send_json_success(array(
            'cart_count' => WC()->cart->get_cart_contents_count(),
            'cart_total' => WC()->cart->get_cart_total(),
            'cart_items' => $cart_items,
            'checkout_url' => wc_get_checkout_url()
        ));
    } else {
        wp_send_json_error('Failed to remove item');
    }
}
add_action('wp_ajax_custom_remove_from_cart', 'custom_remove_from_cart');
add_action('wp_ajax_nopriv_custom_remove_from_cart', 'custom_remove_from_cart');

function custom_ajax_add_to_cart() {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'custom_cart_nonce')) {
        wp_send_json_error('Invalid nonce');
        return;
    }
    
    $product_id = intval($_POST['product_id']);
    $variation_id = intval($_POST['variation_id'] ?? 0);
    $variation = $_POST['variation'] ?? array();
    
    // Clean variation data
    $cleaned_variation = array();
    if (is_array($variation)) {
        foreach ($variation as $key => $value) {
            if (!empty($value)) {
                $cleaned_variation[sanitize_text_field($key)] = sanitize_text_field($value);
            }
        }
    }
    
    // Let WooCommerce handle the add to cart logic naturally
    // It will automatically merge items and increase quantities
    if ($variation_id) {
        $result = WC()->cart->add_to_cart($product_id, 1, $variation_id, $cleaned_variation);
    } else {
        $result = WC()->cart->add_to_cart($product_id, 1);
    }
    
    if ($result !== false) {
        // Get updated cart data with improved image handling
        $cart_items = array();
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $product_id = $cart_item['product_id'];
            $variation_id = $cart_item['variation_id'];
            
            // Get product image with comprehensive fallbacks
            $image_url = '';
            
            // Try multiple image sources in order of preference
            $image_sources = array();
            
            // For variations, try variation image first
            if ($variation_id) {
                $variation_product = wc_get_product($variation_id);
                if ($variation_product && $variation_product->get_image_id()) {
                    $image_sources[] = $variation_product->get_image_id();
                }
            }
            
            // Add main product image
            if ($product->get_image_id()) {
                $image_sources[] = $product->get_image_id();
            }
            
            // Add parent product image if this is a variation
            if ($product_id && $product_id != $product->get_id()) {
                $parent_product = wc_get_product($product_id);
                if ($parent_product && $parent_product->get_image_id()) {
                    $image_sources[] = $parent_product->get_image_id();
                }
            }
            
            // Try each image source until we find a valid one
            foreach ($image_sources as $image_id) {
                $temp_url = wp_get_attachment_image_url($image_id, 'thumbnail');
                if ($temp_url && !empty($temp_url)) {
                    $image_url = $temp_url;
                    break;
                }
            }
            
            // Final fallback to WooCommerce placeholder
            if (!$image_url) {
                $image_url = wc_placeholder_img_src('thumbnail');
            }
            
            $variation_text = '';
            if ($cart_item['variation_id'] && !empty($cart_item['variation'])) {
                $variation_attributes = array();
                foreach ($cart_item['variation'] as $name => $value) {
                    $taxonomy = str_replace('attribute_', '', $name);
                    $term = get_term_by('slug', $value, $taxonomy);
                    $label = $term ? $term->name : $value;
                    $variation_attributes[] = $label;
                }
                $variation_text = implode(', ', $variation_attributes);
            }
            
            $cart_items[] = array(
                'key' => $cart_item_key,
                'name' => $product->get_name(),
                'price' => wc_price($product->get_price()),
                'image' => $image_url,
                'variation' => $variation_text,
                'quantity' => $cart_item['quantity'],
                'total' => wc_price($cart_item['line_total'])
            );
        }
        
        wp_send_json_success(array(
            'cart_count' => WC()->cart->get_cart_contents_count(),
            'cart_total' => WC()->cart->get_cart_total(),
            'cart_items' => $cart_items,
            'checkout_url' => wc_get_checkout_url(),
            'added' => true
        ));
    } else {
        wp_send_json_error('Failed to add to cart');
    }
}
add_action('wp_ajax_custom_ajax_add_to_cart', 'custom_ajax_add_to_cart');
add_action('wp_ajax_nopriv_custom_ajax_add_to_cart', 'custom_ajax_add_to_cart');

// Hide other cart systems that might interfere
add_action('wp_head', function() {
    ?>
    <style>
        /* Hide competing cart systems */
        .ast-site-header-cart,
        .xoo-wsc-basket,
        .ast-cart-menu-wrap,
        .woocommerce-mini-cart,
        .widget_shopping_cart,
        .cart-contents,
        .header-woo-cart {
            display: none !important;
        }
        
        /* WooCommerce notices */
        .woocommerce-message {
            display: none !important;
        }
    </style>
    <?php
});

// Prevent redirect to cart page after adding items
add_filter('woocommerce_add_to_cart_redirect', '__return_false');

// Hide WooCommerce notices
add_filter('wc_add_to_cart_message_html', '__return_empty_string');

/**
 * 8. UPDATED Custom Size Selector - Now Shows All Sizes with Sold Out Button
 */
function custom_size_selector_override() {
    if (!is_product()) return;
    
    global $product;
    if (!$product || !$product->is_type('variable')) return;
    
    // Get variation data
    $available_variations = $product->get_available_variations();
    $attributes = $product->get_variation_attributes();
    
    $sale_sizes = [];
    $out_of_stock_sizes = [];
    $available_sizes = [];
    
    foreach ($available_variations as $variation) {
        $variation_obj = wc_get_product($variation['variation_id']);
        $size = null;
        
        // Try different attribute formats
        foreach ($variation['attributes'] as $key => $value) {
            if (strpos($key, 'size') !== false) {
                $size = $value;
                break;
            }
        }
        
        if ($size) {
            $available_sizes[] = $size;
            
            if ($variation_obj->is_on_sale()) {
                $sale_sizes[] = $size;
            }
            if (!$variation_obj->is_in_stock()) {
                $out_of_stock_sizes[] = $size;
            }
        }
    }
    
    // Check if product is completely out of stock
    $is_product_out_of_stock = !$product->is_in_stock();
    ?>
    
    <style>
    /* Hide original variations and force custom selector styles */
    .variations { display: none !important; }
    
    /* Force black color for deleted prices - highest specificity */
    .woocommerce-variation-price del,
    .woocommerce-variation .price del,
    .single_variation_wrap del,
    .price del,
    del {
        color: #000000 !important;
        text-decoration: line-through !important;
    }
    
    .custom-size-selector {
        display: flex !important;
        flex-wrap: wrap !important;
        margin: 20px 0 !important;
        font-family: BRHendrix, Arial, sans-serif !important;
        width: 840px !important;
        line-height: 0 !important;
    }

    .custom-size-selector .size-box {
        width: 120px !important;
        height: 55px !important;
        border: 1px solid #D1D5DB !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-size: 14px !important;
        font-weight: bold !important;
        cursor: pointer !important;
        position: relative !important;
        box-sizing: border-box !important;
        margin: 0 !important;
        transition: all 0.2s ease !important;
        border-radius: 0 !important;
        padding: 0 !important;
        background: rgba(255, 255, 255, 0.6) !important;
        color: #6B7280 !important;
        overflow: hidden !important;
    }

    /* Remove gaps between boxes - merge borders */
    .custom-size-selector .size-box + .size-box {
        margin-left: -1px !important;
    }

    /* First box in each row */
    .custom-size-selector .size-box:nth-child(7n+1) {
        margin-left: 0 !important;
    }

    /* New row handling */
    .custom-size-selector .size-box:nth-child(n+8) {
        margin-top: -1px !important;
    }

    .custom-size-selector .size-box:nth-child(7n+1):not(:first-child) {
        margin-top: -1px !important;
    }

    /* Regular sizes */
    .custom-size-selector .size-box.regular {
        border-color: #D1D5DB !important;
        color: #6B7280 !important;
        background-color: rgba(255, 255, 255, 0.6) !important;
    }

    .custom-size-selector .size-box.regular.selected {
        box-shadow: inset 0 0 0 3px black !important;
    }

    /* Sale sizes - Complete red border with z-index */
    .custom-size-selector .size-box.sale {
        border: 1px solid #FAA8A8 !important;
        color: #B91E1C !important;
        background-color: rgba(255, 255, 255, 0.6) !important;
        z-index: 2 !important;
        position: relative !important;
    }

    .custom-size-selector .size-box.sale.selected {
        box-shadow: inset 0 0 0 3px #B91E1C !important;
    }

    /* Out of stock sizes - Shows crossed out but still clickable */
    .custom-size-selector .size-box.out-of-stock {
        border: 1px solid #D1D5DB !important;
        color: #6B7280 !important;
        background: #E5E7EB !important;
        cursor: pointer !important;
        position: relative !important;
        overflow: hidden !important;
    }

    .custom-size-selector .size-box.out-of-stock::before {
        content: '' !important;
        position: absolute !important;
        top: 0 !important;
        left: 0 !important;
        width: 141.42% !important;
        height: 1px !important;
        background: #D5D5D6 !important;
        transform-origin: 0 0 !important;
        transform: rotate(24.78deg) !important;
        pointer-events: none !important;
    }

    .custom-size-selector .size-box.out-of-stock::after {
        content: '' !important;
        position: absolute !important;
        top: 0 !important;
        right: 0 !important;
        width: 141.42% !important;
        height: 1px !important;
        background: #D5D5D6 !important;
        transform-origin: 100% 0 !important;
        transform: rotate(-24.78deg) !important;
        pointer-events: none !important;
    }

    .custom-size-selector .size-text {
        position: relative !important;
        z-index: 1 !important;
        font-size: 14px !important;
        font-weight: bold !important;
        line-height: 1 !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    /* SOLD OUT Button Styling */
    .single_add_to_cart_button.sold-out-button {
        background-color: #e60023 !important;
        color: #ffffff !important;
        border-color: #e60023 !important;
        cursor: not-allowed !important;
        opacity: 0.8 !important;
    }

    .single_add_to_cart_button.sold-out-button:hover {
        background-color: #cc001e !important;
        border-color: #cc001e !important;
    }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        var saleData = <?php echo json_encode($sale_sizes); ?>;
        var stockData = <?php echo json_encode($out_of_stock_sizes); ?>;
        var variationData = <?php echo json_encode($available_variations); ?>;
        var isProductOutOfStock = <?php echo $is_product_out_of_stock ? 'true' : 'false'; ?>;
        var currentSelectedSize = null;
        
        // Force quantity input to 1
        $('input[name="quantity"]').val(1);
        
        // Update add to cart button for sold out products
        function updateAddToCartButton() {
            var $button = $('.single_add_to_cart_button');
            if (isProductOutOfStock || (currentSelectedSize && stockData.includes(currentSelectedSize))) {
                $button.addClass('sold-out-button')
                       .text('SOLD OUT')
                       .prop('disabled', true);
            } else {
                $button.removeClass('sold-out-button')
                       .text('ADD TO BASKET')
                       .prop('disabled', false);
            }
        }
        
        setTimeout(function() {
            $('.variations').hide();
            
            var $target = $('.variations').length ? $('.variations') : 
                         $('label:contains("Size")').length ? $('label:contains("Size")') :
                         $('.single_add_to_cart_button').parent();
            
            if ($target.length) {
                var availableSizes = <?php echo json_encode($available_sizes); ?>;
                var html = '<div class="custom-size-selector">';
                
                availableSizes.forEach(function(size, index) {
                    var classes = ['size-box'];
                    var displaySize = size.replace(/uk|UK/gi, '').trim();
                    
                    if (stockData.includes(size)) {
                        classes.push('out-of-stock');
                    } else if (saleData.includes(size)) {
                        classes.push('sale');
                    } else {
                        classes.push('regular');
                    }
                    
                    html += '<div class="' + classes.join(' ') + '" data-size="' + size + '">';
                    html += '<span class="size-text">' + displaySize + '</span>';
                    html += '</div>';
                });
                
                html += '</div>';
                $target.after(html);
                
                // Auto-select first available size (or first size if all are out of stock)
                var firstAvailableSize = null;
                for (var i = 0; i < availableSizes.length; i++) {
                    if (!stockData.includes(availableSizes[i])) {
                        firstAvailableSize = availableSizes[i];
                        break;
                    }
                }
                
                // If no available sizes, select first size anyway for display
                if (!firstAvailableSize && availableSizes.length > 0) {
                    firstAvailableSize = availableSizes[0];
                }
                
                if (firstAvailableSize) {
                    var $firstBox = $('.size-box[data-size="' + firstAvailableSize + '"]');
                    if ($firstBox.length) {
                        currentSelectedSize = firstAvailableSize;
                        $firstBox.trigger('click');
                    }
                }
                
                // Update button on load
                updateAddToCartButton();
            }
        }, 500);
        
        // Function to apply custom price styling
        function applyCustomPriceStyle() {
            if (!currentSelectedSize) return;
            
            var selectedVariation = null;
            
            // Find the variation for this size
            variationData.forEach(function(variation) {
                var sizeAttr = null;
                for (var key in variation.attributes) {
                    if (key.includes('size')) {
                        sizeAttr = variation.attributes[key];
                        break;
                    }
                }
                if (sizeAttr === currentSelectedSize) {
                    selectedVariation = variation;
                }
            });
            
            if (selectedVariation) {
                var regularPrice = parseFloat(selectedVariation.display_regular_price);
                var salePrice = parseFloat(selectedVariation.display_price);
                var isOnSale = salePrice < regularPrice;
                var isSizeOutOfStock = stockData.includes(currentSelectedSize);
                
                var priceHtml = '';
                
                // If size is out of stock, show sale price if on sale, otherwise regular price (both in black, no badges)
                if (isSizeOutOfStock) {
                    var displayPrice = isOnSale ? salePrice : regularPrice;
                    priceHtml = '<div style="padding-top: 7px;"><span class="price" style="font-family: BRHendrix, Arial, sans-serif; font-size: 30px; font-weight: 500; color: #000000;">£' + displayPrice.toFixed(2) + '</span></div>';
                } else if (isOnSale) {
                    // Regular sale styling for in-stock items
                    var savings = regularPrice - salePrice;
                    var percentOff = Math.round((savings / regularPrice) * 100);
                    
                    priceHtml = '<div style="display: flex; align-items: center; gap: 25px; padding-top: 7px;">';
                    priceHtml += '<span class="price" style="font-family: BRHendrix, Arial, sans-serif; font-size: 30px; font-weight: 500;">';
                    priceHtml += '<span style="color: #000000 !important; font-weight: 500; text-decoration: line-through !important;">£' + regularPrice.toFixed(2) + '</span> ';
                    priceHtml += '<span style="color: #e60023; font-weight: 500;">Now £' + salePrice.toFixed(2) + '</span>';
                    priceHtml += '</span>';
                    priceHtml += '<span class="our-sale-badge" style="background-color: #aa2e26; color: white; padding: 4px 8px; border-radius: 12px; font-size: 14px; font-weight: bold; font-family: BRHendrix, Arial, sans-serif;">' + percentOff + '% OFF</span>';
                    priceHtml += '</div>';
                } else {
                    // Regular price for in-stock, non-sale items
                    priceHtml = '<div style="padding-top: 7px;"><span class="price" style="font-family: BRHendrix, Arial, sans-serif; font-size: 30px; font-weight: 500;">£' + salePrice.toFixed(2) + '</span></div>';
                }
                
                // Simple approach - just replace the content
                var $priceContainer = $('.woocommerce-variation-price, .woocommerce-variation .price').first();
                if ($priceContainer.length) {
                    $priceContainer.html(priceHtml).show().addClass('custom-price-applied');
                }
            }
        }
        
        // Continuous monitoring to override WooCommerce changes
        var priceUpdateInterval = setInterval(function() {
            if (currentSelectedSize) {
                // Only reapply if WooCommerce has overridden our styling
                var $priceContainer = $('.woocommerce-variation-price, .woocommerce-variation .price').first();
                if ($priceContainer.length && !$priceContainer.hasClass('custom-price-applied')) {
                    applyCustomPriceStyle();
                }
            }
        }, 500);
        
        // UPDATED: Allow clicking on ALL size boxes (including out of stock)
        $(document).on('click', '.size-box', function() {
            var $this = $(this);
            var selectedSize = $this.data('size');
            
            // If already selected, don't unselect (always keep one selected)
            if ($this.hasClass('selected')) {
                return;
            }
            
            // Otherwise select this one
            $('.size-box').removeClass('selected').css('box-shadow', 'none');
            $this.addClass('selected');
            
            if ($this.hasClass('sale')) {
                $this.css('box-shadow', 'inset 0 0 0 3px #B91E1C');
            } else {
                $this.css('box-shadow', 'inset 0 0 0 3px black');
            }
            
            // Update current selection
            currentSelectedSize = selectedSize;
            
            // Update WooCommerce variation
            $('select[name*="size"] option').each(function() {
                if ($(this).val() === selectedSize) {
                    $(this).parent().val(selectedSize).trigger('change');
                }
            });
            
            // Update button based on selection
            updateAddToCartButton();
            
            // Apply custom styling immediately and repeatedly
            setTimeout(function() { applyCustomPriceStyle(); }, 50);
            setTimeout(function() { applyCustomPriceStyle(); }, 200);
            setTimeout(function() { applyCustomPriceStyle(); }, 500);
        });
        
        // Override WooCommerce's variation events
        $('body').on('found_variation', function(event, variation) {
            updateAddToCartButton();
            setTimeout(function() { applyCustomPriceStyle(); }, 10);
            setTimeout(function() { applyCustomPriceStyle(); }, 100);
            setTimeout(function() { applyCustomPriceStyle(); }, 300);
        });
        
        // Also monitor DOM changes to catch WooCommerce updates
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.target.classList && 
                    (mutation.target.classList.contains('woocommerce-variation-price') ||
                     mutation.target.classList.contains('price'))) {
                    setTimeout(function() { applyCustomPriceStyle(); }, 10);
                }
            });
        });
        
        // Start observing
        if (document.querySelector('.single_variation_wrap')) {
            observer.observe(document.querySelector('.single_variation_wrap'), {
                childList: true,
                subtree: true,
                attributes: true
            });
        }
    });
    </script>
    
    <?php
}

add_action('wp_footer', 'custom_size_selector_override');

function product_description_shortcode() {
    global $product;
    if ($product && $product->get_description()) {
        return wp_kses_post($product->get_description());
    }
    // Return empty and add script to footer to hide the accordion
    add_action('wp_footer', function() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Hide the accordion item that contains this empty shortcode
            $('.elementor-accordion-item').each(function() {
                var $item = $(this);
                var content = $item.find('.elementor-tab-content').text().trim();
                if (content === '' || content.length === 0) {
                    $item.hide();
                }
            });
        });
        </script>
        <?php
    });
    return '';
}
add_shortcode('product_description', 'product_description_shortcode');

function add_brhendrix_to_astra_fonts( $custom_fonts ) {
    $custom_fonts['BrHendrix'] = array(
        'fallback' => 'sans-serif',
        'weights'  => array( '300', '400', '700' ),
        'styles'   => array( 'normal' ),
    );
    return $custom_fonts;
}
add_filter( 'astra_custom_fonts', 'add_brhendrix_to_astra_fonts' );

/**
 * 9. UNIFIED Badge Solution with Fixed Dimensions
 */

// Remove all existing badge functions
remove_action('woocommerce_before_shop_loop_item_title', 'astra_child_show_sold_out_badge', 9);
remove_action( 'woocommerce_before_shop_loop_item_title',   'woocommerce_show_product_loop_sale_flash', 10 );
remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash',      10 );

// Single unified function for all badges with fixed dimensions
add_action('wp_footer', function() {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        function addUnifiedBadges() {
            // Remove ALL existing badges first
            document.querySelectorAll('.onsale').forEach(badge => badge.remove());
            
            // Target ALL product containers - including Elementor specific ones
            const products = document.querySelectorAll('li.product, .product-item, .elementor-widget-wc-products .product, .elementor-products-grid .product, .woocommerce.elementor-element .product');
            
            products.forEach(function(product) {
                // Make container relative
                product.style.position = 'relative';
                
                // Find image container for better positioning
                const imageContainer = product.querySelector('.woocommerce-LoopProduct-link, .product-image, .attachment-woocommerce_thumbnail') || product;
                imageContainer.style.position = 'relative';
                
                // Check for sale (strikethrough price)
                const hasStrike = product.querySelector('.price del, .price s, [style*="line-through"]');
                
                // Check for out of stock
                const isOutOfStock = product.classList.contains('outofstock') || 
                                   product.querySelector('.outofstock') ||
                                   product.querySelector('.out-of-stock') ||
                                   product.textContent.toLowerCase().includes('sold out');
                
                let badge = null;
                
                if (isOutOfStock) {
                    badge = document.createElement('span');
                    badge.className = 'onsale unified-badge sold-out-badge';
                    badge.textContent = 'SOLD OUT';
                    badge.style.cssText = 'position: absolute !important; top: 10px !important; left: 10px !important; z-index: 9999 !important; display: inline-block !important; padding: 6px 10px !important; background-color: #7A72B2 !important; color: #ffffff !important; font-weight: 700 !important; text-transform: uppercase !important; font-size: 12px !important; border-radius: 0 !important; line-height: 1 !important; box-shadow: none !important; font-family: BRHendrix, Arial, sans-serif !important;';
                } else if (hasStrike) {
                    badge = document.createElement('span');
                    badge.className = 'onsale unified-badge sale-badge';
                    badge.textContent = 'SALE';
                    badge.style.cssText = 'position: absolute !important; top: 10px !important; left: 10px !important; z-index: 9999 !important; display: inline-block !important; padding: 6px 10px !important; background-color: #e60023 !important; color: #ffffff !important; font-weight: 700 !important; text-transform: uppercase !important; font-size: 12px !important; border-radius: 0 !important; line-height: 1 !important; box-shadow: none !important; font-family: BRHendrix, Arial, sans-serif !important;';
                }
                
                if (badge) {
                    imageContainer.insertBefore(badge, imageContainer.firstChild);
                }
            });
        }
        
        // Run multiple times
        addUnifiedBadges();
        setTimeout(addUnifiedBadges, 500);
        setTimeout(addUnifiedBadges, 1500);
        setTimeout(addUnifiedBadges, 3000);
        
        // Run after AJAX
        if (window.jQuery) {
            jQuery(document).ajaxComplete(function() {
                setTimeout(addUnifiedBadges, 300);
            });
        }
    });
    </script>
    
    <style>
    /* Ensure all product containers are positioned */
    li.product,
    .product-item,
    .elementor-widget-wc-products .product,
    .elementor-products-grid .product,
    .woocommerce ul.products li.product,
    .woocommerce.elementor-element .product {
        position: relative !important;
    }
    
    /* Force unified badge positioning with original dimensions */
    .unified-badge {
        position: absolute !important;
        top: 10px !important;
        left: 10px !important;
        z-index: 9999 !important;
        display: inline-block !important;
        padding: 6px 10px !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        font-size: 12px !important;
        border-radius: 0 !important;
        line-height: 1 !important;
        box-shadow: none !important;
    }
    
    /* Specific badge colors */
    .unified-badge.sale-badge {
        background-color: #e60023 !important;
    }
    
    .unified-badge.sold-out-badge {
        background-color: #7A72B2 !important;
    }
    
    /* Fix carousel badge positioning */
    .elementor-widget-loop-carousel .product {
        position: relative !important;
    }
    
    .elementor-widget-loop-carousel .unified-badge {
        position: absolute !important;
        top: 10px !important;
        left: 10px !important;
        z-index: 9999 !important;
    }
    </style>
    <?php
});

add_action('wp_footer', function() {
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Check if product has only one image
        if ($('.woocommerce-product-gallery__image').length === 1) {
            // Try multiple selectors to find the accordion container
            $('.elementor-widget-accordion').parent().css('margin-top', '-300px');
            $('.elementor-widget-accordion').closest('.elementor-row').css('margin-top', '-300px');
            $('.elementor-widget-accordion').closest('[data-element_type="container"]').css('margin-top', '-300px');
        }
    });
    </script>
    <?php
});

function redirect_to_custom_checkout() {
    if (is_checkout() && !is_wc_endpoint_url()) {
        wp_redirect(home_url('/custom-checkout/'));
        exit;
    }
}
add_action('template_redirect', 'redirect_to_custom_checkout');

// AJAX handlers for discount codes
add_action('wp_ajax_apply_discount_code', 'handle_apply_discount');
add_action('wp_ajax_nopriv_apply_discount_code', 'handle_apply_discount');
add_action('wp_ajax_remove_discount_code', 'handle_remove_discount');
add_action('wp_ajax_nopriv_remove_discount_code', 'handle_remove_discount');

function handle_apply_discount() {
    check_ajax_referer('discount_nonce', 'nonce');
    
    $coupon_code = sanitize_text_field($_POST['coupon_code']);
    
    if (empty($coupon_code)) {
        wp_send_json_error('Please enter a discount code');
    }
    
    // Check if coupon exists and is valid
    $coupon = new WC_Coupon($coupon_code);
    
    if (!$coupon->is_valid()) {
        wp_send_json_error('Enter a valid discount code or gift card');
    }
    
    // Apply coupon to cart
    if (WC()->cart->apply_coupon($coupon_code)) {
        WC()->cart->calculate_totals();
        
        $response_data = array(
            'subtotal' => WC()->cart->get_cart_subtotal(),
            'total' => WC()->cart->get_total(),
            'discount' => '-' . WC()->cart->get_discount_total()
        );
        
        wp_send_json_success($response_data);
    } else {
        wp_send_json_error('Unable to apply discount code');
    }
}

function handle_remove_discount() {
    check_ajax_referer('discount_nonce', 'nonce');
    
    // Remove all coupons
    WC()->cart->remove_coupons();
    WC()->cart->calculate_totals();
    
    $response_data = array(
        'subtotal' => WC()->cart->get_cart_subtotal(),
        'total' => WC()->cart->get_total(),
        'discount' => '£0.00'
    );
    
    wp_send_json_success($response_data);
}

function handle_get_applied_coupons() {
    check_ajax_referer('discount_nonce', 'nonce');
    
    $applied_coupons = WC()->cart->get_applied_coupons();
    
    wp_send_json_success(array(
        'coupons' => $applied_coupons
    ));
}
add_action('wp_ajax_get_applied_coupons', 'handle_get_applied_coupons');
add_action('wp_ajax_nopriv_get_applied_coupons', 'handle_get_applied_coupons');

// AJAX handler to get cart totals
function handle_get_cart_totals() {
    check_ajax_referer('discount_nonce', 'nonce');
    
    $discount_total = 0;
    $applied_coupons = WC()->cart->get_applied_coupons();
    
    if (!empty($applied_coupons)) {
        $discount_total = WC()->cart->get_discount_total();
    }
    
    $response_data = array(
        'subtotal' => WC()->cart->get_cart_subtotal(),
        'total' => WC()->cart->get_total(),
        'discount' => $discount_total > 0 ? '-£' . number_format($discount_total, 2) : '£0.00'
    );
    
    wp_send_json_success($response_data);
}
add_action('wp_ajax_get_cart_totals', 'handle_get_cart_totals');
add_action('wp_ajax_nopriv_get_cart_totals', 'handle_get_cart_totals');

function handle_custom_checkout() {
    // Enhanced logging and error handling
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'checkout_nonce')) {
        wp_send_json_error('Security verification failed');
    }
    
    // Check if WooCommerce is available
    if (!function_exists('WC') || !WC() || !WC()->cart) {
        wp_send_json_error('WooCommerce not available');
    }
    
    // Check if cart has items
    if (WC()->cart->is_empty()) {
        wp_send_json_error('Your cart is empty');
    }
    
    try {
        // Simple approach: create order directly
        $order = wc_create_order();
        
        if (is_wp_error($order)) {
            wp_send_json_error('Failed to create order: ' . $order->get_error_message());
        }
        
        // Add billing details
        $order->set_billing_first_name(sanitize_text_field($_POST['billing_first_name'] ?? ''));
        $order->set_billing_last_name(sanitize_text_field($_POST['billing_last_name'] ?? ''));
        $order->set_billing_address_1(sanitize_text_field($_POST['billing_address_1'] ?? ''));
        $order->set_billing_city(sanitize_text_field($_POST['billing_city'] ?? ''));
        $order->set_billing_postcode(sanitize_text_field($_POST['billing_postcode'] ?? ''));
        $order->set_billing_country(sanitize_text_field($_POST['billing_country'] ?? 'GB'));
        $order->set_billing_email(sanitize_email($_POST['billing_email'] ?? ''));
        $order->set_billing_phone(sanitize_text_field($_POST['billing_phone'] ?? ''));
        
        // Set shipping same as billing
        $order->set_shipping_first_name(sanitize_text_field($_POST['billing_first_name'] ?? ''));
        $order->set_shipping_last_name(sanitize_text_field($_POST['billing_last_name'] ?? ''));
        $order->set_shipping_address_1(sanitize_text_field($_POST['billing_address_1'] ?? ''));
        $order->set_shipping_city(sanitize_text_field($_POST['billing_city'] ?? ''));
        $order->set_shipping_postcode(sanitize_text_field($_POST['billing_postcode'] ?? ''));
        $order->set_shipping_country(sanitize_text_field($_POST['billing_country'] ?? 'GB'));
        
        // Add cart items to order
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $quantity = $cart_item['quantity'];
            
            $item_id = $order->add_product($product, $quantity, array(
                'variation' => $cart_item['variation'] ?? array(),
                'totals' => array(
                    'subtotal' => $cart_item['line_subtotal'],
                    'total' => $cart_item['line_total']
                )
            ));
        }
        
        // Apply any coupons
        foreach (WC()->cart->get_applied_coupons() as $coupon_code) {
            $order->apply_coupon($coupon_code);
        }
        
        // Calculate totals
        $order->calculate_totals();
        
        // Set payment method
        $payment_method = sanitize_text_field($_POST['payment_method'] ?? '');
        $order->set_payment_method($payment_method);
        
        // Save the order
        $order->save();
        
        // Clear the cart
        WC()->cart->empty_cart();
        
        // For now, just redirect to order received page (skip payment gateway)
        wp_send_json_success(array(
            'redirect' => $order->get_checkout_order_received_url()
        ));
        
    } catch (Exception $e) {
        wp_send_json_error('Order creation failed: ' . $e->getMessage());
    }
}
?>