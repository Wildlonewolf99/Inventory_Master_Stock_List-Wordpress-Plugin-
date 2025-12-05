jQuery(document).ready(function($) {

    // Global check for required environment variables
    if (typeof ISInventory === 'undefined' || !ISInventory.ajax_url || !ISInventory.nonce) {
        console.error("ISInventory global object or its essential properties are missing.");
        return;
    }

    // =========================================================
    // 1. PRODUCT EDIT SCREEN: Single Variation/Simple Product Save
    // =========================================================

    // --- Save for a single product/variation in the sidebar metabox ---
    $(document).on('click', '.is-save-variation', function(e) {
        e.preventDefault();
        var button = $(this);
        var variation_id = button.data('variation-id');
        
        // Select input using class for specificity
        var input = $('input.is-variation-stock-input[data-variation-id="' + variation_id + '"]'); 
        var stock = input.val();

        if (input.length === 0) {
            alert('Error: Stock input field not found for Variation ID: ' + variation_id);
            return;
        }

        button.prop('disabled', true).text('Saving...');
        input.prop('disabled', true);

        $.ajax({
            url: ISInventory.ajax_url,
            type: 'POST',
            data: {
                action: 'is_update_variation_stock',
                nonce: ISInventory.nonce,
                variation_id: variation_id,
                stock: stock
            },
            success: function(response) {
                if (response.success) {
                    input.data('current-stock', stock);
                    button.text('Saved!');
                } else {
                    alert('Error saving stock: ' + (response.data.message || 'Unknown error'));
                    button.text('Save Failed');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                alert('AJAX error saving stock. Status: ' + textStatus + ', Error: ' + errorThrown);
                button.text('Save Error');
            },
            complete: function() {
                setTimeout(function() {
                    button.prop('disabled', false).text('Save');
                    input.prop('disabled', false);
                }, 1500);
            }
        });
    });

    // --- Save for a single simple product in the sidebar metabox ---
    $(document).on('click', '.is-save-simple', function(e) {
        e.preventDefault();
        var button = $(this);
        var product_id = button.data('product-id');
        var input = $('#is-simple-stock');
        var stock = input.val();

        button.prop('disabled', true).text('Saving...');
        input.prop('disabled', true);

        $.ajax({
            url: ISInventory.ajax_url,
            type: 'POST',
            data: {
                action: 'is_update_variation_stock', 
                nonce: ISInventory.nonce,
                variation_id: product_id, // Pass product ID for simple product
                stock: stock
            },
            success: function(response) {
                if (response.success) {
                    input.data('current-stock', stock);
                    button.text('Saved!');
                } else {
                    alert('Error saving stock: ' + (response.data || 'Unknown error'));
                    button.text('Save Failed');
                }
            },
            error: function() {
                alert('AJAX error saving stock.');
                button.text('Save Error');
            },
            complete: function() {
                setTimeout(function() {
                    button.prop('disabled', false).text('Save');
                    input.prop('disabled', false);
                }, 1500);
            }
        });
    });

    // =========================================================
    // 2. MASTER INVENTORY LIST SCREEN: Bulk Save
    // =========================================================

    // CRITICAL FIX: Bind to the form's 'submit' event to prevent default HTML submission.
    $('#is-bulk-inventory-form').on('submit', function(e) {
        // e.preventDefault(); 
        
        var button = $('#is-bulk-save-stock');
        var originalText = button.val();
        var form = $(this);
        var nonce = form.find('#is_bulk_save_nonce').val() || ISInventory.nonce;
        var updates = [];

        // Collect all input fields where stock has changed
        $('.is-bulk-stock-input').each(function() {
            var input = $(this);
            var newStock = parseInt(input.val());
            var originalStock = parseInt(input.data('current-stock'));
            // Using data-vid (Variation ID)
            var variationId = input.data('vid'); 
            
            // Only process changed values that have a valid ID
            if (newStock !== originalStock && variationId && !isNaN(variationId)) {
                updates.push({
                    variation_id: variationId, 
                    stock: newStock
                });
            }
        });

        if (updates.length === 0) {
            $('.is-bulk-save-status').text('No stock changes detected.').css('color', 'orange');
            return;
        }

        button.prop('disabled', true).val('Saving...');
        $('.is-bulk-save-status').text('Processing ' + updates.length + ' updates...');
        
        // --- Bulk Update Logic (Chunking) ---
        var chunkSize = 50;
        var processedCount = 0;
        var successfulCount = 0;

        function processChunk(chunkIndex) {
            var start = chunkIndex * chunkSize;
            var end = start + chunkSize;
            var chunk = updates.slice(start, end);

            if (chunk.length === 0) {
                button.prop('disabled', false).val(originalText);
                $('.is-bulk-save-status').text('✅ Saved ' + successfulCount + ' items successfully. Reload the page to confirm.').css('color', 'green');
                return;
            }

            var bulkData = {
                action: 'is_bulk_update_master_stock', 
                nonce: nonce,
                updates: chunk 
            };

            $.post(ISInventory.ajax_url, bulkData, function(response) {
                if (response.success) {
                    successfulCount += response.data.count;
                    processedCount += chunk.length;
                    
                    // Update the input field's data-current-stock attribute
                    response.data.results.forEach(function(result) {
                        if (result.status === 'updated') {
                            $('input.is-bulk-stock-input[data-vid="' + result.variation_id + '"]')
                                .data('current-stock', result.stock)
                                .val(result.stock);
                        }
                    });

                    $('.is-bulk-save-status').text('Processing... ' + processedCount + '/' + updates.length + ' completed. (' + successfulCount + ' successful)');
                    processChunk(chunkIndex + 1);
                } else {
                    button.prop('disabled', false).val(originalText);
                    $('.is-bulk-save-status').text('❌ An error occurred while saving: ' + (response.data.message || 'Unknown Error')).css('color', 'red');
                }
            }).fail(function() {
                button.prop('disabled', false).val(originalText);
                $('.is-bulk-save-status').text('❌ Server error during saving.').css('color', 'red');
            });
        }
        
        processChunk(0);
    });

    // =========================================================
    // 3. MASTER INVENTORY LIST SCREEN: Export Handler
    // =========================================================

    $('#is-export-button').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var originalText = button.text();
        var nonce = button.data('nonce') || ISInventory.nonce;

        button.prop('disabled', true).text('Generating CSV...');
        
        // Triggers the PHP AJAX handler and download
        var exportUrl = ISInventory.ajax_url + 
            '?action=is_export_inventory&nonce=' + nonce; 

        window.location.href = exportUrl;

        // Re-enable button after a short delay
        setTimeout(function() {
            button.prop('disabled', false).text(originalText);
        }, 3000);
    });
    
});
