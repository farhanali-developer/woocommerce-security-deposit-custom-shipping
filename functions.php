/**
 * Enqueue styles
 */
function child_enqueue_styles() {

// 	wp_enqueue_style( 'yeg-digital-theme-css', get_stylesheet_directory_uri() . '/style.css', array('astra-theme-css'), CHILD_THEME_YEG_DIGITAL_VERSION, 'all' );
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




// Load custom fields from session
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


// Save custom fields to order meta
add_action('woocommerce_checkout_create_order_line_item', 'add_custom_fields_to_order', 10, 4);
function add_custom_fields_to_order($item, $cart_item_key, $values, $order) {
    if (isset($values['size'])) {
        $size_label = $values['size'];
        if ($size_label === 'custom' && isset($values['custom_width'], $values['custom_height'])) {
            $size_label = "Custom Size {$values['custom_width']}\" x {$values['custom_height']}\"";
        }
        $item->add_meta_data('Size', $size_label);
    }

    if (isset($values['quantity'])) {
        $item->add_meta_data('Quantity', $values['quantity']);
    }

    if (isset($values['custom_price'])) {
        $item->add_meta_data('Custom Price', wc_price($values['custom_price']));
    }
	
	// ✅ carry artwork into the order
    if (!empty($values['artwork_attachment_id'])) {
        $item->add_meta_data('Artwork Attachment ID', intval($values['artwork_attachment_id']));
    }
    if (!empty($values['artwork_url'])) {
        $item->add_meta_data('Artwork URL', esc_url_raw($values['artwork_url']));
    }
}


// FIXED: Single consolidated function for cart item name display
add_filter('woocommerce_cart_item_name', 'custom_add_size_and_artwork_to_product_name', 10, 3);
function custom_add_size_and_artwork_to_product_name($name, $cart_item, $cart_item_key) {
    // Add size information
    if (!empty($cart_item['size'])) {
        $size_label = $cart_item['size'];

        if ($size_label === 'custom' && !empty($cart_item['custom_width']) && !empty($cart_item['custom_height'])) {
            $width  = esc_html($cart_item['custom_width']);
            $height = esc_html($cart_item['custom_height']);
            $size_label = "Custom Size {$width}\" x {$height}\"";
        }

        $name .= '<p class="custom-size-label" style="margin:5px 0 0; font-size: 0.9em; color:#555;">' . esc_html($size_label) . '</p>';
    }
	
    // Add artwork information
    if (!empty($cart_item['artwork_url'])) {
        $url = esc_url($cart_item['artwork_url']);
        $name .= '<p class="artwork-info" style="margin:4px 0 0; font-size:12px; color:#555;">';
        $name .= '<strong>Artwork:</strong> <a href="'.$url.'" target="_blank" rel="noopener">View Image</a>';
        $name .= '</p>';
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

    // FIXED: Properly save artwork data to cart item
    WC()->cart->cart_contents[$cart_item_key]['artwork_attachment_id'] = (int) $attachment_id;
    WC()->cart->cart_contents[$cart_item_key]['artwork_url'] = $file_url;

    // Force-write session + cart hash so next page sees the fields
    WC()->cart->set_session();
    WC()->cart->calculate_totals();
    WC()->cart->maybe_set_cart_cookies();

    wp_safe_redirect( wc_get_checkout_url() );
    exit;
}

// FIXED: Remove duplicate filter - artwork display is now handled in the consolidated function above

// FIXED: Enhanced cart validation to ensure artwork is uploaded
add_action('woocommerce_check_cart_items', function(){
    foreach ( WC()->cart->get_cart() as $key => $item ) {
        // accept upload if either the attachment id OR the URL is present
        $has_art = !empty($item['artwork_attachment_id']) || !empty($item['artwork_url']);
        if ( ! $has_art ) {
            wc_add_notice('Please upload artwork for all items before proceeding to checkout.', 'error');
            break;
        }
    }
});

// NEW: Display artwork in order details on thank you page
add_action('woocommerce_order_item_meta_end', 'display_artwork_in_order_details', 10, 4);
function display_artwork_in_order_details($item_id, $item, $order, $plain_text) {
    $artwork_url = $item->get_meta('Artwork URL');
    $artwork_id = $item->get_meta('Artwork Attachment ID');
    
    if ($artwork_url || $artwork_id) {
        if ($plain_text) {
            echo "\nArtwork: " . ($artwork_url ? $artwork_url : 'Attachment ID: ' . $artwork_id);
        } else {
            echo '<div class="artwork-display" style="margin-top: 10px; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">';
            echo '<h4 style="margin: 0 0 10px 0; font-size: 14px;">Uploaded Artwork:</h4>';
            
            if ($artwork_id) {
                $image_url = wp_get_attachment_url($artwork_id);
                if ($image_url) {
                    echo '<img src="' . esc_url($image_url) . '" alt="Uploaded Artwork" style="max-width: 200px; max-height: 200px; border: 1px solid #ccc; border-radius: 3px;">';
                }
            }
            
            if ($artwork_url) {
                echo '<p style="margin: 10px 0 0 0; font-size: 12px;">';
                echo '<a href="' . esc_url($artwork_url) . '" target="_blank" rel="noopener">View Full Size Image</a>';
                echo '</p>';
            }
            
            echo '</div>';
        }
    }
}

// NEW: Display artwork in order emails
add_action('woocommerce_email_order_meta', 'add_artwork_to_order_emails', 10, 4);
function add_artwork_to_order_emails($order, $sent_to_admin, $plain_text, $email) {
    $artwork_items = [];
    
    foreach ($order->get_items() as $item_id => $item) {
        $artwork_url = $item->get_meta('Artwork URL');
        $artwork_id = $item->get_meta('Artwork Attachment ID');
        
        if ($artwork_url || $artwork_id) {
            $artwork_items[] = [
                'name' => $item->get_name(),
                'url' => $artwork_url,
                'id' => $artwork_id
            ];
        }
    }
    
    if (!empty($artwork_items)) {
        if ($plain_text) {
            echo "\n\nUPLOADED ARTWORK:\n";
            echo str_repeat("=", 50) . "\n";
            foreach ($artwork_items as $artwork) {
                echo "Product: " . $artwork['name'] . "\n";
                if ($artwork['url']) {
                    echo "Artwork URL: " . $artwork['url'] . "\n";
                }
                if ($artwork['id']) {
                    echo "Attachment ID: " . $artwork['id'] . "\n";
                }
                echo "\n";
            }
        } else {
            echo '<div style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #0073aa;">';
            echo '<h3 style="margin: 0 0 15px 0; color: #0073aa;">Uploaded Artwork</h3>';
            
            foreach ($artwork_items as $artwork) {
                echo '<div style="margin-bottom: 15px; padding: 10px; background: white; border: 1px solid #ddd; border-radius: 3px;">';
                echo '<h4 style="margin: 0 0 10px 0; font-size: 14px;">' . esc_html($artwork['name']) . '</h4>';
                
                if ($artwork['id']) {
                    $image_url = wp_get_attachment_url($artwork['id']);
                    if ($image_url) {
                        echo '<img src="' . esc_url($image_url) . '" alt="Uploaded Artwork" style="max-width: 300px; max-height: 300px; border: 1px solid #ccc; border-radius: 3px; margin-bottom: 10px;">';
                    }
                }
                
                if ($artwork['url']) {
                    echo '<p style="margin: 0; font-size: 12px;">';
                    echo '<a href="' . esc_url($artwork['url']) . '" target="_blank" rel="noopener" style="color: #0073aa;">View Full Size Image</a>';
                    echo '</p>';
                }
                
                echo '</div>';
            }
            
            echo '</div>';
        }
    }
}

// NEW: Display artwork in admin order details
add_action('woocommerce_after_order_itemmeta', 'display_artwork_in_admin_order', 10, 3);
function display_artwork_in_admin_order($item_id, $item, $product) {
    $artwork_url = $item->get_meta('Artwork URL');
    $artwork_id = $item->get_meta('Artwork Attachment ID');
    
    if ($artwork_url || $artwork_id) {
        echo '<div class="artwork-admin-display" style="margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 3px;">';
        echo '<h4 style="margin: 0 0 10px 0; font-size: 14px;">Customer Artwork:</h4>';
        
        if ($artwork_id) {
            $image_url = wp_get_attachment_url($artwork_id);
            if ($image_url) {
                echo '<img src="' . esc_url($image_url) . '" alt="Uploaded Artwork" style="max-width: 200px; max-height: 200px; border: 1px solid #ccc; border-radius: 3px; margin-bottom: 10px;">';
            }
        }
        
        if ($artwork_url) {
            echo '<p style="margin: 0; font-size: 12px;">';
            echo '<a href="' . esc_url($artwork_url) . '" target="_blank" rel="noopener">View Full Size Image</a>';
            echo '</p>';
        }
        
        echo '</div>';
    }
}