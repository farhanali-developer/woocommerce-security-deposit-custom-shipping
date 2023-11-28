jQuery(document).ready(function($) {
    $('.wc-enhanced-select').select2({
        closeOnSelect : false,
        placeholder : "Select Days of the week.",
        allowClear: true,
    });

    Object.keys(select2_defaults.defaultDays).forEach(function(multiselectName) {
        var days = select2_defaults.defaultDays[multiselectName];
        $('select[name="woocommerce_custom_shipping_method_region_' + multiselectName + '_days[]"]').val(days).trigger('change');
    });
});
