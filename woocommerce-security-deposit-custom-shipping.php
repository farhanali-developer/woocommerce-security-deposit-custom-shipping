<?php
/**
 * Plugin Name: WooCommerce Security Deposit and Custom Shipping
 * Description: This plugin adds a security deposit feature and custom shipping method to WooCommerce.
 * Version: 1.0
 * Author: Farhan Ali
 */

//  global $days;
$days = array();

register_activation_hook(__FILE__, 'check_dependencies');


function check_dependencies() {
    if (!is_plugin_active('woocommerce/woocommerce.php')) {
        add_action('admin_notices', 'woocommerce_not_active_notice');
        deactivate_plugins(plugin_basename(__FILE__));
        return;
    }
}

function woocommerce_not_active_notice() {
    ?>
    <div class="error notice">
        <p><?php _e('WooCommerce Security Deposit and Custom Shipping requires WooCommerce plugin to be active. Please activate WooCommerce.', 'text-domain'); ?></p>
    </div>
    <?php
}

add_action('plugins_loaded', 'init_my_plugin');

function init_my_plugin() {
    // Check if WooCommerce is active
    if (class_exists('WooCommerce')) {

        function enqueue_custom_script() {
            global $days;
            wp_enqueue_style('jquery-ui-style', '//code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css');
            wp_enqueue_style('custom-style', plugin_dir_url(__FILE__) . 'style.css', '1.0');
            wp_enqueue_script('jquery-ui', 'https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.13.2/jquery-ui.min.js', array('jquery'), true);
            wp_enqueue_script('custom-script', plugin_dir_url(__FILE__) . 'script.js', array('jquery'), '1.2', true);
        }
        
        add_action('wp_enqueue_scripts', 'enqueue_custom_script');


        function load_select2_assets() {            
            wp_enqueue_style('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css');
            wp_enqueue_script('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array('jquery'), '', true);
            wp_enqueue_script('custom-admin-script', plugin_dir_url(__FILE__) . 'admin_script.js', array('jquery'), '1.0', true);
        }
        add_action('admin_enqueue_scripts', 'load_select2_assets');

        // Add security deposit amount field to product settings for all product types
        add_action('woocommerce_product_options_general_product_data', 'add_product_security_deposit_field');

        function add_product_security_deposit_field() {
            global $post;

            // Get product type
            $product = wc_get_product($post->ID);
            $product_type = $product->get_type();

            // Check product type and add fields accordingly
            if ($product_type !== 'variable') {
                woocommerce_wp_text_input(array(
                    'id' => 'security_deposit',
                    'class' => 'short',
                    'label' => __('Security Deposit Amount', 'text-domain') . ' (' . get_woocommerce_currency_symbol() . ')',
                    'desc_tip' => 'true',
                ));

                // if ($product_type === 'simple' || $product_type === 'variable') {
                //     // Add a field to choose pickup or delivery for the product
                //     woocommerce_wp_select(array(
                //         'id' => 'pickup_or_delivery',
                //         'label' => __('Pickup/Delivery', 'text-domain'),
                //         'options' => array(
                //             'pickup' => __('Pickup', 'text-domain'),
                //             'delivery' => __('Delivery', 'text-domain'),
                //             'both' => __('Both', 'text-domain')
                //         )
                //     ));
                // }
            }
        }

        // Save custom field values for all product types
        add_action('woocommerce_process_product_meta', 'save_product_security_deposit_field');

        function save_product_security_deposit_field($post_id) {
            $security_deposit = isset($_POST['security_deposit']) ? wc_clean($_POST['security_deposit']) : '';
            update_post_meta($post_id, 'security_deposit', $security_deposit);

            $pickup_or_delivery = isset($_POST['pickup_or_delivery']) ? wc_clean($_POST['pickup_or_delivery']) : '';
            update_post_meta($post_id, 'pickup_or_delivery', $pickup_or_delivery);
        }

        // Add fields to variable product variations
        add_action('woocommerce_variation_options_pricing', 'add_variation_fields', 10, 3);
        function add_variation_fields($loop, $variation_data, $variation) {
            woocommerce_wp_text_input(array(
                'id' => '_security_deposit[' . $variation->ID . ']',
                'wrapper_class' => 'form-field variable_regular_price_0_field form-row form-row-first',
                'label' => __('Security Deposit Amount', 'text-domain') . ' (' . get_woocommerce_currency_symbol() . ')',
                'desc_tip' => 'true',
                'value' => get_post_meta($variation->ID, '_security_deposit', true),
            ));

            // woocommerce_wp_select(array(
            //     'id' => '_pickup_or_delivery[' . $variation->ID . ']',
            //     'label' => __('Pickup/Delivery', 'text-domain'),
            //     'wrapper_class' => 'form-field variable_sale_price0_field form-row form-row-last',
            //     'options' => array(
            //         'pickup' => __('Pickup', 'text-domain'),
            //         'delivery' => __('Delivery', 'text-domain'),
            //         'both' => __('Both', 'text-domain')
            //     ),
            //     'value' => get_post_meta($variation->ID, '_pickup_or_delivery', true),
            // ));
        }

        add_action('woocommerce_save_product_variation', 'save_variation_fields', 10, 2);
        function save_variation_fields($variation_id, $loop) {
            $security_deposit = isset($_POST['_security_deposit'][$variation_id]) ? wc_clean($_POST['_security_deposit'][$variation_id]) : '';
            update_post_meta($variation_id, '_security_deposit', $security_deposit);

            $pickup_or_delivery = isset($_POST['_pickup_or_delivery'][$variation_id]) ? wc_clean($_POST['_pickup_or_delivery'][$variation_id]) : '';
            update_post_meta($variation_id, '_pickup_or_delivery', $pickup_or_delivery);
        }

        // Calculate and add security deposit to the cart
        add_action('woocommerce_cart_calculate_fees', 'add_security_deposit_to_cart');

        function add_security_deposit_to_cart() {
            global $woocommerce;
        
            foreach ($woocommerce->cart->get_cart() as $cart_item_key => $cart_item) {
                $product_id = $cart_item['product_id'];
                $variation_id = $cart_item['variation_id'];
        
                // Check if the product has variations
                if (!empty($variation_id) && $variation_id > 0) {
                    $security_deposit = get_post_meta($variation_id, 'security_deposit', true);
                } else {
                    // For simple products, use the product ID
                    $security_deposit = get_post_meta($product_id, 'security_deposit', true);
                }
        
                // If security deposit exists, add it as a fee
                if (!empty($security_deposit)) {
                    $woocommerce->cart->add_fee(__('Security Deposit', 'text-domain'), $security_deposit);
                } else {
                    // If no security deposit, you can add a default fee or leave it empty
                    $woocommerce->cart->add_fee(__('Security Deposit', 'text-domain'), '0');
                }
            }
        }


        add_filter('woocommerce_checkout_fields', 'customize_checkout_fields');

        function customize_checkout_fields($fields) {
            global $days;
        
            // Get customer's shipping address
            $customer_address = WC()->customer->get_shipping();
        
            // Check if shipping address exists and is an array
            if (is_array($customer_address)) {
                // Access individual address components
                $customer_country_code = $customer_address['country'];
                $customer_state_code = $customer_address['state'];
                $customer_city = $customer_address['city'];
                $customer_postcode = $customer_address['postcode'];
                $new_postcode = substr($customer_postcode, 0, 3);
        
                // Get selected regions and days from settings
                $regions_and_days_serialized = unserialize(get_option('regions'));
                
                // Unserialize the data
                $regions_and_days = $regions_and_days_serialized;

                
                // Iterate through the regions_and_days array
                if (is_array($regions_and_days)) {
                    foreach ($regions_and_days as $region => $allowed_days) {
                        if(!empty($new_postcode) && strpos($region, $new_postcode) !== false)
                        {
                            $days = $allowed_days;
                            wp_localize_script('custom-script', 'allowedDays', $days);
                            break;
                        }
                        if(!empty($customer_city) && stripos($region, $customer_city))
                        {
                            $days = $allowed_days;
                            wp_localize_script('custom-script', 'allowedDays', $days);
                            break;
                        }
                        if(!empty($customer_state_code) && stripos($region, $customer_state_code))
                        {
                            $days = $allowed_days;
                            wp_localize_script('custom-script', 'allowedDays', $days);
                            break;
                        }
                        if(!empty($customer_country_code) && stripos($region, $customer_country_code) !== false)
                        {
                            $days = $allowed_days;
                            wp_localize_script('custom-script', 'allowedDays', $days);
                            break;
                        }
                    } 
                }
            }
        
            return $fields; // Always return the modified fields
        }


        //DISPLAY ORDER TYPE DELIVERY/LOCAL PICKUP IN ADMIN PANEL ORDER DETAILS
        add_action('woocommerce_admin_order_data_after_billing_address', 'wps_select_checkout_field_display_admin_order_meta', 10, 1 );
        function wps_select_checkout_field_display_admin_order_meta( $order ) {
            $delivery_date = $order->get_meta('delivery_date');
            $pickup_date = $order->get_meta('pickup_date');
            
            if ( ! empty($delivery_date) ) {
                echo "<p><strong>Order Type:</strong> Delivery & Pickup<p>";
                echo '<p><strong>'.__('Delivery Date').':</strong> ' . $delivery_date . '</p>';
            }
            else if ( ! empty($pickup_date) ) {
                echo "<p><strong>Order Type:</strong> Customer Pickup & Drop Off<p>";
                echo '<p><strong>'.__('Pickup Date').':</strong> ' . $pickup_date . '</p>';
            }
        }
        

        // Save pickup date or delivery date as order meta
        add_action('woocommerce_checkout_create_order', 'save_pickup_or_delivery_date', 10, 1);

        function save_pickup_or_delivery_date($order) {
            $selected_shipping_method = $_POST['shipping_method'][0];
        
            if (strpos($selected_shipping_method, 'flat_rate') !== false && isset($_POST['delivery_date']) && !empty($_POST['delivery_date'])) {
                $delivery_date = sanitize_text_field($_POST['delivery_date']);
                $order->update_meta_data('delivery_date', $delivery_date);
            }
            elseif (strpos($selected_shipping_method, 'local_pickup') !== false && isset($_POST['pickup_date']) && !empty($_POST['pickup_date'])) {
                $pickup_date = sanitize_text_field($_POST['pickup_date']);
                $order->update_meta_data('pickup_date', $pickup_date);
            }
        }


        // Display pickup date or delivery date on thank you page in order details table
        add_action('woocommerce_order_details_after_order_table_items', 'display_pickup_or_delivery_date_in_admin');

        function display_pickup_or_delivery_date_in_admin($order) {

            $pickupDate = $order->get_meta('pickup_date');
            if (!empty($pickupDate)) {
                ?>
                <tr>
                    <th scope="row">Pickup Date:</th>
                    <td><?php echo esc_html($pickupDate); ?></td>
                </tr>
                <?php
            }
        
            $deliveryDate = $order->get_meta('delivery_date');
            if (!empty($deliveryDate)) {
                ?>
                <tr>
                    <th scope="row">Delivery Date:</th>
                    <td><?php echo esc_html($deliveryDate); ?></td>
                </tr>
                <?php
            }
            
            ?>
            <?php
        }


        // Add the radio boxes for pickup and delivery inside "Your order" section on checkout page            
        add_action( 'woocommerce_review_order_after_shipping', 'display_custom_date_fields');
        function display_custom_date_fields() {
            // Check the selected shipping method
            $chosen_shipping_method = WC()->session->get('chosen_shipping_methods')[0];

            // Check if Local Pickup is selected
            $is_local_pickup = strpos($chosen_shipping_method, 'local_pickup') !== false;

            // Check if Delivery is selected
            $is_delivery = strpos($chosen_shipping_method, 'flat_rate') !== false;
            ?>

                <tr> 
                    <th>Tree Delivery Date<span style="color: red">*</span></th>
                    <?php if ($is_local_pickup) : ?>
                    <td>
                        <!-- <p>Tree pickup is available Monday- Friday from 9AM - 5PM at Farlinger Farms (<a href="https://maps.app.goo.gl/waFbZEAZBNC7dPVR8" target="_blank">Get Directions</a>)</p> -->
                        <input type="text" id="pickup_date" name="pickup_date" class="pickup_date" placeholder="Select a Pickup Date" required>
                    </td>

                    
                    <?php elseif ($is_delivery) : ?>
                        <td><input type="text" id="delivery_date" name="delivery_date" class="delivery_date" placeholder="Select a Delivery Date" required></td>
                        <?php endif; ?>
                </tr>
                <?php if($is_delivery): ?>
                <tr>
                    <th>Tree Pickup Date</th>
                    <td id="tree-pickup-date"></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <?php if($is_local_pickup): ?>
                    <td colspan="2" style="text-align: left;">
                        <ul>
                            <li>Once the tree is picked up, it must remain indoors for the entire rental period</li>
                            <li>Please return your tree within 24 days of pickup in order to collect your deposit</li>
                            <li>Returns can be made after Christmas, Monday-Friday between 9:00am and 5:00pm at the farm</li>
                        </ul>
                    </td>
                    <?php elseif($is_delivery): ?>
                        <td colspan="2" style="text-align: left;">
                            <ul>
                                <li>If these delivery and pickup dates do not fit your schedule, feel free to stop by and pick up or drop off your tree during our opening hours: Monday-Friday between 9:00am and 5:00pm at the farm</li>
                            </ul>
                        </td>
                    <?php endif;?>
                </tr>
                <?php
        }
               
        

        class WC_Custom_Shipping_Method extends WC_Shipping_Method {
            function get_delivery_days() {
                return array(
                    'monday' => __('Monday', 'your-text-domain'),
                    'tuesday' => __('Tuesday', 'your-text-domain'),
                    'wednesday' => __('Wednesday', 'your-text-domain'),
                    'thursday' => __('Thursday', 'your-text-domain'),
                    'friday' => __('Friday', 'your-text-domain'),
                    'saturday' => __('Saturday', 'your-text-domain'),
                    'sunday' => __('Sunday', 'your-text-domain'),
                );
            }

            public function __construct() {
                $this->id                 = 'custom_shipping_method'; // Unique ID for your shipping method.
                $this->method_title       = __('Custom Shipping Method', 'text-domain'); // Title shown in WooCommerce settings.
                $this->method_description = __('Customize your custom shipping method', 'text-domain');
                $this->enabled = 'yes'; // Enable this shipping method.
                $this->init();
                // Initialize custom settings fields




                
            }
        
            public function init() {
                $this->init_form_fields();
                $saved_options = unserialize(get_option('regions', ''));

                // Define your settings fields
                $this->form_fields = array(
                    'enabled' => array(
                        'title' => __('Enable/Disable', 'your-text-domain'),
                        'type' => 'checkbox',
                        'label' => __('Enable this shipping method', 'your-text-domain'),
                        'default' => 'no',
                    ),
                    'title' => array(
                        'title' => __('Method Title', 'your-text-domain'),
                        'type' => 'text',
                        'description' => __('This controls the title which the user sees during checkout.', 'your-text-domain'),
                        // 'default' => __('Custom Shipping', 'your-text-domain'),
                        'placeholder' => __('Shipping Method Title', 'your-text-domain'),
                        'desc_tip' => true,
                    ),
                );

                $regions = $this->get_shipping_regions_options(); // Define a function to fetch available regions
                $region_days = $this->get_delivery_days();
                $default_days = array();
                foreach($regions as $region_name => $region_code){

                    $day_options = array();
                    foreach ($region_days as $day_code => $day_name) {
                        $day_options[$day_code] = $day_name;
                    }

                    $region = str_replace(' ', '_', $region_code);
                    if (isset($saved_options["woocommerce_custom_shipping_method_region_{$region}"])) {
                        $default_days = $saved_options["woocommerce_custom_shipping_method_region_{$region}"];
                    }

                    $this->form_fields["region_{$region_code}_days"] = array(
                        'title' => sprintf(__('Delivery Days for %s', 'your-text-domain'), $region_name),
                        'type' => 'multiselect',
                        'class' => 'wc-enhanced-select',
                        'description' => sprintf(__('Select delivery days for %s region.', 'your-text-domain'), $region_name),
                        'options' => $day_options,
                        'desc_tip' => true,
                        'default' => $default_days, // Populate the default values from the saved options
                    );
                }
                // Save settings in the admin.
                add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
                $this->init_settings();
            }
             
            public function init_form_fields() {
                $saved_options = unserialize(get_option('regions', '')); // Load saved options from the database
                
            
                $this->instance_form_fields = array(
                    'enabled' => array(
                        'title' => __('Enable/Disable', 'text-domain'),
                        'type' => 'checkbox',
                        'label' => __('Enable this shipping method', 'text-domain'),
                        'default' => 'no',
                    ),
                    'title' => array(
                        'title' => __('Method Title', 'text-domain'),
                        'type' => 'text',
                        'description' => __('This controls the title which the user sees during checkout.', 'text-domain'),
                        'placeholder' => __('Shipping Method Title', 'your-text-domain'),
                        'desc_tip' => true,
                    )
                );
            
                // Dynamically add fields for each region
                $regions = $this->get_shipping_regions_options(); // Define a function to fetch available regions
                $region_days = $this->get_delivery_days();
                
            
                $all_default_days = array(); // Create an empty array to store all default days

                foreach($regions as $region_name => $region_code){
                    $day_options = array();
                    foreach ($region_days as $day_code => $day_name) {
                        $day_options[$day_name] = $day_code;
                    }
                
                    $default_days = array();
                    $region = str_replace(' ', '_', $region_code);
                    if (isset($saved_options["woocommerce_custom_shipping_method_region_{$region}"])) {
                        $default_days = $saved_options["woocommerce_custom_shipping_method_region_{$region}"];
                    }
                
                    // Add default days to the aggregated array
                    $all_default_days[$region_code] = $default_days;
                
                    // Populate instance form fields
                    $this->instance_form_fields["region_{$region_code}_days"] = array(
                        'title' => sprintf(__('Delivery Days for %s', 'your-text-domain'), $region_name),
                        'type' => 'multiselect',
                        'class' => 'wc-enhanced-select',
                        'description' => sprintf(__('Select delivery days for %s region.', 'your-text-domain'), $region_name),
                        'options' => $day_options,
                        'desc_tip' => true,
                        'default' => $default_days,
                    );
                }
                
                // Localize all default days in a single call
                wp_localize_script('custom-admin-script', 'select2_defaults', array(
                    'defaultDays' => $all_default_days,
                ));
            }

            private function get_shipping_regions_options() {
                $options = array();
            
                // Get shipping zones
                $shipping_zones = WC_Shipping_Zones::get_zones();
            
                foreach ($shipping_zones as $shipping_zone) {
                    $zone_name = $shipping_zone['zone_name'];
                    $zone_locations = $shipping_zone['zone_locations'];
            
                    foreach ($zone_locations as $location) {
                        // Access object properties correctly
                        $location_code = $location->code;
                        $location_type = $location->type;
            
                        // Format as 'Zone Name: Location Code - Location Type'
                        $option_value = "{$zone_name}:{$location_code}";
                        $option_label = "{$zone_name} - {$location_code} ({$location_type})";
            
                        // Check if the region code already exists, if not, create a new entry
                        if (!isset($options[$option_value])) {
                            $options[$option_value] = $option_label;
                        }
                    }
                }
            
                return $options;
            }
            

            public function process_admin_options() {
                $this->init_form_fields();
                // Save the combined regions and days data as the 'regions' option
                $regions_with_days = array();
                foreach ($_POST as $key => $value) {
                    // Check if the key contains "woocommerce_custom_shipping_method_region"
                    if (strpos($key, 'woocommerce_custom_shipping_method_region') !== false) {
                        // Extract region name from the key
                        $region = substr($key, 0, strpos($key, "_days"));
                
                        // Add region and days to the $regions_with_days array
                        $regions_with_days[$region] = $value;
                    }
                }
                update_option('regions', serialize($regions_with_days));

                $saved_options = unserialize(get_option('regions', ''));
                // Dynamically add fields for each region
                $regions = $this->get_shipping_regions_options(); // Define a function to fetch available regions
                $region_days = $this->get_delivery_days();
                
            
                $all_default_days = array(); // Create an empty array to store all default days

                foreach($regions as $region_name => $region_code){
                    $day_options = array();
                    foreach ($region_days as $day_code => $day_name) {
                        $day_options[$day_name] = $day_code;
                    }
                
                    $default_days = array();
                    $region = str_replace(' ', '_', $region_code);
                    if (isset($saved_options["woocommerce_custom_shipping_method_region_{$region}"])) {
                        $default_days = $saved_options["woocommerce_custom_shipping_method_region_{$region}"];
                    }
                
                    // Add default days to the aggregated array
                    $all_default_days[$region_code] = $default_days;
                
                }
                
                ?>
                <script type="text/javascript">
                    // Set default days for each region after saving changes
                    document.addEventListener("DOMContentLoaded", function() {
                        var select2DefaultsAfterSave = <?php echo json_encode($all_default_days); ?>;
                        Object.keys(select2DefaultsAfterSave).forEach(function(multiselectName) {
                            var days = select2DefaultsAfterSave[multiselectName];
                            jQuery("select[name='woocommerce_custom_shipping_method_region_" + multiselectName + "_days[]']").val(days).trigger("change");
                        });
                    });
                </script>
                <?php                
            
                parent::process_admin_options();
            }

            public function calculate_shipping($package = array()) {
                // Get selected regions and days from settings
                $selected_regions = $this->get_option('regions', array());
                $region = 'CA:AB'; // Get the region code from the package data
            
                // Default shipping cost
                $shipping_cost = 0;
            
                // Check if the region is in the selected regions list and today is a selected day
                if (isset($selected_regions[$region]) && in_array(strtolower(date('l')), $selected_regions[$region])) {
                    // Get the cost from a specific shipping method (e.g., flat_rate, free_shipping, local_pickup)
                    $shipping_methods = WC()->shipping->get_shipping_methods();
            
                    // Check if the flat rate shipping method is available
                    if (isset($shipping_methods['flat_rate'])) {
                        $flat_rate_cost = $shipping_methods['flat_rate']->cost; // Get flat rate shipping cost
                        $shipping_cost = $flat_rate_cost; // Set shipping cost to flat rate cost
                    } else {
                        // Handle the case where the flat rate shipping method is not available
                        // You might want to set a default or handle it according to your specific requirements
                    }
                }
            
                // Add the calculated shipping cost to the package
                $this->add_rate(array(
                    'id' => $this->id,
                    'label' => $this->title,
                    'cost' => $shipping_cost,
                    'calc_tax' => 'per_order'
                ));
            }     
        }
        
        // Register the custom shipping method.
        function add_custom_shipping_method($methods) {
            $methods['custom_shipping_method'] = 'WC_Custom_Shipping_Method';
            return $methods;
        }
        add_filter('woocommerce_shipping_methods', 'add_custom_shipping_method');
        


        
    } else {
        // WooCommerce is not active, display an admin notice or deactivate your plugin
        add_action('admin_notices', 'woocommerce_not_active_notice');
    }
}
