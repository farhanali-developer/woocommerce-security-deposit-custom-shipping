# Artwork Upload Fixes Documentation

## Overview
This document explains the fixes and enhancements made to enable artwork uploads to be properly attached to WooCommerce orders and displayed throughout the order process.

## Key Issues Fixed

### 1. **Session Persistence of Artwork Data**
- The `get_custom_fields_from_session` function now includes `artwork_attachment_id` and `artwork_url` in the array of custom keys that get loaded from session storage.

### 2. **Order Item Meta Storage**
- Modified `add_custom_fields_to_order` function to save artwork data with both public and private meta keys:
  - `_artwork_attachment_id` (private, for internal use)
  - `_artwork_url` (private, for internal use)  
  - `Artwork Attachment ID` (public, visible in order details)
  - `Artwork URL` (public, visible in order details)

### 3. **Backend Order Display**
- Added `display_artwork_in_admin_order` function to show artwork thumbnails in the WordPress admin order details page
- Added artwork column to orders list table for quick visibility

### 4. **Customer-Facing Display**
- **Thank You Page**: Added `display_artwork_on_thank_you` function to show uploaded artwork with thumbnail preview
- **Order Emails**: Added `add_artwork_to_emails` function to include artwork links and thumbnails in both HTML and plain text emails
- **My Account**: Modified order item names to include artwork links in customer's order history

### 5. **Cart/Checkout Validation**
- Enhanced the existing `check_cart_items_for_artwork` function to provide specific error messages for items missing artwork
- Added redirect logic to prevent checkout if artwork is missing

## New Features Added

### 1. **Visual Artwork Display**
- Thumbnail previews in admin, emails, and thank you page
- Full-size image links throughout the order process

### 2. **Artwork Column in Orders Table**
- Quick visual indicator (✓ or —) showing which orders have artwork attached

### 3. **Cleanup Functionality**
- Optional scheduled task to clean up orphaned artwork attachments older than 30 days
- Helps manage server storage by removing unused uploads

### 4. **Improved Error Handling**
- Better validation messages when artwork is missing
- Prevents checkout completion without required artwork

## How It Works

1. **Upload Process**: 
   - User adds product to cart
   - Redirected to artwork upload page
   - Uploads PNG file
   - File saved to Media Library
   - Attachment ID and URL saved to cart item

2. **Order Creation**:
   - Cart item data (including artwork) transferred to order item meta
   - Both private and public meta keys used for flexibility

3. **Display Logic**:
   - Admin sees thumbnails in order details
   - Customer sees artwork on thank you page and in emails
   - Download links available in My Account area

## Testing Checklist

- [ ] Add product to cart and upload artwork
- [ ] Verify artwork appears in cart
- [ ] Complete checkout
- [ ] Check artwork visible in:
  - [ ] Admin order details
  - [ ] Thank you page
  - [ ] Order confirmation email
  - [ ] Customer's My Account > Orders
  - [ ] Admin orders list (artwork column)

## Configuration

The artwork upload page URL is configured in this line:
```php
define('ARTWORK_UPLOAD_PAGE_URL', 'https://albertastickers.com/artwork-upload/');
```

## File Types

Currently configured to accept:
- PNG files (primary)
- WebP files
- JPEG files

To modify accepted file types, edit the `$allowed_mimes` array in the `handle_artwork_upload_submission` function.