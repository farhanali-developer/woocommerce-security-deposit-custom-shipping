<?php
/**
 * Child Theme Functions
 * 
 * @package YEG Digital
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Child theme version
define( 'CHILD_THEME_YEG_DIGITAL_VERSION', '1.0.0' );

/**
 * Enqueue styles
 */
function child_enqueue_styles() {
    // Parent first (adjust handle/path to your theme)
    wp_enqueue_style('parent-style', get_template_directory_uri() . '/style.css');

    // Child, dependent on parent, versioned by last modified time
    $child_css = get_stylesheet_directory() . '/style.css';
    wp_enqueue_style(
        'yeg-digital-theme',
        get_stylesheet_uri(),
        ['parent-style'],
        filemtime($child_css)
    );
}
add_action( 'wp_enqueue_scripts', 'child_enqueue_styles', 15 );

add_action('wp_enqueue_scripts', 'enqueue_custom_price_script');
function enqueue_custom_price_script() {
	if (is_product()) {
		wp_enqueue_script('custom-price-handler', get_stylesheet_directory_uri() . '/custom.js', ['jquery'], CHILD_THEME_YEG_DIGITAL_VERSION, true);

		$product_id = get_the_ID();
		$base_price = get_field('base_price', $product_id);
		$product_variations = get_field('product_variations', $product_id);
		$discount_slabs = get_field('discount_slabs', $product_id);
		
		$price_matrix = [];

		if ($product_variations) {
			foreach ($product_variations as $variation) {
				$width = $variation['width'];
				$height = $variation['height'];
				$price = $variation['price'];
				
				$price_matrix[] = [
					'width'      => $width,
					'height'     => $height,
					'price' => $price,
				];
			}
		}

		// Convert discounts to a key => value pair
		$discount_map = [];
		if ($discount_slabs) {
			foreach ($discount_slabs as $slab) {
				$discount_map[$slab['quantity']] = $slab['discount_percentage'];
			}
		}

		// Pass both configs to JS
		wp_localize_script('custom-price-handler', 'customPricingConfig', [
			'base_price' => $base_price,
		]);
		wp_localize_script('custom-price-handler', 'priceMatrix', $price_matrix);
		wp_localize_script('custom-price-handler', 'discountSlabs', $discount_map);
	}
}


add_action('wp_enqueue_scripts', function () {
    if (is_product() || is_cart() || is_checkout() || is_page('artwork-upload')) {
        wp_enqueue_script('wc-add-to-cart'); // AJAX add to cart
        wp_enqueue_script('wc-cart-fragments'); // Cart updates
        wp_enqueue_script('woocommerce'); // General WooCommerce scripts
    }
});


//THIS IS THE PROBLEM -> FIX THIS
add_action('wp_footer', function () {
    if (is_product()) {
        ?>
         <script>
            if (typeof wc_add_to_cart_params === 'undefined') {
                window.wc_add_to_cart_params = {
                    ajax_url: "<?php echo admin_url('admin-ajax.php'); ?>",
                    wc_ajax_url: "<?php echo admin_url('admin-ajax.php'); ?>?wc-ajax=%%endpoint%%",
                    i18n_view_cart: "<?php esc_js(_e('View cart', 'woocommerce')); ?>",
                    cart_url: "<?php echo wc_get_cart_url(); ?>",
                    is_cart: "0"
                };
            }
         </script>
         <?php
    }
});

add_action('wp_footer', function () {
    if (!is_product()) return;

    // Resolve your artwork page URL robustly
    $artwork_url = defined('ARTWORK_UPLOAD_PAGE_URL') && ARTWORK_UPLOAD_PAGE_URL
        ? ARTWORK_UPLOAD_PAGE_URL
        : ( ($p = get_page_by_path('artwork-upload')) ? get_permalink($p->ID) : wc_get_checkout_url() );

    ?>
    <script>
    (function($){
      var artworkUrl = <?php echo json_encode($artwork_url); ?>;

      // If other scripts are also redirecting on added_to_cart, our redirect will still win.
      // Use a tiny delay so Woo can update fragments, then navigate.
      $(document.body).on('added_to_cart', function(){
        setTimeout(function(){ window.location.href = artworkUrl; }, 30);
      });

      // OPTIONAL: if some plugin aggressively hijacks the event, you can uncomment the following
      // to neutralize their handlers and guarantee our redirect.
      // $(document.body).off('added_to_cart').on('added_to_cart', function(e){
      //   e.stopImmediatePropagation();
      //   window.location.href = artworkUrl;
      // });
    })(jQuery);
    </script>
    <?php
});




// Add custom fields (size, quantity, price) to cart item
add_filter('woocommerce_add_cart_item_data', 'add_custom_fields_to_cart', 10, 2);
function add_custom_fields_to_cart($cart_item_data, $product_id) {
    if (isset($_POST['size'], $_POST['custom_price'])) {

        $cart_item_data['size'] = sanitize_text_field($_POST['size']);
        $cart_item_data['custom_price'] = floatval($_POST['custom_price']);

        // Convert custom qty into WooCommerce's "quantity"
		$final_qty = 1;

		if (isset($_POST['custom_qty']) && $_POST['custom_qty'] === 'custom') {
			$final_qty = intval($_POST['custom_qty_value']);
		} elseif (isset($_POST['custom_qty'])) {
			$final_qty = intval($_POST['custom_qty']);
		}

		$_POST['custom_qty'] = $final_qty; // If needed by Woo
		$cart_item_data['custom_quantity'] = $final_qty;

        if ($_POST['size'] === 'custom') {
            $cart_item_data['custom_width'] = sanitize_text_field($_POST['custom_width']);
            $cart_item_data['custom_height'] = sanitize_text_field($_POST['custom_height']);
        }

        $cart_item_data['unique_key'] = md5(microtime() . rand());
    }

    return $cart_item_data;
}




// Load custom fields from session - FIXED to include artwork fields
add_filter('woocommerce_get_cart_item_from_session', 'get_custom_fields_from_session', 20, 2);
function get_custom_fields_from_session($cart_item, $values) {
	$custom_keys = ['size', 'custom_qty_value', 'custom_quantity', 'custom_price', 'custom_width', 'custom_height', 'artwork_attachment_id', 'artwork_url'];

    foreach ($custom_keys as $key) {
        if (isset($values[$key])) {
            $cart_item[$key] = $values[$key];
        }
    }

    return $cart_item;
}




// Override price in cart
add_action('woocommerce_before_calculate_totals', 'override_price_in_cart', 20, 1);
function override_price_in_cart($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    if ($cart->is_empty()) return;

    foreach ($cart->get_cart() as $cart_item) {
        if (isset($cart_item['custom_price'])) {
            $cart_item['data']->set_price($cart_item['custom_price']);
        }
    }
}


// Save custom fields to order meta - FIXED to properly save artwork
add_action('woocommerce_checkout_create_order_line_item', 'add_custom_fields_to_order', 10, 4);
function add_custom_fields_to_order($item, $cart_item_key, $values, $order) {
    if (isset($values['size'])) {
        $size_label = $values['size'];
        if ($size_label === 'custom' && isset($values['custom_width'], $values['custom_height'])) {
            $size_label = "Custom Size {$values['custom_width']}\" x {$values['custom_height']}\"";
        }
        $item->add_meta_data('Size', $size_label);
    }

    if (isset($values['custom_quantity'])) {
        $item->add_meta_data('Quantity', $values['custom_quantity']);
    }

    if (isset($values['custom_price'])) {
        $item->add_meta_data('Custom Price', wc_price($values['custom_price']));
    }
	
	// ✅ Save artwork attachment ID and URL to order item meta
    if (!empty($values['artwork_attachment_id'])) {
        $item->add_meta_data('_artwork_attachment_id', intval($values['artwork_attachment_id']));
        $item->add_meta_data('Artwork Attachment ID', intval($values['artwork_attachment_id']));
    }
    if (!empty($values['artwork_url'])) {
        $item->add_meta_data('_artwork_url', esc_url_raw($values['artwork_url']));
        $item->add_meta_data('Artwork URL', esc_url_raw($values['artwork_url']));
    }
}


// Add custom size to product name in cart, checkout, emails - REMOVED duplicate artwork display
add_filter('woocommerce_cart_item_name', 'custom_add_size_to_product_name', 10, 3);
function custom_add_size_to_product_name($name, $cart_item, $cart_item_key) {
    if (!empty($cart_item['size'])) {
        $size_label = $cart_item['size'];

        if ($size_label === 'custom' && !empty($cart_item['custom_width']) && !empty($cart_item['custom_height'])) {
            $width  = esc_html($cart_item['custom_width']);
            $height = esc_html($cart_item['custom_height']);
            $size_label = "Custom Size {$width}\" x {$height}\"";
        }

        $name .= '<p class="custom-size-label" style="margin:5px 0 0; font-size: 0.9em; color:#555;">' . esc_html($size_label) . '</p>';
    }
	
	// ✅ append artwork link if available
    if ( ! empty($cart_item['artwork_url']) ) {
        $url  = esc_url($cart_item['artwork_url']);
        $name .= '<p style="margin:4px 0 0; font-size:12px; color:#555;">Artwork: <a href="'.$url.'" target="_blank" rel="noopener">View</a></p>';
    }
	
    return $name;
}


// Display custom quantity as read-only text in cart and checkout
add_filter('woocommerce_cart_item_quantity', 'custom_display_cart_quantity', 10, 3);
function custom_display_cart_quantity($product_quantity, $cart_item_key, $cart_item) {
    if (isset($cart_item['custom_quantity'])) {
        $custom_qty = intval($cart_item['custom_quantity']);
        return '<span class="custom-product-quantity"><strong>' . $custom_qty . '</strong></span>';
    }
    return $product_quantity;
}



// Update checkout page quantity display
add_filter('woocommerce_checkout_cart_item_quantity', 'custom_update_checkout_quantity_text', 10, 3);
function custom_update_checkout_quantity_text($quantity_html, $cart_item, $cart_item_key) {
    if (isset($cart_item['custom_quantity'])) {
        $custom_qty = intval($cart_item['custom_quantity']);
        return '<strong class="product-quantity">Qty: ' . $custom_qty . '</strong>';
    }
    return $quantity_html;
}


add_action('woocommerce_before_add_to_cart_button', function () {
    $product_variations = get_field('product_variations', get_the_ID());
    $discount_slabs = get_field('discount_slabs', get_the_ID());

    if ($product_variations) {
        // Size selection
        echo '<div id="custom-size-options"><h3>Select a size</h3>';
        foreach ($product_variations as $i => $variation) {
            $size_label = esc_attr($variation['width'] . " " . $variation['unit'] . " x " . $variation['height'] . " " . $variation['unit']);
            $checked = $i === 0 ? 'checked' : '';
            echo "<label style='display:block; margin-bottom:5px;'><input style='vertical-align: middle;' type='radio' name='size' value='{$size_label}' data-size-index='{$i}' {$checked}> {$size_label}</label>";
        }
        echo "<label><input type='radio' name='size' value='custom'> Custom Size</label>";
        echo '<div id="custom-size-inputs" style="display: none; margin-top: 10px;">
                <input type="number" id="custom-width" name="custom_width" placeholder="Width" min="0" step="0.01" style="width: 100px; max-width: 100%;" />
                ×
                <input type="number" id="custom-height" name="custom_height" placeholder="Height" min="0" step="0.01" style="width: 100px; max-width: 100%;" />
              </div>';
        echo '</div>'; // End size section
	}

	if($discount_slabs){
        // Quantity section
        echo '<div id="custom-quantity-options"><h3>Select a quantity</h3>';

        foreach ($discount_slabs as $i => $slab) {
			
			$qty   = esc_attr($slab['quantity']);
			$saved = isset($slab['discount_percentage']) ? esc_attr($slab['discount_percentage']) : '0';
			$label = " {$qty} <span class='discount-text' style='color:green; float: right;'>Save {$saved}%</span>";
			$checked = $i === 0 ? 'checked' : '';
			echo "<label style='display:block; margin-bottom:5px;'><input style='vertical-align: middle;' type='radio' name='custom_qty' value='{$qty}' {$checked}>{$label}</label>";
        }

        // Custom Quantity input
        echo "<label style='display:block; margin-bottom:5px;'><input style='vertical-align: middle;' type='radio' name='custom_qty' value='custom'> Custom Quantity</label>";
        echo '<div id="custom-quantity-wrapper" style="display:none; margin-top:10px;">
                <input type="number" id="custom-qty" name="custom_qty_value" placeholder="Quantity" min="1" step="1" style="width: 100px; max-width: 100%;" />
                <div id="dynamic-discount-text" style="margin-top:5px; color:green; float: right;"></div>
              </div>';

        echo '</div>'; // End quantity section
    }

    echo '<input type="hidden" name="custom_price" id="custom_price">';
});

// Remove WooCommerce quantity field on single product page
add_filter('woocommerce_is_sold_individually', '__return_true');

// === CONFIG: set the upload page permalink (or leave empty to use the "artwork-upload" page by slug) ===
// 1) Set your artwork upload page permalink EXACTLY here:
if ( ! defined('ARTWORK_UPLOAD_PAGE_URL') ) {
    define('ARTWORK_UPLOAD_PAGE_URL', 'https://albertastickers.com/artwork-upload/'); // <-- change me
}

/**
 * Capture the last added cart item key into the session.
 */
add_action('woocommerce_add_to_cart', function($cart_item_key) {
    if ( function_exists('WC') && WC()->session ) {
        WC()->session->set('pending_artwork_key', $cart_item_key);
        WC()->session->set('last_artwork_key', $cart_item_key); // fallback for the shortcode
    }
}, 10, 1);

/**
 * Redirect to the artwork upload page (instead of going straight to checkout).
 * We keep your existing logic intact—this just inserts a step in between.
 */
add_filter('woocommerce_add_to_cart_redirect', function($redirect_url) {
    if ( ! function_exists('WC') || ! WC()->session ) return $redirect_url;

    $pending_key = WC()->session->get('pending_artwork_key');
    if ( ! $pending_key ) return $redirect_url;

    // never unset before building URL — and don't fall back to checkout here
    $url = ARTWORK_UPLOAD_PAGE_URL ? ARTWORK_UPLOAD_PAGE_URL : wc_get_page_permalink( 'checkout' );
    $url = add_query_arg('ci', rawurlencode($pending_key), $url);

    // clear the session flag AFTER we've built the URL
    WC()->session->__unset('pending_artwork_key');

    return $url;
}, 9999);

/**
 * Mark artwork-upload page as uncacheable (avoids full-page cache issues).
 */
add_action('template_redirect', function () {
    if ( is_page() ) {
        global $post;
        if ( $post && 'artwork-upload' === $post->post_name ) {
            if ( ! headers_sent() ) {
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
            }
            if ( ! defined('DONOTCACHEPAGE') ) {
                define('DONOTCACHEPAGE', true);
            }
        }
    }
});

/**
 * Shortcode: [artwork_upload]
 * Renders a simple PNG upload form, tied to the specific cart item via ?ci=cart_item_key.
 */
add_shortcode('artwork_upload', function(){
    if ( ! function_exists('WC') || ! WC()->cart ) {
        return '<p>Your cart is empty.</p>';
    }

    // Prefer ci from URL
    $ci = isset($_GET['ci']) ? sanitize_text_field(wp_unslash($_GET['ci'])) : '';

    // If missing or not found, fall back to the session's last key
    $cart = WC()->cart->get_cart();
    if ( empty($ci) || ! isset($cart[$ci]) ) {
        $session_key = function_exists('WC') && WC()->session ? WC()->session->get('last_artwork_key') : '';
        if ( $session_key && isset($cart[$session_key]) ) {
            $ci = $session_key;
        }
    }

    if ( empty($ci) || ! isset($cart[$ci]) ) {
        return '<p>We could not find the cart item for this upload. Please add your item again.</p>';
    }

    $action = esc_url( admin_url('admin-post.php') );
    $nonce  = wp_create_nonce('artwork_upload_' . $ci);

    ob_start(); ?>
    <div class="artwork-upload-wrapper">
        <h2>Upload your artwork</h2>
        <p>Please upload a PNG file for this item. You'll be sent to checkout after it uploads.</p>
        <form method="post" action="<?php echo $action; ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="artwork_upload">
            <input type="hidden" name="ci" value="<?php echo esc_attr($ci); ?>">
            <input type="hidden" name="artwork_nonce" value="<?php echo esc_attr($nonce); ?>">
            <p><input type="file" name="artwork_file" accept="image/png" required></p>
            <p><button type="submit" class="button button-primary">Upload &amp; continue to checkout</button></p>
        </form>
        <p><a href="<?php echo esc_url( wc_get_checkout_url() ); ?>">Skip upload and go to checkout →</a></p>
    </div>
    <?php
    return ob_get_clean();
});

/**
 * Handle the artwork upload (logged out + logged in).
 */
add_action('admin_post_nopriv_artwork_upload', 'handle_artwork_upload_submission');
add_action('admin_post_artwork_upload',        'handle_artwork_upload_submission');

function handle_artwork_upload_submission() {
    if ( ! function_exists('WC') || ! WC()->cart ) {
        wp_safe_redirect( wc_get_checkout_url() );
        exit;
    }

    $cart_item_key = isset($_POST['ci']) ? sanitize_text_field(wp_unslash($_POST['ci'])) : '';
    $nonce         = isset($_POST['artwork_nonce']) ? sanitize_text_field(wp_unslash($_POST['artwork_nonce'])) : '';

    if ( empty($cart_item_key) || ! wp_verify_nonce($nonce, 'artwork_upload_' . $cart_item_key) ) {
        wp_safe_redirect( wc_get_checkout_url() );
        exit;
    }

    $cart = WC()->cart->get_cart();
    if ( ! isset($cart[$cart_item_key]) ) {
        wp_safe_redirect( wc_get_checkout_url() );
        exit;
    }

    // Validate file
    if ( ! isset($_FILES['artwork_file']) || empty($_FILES['artwork_file']['name']) ) {
        wc_add_notice('Please choose a PNG file to upload.', 'error');
        wp_safe_redirect( add_query_arg('ci', rawurlencode($cart_item_key), wp_get_referer() ?: home_url('/') ) );
        exit;
    }

    $file = $_FILES['artwork_file'];

    // Only allow PNG
	$allowed_mimes = [
	  'png'  => 'image/png',
	  'webp' => 'image/webp',
	  'jpeg' => 'image/jpeg'
	];
    $filetype      = wp_check_filetype( $file['name'], $allowed_mimes );

    if ( empty($filetype['ext']) || $filetype['ext'] !== 'png' ) {
        wc_add_notice('Only PNG files are allowed.', 'error');
        wp_safe_redirect( add_query_arg('ci', rawurlencode($cart_item_key), wp_get_referer() ?: home_url('/') ) );
        exit;
    }

    // Handle upload to /uploads
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $overrides = [ 'test_form' => false, 'mimes' => $allowed_mimes ];
    $movefile  = wp_handle_upload( $file, $overrides );

    if ( ! $movefile || isset($movefile['error']) ) {
        wc_add_notice('Upload failed: ' . ( $movefile['error'] ?? 'Unknown error' ), 'error');
        wp_safe_redirect( add_query_arg('ci', rawurlencode($cart_item_key), wp_get_referer() ?: home_url('/') ) );
        exit;
    }

    // Create attachment in media library
    $attachment_id = 0;
    $file_url      = $movefile['url'];
    $file_path     = $movefile['file'];

    $attachment = [
        'post_mime_type' => 'image/png',
        'post_title'     => sanitize_file_name( wp_basename( $file_path ) ),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];
    $attachment_id = wp_insert_attachment( $attachment, $file_path );

    if ( $attachment_id ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata( $attachment_id, $file_path );
        wp_update_attachment_metadata( $attachment_id, $attach_data );
    }

    // Save to the cart item & persist session
	WC()->cart->cart_contents[ $cart_item_key ]['artwork_attachment_id'] = (int) $attachment_id;
	WC()->cart->cart_contents[ $cart_item_key ]['artwork_url']           = $file_url;

	// Force-write session + cart hash so next page sees the fields
	WC()->cart->set_session();
	WC()->cart->calculate_totals(); // <-- important
	WC()->cart->maybe_set_cart_cookies();

	wp_safe_redirect( wc_get_checkout_url() );
	exit;
}

/**
 * Display artwork in order details (backend admin)
 */
add_action('woocommerce_after_order_itemmeta', 'display_artwork_in_admin_order', 10, 3);
function display_artwork_in_admin_order($item_id, $item, $product) {
    // Get artwork attachment ID
    $attachment_id = $item->get_meta('_artwork_attachment_id');
    $artwork_url = $item->get_meta('_artwork_url');
    
    if ($attachment_id || $artwork_url) {
        echo '<div class="artwork-display" style="margin-top: 10px;">';
        echo '<strong>Artwork:</strong> ';
        
        if ($attachment_id) {
            $thumb_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
            $full_url = wp_get_attachment_image_url($attachment_id, 'full');
            
            if ($thumb_url) {
                echo '<a href="' . esc_url($full_url) . '" target="_blank">';
                echo '<img src="' . esc_url($thumb_url) . '" style="max-width: 100px; height: auto; vertical-align: middle; margin: 5px;" />';
                echo '</a>';
            }
        }
        
        if ($artwork_url) {
            echo '<a href="' . esc_url($artwork_url) . '" target="_blank" class="button button-small">View Full Size</a>';
        }
        
        echo '</div>';
    }
}

/**
 * Display artwork on thank you page
 */
add_action('woocommerce_order_item_meta_end', 'display_artwork_on_thank_you', 10, 4);
function display_artwork_on_thank_you($item_id, $item, $order, $plain_text) {
    // Only show on thank you page, not in emails
    if ($plain_text || is_admin()) {
        return;
    }
    
    $attachment_id = $item->get_meta('_artwork_attachment_id');
    $artwork_url = $item->get_meta('_artwork_url');
    
    if ($artwork_url) {
        echo '<div class="artwork-preview" style="margin-top: 10px;">';
        echo '<strong>Uploaded Artwork:</strong> ';
        echo '<a href="' . esc_url($artwork_url) . '" target="_blank" style="color: #0073aa;">View artwork</a>';
        
        // Show thumbnail if available
        if ($attachment_id) {
            $thumb_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
            if ($thumb_url) {
                echo '<br><a href="' . esc_url($artwork_url) . '" target="_blank">';
                echo '<img src="' . esc_url($thumb_url) . '" style="max-width: 150px; height: auto; margin-top: 5px; border: 1px solid #ddd;" />';
                echo '</a>';
            }
        }
        
        echo '</div>';
    }
}

/**
 * Add artwork to order emails
 */
add_action('woocommerce_order_item_meta_end', 'add_artwork_to_emails', 10, 4);
function add_artwork_to_emails($item_id, $item, $order, $plain_text) {
    // Only show in emails
    if (!did_action('woocommerce_email_header')) {
        return;
    }
    
    $attachment_id = $item->get_meta('_artwork_attachment_id');
    $artwork_url = $item->get_meta('_artwork_url');
    
    if ($artwork_url) {
        if ($plain_text) {
            // Plain text email
            echo "\nArtwork: " . $artwork_url . "\n";
        } else {
            // HTML email
            echo '<div style="margin: 10px 0;">';
            echo '<strong>Artwork:</strong> ';
            echo '<a href="' . esc_url($artwork_url) . '" style="color: #0073aa;">View uploaded artwork</a>';
            
            // Include thumbnail in HTML emails
            if ($attachment_id) {
                $thumb_url = wp_get_attachment_image_url($attachment_id, 'medium');
                if ($thumb_url) {
                    echo '<br><a href="' . esc_url($artwork_url) . '">';
                    echo '<img src="' . esc_url($thumb_url) . '" style="max-width: 200px; height: auto; margin-top: 10px; border: 1px solid #ddd;" />';
                    echo '</a>';
                }
            }
            
            echo '</div>';
        }
    }
}

/**
 * Add artwork column to orders list in admin
 */
add_filter('manage_edit-shop_order_columns', 'add_artwork_column_to_orders');
function add_artwork_column_to_orders($columns) {
    $new_columns = [];
    
    foreach ($columns as $key => $value) {
        $new_columns[$key] = $value;
        
        // Add artwork column after order total
        if ($key === 'order_total') {
            $new_columns['artwork'] = 'Artwork';
        }
    }
    
    return $new_columns;
}

/**
 * Populate artwork column in orders list
 */
add_action('manage_shop_order_posts_custom_column', 'populate_artwork_column', 10, 2);
function populate_artwork_column($column, $post_id) {
    if ($column === 'artwork') {
        $order = wc_get_order($post_id);
        $has_artwork = false;
        
        foreach ($order->get_items() as $item) {
            if ($item->get_meta('_artwork_attachment_id') || $item->get_meta('_artwork_url')) {
                $has_artwork = true;
                break;
            }
        }
        
        if ($has_artwork) {
            echo '<span style="color: green;">✓</span>';
        } else {
            echo '<span style="color: #999;">—</span>';
        }
    }
}

/**
 * Make artwork downloadable from My Account > Orders
 */
add_filter('woocommerce_order_item_name', 'add_artwork_link_to_order_item_name', 10, 3);
function add_artwork_link_to_order_item_name($item_name, $item, $is_visible) {
    // Only modify on my account pages
    if (!is_wc_endpoint_url('orders') && !is_wc_endpoint_url('view-order')) {
        return $item_name;
    }
    
    $artwork_url = $item->get_meta('_artwork_url');
    
    if ($artwork_url) {
        $item_name .= '<br><small class="artwork-link">Artwork: <a href="' . esc_url($artwork_url) . '" target="_blank">View uploaded file</a></small>';
    }
    
    return $item_name;
}

/**
 * Optional: Clean up orphaned artwork attachments (run via WP-CLI or as scheduled task)
 */
function cleanup_orphaned_artwork_attachments() {
    global $wpdb;
    
    // Get all artwork attachment IDs from orders
    $used_attachments = $wpdb->get_col("
        SELECT DISTINCT meta_value 
        FROM {$wpdb->prefix}woocommerce_order_itemmeta 
        WHERE meta_key = '_artwork_attachment_id'
    ");
    
    // Get all attachments that look like artwork uploads
    $args = [
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => -1,
        'meta_query' => [
            [
                'key' => '_wp_attachment_metadata',
                'compare' => 'EXISTS'
            ]
        ]
    ];
    
    $attachments = get_posts($args);
    
    foreach ($attachments as $attachment) {
        // Check if this attachment is used in any order
        if (!in_array($attachment->ID, $used_attachments)) {
            // Additional check: only delete if older than 30 days
            $age = time() - strtotime($attachment->post_date);
            if ($age > 30 * DAY_IN_SECONDS) {
                // Check if filename suggests it's an artwork upload
                if (strpos($attachment->post_title, 'artwork') !== false || 
                    strpos($attachment->post_title, 'upload') !== false) {
                    wp_delete_attachment($attachment->ID, true);
                }
            }
        }
    }
}

// Optional: Schedule cleanup
add_action('init', function() {
    if (!wp_next_scheduled('cleanup_orphaned_artwork')) {
        wp_schedule_event(time(), 'weekly', 'cleanup_orphaned_artwork');
    }
});
add_action('cleanup_orphaned_artwork', 'cleanup_orphaned_artwork_attachments');

/**
 * Check cart items for artwork before allowing checkout
 */
add_action('woocommerce_check_cart_items', 'check_cart_items_for_artwork');
function check_cart_items_for_artwork() {
    $missing_artwork = false;
    
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        // Check if this product requires artwork (you can add custom logic here)
        $requires_artwork = true; // Set this based on your product requirements
        
        if ($requires_artwork) {
            $has_artwork = !empty($cart_item['artwork_attachment_id']) || !empty($cart_item['artwork_url']);
            
            if (!$has_artwork) {
                $missing_artwork = true;
                $product_name = $cart_item['data']->get_name();
                wc_add_notice(sprintf('Please upload artwork for "%s" before proceeding to checkout.', $product_name), 'error');
            }
        }
    }
    
    if ($missing_artwork) {
        // Optionally redirect back to cart or artwork upload page
        if (is_checkout()) {
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }
    }
}