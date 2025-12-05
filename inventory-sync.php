<?php
/*
Plugin Name: Inventory Sync (Master & Client)
Description: Centralized inventory manager for WooCommerce: master pushes stock updates to multiple client sites. Editable inventory table on product edit screen.
Version: 3.0
Author: Praveen Thamotharan
URL: https://www.linkedin.com/in/praveen-thamotharan-a446281a6/
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// Ensure WP_List_Table is available
if ( ! class_exists( 'WP_List_Table' ) ) {
require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class IS_Inventory_Sync {
private $option_name = 'is_inventory_sync_options';
private $nonce_action = 'is_inventory_sync_nonce_action';
private $rest_namespace = 'is-inventory/v1';

public function __construct() {
add_action( 'init', [ $this, 'init_hooks' ] );
register_activation_hook( __FILE__, [ $this, 'activate' ] );
}

public function activate() {
$defaults = [
'mode' => 'master', // 'master' or 'client' (default master)
'api_key' => wp_generate_password( 32, false, false ),
'clients' => [], // for master: array of [ 'url' => 'https://store2.com', 'key' => 'secret' ]
'last_sync' => null,
];
if ( ! get_option( $this->option_name ) ) {
add_option( $this->option_name, $defaults );
}
}

public function init_hooks() {
// Admin menus
add_action( 'admin_menu', [ $this, 'admin_menu' ] );

// Product meta box on edit screen (variations table)
add_action( 'add_meta_boxes', [ $this, 'add_inventory_metabox' ] );
add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

// AJAX endpoints
add_action( 'wp_ajax_is_update_variation_stock', [ $this, 'ajax_update_variation_stock' ] );
add_action( 'wp_ajax_is_bulk_update_master_stock', [ $this, 'ajax_bulk_update_master_stock' ] );
// NEW AJAX HANDLER FOR EXPORT (Point 6)
add_action( 'wp_ajax_is_export_inventory', [ $this, 'ajax_export_inventory' ] );

// REST endpoints
add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

// Filter: ensure plugin only works when WooCommerce active
add_action( 'admin_notices', [ $this, 'maybe_woo_missing_notice' ] );
}

public function maybe_woo_missing_notice(){
if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
echo '<div class="notice notice-error"><p><strong>Inventory Sync:</strong> WooCommerce must be active for this plugin to work.</p></div>';
}
}

public function get_options(){
return get_option( $this->option_name, [] );
}

public function update_options( $arr ) {
update_option( $this->option_name, $arr );
}

/* -------------------------
Admin UI
--------------------------*/
public function admin_menu(){
add_menu_page( 'Inventory Sync', 'Inventory Sync', 'manage_woocommerce', 'is-inventory-sync', [ $this, 'admin_page' ], 'dashicons-portfolio', 56 );
}

public function enqueue_assets( $hook ) {
// load only on plugin pages and product edit pages
if ( $hook === 'toplevel_page_is-inventory-sync' || $hook === 'post.php' || $hook === 'post-new.php' ) {
wp_enqueue_script( 'is-inventory-js', plugin_dir_url(__FILE__) . 'assets/is-inventory.js', [ 'jquery' ], '1.9', true );
wp_localize_script( 'is-inventory-js', 'ISInventory', [
'ajax_url' => admin_url( 'admin-ajax.php' ),
'nonce'    => wp_create_nonce( $this->nonce_action ),
'list_table_page_url' => menu_page_url('is-inventory-sync', false), // NEW: For filtering
] );

// Enqueue custom style handle
wp_enqueue_style( 'is-inventory-css', plugin_dir_url(__FILE__) . 'assets/is-inventory.css', [], '1.9' );
}
}

public function admin_page() {
if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Permission denied' );
$opts = $this->get_options();

// Handle form submissions (simple)
if ( isset($_POST['is_save_settings']) && check_admin_referer('is_save_settings_action','is_save_settings_nonce') ) {
$mode = sanitize_text_field( $_POST['mode'] ?? 'master' );
$api_key = sanitize_text_field( $_POST['api_key'] ?? $opts['api_key'] );
$clients_raw = sanitize_textarea_field( $_POST['clients'] ?? '' );
// clients input format: one per line "https://store2.com,SECRETKEY"
$clients = [];
if ( $clients_raw ) {
$lines = array_filter( array_map('trim', explode("\n", $clients_raw)) );
foreach( $lines as $line ) {
$parts = array_map('trim', explode(',', $line) );
if ( ! empty($parts[0]) ) {
$clients[] = [ 'url' => rtrim($parts[0],'/'), 'key' => $parts[1] ?? '' ];
}
}
}
$opts['mode'] = $mode;
$opts['api_key'] = $api_key;
$opts['clients'] = $clients;
$this->update_options( $opts );
echo '<div class="updated"><p>Settings saved.</p></div>';
}

// Handle sync action
if ( isset($_POST['is_sync_now']) && check_admin_referer('is_sync_now_action','is_sync_now_nonce') ) {
$res = $this->do_sync();
echo '<div class="updated"><p>Sync completed. Push results:</p><pre style="max-height:200px;overflow:auto;">' . esc_html( print_r($res, true) ) . '</pre></div>';
}

// Render page
?>
<div class="wrap">
<h1>Inventory Sync</h1>
<form method="post">
<?php wp_nonce_field( 'is_save_settings_action','is_save_settings_nonce' ); ?>
<table class="form-table">
<tr>
<th>Mode</th>
<td>
<select name="mode">
<option value="master" <?php selected( $opts['mode'] ?? 'master', 'master' ); ?>>Master (central controller)</option>
<option value="client" <?php selected( $opts['mode'] ?? 'master', 'client' ); ?>>Client (remote store)</option>
</select>
<p class="description">Master will push stock updates to configured Clients. Client will accept updates from Master.</p>
</td>
</tr>
<tr>
<th>API Key (this site)</th>
<td>
<input type="text" name="api_key" value="<?php echo esc_attr( $opts['api_key'] ?? '' ); ?>" style="width:420px;">
<p class="description">Store this secret on the remote site(s) or in the master configuration so they can authenticate requests. Keep this private.</p>
</td>
</tr>
<tr>
<th>Clients (master only)</th>
<td>
<textarea name="clients" rows="6" cols="80" placeholder="https://store2.com,SECRETKEY"><?php
if ( ! empty( $opts['clients'] ) && is_array( $opts['clients'] ) ) {
foreach( $opts['clients'] as $c ) {
echo esc_textarea( $c['url'] . ',' . $c['key'] ) . "\n";
}
}
?></textarea>
<p class="description">One per line: <code>https://client-site.com,CLIENT_API_KEY</code></p>
</td>
</tr>
</table>
<?php submit_button( 'Save Settings', 'primary', 'is_save_settings' ); ?>
</form>

<?php if ( ( $opts['mode'] ?? 'master' ) === 'master' ) : ?>
<h2>Manual Sync</h2>
<form method="post">
<?php wp_nonce_field('is_sync_now_action','is_sync_now_nonce'); ?>
<p><button type="submit" class="button button-primary" name="is_sync_now">Sync Now (push local stock to clients)</button></p>
</form>

<?php $this->render_master_inventory_table(); // Render the bulk edit table ?>

<h2>Quick Overview</h2>
<p>Last sync: <?php echo esc_html( $opts['last_sync'] ?? 'never' ); ?></p>
<p>Connected clients: <?php echo intval( count( $opts['clients'] ?? [] ) ); ?></p>
<p>Recommended: use HTTPS on client endpoints and restrict access by IP if possible.</p>
<?php else: ?>
<h2>Client Mode</h2>
<p>This site will accept POST updates from Master at REST endpoint: <code><?php echo esc_url( rest_url( $this->rest_namespace . '/update-stock' ) ); ?></code></p>
<p>Master should call with header <code>X-IS-KEY: CLIENT_API_KEY</code> and JSON body like:</p>
<pre>{
"sku": "ABC123",
"variations": [
{ "variation_sku": "ABC123-RED", "stock": 10 },
{ "variation_sku": "ABC123-BLUE", "stock": 3 }
]
}</pre>
<?php endif; ?>
</div>
<?php
}

/* -------------------------
Master Bulk Inventory Table Handler
--------------------------*/
public function render_master_inventory_table() {
if ( ! current_user_can( 'manage_woocommerce' ) ) return;

// Initialize the list table
$inventory_list_table = new IS_Inventory_List_Table();
$inventory_list_table->prepare_items();

?>
<hr>
<h2>Master Inventory List (Horizontal Matrix) 📝</h2>
<p>Adjust stock levels below and click **Save Changes (Locally)**. Then, click **Sync Now** to push all updated local stock to your client sites. Only **variable products with variations that manage stock and have SKUs** are listed. If nothing appears, check the **`wp-content/debug.log`** file for **`IS_MATRIX_LOG:`** messages.</p>

<form id="is-filter-form" method="get">
<input type="hidden" name="page" value="is-inventory-sync" />
<?php $inventory_list_table->search_box( 'Search Products', 'series_search' ); // Point 3 ?>
<?php
// A-Z Filter (Point 1)
$current_letter = sanitize_text_field( $_GET['filter_by_series'] ?? '' );
$az_letters = array_merge( range('A', 'Z'), range(0, 9) );
?>
<div class="alignleft actions">
<ul class="subsubsub" style="float:none; display:inline-block;">
<li><a href="<?php echo esc_url( remove_query_arg(['filter_by_series', 'paged']) ); ?>" class="<?php echo empty($current_letter) ? 'current' : ''; ?>">All</a> |</li>
<?php foreach ($az_letters as $letter):
$url = add_query_arg('filter_by_series', $letter, remove_query_arg('paged'));
?>
<li><a href="<?php echo esc_url($url); ?>" class="<?php echo ($current_letter === $letter) ? 'current' : ''; ?>"><?php echo esc_html($letter); ?></a><?php if ($letter !== 9) echo ' |'; ?></li>
<?php endforeach; ?>
</ul>
</div>
</form>

<form id="is-bulk-inventory-form" method="post">
<?php wp_nonce_field( $this->nonce_action, 'is_bulk_save_nonce' ); ?>

<div class="is-matrix-container">
<?php $inventory_list_table->display(); ?>
</div>

<?php
// Display Grand Total Statement
if ( $inventory_list_table->grand_total_stock > 0 ) {
echo '<div class="is-grand-total-statement">';
echo '<h3>Total Stock in Inventory: <span class="is-total-stock-value">' . number_format( $inventory_list_table->grand_total_stock ) . ' units</span></h3>';
echo '</div>';
}
?>
<div class="is-matrix-actions">
<?php submit_button( 'Save Changes (Locally)', 'secondary', 'is_bulk_save_stock', true, ['style' => 'margin-top:10px;'] ); ?>
<button type="button" id="is-export-button" class="button button-primary" data-nonce="<?php echo wp_create_nonce( $this->nonce_action ); ?>">Export All Stock to CSV</button>
</div>
<p class="is-bulk-save-status" style="font-weight: bold;"></p>
</form>
<?php
}

/* -------------------------
AJAX Handlers
--------------------------*/

public function ajax_update_variation_stock() {
		check_ajax_referer( 'is-inventory-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$variation_id = isset( $_POST['variation_id'] ) ? intval( $_POST['variation_id'] ) : 0;
		$stock        = isset( $_POST['stock'] ) ? intval( $_POST['stock'] ) : 0;

		if ( ! $variation_id ) {
			wp_send_json_error( [ 'message' => 'Missing product or variation ID.' ] );
		}

		try {
			// WC_Product_Factory handles both simple products (using product ID) and variations (using variation ID)
			$product = wc_get_product( $variation_id );

			if ( ! $product ) {
				wp_send_json_error( [ 'message' => 'Product or variation not found.' ] );
			}

			// Ensure stock management is enabled for this product/variation
			if ( ! $product->get_manage_stock() ) {
				$product->set_manage_stock( true );
			}

			// Update the stock quantity
			$product->set_stock_quantity( $stock );
			$product->save();
            
            // Re-fetch product to get accurate new stock value (important for confirmation)
            $new_product = wc_get_product( $variation_id );
            $new_stock = $new_product->get_stock_quantity();

			wp_send_json_success( [ 
                'message' => 'Stock updated successfully.',
                'new_stock' => $new_stock,
                'variation_id' => $variation_id,
            ] );

		} catch ( Exception $e ) {
			wp_send_json_error( [ 'message' => 'Failed to update stock: ' . $e->getMessage() ] );
		}
	}

/**
	 * AJAX handler for bulk updating the master stock list.
	 * Expects: $_POST['updates'] = array of { variation_id: ID, stock: QUANTITY }
	 */
	public function ajax_bulk_update_master_stock() {
		// 1. Security Check
		check_ajax_referer( 'is-inventory-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		// 2. Retrieve and Validate Data
		$updates = isset( $_POST['updates'] ) ? map_deep( $_POST['updates'], 'sanitize_text_field' ) : [];

		if ( empty( $updates ) || ! is_array( $updates ) ) {
			wp_send_json_error( [ 'message' => 'No updates received.' ] );
		}

		$success_count = 0;
		$results       = [];

		// 3. Process Updates
		foreach ( $updates as $update ) {
			$variation_id = intval( $update['variation_id'] ?? 0 );
			$new_stock    = intval( $update['stock'] ?? 0 );
			$status       = 'failed';
			$message      = 'Unknown error';

			if ( $variation_id > 0 ) {
				try {
					$product = wc_get_product( $variation_id );

					if ( $product ) {
						// Ensure stock is managed
						if ( ! $product->get_manage_stock() ) {
							$product->set_manage_stock( true );
						}

						// Update stock quantity
						$product->set_stock_quantity( $new_stock );
						$product->save();

						$status        = 'updated';
						$message       = 'Stock updated.';
						$success_count++;
					} else {
						$message = 'Product/Variation not found.';
					}
				} catch ( \Exception $e ) {
					$message = 'WooCommerce error: ' . $e->getMessage();
				}
			} else {
				$message = 'Invalid Variation ID.';
			}

			// Store result for JavaScript feedback
			$results[] = [
				'variation_id' => $variation_id,
				'stock'        => $new_stock,
				'status'       => $status,
				'message'      => $message,
			];
		}

		// 4. Send Success Response
		wp_send_json_success( [
			'message' => 'Bulk update complete.',
			'count'   => $success_count,
			'results' => $results,
		] );
	}

// NEW AJAX HANDLER FOR EXPORT (Point 6)
public function ajax_export_inventory() {
check_ajax_referer( $this->nonce_action, 'nonce' );
if ( ! current_user_can( 'manage_woocommerce' ) ) {
wp_send_json_error( 'Permission denied', 403 );
}

// Temporarily change display options to get ALL items
$original_per_page = 20; // Default from your prepare_items
$_REQUEST['posts_per_page'] = -1; // Get all items for export

$list_table = new IS_Inventory_List_Table();
// Force the table to fetch all data without pagination/slicing
$all_data = $list_table->get_grouped_inventory_data( true ); // passing true to bypass internal caching if needed
$columns = $list_table->get_columns();

// 1. Create the CSV header
$header = [];
$header_keys = [];
foreach ($columns as $key => $name) {
$header[] = $name;
$header_keys[] = $key;
}

// 2. Prepare the CSV content
$csv_output = implode(',', $header) . "\n";

foreach ($all_data as $item) {
$row = [];
foreach ($header_keys as $key) {
$value = '';

switch ($key) {
case 'series_name':
$value = $item['series_name'] . ' - ' . $item['color_name'];
break;
case 'total_stock':
$value = $item['total_stock'];
break;
default:
// Dynamic power columns: 'power_xxx'
if (strpos($key, 'power_') === 0) {
$power_slug = substr($key, 6);
if (isset($item['variations_by_power'][$power_slug])) {
$value = $item['variations_by_power'][$power_slug]['stock'];
} else {
$value = ''; // Empty cell for non-existent power
}
}
break;
}

// Wrap values that might contain commas in quotes
$row[] = '"' . str_replace('"', '""', $value) . '"';
}
$csv_output .= implode(',', $row) . "\n";
}

// Send the CSV content
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="inventory-stock-matrix-' . date('YmdHis') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');
echo $csv_output;

wp_die();
}


/* -------------------------
Product Edit: Inventory table meta box
--------------------------*/
public function add_inventory_metabox() {
global $post;
if ( ! isset($post) || $post->post_type !== 'product' ) return;
add_meta_box( 'is_inventory_metabox', 'Inventory Table (All Variations)', [ $this, 'render_inventory_metabox' ], 'product', 'side', 'default' );
}

public function render_inventory_metabox( $post ) {
if ( ! current_user_can( 'edit_posts' ) ) return;
$product = wc_get_product( $post->ID );
if ( ! $product ) {
echo '<p>Product not found.</p>'; return;
}

if ( $product->is_type('variable') ) {
$children = $product->get_children();
if ( empty($children) ) {
echo '<p>No variations.</p>'; return;
}
echo '<table class="is-inventory-table" style="width:100%">';
// MODIFIED: Added SKU column
echo '<tr><th>Variation</th><th>SKU</th><th>Stock</th></tr>';
foreach( $children as $var_id ) {
$var = wc_get_product( $var_id );
$label = $var ? $var->get_formatted_name() : ('#' . $var_id);
$stock = $var ? $var->get_stock_quantity() : '';
$sku = $var ? $var->get_sku() : ''; // NEW: Get variation SKU
echo '<tr>';
echo '<td style="font-size:12px;">' . esc_html( $label ) . '<br><small>#' . intval($var_id) . '</small></td>';
echo '<td style="font-size:12px;">' . esc_html( $sku ) . '</td>'; // NEW: Display SKU
echo '<td><input type="number" min="0" class="is-variation-stock-input" data-variation-id="'.intval($var_id).'" value="'.esc_attr($stock).'" style="width:80px;"><br>';
echo '<button class="button is-save-variation" data-variation-id="'.intval($var_id).'">Save</button></td>';
echo '</tr>';
}
echo '</table>';
echo '<p class="description">Edit stocks and press Save per variation. Master mode will push updates when you press Sync Now.</p>';
} else {
// simple product
$stock = $product->get_stock_quantity();
$sku = $product->get_sku();
echo '<p>SKU: <strong>' . esc_html( $sku ) . '</strong></p>'; // NEW: Display SKU for simple
echo '<label>Stock: <input type="number" id="is-simple-stock" value="'.esc_attr($stock).'" style="width:100px;"></label>';
echo '<button class="button is-save-simple" data-product-id="'.intval($post->ID).'">Save</button>';
}

// nonce for AJAX
wp_nonce_field( $this->nonce_action, 'is_save_variation_nonce' );
}

/* -------------------------
REST endpoints & Sync 
--------------------------*/

public function register_rest_routes() {
register_rest_route( $this->rest_namespace, '/update-stock', [
'methods' => 'POST',
'callback' => [ $this, 'rest_update_stock' ],
'permission_callback' => '__return_true', // we'll do custom auth inside
] );

register_rest_route( $this->rest_namespace, '/get-product-stock', [
'methods' => 'GET',
'callback' => [ $this, 'rest_get_product_stock' ],
'permission_callback' => '__return_true',
] );
}

private function get_request_api_key() {
$headers = getallheaders();
if ( isset( $headers['X-IS-KEY'] ) ) return sanitize_text_field( $headers['X-IS-KEY'] );
// also accept header in lowercase
foreach( $headers as $k=>$v ){
if ( strtolower($k) === 'x-is-key' ) return sanitize_text_field( $v );
}
// fallback to query param
if ( isset($_REQUEST['is_key']) ) return sanitize_text_field( $_REQUEST['is_key'] );
return '';
}

// UPDATED REST HANDLER FOR NEW PRODUCT SYNC (Point 5)
public function rest_update_stock( WP_REST_Request $request ) {
$opts = $this->get_options();
$provided = $this->get_request_api_key();

if ( empty( $provided ) || $provided !== ( $opts['api_key'] ?? '' ) ) {
return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 401 );
}

$body = $request->get_json_params();
if ( empty( $body ) ) {
return new WP_REST_Response( [ 'error' => 'Empty body' ], 400 );
}

$main_sku = sanitize_text_field( $body['sku'] ?? '' );
$variations = $body['variations'] ?? [];
$is_new = $body['is_new'] ?? false; // New flag for product creation

$results = [];

// Handle Variable Product updates (via variations)
if ( ! empty($variations) && is_array($variations) ) {
$parent_id = $main_sku ? wc_get_product_id_by_sku( $main_sku ) : 0;
$parent_product = $parent_id ? wc_get_product( $parent_id ) : false;
$creation_status = '';

// NEW PRODUCT CREATION LOGIC (Point 5)
if ( ! $parent_product && $is_new ) {
// Parent product doesn't exist. Create a placeholder simple product.
// NOTE: We cannot create a complex variable product structure here, so we create a simple product as a placeholder for the main SKU.
$new_product = new WC_Product_Simple();
$new_product->set_name( 'Synced Product Placeholder: ' . $main_sku );
$new_product->set_sku( $main_sku );
$new_product->set_status( 'draft' );
$new_product->set_manage_stock( true ); // Assume stock managed
$new_product->set_stock_quantity( 0 ); // Initial stock
$parent_id = $new_product->save();
if ($parent_id) {
$parent_product = wc_get_product( $parent_id );
$creation_status = 'created_simple_placeholder';
} else {
return new WP_REST_Response( [ 'error' => 'Failed to create parent product placeholder.', 'sku' => $main_sku ], 500 );
}
}


if ( ! $parent_product || ! $parent_product->is_type('variable') ) {
// If it's not variable, we can't process variations against it, but if it was just created as a placeholder, we skip this error.
if ( $creation_status !== 'created_simple_placeholder' ) {
return new WP_REST_Response( [ 'error' => 'Product found, but not variable: ' . $parent_id ], 400 );
}
}

foreach( $variations as $v ) {
$v_sku = sanitize_text_field( $v['variation_sku'] ?? '' ); 
$stock = isset( $v['stock'] ) ? floatval( $v['stock'] ) : null;

if ( $v_sku && $stock !== null ) {
// Lookup variation by SKU
$vid = wc_get_product_id_by_sku( $v_sku );
$variation = $vid ? wc_get_product( $vid ) : false;

// NEW VARIATION CREATION LOGIC (Point 5)
if ( ! $variation && $is_new ) {
// We cannot reliably create a variation without its parent being a variable product and knowing its full attributes.
// We will create a simple product placeholder for this variation's SKU instead, since it is a new SKU.
$new_v_product = new WC_Product_Simple();
$new_v_product->set_name( 'Synced Variation Placeholder: ' . $v_sku );
$new_v_product->set_sku( $v_sku );
$new_v_product->set_status( 'draft' );
$new_v_product->set_manage_stock( true );
$new_v_product->set_stock_quantity( $stock );
if ($new_v_product->save()) {
$results[] = [ 'sku' => $v_sku, 'stock' => $stock, 'status' => 'created_simple_placeholder_for_variation' ];
continue; // Move to next item
} else {
$results[] = [ 'sku' => $v_sku, 'status' => 'failed_to_create_variation_placeholder' ];
continue; // Move to next item
}
}
// END NEW VARIATION CREATION LOGIC


if ( $variation && $variation->get_parent_id() != $parent_id ) {
$results[] = [ 'sku' => $v_sku, 'status' => 'sku_found_wrong_parent' ];
continue;
}

if ( $variation ) {
// Update stock
$variation->set_manage_stock( true );
$variation->set_stock_quantity( $stock );
$variation->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
$variation->save();
$results[] = [ 'sku' => $v_sku, 'stock' => $stock, 'status' => 'updated' ];
} else {
$results[] = [ 'sku' => $v_sku, 'status' => 'not_found' ];
}
}
}
return new WP_REST_Response( [ 'ok' => true, 'results' => $results, 'main_sku' => $main_sku, 'parent_status' => $creation_status ], 200 );
}

// Handle Simple Product update
if ( $main_sku ) {
$product_id = wc_get_product_id_by_sku( $main_sku ); 
$product = $product_id ? wc_get_product( $product_id ) : false;
$creation_status = '';

// NEW SIMPLE PRODUCT CREATION LOGIC (Point 5)
if ( ! $product && $is_new ) {
$stock = isset( $body['stock'] ) ? floatval( $body['stock'] ) : 0;
$new_product = new WC_Product_Simple();
$new_product->set_name( 'Synced Product Placeholder: ' . $main_sku );
$new_product->set_sku( $main_sku );
$new_product->set_status( 'draft' );
$new_product->set_manage_stock( true );
$new_product->set_stock_quantity( $stock );
$product_id = $new_product->save();
if ($product_id) {
$product = wc_get_product( $product_id );
$creation_status = 'created_simple_placeholder';
} else {
return new WP_REST_Response( [ 'error' => 'Failed to create simple product placeholder.', 'sku' => $main_sku ], 500 );
}
}
// END NEW SIMPLE PRODUCT CREATION LOGIC


if ( $product && $product->is_type('simple') ) {
$stock = isset( $body['stock'] ) ? floatval( $body['stock'] ) : null;
if ( $stock !== null ) {
$product->set_manage_stock( true );
$product->set_stock_quantity( $stock );
$product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
$product->save();
return new WP_REST_Response( [ 'ok' => true, 'sku' => $main_sku, 'product_id' => $product_id, 'stock' => $stock, 'status' => $creation_status ?: 'updated' ], 200 );
}
} else {
return new WP_REST_Response( [ 'error' => 'Product not found or not simple by SKU: ' . $main_sku ], 404 );
}
}

return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
}

public function rest_get_product_stock( WP_REST_Request $request ) {
$sku = sanitize_text_field( $request->get_param('sku') ?? '' );
$product_id = intval( $request->get_param('product_id') ?? 0 );
if ( ! $sku && ! $product_id ) {
return new WP_REST_Response( [ 'error' => 'sku or product_id required' ], 400 );
}

if ( $sku ) {
$product_id = wc_get_product_id_by_sku( $sku );
}

$product = wc_get_product( $product_id );
if ( ! $product ) return new WP_REST_Response( [ 'error' => 'product not found' ], 404 );

$data = [ 'product_id' => $product_id, 'sku' => $product->get_sku(), 'type' => $product->get_type() ];
if ( $product->is_type('variable') ) {
$data['variations'] = [];
foreach( $product->get_children() as $vid ) {
$v = wc_get_product($vid);
$data['variations'][] = [ 'variation_id' => $vid, 'variation_sku' => $v ? $v->get_sku() : '', 'stock' => $v ? $v->get_stock_quantity() : null ];
}
} else {
$data['stock'] = $product->get_stock_quantity();
}
return new WP_REST_Response( $data, 200 );
}

// UPDATED SYNC LOGIC (Point 5)
public function do_sync() {
$opts = $this->get_options();
if ( ( $opts['mode'] ?? 'master' ) !== 'master' ) return [ 'error' => 'not_master' ];
$clients = $opts['clients'] ?? [];
$result = [];

// Collect all products (IDs) - Simple and Variable variations
$args = [ 
'post_type' => [ 'product', 'product_variation' ], 
'post_status' => 'publish', 
'numberposts' => -1, 
'fields' => 'ids',
'meta_query' => [
[ 'key' => '_manage_stock', 'value' => 'yes' ],
[ 'key' => '_sku', 'compare' => '!=', 'value' => '' ],
],
];
$posts = get_posts( $args );

foreach( $clients as $client ) {
$client_url = rtrim( $client['url'], '/' );
$client_key = $client['key'];
$push_results = [];

// Group variations by parent SKU to send efficiently
$grouped_payloads = [];

foreach( $posts as $pid ) {
$product = wc_get_product( $pid );
if ( ! $product || empty( $product->get_sku() ) ) continue;

$product_sku = $product->get_sku(); 
$parent_product = null;

if ( $product->is_type('variation') ) {
$parent_product = wc_get_product( $product->get_parent_id() );
$parent_sku = $parent_product ? $parent_product->get_sku() : 'NO_PARENT_SKU';

if ( ! isset($grouped_payloads[$parent_sku]) ) {
$grouped_payloads[$parent_sku] = [ 'sku' => $parent_sku, 'variations' => [], 'is_new' => false ];
}
// Send variation details
$grouped_payloads[$parent_sku]['variations'][] = [ 
'variation_sku' => $product_sku, 
'stock' => $product->get_stock_quantity() 
];

} else if ( $product->is_type('simple') ) {
// Send simple product as its own payload
$payload = [ 'sku' => $product_sku, 'stock' => $product->get_stock_quantity(), 'is_new' => false ];
$grouped_payloads[$product_sku] = $payload;
}
}

// Process and send all collected payloads
foreach( $grouped_payloads as $sku => $payload ) {
$endpoint = $client_url . '/wp-json/' . $this->rest_namespace . '/update-stock';

// 1. Initial Check: Try to fetch the product stock from client to see if it exists
$check_url = add_query_arg( ['sku' => $sku, 'is_key' => $client_key], $client_url . '/wp-json/' . $this->rest_namespace . '/get-product-stock' );
$check_resp = wp_remote_get( $check_url, ['timeout' => 10, 'sslverify' => false] );
$check_code = wp_remote_retrieve_response_code( $check_resp );

if ($check_code === 404) {
// Product does not exist on client, flag payload as new
$payload['is_new'] = true;
// For variable products, the main SKU may not exist, but variations might.
// The client side will handle creating simple placeholders.
}

$args = [
'body'    => wp_json_encode( $payload ),
'headers' => [
'Content-Type' => 'application/json',
'X-IS-KEY' => $client_key,
'Accept' => 'application/json',
],
'timeout' => 20,
'sslverify' => false,
];

$resp = wp_remote_post( $endpoint, $args );
if ( is_wp_error( $resp ) ) {
$push_results[] = [ 'sku' => $sku, 'error' => $resp->get_error_message() ];
} else {
$code = wp_remote_retrieve_response_code( $resp );
$body = wp_remote_retrieve_body( $resp );
$push_results[] = [ 'sku' => $sku, 'code' => $code, 'body' => $body ];
}
}


$result[] = [ 'client' => $client_url, 'results' => $push_results ];
}

$opts['last_sync'] = current_time( 'mysql' );
$this->update_options( $opts );

return $result;
}
}

new IS_Inventory_Sync();


// -------------------------------------------------------------
// --- WP_List_Table Implementation for Matrix Display (Variable Products) ---
// -------------------------------------------------------------

class IS_Inventory_List_Table extends WP_List_Table {
    
    private $inventory_items = null; // Changed to null to ensure proper check in get_grouped_inventory_data
    private $all_powers = [];
public $grand_total_stock = 0; // Grand Total property for use in separate statement

    public function __construct() {
        parent::__construct( [
            'singular' => 'product_inventory',
            'plural'   => 'product_inventories',
            'ajax'     => false 
        ] );
    }


    // Helper to find all unique power/sphere attributes
    private function get_all_powers() {
        if ( ! empty( $this->all_powers ) ) {
            return $this->all_powers;
        }

        global $wpdb;
        
        // Fetch all unique attribute values used for variations
        $power_values = $wpdb->get_col( "
            SELECT DISTINCT meta_value 
            FROM {$wpdb->postmeta} 
            WHERE meta_key LIKE 'attribute_%' 
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product_variation')
        " );
        
        // Filter: Keep only values that are numeric (like 0, 100, -1.50). 
        $power_values = array_filter( $power_values, function($v) {
            // Check if it's numeric, or contains a decimal/minus sign
            return is_numeric($v) || (strpos($v, '.') !== false) || (strpos($v, '-') !== false);
        });
        
        $this->all_powers = array_unique( $power_values );
        // Numeric sort for proper display order
        usort( $this->all_powers, function($a, $b) {
            return (float)$a - (float)$b;
        });

        // Set a max number of powers for sanity
        if ( count($this->all_powers) > 100 ) {
            $this->all_powers = array_slice($this->all_powers, 0, 100);
        }
        
        error_log('IS_MATRIX_LOG: Total detected Power Values: ' . count($this->all_powers) . ' | Examples: ' . implode(', ', array_slice($this->all_powers, 0, 5)));

        return $this->all_powers;
    }


    public function get_columns() {
        $columns = [
            'series_name'   => 'Series / Color',
            'total_stock'   => 'Total Stock',
        ];
        
        // Add dynamic columns for each power value
        $powers = $this->get_all_powers();
        foreach ( $powers as $power ) {
            // Use sanitize_title for column key, but original power for display name
            $columns[ 'power_' . sanitize_title($power) ] = esc_html($power);
        }
        
        return $columns;
    }

    public function get_sortable_columns() {
        return [
            'series_name'   => [ 'series_name', false ],
            'total_stock'   => [ 'total_stock', false ],
        ];
    }

public function column_default( $item, $column_key ) {
		
    // Handle fixed columns
    switch ( $column_key ) {
        case 'series_name':
            return '<div style="width:auto;"><strong>' . esc_html( $item['series_name'] ) . '</strong><br><small>' . esc_html( $item['color_name'] ) . '</small></div>';
        case 'total_stock':
            return intval( $item['total_stock'] );
    }
    
    // Handle dynamic power/stock columns (The individual editable stock field)
    if ( strpos( $column_key, 'power_' ) === 0 ) {
        $power_slug = substr( $column_key, 6 ); // Remove 'power_' prefix
        
        if ( ! isset( $item['variations_by_power'][$power_slug] ) ) {
            return '<span style="color:#aaa;">-</span>';  
        }

        $variation = $item['variations_by_power'][$power_slug];
        
        // ** NEW LINES: GET THE ID AND THE STOCK/SKU **
        $current_stock = intval( $variation['stock'] );
        $variation_id = $variation['variation_id'] ?? 0; // Get the ID
        
        // We will pass the ID for the JS and the input name.
        if ( $variation_id === 0 ) {
            return '<span style="color:red;">Error: ID missing</span>';
        }

        // 💡 CORRECTED CODE: Use data-vid (Variation ID) instead of data-sku
        return sprintf(
            '<input type="number" min="0" value="%1$d" class="is-bulk-stock-input" data-vid="%2$d" data-current-stock="%1$d" style="width:50px; text-align:center;" name="is_stock_update[%2$d]">',
            $current_stock, // %1$d
            $variation_id   // %2$d (used for data-vid and input name key)
        );
    }

    return '';
}
    
    // Grouping and slicing the data for the current page
// Added $bypass_cache for export function (Point 6)
private function get_grouped_inventory_data( $bypass_cache = false ) {
        if ( ! $bypass_cache && ! is_null( $this->inventory_items ) ) {
            return $this->inventory_items;
        }

        // 1. Get all Variable Products 
        $product_args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => [
                [
                    'taxonomy' => 'product_type',
                    'field'    => 'slug',
                    'terms'    => 'variable',
                ],
            ],
        ];
        $parent_ids = get_posts( $product_args );
        
        $inventory_data = [];
        $grand_total_stock = 0;
        $all_powers_values = array_map('strval', $this->get_all_powers()); 

        error_log('IS_MATRIX_LOG: Found ' . count($parent_ids) . ' Variable Products.');

        foreach( $parent_ids as $parent_id ) {
            $parent = wc_get_product( $parent_id );
            if ( ! $parent ) continue;

            $series_name = $parent->get_name();
            $children_ids = $parent->get_children();
            $attributes = $parent->get_variation_attributes();

            // --- 2. Determine Power and Color Keys based on attribute values ---
            
            $power_key = null;
            $color_key = null;
            
            error_log('IS_MATRIX_LOG: Processing Parent ID: ' . $parent_id . ' (' . $series_name . ') | Total Attributes: ' . count($attributes));

            // Logic to identify the Power attribute (numeric values)
            foreach($attributes as $key => $values) {
                // Check if the attribute values overlap significantly with the globally detected numeric/power values
                $intersection = array_intersect(array_map('strval', $values), $all_powers_values);
                
                if (count($intersection) > 0) {
                    $power_key = $key;
                    error_log('IS_MATRIX_LOG:    Attribute Detected: Power Key is "' . $power_key . '"');
                } else {
                    // Assign the non-power attribute as the color/series grouping key (fallback)
                    if (is_null($color_key)) {
                        $color_key = $key; 
                        error_log('IS_MATRIX_LOG:    Attribute Detected: Color/Group Key is "' . $color_key . '"');
                    }
                }
            }

            // CRITICAL CHECK A: If Power key cannot be determined, skip product.
            if (is_null($power_key)) {
                error_log('IS_MATRIX_LOG: ❌ CRITICAL CHECK A FAILED: Skipping Parent #' . $parent_id . ' - Cannot determine Power attribute.');
                continue; 
            }
            
            // If only one attribute exists, use the parent name for color grouping
            if (is_null($color_key)) {
                $color_key = '_parent_name';
                error_log('IS_MATRIX_LOG:    Falling back to Parent Name for Color/Group Key.');
            }

            $color_groups = [];
            $has_valid_variations = false; 

            // --- 3. Group Variations by Color Key and map Power values ---
            foreach( $children_ids as $vid ) {
                $variation = wc_get_product( $vid );
                
                // CRITICAL CHECK B: Filter variations lacking SKU or stock management
                if ( ! $variation || empty( $variation->get_sku() ) || ! $variation->get_manage_stock() ) {
                    $v_sku_status = $variation ? (empty($variation->get_sku()) ? 'NO_SKU' : ( $variation->get_manage_stock() ? 'OK' : 'NO_MANAGE_STOCK' ) ) : 'NOT_FOUND';
                    error_log('IS_MATRIX_LOG: ❌ CRITICAL CHECK B FAILED: Skipping Variation #' . $vid . ' (SKU Status: ' . $v_sku_status . ')');
                    continue;
                }
                
                // ** NEW CODE BLOCK: Aggressive Attribute Retrieval (V2.1) **
                // 1. Get all attributes for the variation (slugs)
                $v_attributes = $variation->get_variation_attributes();

                // 2. Try to get the power value from the standard attributes array first (usually a slug)
                $power_value_raw = $power_key ? $v_attributes[$power_key] ?? '' : '';

                // 3. Fallback A: If still empty, perform a direct lookup on the variation's post meta for the full attribute meta key.
                if ( empty( $power_value_raw ) && $power_key ) {
                    // Standard WooCommerce variation meta key pattern is 'attribute_' + taxonomy slug
                    $meta_key_to_check = 'attribute_' . $power_key; 
                    $power_value_raw = $variation->get_meta( $meta_key_to_check, true ); 
                }

                // ** 4. Fallback B: FINAL AGGRESSIVE CHECK **
                // If still empty, loop through all variation meta keys looking for any attribute that has a value.
                if ( empty( $power_value_raw ) ) {
                    $all_meta = get_post_meta( $vid );
                    foreach( $all_meta as $meta_key => $meta_value ) {
                        // If the meta key starts with 'attribute_' and has a value, we'll assume it's the one we want.
                        if ( strpos($meta_key, 'attribute_') === 0 && ! empty($meta_value[0]) ) {
                            // We have found *an* attribute value. Use it, and break.
                            $power_value_raw = $meta_value[0];
                            error_log('IS_MATRIX_LOG: ⚠️ CRITICAL FALLBACK USED: Found value "' . $power_value_raw . '" under key: ' . $meta_key );
                            break; 
                        }
                    }
                }

                $power_value = $power_value_raw;

                // 5. Convert the term slug (which is what $power_value_raw contains) to the readable term name if it's a global taxonomy.
                // We must check if $power_key is set because the fallback might return a value but we don't know the exact taxonomy.
                if ( $power_value && $power_key && taxonomy_exists( $power_key ) ) {
                    $term = get_term_by( 'slug', $power_value, $power_key );
                    if ( $term ) {
                        // Use the term name (e.g., "100" instead of the slug "100")
                        $power_value = $term->name;
                    }
                }
                // End of NEW CODE BLOCK
                
                // Get Color Value (or Parent Name if only one attribute exists)
                if ($color_key === '_parent_name') {
                    $color_value = $series_name;
                } else {
                    // Use $v_attributes for the color key too
                    $color_slug = $v_attributes[$color_key] ?? '';
                    $color_value = $color_slug;
                    
                    if ( $color_value && taxonomy_exists( $color_key ) ) {
                        $term = get_term_by( 'slug', $color_slug, $color_key );
                        if ( $term ) {
                            $color_value = $term->name;
                        }
                    }
                }
                $color_value = $color_value ? $color_value : 'N/A';
                
                // Use the potentially translated/readable power value for the final keying
                $power_slug = sanitize_title( $power_value );
                $color_slug = sanitize_title( $color_value );
                
                if ( empty($power_slug) ) {
                    error_log('IS_MATRIX_LOG: ❌ CRITICAL CHECK C FAILED: Skipping Variation #' . $vid . ' - Power Value is empty. Raw value: ' . $power_value_raw);
                    continue; // Must have a power to be listed in a power column
                }

                if ( ! isset( $color_groups[$color_slug] ) ) {
                    $color_groups[$color_slug] = [
                        'series_name' => $series_name,
                        'color_name' => $color_value,
                        'total_stock' => 0,
                        'variations_by_power' => []
                    ];
                    error_log('IS_MATRIX_LOG:    New Group Created: Slug "' . $color_slug . '" (Color: ' . $color_value . ')');
                }

                $stock = intval( $variation->get_stock_quantity() );
                $color_groups[$color_slug]['total_stock'] += $stock;
                $has_valid_variations = true; 

                $color_groups[$color_slug]['variations_by_power'][$power_slug] = [
                    'variation_id' => $vid, // <<< ADDED variation ID here for use in column_default
                    'stock' => $stock
                ];
                error_log('IS_MATRIX_LOG:    Variation Added to Group: Color: ' . $color_value . ' | Power: ' . $power_value . ' | SKU: ' . $variation->get_sku() );

            }
            
            // Only merge the data if valid variations were found
            if ($has_valid_variations) {
                // Sum the total stock for all rows of this parent product
                $parent_total_stock = array_sum( array_column( $color_groups, 'total_stock' ) );
                $grand_total_stock += $parent_total_stock; // <<< SUMMING GRAND TOTAL HERE

                $inventory_data = array_merge($inventory_data, array_values($color_groups));
                error_log('IS_MATRIX_LOG: ✅ SUCCESS: Parent #' . $parent_id . ' added to inventory data. Parent Total Stock: ' . $parent_total_stock);
            } else {
                error_log('IS_MATRIX_LOG: ❌ CRITICAL CHECK D FAILED: Parent #' . $parent_id . ' did not yield any valid, filter-passing variations. Skipping.');
            }
        }

// --- SORTING & FILTERING (Points 1 & 3) ---
// Alphabetical sort (Point 1)
usort($inventory_data, function($a, $b) {
return strcasecmp($a['series_name'] . $a['color_name'], $b['series_name'] . $b['color_name']);
});

// Search Filter (Point 3)
$search_term = sanitize_text_field( $_GET['s'] ?? '' );
if (!empty($search_term)) {
$inventory_data = array_filter($inventory_data, function($item) use ($search_term) {
$haystack = strtolower($item['series_name'] . ' ' . $item['color_name']);
return strpos($haystack, strtolower($search_term)) !== false;
});
}

// A-Z Filter (Point 1)
$filter_letter = sanitize_text_field( $_GET['filter_by_series'] ?? '' );
if (!empty($filter_letter)) {
$inventory_data = array_filter($inventory_data, function($item) use ($filter_letter) {
$first_char = strtoupper(substr($item['series_name'], 0, 1));

// For numeric filter (0-9)
if (is_numeric($filter_letter)) {
return is_numeric($first_char);
}

return $first_char === $filter_letter;
});
}
// --- END SORTING & FILTERING ---

        // Save Grand Total to property
        $this->grand_total_stock = $grand_total_stock;
error_log('IS_MATRIX_LOG: GRAND TOTAL calculated and saved to property: ' . $grand_total_stock);

// This data array now holds the filtered and sorted data, ready for pagination/export
        $this->inventory_items = $inventory_data;
        return $this->inventory_items;
    }


    public function prepare_items() {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [ $columns, $hidden, $sortable ];

        $data = $this->get_grouped_inventory_data(); // Fetch the grouped, sorted, and filtered data

        // --- Pagination Logic (Point 2) ---
        $per_page = 40; // Max 40 products per page
        $current_page = $this->get_pagenum();
        $total_items = count($data);

        $data_paged = array_slice($data, ( ( $current_page - 1 ) * $per_page ), $per_page );

        $this->items = $data_paged; // Set the items for display

        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page )
        ] );
        // --- End Pagination Logic ---
    }

// Overriding search_box for cleaner implementation (Point 3)
public function search_box( $text, $input_id ) {
$input_id = $input_id . '-search-input';
if ( ! empty( $_REQUEST['orderby'] ) ) {
echo '<input type="hidden" name="orderby" value="' . esc_attr( $_REQUEST['orderby'] ) . '" />';
}
if ( ! empty( $_REQUEST['order'] ) ) {
echo '<input type="hidden" name="order" value="' . esc_attr( $_REQUEST['order'] ) . '" />';
}
?>
<p class="search-box">
<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $text ); ?>:</label>
<input type="search" id="<?php echo esc_attr( $input_id ); ?>" name="s" value="<?php _admin_search_query(); ?>" />
<?php submit_button( $text, '', '', false, array( 'id' => 'search-submit' ) ); ?>
</p>
<?php
}

    public function no_items() {
        echo 'No variable products with variations, SKUs, and stock management enabled found. Check debug log for attribute detection errors. If the previous log showed empty "Raw value", this might be due to variations not having their attributes set correctly in the database.';
    }

    // Override display to use our custom class for matrix styling
    public function display() {
        ?>
        <div class="tablenav top"><?php $this->display_tablenav( 'top' ); ?></div>

        <table class="<?php echo implode( ' ', $this->get_table_classes() ); ?> is-matrix-table">
            <thead>
                <tr>
                    <?php $this->print_custom_column_headers(); ?>
                </tr>
            </thead>
            <tbody id="the-list" <?php if ( $this->get_singular_item() ) echo ' data-wp-lists="list:' . $this->get_singular_item() . '"'; ?>>
                <?php $this->display_rows_or_placeholder(); ?>
            </tbody>
        </table>
        
        <div class="tablenav bottom"><?php $this->display_tablenav( 'bottom' ); ?></div>
        <?php
    }

    // A brand new, controlled header function
    public function print_custom_column_headers( $with_checkbox = true ) {
        // Get columns from the original method
        list( $columns, $hidden, $sortable, $primary ) = $this->get_column_info();
        
        foreach ( $columns as $column_key => $column_display_name ) {
            $classes = [ $column_key ];
            
            // Add custom classes for sticky columns
            if ( $column_key === 'series_name' ) {
                $classes[] = 'column-series-name-sticky';
            } elseif ( $column_key === 'total_stock' ) {
                $classes[] = 'column-total-stock-sticky';
            }

            // Standard WP_List_Table column setup
            $tag = ( 'cb' === $column_key ) ? 'td' : 'th';
            $attributes = 'scope="col"';
            
            if ( ! empty( $classes ) ) {
                $attributes .= ' class="' . esc_attr( implode( ' ', $classes ) ) . '"';
            }
            
            // Output the header cell
            echo "<$tag $attributes>";
            echo esc_html( $column_display_name );
            echo "</$tag>";
        }
    }

}
