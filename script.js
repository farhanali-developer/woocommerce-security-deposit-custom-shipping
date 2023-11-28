// Use jQuery.noConflict() to avoid conflicts with other libraries using $
jQuery.noConflict();

jQuery(document).ready(function($) {
        

        var width = $(window).width(); 

        if ((width >= 922  )) {
            $(".ast-mobile-order-review-wrap").remove();
        }
        else {
        //do something else
        }


            $(document).on('focus', '#delivery_date', function() {
                // Store a reference to the input field
                var $input = $(this);
                
                // Initialize the datepicker with the desired options
                $input.datepicker({
                    // showButtonPanel: true,
                    buttonImage: "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABEAAAATCAYAAAB2pebxAAABGUlEQVQ4jc2UP06EQBjFfyCN3ZR2yxHwBGBCYUIhN1hqGrWj03KsiM3Y7p7AI8CeQI/ATbBgiE+gMlvsS8jM+97jy5s/mQCFszFQAQN1c2AJZzMgA3rqpgcYx5FQDAb4Ah6AFmdfNxp0QAp0OJvMUii2BDDUzS3w7s2KOcGd5+UsRDhbAo+AWfyU4GwnPAYG4XucTYOPt1PkG2SsYTbq2iT2X3ZFkVeeTChyA9wDN5uNi/x62TzaMD5t1DTdy7rsbPfnJNan0i24ejOcHUPOgLM0CSTuyY+pzAH2wFG46jugupw9mZczSORl/BZ4Fq56ArTzPYn5vUA6h/XNVX03DZe0J59Maxsk7iCeBPgWrroB4sA/LiX/R/8DOHhi5y8Apx4AAAAASUVORK5CYII=",
                    buttonImageOnly: true,
                    dateFormat: "MM d, yy",
                    changeMonth: true,
                    changeYear: true,
                    yearRange: "c-100:c+10",
                    dayNamesMin: ["S", "M", "T", "W", "T", "F", "S"],
                    minDate: dateToday,
                    beforeShowDay: function(date) {
                        if (!isAllowedDay(date)) {
                            return [false, '']; // Disable if not an allowed day
                        }
                
                        var day = date.getDay(); // Get the day of the week (0 for Sunday, 1 for Monday, etc.)
                        var weekNumber = Math.ceil((date.getDate() - date.getDay() + 1) / 7); // Calculate the week number of the month
                
                        // Enable dates in the 2nd and 3rd weeks of December
                        if (date.getMonth() === 11 && (weekNumber === 2 || weekNumber === 3)) { // December (JavaScript months are zero-based)
                            return [true, '']; // Enable dates in the 2nd and 3rd weeks of December
                        } else {
                            return [false, '']; // Disable all other days
                        }
                    },
                    onSelect: function(dateText, instance) {
                        // Set the selected date in the delivery date input field
                        $input.val(dateText);
        
                        // Calculate the date 24 days after the selected delivery date
                        var selectedDate = new Date(dateText);
                        var pickupDate = new Date(selectedDate.getTime() + (24 * 24 * 60 * 60 * 1000));
        
                        // Format the pickup date as "Month dd, yyyy"
                        var formattedPickupDate = $.datepicker.formatDate("MM dd, yy", pickupDate);
        
                        // Set the calculated pickup date as the value of the "tree-pickup-date" input field
                        $('#tree-pickup-date').text(formattedPickupDate);
                    }
                });
            
                // Open the datepicker when the input field is clicked
                $input.datepicker('show');
            });

            $(document).on('focus', '#pickup_date', function() {
                // Store a reference to the input field
                var $input = $(this);
            
                // Initialize the datepicker with the desired options
                $input.datepicker({
                    // showButtonPanel: true,
                    buttonImage: "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABEAAAATCAYAAAB2pebxAAABGUlEQVQ4jc2UP06EQBjFfyCN3ZR2yxHwBGBCYUIhN1hqGrWj03KsiM3Y7p7AI8CeQI/ATbBgiE+gMlvsS8jM+97jy5s/mQCFszFQAQN1c2AJZzMgA3rqpgcYx5FQDAb4Ah6AFmdfNxp0QAp0OJvMUii2BDDUzS3w7s2KOcGd5+UsRDhbAo+AWfyU4GwnPAYG4XucTYOPt1PkG2SsYTbq2iT2X3ZFkVeeTChyA9wDN5uNi/x62TzaMD5t1DTdy7rsbPfnJNan0i24ejOcHUPOgLM0CSTuyY+pzAH2wFG46jugupw9mZczSORl/BZ4Fq56ArTzPYn5vUA6h/XNVX03DZe0J59Maxsk7iCeBPgWrroB4sA/LiX/R/8DOHhi5y8Apx4AAAAASUVORK5CYII=",
                    buttonImageOnly: true,
                    dateFormat: "MM d, yy",
                    changeMonth: true,
                    changeYear: true,
                    yearRange: "c-100:c+10",
                    dayNamesMin: ["S", "M", "T", "W", "T", "F", "S"],
                    minDate: dateToday, // December is 11 because months are zero-indexed in JavaScript,
                    onSelect: function(dateText, instance) {
                        // Set the selected date in the input field
                        $input.val(dateText);
                    }
                });
            
                // Open the datepicker when the input field is clicked
                $input.datepicker('show');
            });
            


    var dateToday = new Date();
    let i = 0;


    var allowedDaysArray = allowedDays;

    

    function getDayName(dayOfWeek) { 
        // Define an array to map day of week to weekday names
        var daysOfWeek = ["sunday", "monday", "tuesday", "wednesday", "thursday", "friday", "saturday"];
        // Return the weekday name based on the day of the week
        return daysOfWeek[dayOfWeek];
    }
    
    function isAllowedDay(date) {
        // Get the day of the week (0 for Sunday, 1 for Monday, and so on)
        var dayOfWeek = date.getDay();
        // Get the name of the day (e.g., "monday", "tuesday")
        var dayName = getDayName(dayOfWeek).toLowerCase();

        return allowedDaysArray.includes(dayName);
    }

});
