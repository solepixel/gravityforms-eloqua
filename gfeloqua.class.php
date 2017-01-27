<?php

if( class_exists( 'GFEloqua' ) )
	return;

class GFEloqua extends GFFeedAddOn {

	protected $_version = GFELOQUA_VERSION;
	protected $_min_gravityforms_version = '1.8.17';
	protected $_slug = 'gravityformseloqua';
	protected $_path = 'gravityforms-eloqua/gravityforms-eloqua.plugin.php';
	protected $_full_path = __FILE__;
	protected $_url = 'http://www.briandichiara.com';
	protected $_title = 'Gravity Forms Eloqua';
	protected $_short_title = 'Eloqua';

	// Members plugin integration
	protected $_capabilities = array( 'gravityformseloqua', 'gravityformseloqua_uninstall' );

	// Permissions
	protected $_capabilities_settings_page = 'gravityformseloqua';
	protected $_capabilities_form_settings = 'gravityformseloqua';
	protected $_capabilities_uninstall = 'gravityformseloqua_uninstall';
	protected $_enable_rg_autoupgrade = true;

	private static $_instance = null;

	protected $eloqua;

	protected $token_auto_generated = false;
	protected $oauth_token_retrieved = false;

	private $folders = array();

	private $init_counter = 0;
	private $text_for_success = 'Success!';
	private $text_for_failed = 'Failed.';

	private $last_response = false;
	private $log_level = 1;
	private $debugger;

	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new GFEloqua();
		}

		return self::$_instance;
	}

	public function init(){
		parent::init();

		$this->debugger = new GFEloqua_Debugger();

		$this->_maybe_store_settings();
		$this->_maybe_clear_settings();

		$use_basic = get_option( GFELOQUA_OPT_PREFIX . 'auth_basic' );
		$use_oauth = (bool) get_option( GFELOQUA_OPT_PREFIX . 'use_oauth', ! $use_basic );

		$this->eloqua = new Eloqua_API( $this->get_connection_string(), $use_oauth );

		add_action( 'wp_ajax_gfeloqua_clear_transient', array( $this, 'clear_eloqua_transient' ) );
		add_action( 'wp_ajax_gfeloqua_resubmit_entry', array( $this, 'resubmit_entry' ) );
		add_action( 'wp_ajax_gfeloqua_reset_entry', array( $this, 'reset_entry' ) );

		if( $this->is_detail_page() ){
			wp_enqueue_script( 'gform_conditional_logic' );
			wp_enqueue_script( 'gform_gravityforms' );
			wp_enqueue_script( 'gform_form_admin' );
		}

		// this fixes the update notice on the plugins page
		add_action( 'admin_init', array( $this, 'insert_version_data' ) );

		// oauth actions
		add_action( 'admin_init', array( $this, 'handle_oauth_code' ) );
		add_action( 'admin_head', array( $this, 'close_oauth_window' ) );

		// Disconnect Notice
		add_action( 'admin_notices', array( $this, 'disconnect_notice' ) );

		// cron actions
		add_filter( 'cron_schedules', array( $this, 'add_extra_cron_schedule' ) );

		add_action( 'gfeloqua_disconnect_notification', array( $this, 'disconnect_notification' ) );
		if( ! wp_next_scheduled( 'gfeloqua_disconnect_notification' ) )
			wp_schedule_event( time(), 'hourly', 'gfeloqua_disconnect_notification' );

		$retry_interval = $this->get_plugin_setting( 'gfeloqua_retry_interval' );
		if( ! $retry_interval )
			$retry_interval = '5min';
		add_action( 'gfeloqua_process_failed_submissions', array( $this, 'retry_failed_submissions' ) );
		if( ! wp_next_scheduled( 'gfeloqua_process_failed_submissions' ) )
			wp_schedule_event( time(), $retry_interval, 'gfeloqua_process_failed_submissions' );

		// entry detail
		add_action( 'gform_entry_detail', array( $this, 'entry_notes' ), 10, 2 );

		// entry meta (for column)
		add_action( 'gform_entry_meta', array( $this, 'add_success_meta' ), 10, 2 );
	}

	/**
	 * This disables the update message for GFEloqua Plugin on the plugin screen
	 * @return void
	 */
	function insert_version_data(){
		$update_info = get_transient( 'gform_update_info' );

		if( ! $update_info )
			return;

		$body = json_decode( $update_info['body'] );

		if( isset( $body->offerings->{$this->_slug} ) )
			return;

		// add gfeloqua to the list
		$gfeloqua = new stdClass();
		$gfeloqua->is_available = true;
		$gfeloqua->version = $this->_version;
		$gfeloqua->url = $this->_url;

		if( is_array( $body->offerings ) ){
			$body->offerings[ $this->_slug ] = $gfeloqua;
		} else {
			$body->offerings->{$this->_slug} = $gfeloqua;
		}

		$update_info['body'] = json_encode( $body );

		set_transient( 'gform_update_info', $update_info, DAY_IN_SECONDS );
	}

	/**
	 * Settings fields for Eloqua Feed
	 * @return array  feed settings
	 */
	public function feed_settings_fields() {
		$feed = $this->get_current_feed();

		return array(
			array(
				'fields' => array(
					array(
						'name'     => 'feed_name',
						'label'    => __( 'Name', 'gfeloqua' ),
						'type'     => 'text',
						'required' => true,
						'class'    => 'medium',
						'tooltip'  => '<h6>' . __( 'Name', 'gfeloqua' ) . '</h6>' . __( 'Enter a feed name to uniquely identify this setup.', 'gfeloqua' ),
					),
					array(
						'label'   => __( 'Eloqua Form', 'gfeloqua' ) . ' <a href="#gfe-forms-refresh" class="gfe-refresh">Refresh</a>',
						'type'    => 'eloqua_forms',
						'onchange'   => 'jQuery(this).parents("form").submit();',
						'name'    => 'gfeloqua_form'
					),
					array(
						'name' => 'mapped_fields',
						'label' => __( 'Map Fields', 'gfeloqua' ) . ' <a href="#gfe-form-fields-refresh" class="gfe-refresh">Refresh</a>',
						'type' => 'list_fields',
						'dependency' => 'gfeloqua_form',
						'tooltip'    => '<h6>' . __( 'Map Fields', 'gfeloqua' ) . '</h6>' . __( 'Associate your Eloqua custom fields to the appropriate Gravity Form fields by selecting the appropriate form field from the list.', 'gfeloqua' ),
					),
					array(
						'name'       => 'optin',
						'label'      => __( 'Opt In', 'gfeloqua' ),
						'type'       => 'feed_condition',
						'dependency' => 'gfeloqua_form',
						'tooltip'    => '<h6>' . __( 'Opt-In Condition', 'gfeloqua' ) . '</h6>' . __( 'When the opt-in condition is enabled, form submissions will only be exported to Eloqua when the condition is met. When disabled all form submissions will be exported.', 'gfeloqua' ),
					),
				)
			)
		);
	}

	/**
	 * Requirements to enqueue scripts/styles
	 * @return array enqueue requirements
	 */
	function enqueue_conditions(){
		return array(
			array( 'query' => 'page=gf_edit_forms&view=settings&subview=' . $this->_slug ),
			array( 'query' => 'page=gf_settings&subview=' . $this->_slug ),
			array( 'query' => 'page=gf_entries&view=entry' )
		);
	}

	/**
	 * Plugin Styles
	 * @return array  styles
	 */
	public function styles() {
		$styles = array(
			array(
				'handle'  => 'select2',
				'src'     => $this->get_base_url() . '/lib/select2/css/select2.min.css',
				'version' => '4.0.0',
				'enqueue' => $this->enqueue_conditions()
			),
			array(
				'handle'  => 'gfeloqua',
				'src'     => $this->get_base_url() . '/assets/css/gfeloqua.min.css',
				'version' => $this->_version,
				'enqueue' => $this->enqueue_conditions()
			)
		);

		return array_merge( parent::styles(), $styles );
	}

	/**
	 * Plugin scripts
	 * @return array  scripts
	 */
	public function scripts() {
		$scripts = array(
			array(
				'handle'  => 'select2',
				'src'     => $this->get_base_url() . '/lib/select2/js/select2.min.js',
				'version' => '4.0.0',
				'deps'    => array( 'jquery' ),
				'strings' => array(
					'ajax_url'  => admin_url( 'admin-ajax.php' ),
					'confirm_reset' => __( 'Are you sure you want to reset the status of this entry?', 'gfeloqua' )
				),
				'enqueue' => $this->enqueue_conditions()
			),
			array(
				'handle'  => 'gfeloqua',
				'src'     => $this->get_base_url() . '/assets/js/gfeloqua.min.js',
				'version' => $this->_version,
				'deps'    => array( 'jquery', 'select2' ),
				'strings' => array(
					'ajax_url'  => admin_url( 'admin-ajax.php' ),
					'confirm_reset' => __( 'Are you sure you want to reset the status of this entry?', 'gfeloqua' )
				),
				'enqueue' => $this->enqueue_conditions()
			),

		);

		return array_merge( parent::scripts(), $scripts );
	}

	/**
	 * Throw in a few custom items into the feed edit page if the plugin isn't setup yet.
	 * @param  array $form     GF Form
	 * @param  int $feed_id    GF Feed ID
	 * @return void
	 */
	public function feed_edit_page( $form, $feed_id ) {

		// ensures valid credentials were entered in the settings page
		if( ! $this->get_connection_string() ){
			$settings_page = $this->get_plugin_settings_url();
			$view = GFELOQUA_PATH . '/views/needs-setup.php';
			include( $view );
			return;
		}

		echo '<script type="text/javascript">var form = ' . GFCommon::json_encode( $form ) . ';</script>';

		parent::feed_edit_page( $form, $feed_id );
	}

	/**
	 * Throw in a few custom items into the feed edit page if the plugin isn't setup yet.
	 * @param  array $form  GF Form
	 * @return [type]       [description]
	 */
	public function feed_list_page( $form = NULL ){
		if( ! $this->get_connection_string() ){
			$settings_page = $this->get_plugin_settings_url();
			$view = GFELOQUA_PATH . '/views/needs-setup.php';
			include( $view );
			return;
		}

		parent::feed_list_page( $form );
	}

	/**
	 * Displayed on feed list, custom columns showing Feed Name and Eloqua Form Name
	 * @return array  list of columns
	 */
	public function feed_list_columns() {
		return array(
			'feed_name' => __( 'Feed Name', 'gfeloqua' ),
			'gfeloqua_form' => __( 'Eloqua Form Name', 'gfeloqua' ),
		);
	}

	/**
	 * Display the Feed Name value
	 * @param  array $feed  GF Feed
	 * @return string       Feed Name
	 */
	public function get_column_value_feed_name( $feed ){
		return $feed['meta']['feed_name'];
	}

	/**
	 * Display the Eloqua Form Name
	 * @param  array $feed  GF Feed
	 * @return string       Eloqua Form Name
	 */
	public function get_column_value_gfeloqua_form( $feed ){
		$form_name = '';
		$form = $this->eloqua->get_form( $feed['meta']['gfeloqua_form'] );

		if( is_object( $form ) )
			$form_name = $form->name;

		$form_name .= $form_name ? ' (ID: ' . $feed['meta']['gfeloqua_form'] . ')' : 'ID: ' . $feed['meta']['gfeloqua_form'];

		return $form_name;
	}

	/**
	 * If we have a valid set of credentials or OAuth token, test it
	 * @param  string $authstring Pass in the authstring directly
	 * @return bool               Did testing connect successfully?
	 */
	public function test_authentication( $authstring = NULL ){
		if( $authstring ){
			$connection_string = $authstring;
		} else {
			$connection_string = $this->get_connection_string();
		}

		if( ! $connection_string )
			return false;

		if( $authstring ){
			$use_oauth = false;
		} else {
			$use_basic = get_option( GFELOQUA_OPT_PREFIX . 'auth_basic' );
			$use_oauth = (bool) get_option( GFELOQUA_OPT_PREFIX . 'use_oauth', ! $use_basic );
		}

		$test = new Eloqua_API( $connection_string, $use_oauth );

		if( ! $test->connect() ){

			$debug_connection = new GFEloqua_Debug_Data( 'Connection Failure', $test, 'Attempting to use Refresh Token.' );
			$this->debugger->add_data( $debug_connection );

			if( $use_oauth && $refresh_token = get_option( GFELOQUA_OPT_PREFIX . 'oauth_refresh_token' ) ){
				// Try the refresh token
				return $this->refresh_token( $refresh_token );
			}

			return false;
		}

		return true;
	}

	public function refresh_token( $refresh_token = false ){
		if( ! $refresh_token )
			return false;

		delete_option( GFELOQUA_OPT_PREFIX . 'oauth_token' );

		if( ! $this->eloqua )
			$this->eloqua = new Eloqua_API();

		$client_id = $this->eloqua->_oauth_client_id;
		$client_secret = $this->eloqua->_oauth_client_secret;
		$token_url = $this->eloqua->_oauth_token_url;

		$basic_auth = $client_id . ':' . $client_secret . '@';

		$url = str_replace( array( 'http://','https://' ), 'https://' . $basic_auth, $token_url );

		$args = array(
			'refresh_token' => $refresh_token,
			'grant_type' => 'refresh_token',
			'scope' => $this->eloqua->_oauth_scope,
			'redirect_uri' => $this->eloqua->_oauth_redirect_uri
		);

		$args_string = json_encode( $args );

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );

		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'POST' );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $args_string );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Content-Length: ' . strlen( $args_string ) )
		);

		$response = curl_exec( $ch );
		$json = json_decode( $response );

		if( $json ){
			if( isset( $json->error ) ){
				$debug_json = new GFEloqua_Debug_Data( 'OAUTH JSON Error', $json->error, 'Possibly invalid grant when retrieving refresh token.' );
				$this->debugger->add_data( $debug_json );
			} else {
				/**
				 * public 'access_token' => string
				 * public 'expires_in' => int 28800
				 * public 'token_type' => string 'bearer' (length=6)
				 * public 'refresh_token' => string
				 */
				$oauth_token = $json->access_token;
				update_option( GFELOQUA_OPT_PREFIX . 'oauth_token', $oauth_token );

				if( isset( $json->refresh_token ) && $json->refresh_token ){
					update_option( GFELOQUA_OPT_PREFIX . 'oauth_refresh_token', $json->refresh_token );
				}

				// refresh the eloqua object
				$this->eloqua = new Eloqua_API( $oauth_token, true );

				return true;
			}
		}
		return false;
	}

	/**
	 * The OAuth API will send back the oauth code. This will retrieve it, store it and prepare the page for a window close
	 * @return void
	 */
	function handle_oauth_code(){
		if( ! isset( $_GET['gfeloqua-oauth-code'] ) )
			return;

		if( ! $_GET['gfeloqua-oauth-code'] )
			return;

		$oauth_code = sanitize_text_field( $_GET['gfeloqua-oauth-code'] );

		if( ! $oauth_code )
			return;

		if( $this->generate_oauth_token( $oauth_code ) ){
			// make a note we generated the token successfully.
			$this->token_auto_generated = true;
		}

		// set the flag to close the window
		$this->oauth_token_retrieved = true;
	}

	/**
	 * If we grabbed the OAuth token, try to automatically close the window
	 * @return void
	 */
	function close_oauth_window(){
		if( ! $this->oauth_token_retrieved )
			return;

		echo '<script>window.close();</script>';
		echo '<p style="text-align:center; padding:20px;">' . __( 'If this window does not close automatically, close it to continue.', 'gfeloqua' ) . '</p>';
		exit();
	}

	/**
	 * Get whichever connection string is stored for use
	 * @return string  authstring or oauth token
	 */
	function get_connection_string(){
		$use_basic = get_option( GFELOQUA_OPT_PREFIX . 'auth_basic' );
		$use_oauth = (bool) get_option( GFELOQUA_OPT_PREFIX . 'use_oauth', ! $use_basic );

		$connection_string = $use_oauth ? $this->get_oauth_token() : $this->get_authstring();

		return $connection_string;
	}

	/**
	 * Detect if the user is switching authentication methods
	 * @return boolean  is_switch
	 */
	public function is_switch(){
		$is_switch = false;
		if( $this->is_save_postback() ) {
			$posted = $this->get_posted_settings();

			$switch_to_basic = isset( $posted['gfeloqua_use_basic'] ) && $posted['gfeloqua_use_basic'] == '1';
			$switch_to_oauth = isset( $posted['gfeloqua_use_oauth'] ) && $posted['gfeloqua_use_oauth'] == '1';

			if( $switch_to_basic || $switch_to_oauth ){
				$is_switch = true;
			}
		}
		return $is_switch;
	}

	/**
	 * Detect if the user is switching disconnecting from Eloqua
	 * @return boolean  is_disconnect
	 */
	public function is_disconnect(){
		$is_disconnect = false;

		if( $this->is_save_postback() ) {
			$posted = $this->get_posted_settings();
			$is_disconnect = isset( $posted['eloqua_disconnect'] ) && $posted['eloqua_disconnect'] == '1';
		}

		return $is_disconnect;
	}

	/**
	 * Detect if the user tried to setup the authentication credentials
	 * @return boolean  tried
	 */
	public function tried_to_setup(){
		$tried = false;

		if( $this->is_save_postback() ) {
			$posted = $this->get_posted_settings();
			$tried = isset( $posted['sitename'] ) && isset( $posted['username'] ) && isset( $posted['password'] ) &&
				$posted['sitename'] && $posted['username'] && $posted['password'];
		}

		return $tried;
	}

	/**
	 * We need to override the default validate_settings method for cases when they are disconnecting, switching auth methods or their credentials are not valid.
	 * Be sure to call parent::validate_settings() at the end.
	 */
	public function validate_settings( $fields, $settings ){
		if( $this->is_switch() || $this->is_disconnect() )
			return true;

		if( ! $this->get_connection_string() && $this->tried_to_setup() )
			return false;

		if( $this->get_connection_string() && ! $this->test_authentication() )
			return false;

		return parent::validate_settings( $fields, $settings );
	}

	/**
	 * We need to override the default get_save_error_message method for cases when they are disconnecting, switching auth methods or their credentials are not valid.
	 * Be sure to call parent::get_save_error_message() at the end.
	 */
	public function get_save_error_message( $sections ){
		if( $this->is_switch() )
			return __( 'Switched authentication method.', 'gfeloqua' );

		if( $this->is_disconnect() )
			return __( 'Your connection settings have been removed.', 'gfeloqua' );

		$error = $this->debugger->get_last_message_html();

		if( ! $this->get_connection_string() && $this->tried_to_setup() )
			return __( 'Unable to connect to Eloqua. Invalid authentication credentials. (Invalid Connection String)', 'gfeloqua' ) . ' ' . $error;

		if( $this->get_connection_string() && ! $this->test_authentication() )
			return __( 'Unable to connect to Eloqua. Invalid authentication credentials.', 'gfeloqua' ) . ' ' . $error;

		return parent::get_save_error_message( $sections );
	}

	/**
	 * We need to override the default get_save_success_message method for cases when they are disconnecting or switching auth methods.
	 * Be sure to call parent::get_save_success_message() at the end.
	 */
	public function get_save_success_message( $sections ){
		if( $this->is_switch() )
			return __( 'Switched authentication method.', 'gfeloqua' );

		if( $this->is_disconnect() )
			return __( 'Your connection settings have been removed.', 'gfeloqua' );

		return parent::get_save_success_message( $sections );
	}

	/**
	 * Display a select list of Eloqua Forms pulled from the API
	 * @param  array  $field  the form field
	 * @param  boolean $echo  if the field should be echo'd
	 * @return array  $field
	 */
	public function settings_eloqua_forms( $field, $echo = true ){
		$forms = array(
			array(
				'label' => __( 'Select an Eloqua Form', 'gfeloqua' ),
				'value' => ''
			)
		);

		if( $this->eloqua ){
			$eloqua_forms = $this->eloqua->get_forms();
			if( count( $eloqua_forms ) ){
				foreach( $eloqua_forms as $form ){
					$this->setup_folders( $form );
				}
				foreach( $eloqua_forms as $form ){
					$this->add_form_to_array( $form, $forms );
				}
			} else {
				$forms[0]['label'] = __( 'No Eloqua Forms were found.', 'gfeloqua' );
			}
		}

		$field['type']    = 'select';
		$field['choices'] = $forms;

		$html = $this->settings_select( $field, false );

		if ( $echo )
			echo $html;

		return $html;
	}

	public function setup_folders( $form ){
		if( ! isset( $form->folderId ) || ! $form->folderId )
			return;

		if( isset( $this->folders[ $form->folderId ] ) )
			return;

		$folder = $this->eloqua->get_form_folder_name( $form->folderId );
		$this->folders[ $form->folderId ] = $folder->name;
	}

	public function add_form_to_array( $form, &$array ){
		if( strtolower( $form->type ) != 'form' )
			return;

		if( isset( $form->folderId ) && $form->folderId ){
			$choices = isset( $array[ '_folder' . $form->folderId ]['choices'] ) ? $array[ '_folder' . $form->folderId ]['choices'] : array();
			$choices[ '_form' . $form->id ] = array(
				'label' => $form->name . ' (' . $form->currentStatus . ')',
				'value' => $form->id
			);

			$array[ '_folder' . $form->folderId ] = array(
				'label' => $this->folders[ $form->folderId ],
				'choices' => $choices
			);
		} elseif( ! isset( $array[ '_form' . $form->id ] ) ) {
			$array[ '_form' . $form->id ] = array(
				'label' => $form->name . ' (' . $form->currentStatus . ')',
				'value' => $form->id
			);
		}
	}

	/**
	 * The Eloqua fields to be mapped to Gravity Forms fields
	 * @param  array  $field  the form field
	 * @param  boolean $echo  if the field should be echo'd
	 * @return array  $field
	 */
	public function settings_list_fields( $field, $echo = true ) {

		$form_id = $this->get_setting( 'gfeloqua_form' );
		$custom_fields = $this->eloqua->get_form_fields( $form_id );

		$field_map = array();

		if( is_array( $custom_fields ) && count( $custom_fields ) ){
			foreach( $custom_fields as $custom_field ){
				if( $custom_field->displayType == 'submit' )
					continue;

				$field_map[] = array(
					'name' => $custom_field->id,
					'label' => $custom_field->name,
					'required' => $this->eloqua->is_field_required( $custom_field )
				);
			}
		}

		$field['type'] = 'field_map';
		$field['field_map'] = $field_map;

		$html = $this->settings_field_map( $field, false );

		if ( $echo )
			echo $html;

		return $html;

	}

	/**
	 * Ajax method used to clear a specified transient
	 * @return void
	 */
	public function clear_eloqua_transient(){
		$transient = isset( $_GET['transient'] ) ? sanitize_text_field( $_GET['transient'] ) : false;
		if( $transient ){
			$this->eloqua->clear_transient( $transient );
			$debug_transient = new GFEloqua_Debug_Data( 'Transient Cleared', $transient );
			$debug_transient->set_level( 2 );
			$this->debugger->add_data( $debug_transient );
		}

		wp_send_json( array( 'success' => true ) );
	}

	/**
	 * Main Settings Field for this plugin
	 * @return array $settings
	 */
	public function plugin_settings_fields() {

		$this->_maybe_store_settings();
		$this->_maybe_clear_settings();

		$fields = array();

		$authenticated = $this->get_connection_string();
		$use_basic = get_option( GFELOQUA_OPT_PREFIX . 'auth_basic' );
		$use_oauth = get_option( GFELOQUA_OPT_PREFIX . 'use_oauth', ! $use_basic );

		if( $use_oauth && ! $authenticated ){
			$title = __( 'Login to Eloqua', 'gfeloqua' );

			$fields[] = array(
				'name' => GFELOQUA_OPT_PREFIX . 'oauth_code',
				'tooltip' => __( 'Login to Eloqua using OAuth', 'gfeloqua' ),
				'label' => __( 'Login', 'gfeloqua' ),
				'type' => 'oauth_link',
				'class' => 'gfeloqua-oauth'
			);

			$fields[] = array(
				'type' => 'checkbox',
				'name' => 'switch_to_basic',
				'label' => __( 'Basic Authentication', 'gfeloqua' ),
				'tooltip' => __( 'Use Basic HTTP Authentication instead of OAuth', 'gfeloqua' ),
				'horizontal' => true,
				'choices' => array(
					array(
						'name' => 'gfeloqua_use_basic',
						'label' => __( 'Switch to Basic HTTP Authentication', 'gfeloqua' )
					)
				)
			);

		} else if( ! $use_oauth && ! $authenticated ){
			$title = __( 'Login to Eloqua', 'gfeloqua' );

			$fields[] = array(
				'name'    => 'sitename',
				'tooltip' => __( 'Your Site Name is usually your company name without any spaces.', 'gfeloqua' ),
				'label'   => __( 'Site Name', 'gfeloqua' ),
				'type'    => 'text',
				'class'   => 'medium',
				'required' => true
			);
			$fields[] = array(
				'name'    => 'username',
				'tooltip' => __( 'Your login user name', 'gfeloqua' ),
				'label'   => __( 'Username', 'gfeloqua' ),
				'type'    => 'text',
				'class'   => 'medium',
				'required' => true
			);
			$fields[] = array(
				'name'    => 'password',
				'tooltip' => __( 'Your login password', 'gfeloqua' ),
				'label'   => __( 'Password', 'gfeloqua' ),
				'type'    => 'text',
				'class'   => 'medium',
				'required' => true
			);

			$fields[] = array(
				'type' => 'checkbox',
				'name' => 'switch_to_oauth',
				'label' => __( 'OAuth', 'gfeloqua' ),
				'tooltip' => __( 'Use OAuth instead of Basic HTTP Authentication', 'gfeloqua' ),
				'horizontal' => true,
				'choices' => array(
					array(
						'name' => 'gfeloqua_use_oauth',
						'label' => __( 'Switch to OAuth', 'gfeloqua' )
					)
				)
			);
		} elseif( $authenticated ){
			$title = __( 'Clear Authentication Credentials', 'gfeloqua' );

			$fields[] = array(
				'type' => 'checkbox',
				'name' => 'eloqua_disconnect',
				'label' => __( 'Disconnect', 'gfeloqua' ),
				'tooltip' => __( 'Disconnect your Eloqua account from Gravity Forms', 'gfeloqua' ),
				'horizontal' => true,
				'choices' => array(
					array(
						'name' => 'eloqua_disconnect',
						'label' => __( 'Your Eloqua settings are securely stored. To clear these settings, check this box and click "Update Settings".', 'gfeloqua' )
					)
				)
			);
		} else {
			$title = __( 'There was a problem. Please contact plugin author.', 'gfeloqua' );
		}

		return array(
			array(
				'title'  => $title,
				'fields' => $fields
			), // end settings group
			array(
				'title' => __( 'Disconnect Notification', 'gfeloqua' ),
				'description' => __( 'If your site ever loses connection with Eloqua for any reason, this alert will notify you when Eloqua cannot be reached, allowing you to correct the issue as quickly as possible.', 'gfeloqua' ),
				'fields' => array(
					array(
						'type' => 'checkbox',
						'name' => 'gfeloqua_enable_disconnect_notice',
						'label' => __( 'Admin Notice', 'gfeloqua' ),
						'tooltip' => __( 'When enabled, you will see an admin notice in the WordPress Dashboard when your connection to Eloqua is lost', 'gfeloqua' ),
						'horizontal' => true,
						'choices' => array(
							array(
								'name' => 'enable_disconnect_notice',
								'label' => __( 'Enable Disconnect Admin Notice', 'gfeloqua' )
							)
						)
					),
					array(
						'type' => 'checkbox',
						'name' => 'gfeloqua_enable_disconnect_alert',
						'label' => __( 'Email Alert', 'gfeloqua' ),
						'tooltip' => __( 'When enabled, you will be notified by email when your connection to Eloqua is lost', 'gfeloqua' ),
						'horizontal' => true,
						'choices' => array(
							array(
								'name' => 'enable_disconnect_alert',
								'label' => __( 'Enable Disconnect Notification Email', 'gfeloqua' )
							)
						)
					),
					array(
						'name'    => 'disconnect_alert_email',
						'tooltip' => __( 'Email address to send disconnect alerts', 'gfeloqua' ),
						'label'   => __( 'Email Address', 'gfeloqua' ),
						'type'    => 'text',
						'class'   => 'medium',
						'default_value' => get_bloginfo( 'admin_email' )
					)
				) // end fields array
			), // end settings group

			array(
				'title' => __( 'Retry Attempts', 'gfeloqua' ),
				'description' => __( 'If an entry fails to send over to Eloqua, this plugin will automatically retry the submission a number of times before failing completely.', 'gfeloqua' ),
				'fields' => array(
					array(
						'type' => 'text',
						'input_type' => 'number',
						'name' => 'gfeloqua_retry_limit',
						'label' => __( 'Retry Attempts', 'gfeloqua' ),
						'value' => '5',
						'tooltip' => __( 'This will determine how many retries a submission will receive before failing.', 'gfeloqua' ),
					),
					array(
						'type' => 'select',
						'name' => 'gfeloqua_retry_interval',
						'label' => __( 'Retry Interval', 'gfeloqua' ),
						'value' => '5min',
						'tooltip' => __( 'This determines how frequently failed submissions are retried.', 'gfeloqua' ),
						'choices' => array(
							array(
								'value' => '5min',
								'label' => __( 'Every 5 Minutes', 'gfeloqua' )
							),
							array(
								'value' => '30min',
								'label' => __( 'Every 30 Minutes', 'gfeloqua' )
							),
							array(
								'value' => 'hourly',
								'label' => __( 'Every Hour', 'gfeloqua' )
							),
							array(
								'value' => 'daily',
								'label' => __( 'Every 24 Hours', 'gfeloqua' )
							)
						)
					),
					array(
						'type' => 'checkbox',
						'name' => 'gfeloqua_enable_retry_limit_alert',
						'label' => __( 'Email Alert When Limit Reached', 'gfeloqua' ),
						'tooltip' => __( 'When enabled, you will be notified by email when an entry has continued to fail and reached its retry limit.', 'gfeloqua' ),
						'horizontal' => true,
						'choices' => array(
							array(
								'name' => 'enable_retry_limit_alert',
								'label' => __( 'Enable Retry Limit Notification Email', 'gfeloqua' )
							)
						)
					),
					array(
						'name'    => 'retry_limit_alert_email',
						'tooltip' => __( 'Email address to send retry limit alerts', 'gfeloqua' ),
						'label'   => __( 'Email Address', 'gfeloqua' ),
						'type'    => 'text',
						'class'   => 'medium',
						'default_value' => get_bloginfo( 'admin_email' )
					)
				) // end fields array
			) // end settings group
		);
	}

	/**
	 * Retrieve the oauth token from settings
	 * @return string oauth_token
	 */
	function get_oauth_token(){
		return get_option( GFELOQUA_OPT_PREFIX . 'oauth_token' );
	}

	/**
	 * Retrieve the authstring from settings or try to generate one
	 * @return string oauth_token
	 */
	function get_authstring(){
		$authstring = get_option( GFELOQUA_OPT_PREFIX . 'authstring' );

		if( ! $authstring )
			$authstring = $this->generate_authstring();

		return $authstring;
	}

	/**
	 * Generate and store an auth string either from provided $source_array or from posted values
	 * @param  array  $source_array Array containing fields to generate authstring
	 * @return string               authstring
	 */
	function generate_authstring( $source_array = array() ){
		$authstring = '';
		$posted = array();

		if( $this->is_save_postback() ){
			$posted = $this->get_posted_settings();
		}

		if( $source_array )
			$posted = $source_array;

		if( ! $posted )
			return $authstring;

		$sitename = isset( $posted['sitename'] ) && $posted['sitename'] ? trim( $posted['sitename'] ) : '';
		$username = isset( $posted['username'] ) && $posted['username'] ? trim( $posted['username'] ) : '';
		$password = isset( $posted['password'] ) && $posted['password'] ? trim( $posted['password'] ) : '';

		if( $sitename && $username && $password )
			$authstring = base64_encode( "{$sitename}\\{$username}:{$password}" );

		if( $authstring ){
			if( $this->test_authentication( $authstring ) ){
				update_option( GFELOQUA_OPT_PREFIX . 'authstring', $authstring );
			} else {
				$authstring = '';
			}
		}

		return $authstring;
	}

	/**
	 * Special Gravity Forms Settings Field that allows a user to connect to Eloqua using OAuth
	 * @param  array  $field  form field
	 * @param  boolean $echo  if the field shoul be echo'd
	 * @return string  the field html
	 */
	function settings_oauth_link( $field, $echo = true ){
		$field['type'] = 'oauth_link'; //making sure type is set to oauth_link
		$attributes    = $this->get_field_attributes( $field );
		$default_value = rgar( $field, 'value' ) ? rgar( $field, 'value' ) : rgar( $field, 'default_value' );
		$value         = $this->get_setting( $field['name'], $default_value );

		$name    = esc_attr( $field['name'] );
		$tooltip = isset( $choice['tooltip'] ) ? gform_tooltip( $choice['tooltip'], rgar( $choice, 'tooltip_class' ), true ) : '';
		$oauth_url = $this->eloqua->get_oauth_url( $this->get_plugin_settings_url() );

		$html = '<a id="gfeloqua_oauth" data-width="750" data-height="750" class="button" href="' . $oauth_url . '">' . __( 'Authenticate with your Eloqua account', 'gfeloqua' ) . '</a>
			<span id="' . GFELOQUA_OPT_PREFIX . 'oauth_code" style="display:none;">' . __( 'If you have a code, paste it here:', 'gfeloqua' ) . ' <input type="text" name="' . $name .'" value="' . $value . '" style="width:375px;" /></span>';

		if ( $echo )
			echo $html;

		return $html;
	}

	/**
	 * Used whenever disconnect checkbox value is present
	 * @return bool  if settings where cleared
	 */
	function _maybe_clear_settings(){
		if( $this->is_save_postback() ) {

			$posted = $this->get_posted_settings();

			if( isset( $posted['eloqua_disconnect'] ) && $posted['eloqua_disconnect'] == '1' ){

				delete_option( GFELOQUA_OPT_PREFIX . 'oauth_token' );
				delete_option( GFELOQUA_OPT_PREFIX . 'authstring' );

				$debug_disconnect = new GFEloqua_Debug_Data( 'Eloqua Disconnected' );
				$debug_disconnect->set_level( 2 );
				$this->debugger->add_data( $debug_disconnect );

				return true;
			}
		}

		return false;
	}

	/**
	 * We don't want to store the authentication credentials in the database, so hijack the values and overwrite the save values without any extra values
	 * @return void
	 */
	function _maybe_store_settings(){
		$settings = $this->get_plugin_settings();

		// backwards compatibility in case credentials are stored.
		if( $authstring = $this->generate_authstring( $settings ) ){
			unset( $settings['sitename'] );
			unset( $settings['username'] );
			unset( $settings['password'] );
			$this->update_plugin_settings( $settings );
		}

		// backwards compatibility in case authstring is stored.
		if( isset( $settings['authstring'] ) ){
			update_option( GFELOQUA_OPT_PREFIX . 'authstring', $settings['authstring'] );
			unset( $settings['authstring'] );
			$this->update_plugin_settings( $settings );
		}

		// make sure this doesn't get stored.
		unset( $settings['eloqua_disconnect'] );

		// unset checkboxes
		if( ! isset( $settings['enable_disconnect_alert'] ) ){
			$settings['enable_disconnect_alert'] = '';
		}
		if( ! isset( $settings['enable_disconnect_notice'] ) ){
			$settings['enable_disconnect_notice'] = '';
		}

		$this->update_plugin_settings( $settings );

		if( $this->is_save_postback() ) {

			$posted = $this->get_posted_settings();

			if( isset( $posted['gfeloqua_use_basic'] ) && $posted['gfeloqua_use_basic'] == '1' ){
				update_option( GFELOQUA_OPT_PREFIX . 'auth_basic', '1' );
				delete_option( GFELOQUA_OPT_PREFIX . 'use_oauth' );
				delete_option( GFELOQUA_OPT_PREFIX . 'oauth_token' );
			} elseif( isset( $posted['gfeloqua_use_oauth'] ) && $posted['gfeloqua_use_oauth'] == '1' ){
				update_option( GFELOQUA_OPT_PREFIX . 'use_oauth', '1' );
				delete_option( GFELOQUA_OPT_PREFIX . 'auth_basic' );
				delete_option( GFELOQUA_OPT_PREFIX . 'authstring' );
			}

			// This should only be necessary if Basic Authentication is used.
			$this->generate_authstring();

			// This should only be necessary if OAuth is used
			$this->generate_oauth_token();
		}
	}

	/**
	 * When we have an OAuth code, we need to generate the token immediately
	 * @param  string $code  OAuth code
	 * @return mixed  $token or false
	 */
	public function generate_oauth_token( $code = '' ){
		if( $this->is_save_postback() ){
			$param = GFELOQUA_OPT_PREFIX . 'oauth_code';
			$code = isset( $_POST[ $param ] ) ? sanitize_text_field( $_POST[ $param ] ) : '';
		}

		if( ! $code ){
			$debug_oauth = new GFEloqua_Debug_Data( 'OAUTH Code not present', $code );
			$this->debugger->add_data( $debug_oauth );
			return false;
		}

		if( ! $this->eloqua )
			$this->eloqua = new Eloqua_API();

		$client_id = $this->eloqua->_oauth_client_id;
		$client_secret = $this->eloqua->_oauth_client_secret;
		$token_url = $this->eloqua->_oauth_token_url;

		$basic_auth = $client_id . ':' . $client_secret . '@';

		$url = str_replace( array( 'http://','https://' ), 'https://' . $basic_auth, $token_url );

		$args = array(
			'code' => $code,
			'grant_type' => 'authorization_code',
			'redirect_uri' => $this->eloqua->_oauth_redirect_uri
		);

		$args_string = json_encode( $args );

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );

		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'POST' );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $args_string );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Content-Length: ' . strlen( $args_string ) )
		);

		$response = curl_exec( $ch );
		$json = json_decode( $response );

		if( $json ){
			if( isset( $json->error ) ){
				$debug_json = new GFEloqua_Debug_Data( 'OAUTH JSON Error', $json->error, 'Possibly Invalid Grant.' );
				$this->debugger->add_data( $debug_json );
			} elseif( isset( $json->access_token ) ) {
				/**
				 * public 'access_token' => string
				 * public 'expires_in' => int 28800
				 * public 'token_type' => string 'bearer' (length=6)
				 * public 'refresh_token' => string
				 */
				$oauth_token = $json->access_token;
				update_option( GFELOQUA_OPT_PREFIX . 'oauth_token', $oauth_token );

				if( isset( $json->token_type ) && $json->token_type ){
					update_option( GFELOQUA_OPT_PREFIX . 'oauth_token_type', $json->token_type );
				}
				if( isset( $json->refresh_token ) && $json->refresh_token ){
					update_option( GFELOQUA_OPT_PREFIX . 'oauth_refresh_token', $json->refresh_token );
				}

				return $oauth_token;
			} else {
				// no idea, need to log errors.
			}
		}

		return false;
	}

	/**
	 * Send the form data over to Eloqua with the matched field data
	 * @param  array $feed   GF Feed Array
	 * @param  array $entry  Posted Entry Data
	 * @param  array $form   GF Form Array
	 * @return void
	 */
	public function process_feed( $feed, $entry, $form, $manual = false ){
		if( ! $this->test_authentication() ){
			$debug_auth = new GFEloqua_Debug_Data( 'Authentication Failure'  );
			$this->debugger->add_data( $debug_auth );
			$this->log_entry_notes( $entry['id'], $form['id'] );

			return false;
		}

		$form_id = $feed['meta']['gfeloqua_form'];

		$form_submission = new stdClass();

		$form_submission->id = (int) $form_id;
		$form_submission->type = 'FormData';
		$form_submission->submittedAt = (int) current_time( 'timestamp' );
		$form_submission->submittedByContactId = NULL;
		$form_submission->fieldValues = array();

		foreach( $feed['meta'] as $key => $gf_field_id ){

			if( strpos( $key, 'mapped_fields_' ) !== false ){
				if( ! isset( $entry[ $gf_field_id ] ) && ! isset( $entry[ $gf_field_id . '.1' ] ) )
					continue;

				$key = str_replace( 'mapped_fields_', '', $key );

				$field_value = new stdClass();
				$field_value->id = (int) $key;
				$field_value->type = 'FieldValue';
				if( isset( $entry[ $gf_field_id . '.1' ] ) ){
					$field_value->value = '';
					foreach( $entry as $entry_key => $value ){
						if( strpos( $entry_key, $gf_field_id . '.' ) !== false && $value ){
							$field_value->value .= $field_value->value ? ',' . $value : $value;
						}
					}
				} else {
					$field_value->value = $entry[ $gf_field_id ];
				}

				$form_submission->fieldValues[] = $field_value;
			}
		}

		$debug_submission = new GFEloqua_Debug_Data( 'Submission Object to Eloqua', $form_submission );
		$this->debugger->add_data( $debug_submission );

		$response = $this->eloqua->submit_form( $form_id, $form_submission );
		$errors = $this->eloqua->get_errors();
		$this->prepare_errors( $errors, $entry['id'], $manual );

		if( ! $response || $errors ){

			if( $response ){
				$this->mark_as_sent( $entry['id'], $form['id'], true );
			} else {
				gform_update_meta( $entry['id'], GFELOQUA_OPT_PREFIX . 'success', $this->text_for_failed, $form['id'] );
			}

			$status = false;
		} else {
			$this->mark_as_sent( $entry['id'], $form['id'] );
			$status = true;
		}

		$this->log_entry_notes( $entry['id'], $form['id'] );

		return $status;

	}

	function prepare_errors( $errors = false, $entry_id = NULL, $manual = false ){
		$last_response = false;
		if( $this->eloqua->last_response ){
			$last_response = $this->eloqua->last_response;
		} elseif( $this->last_response ) {
			$last_response = $this->last_response;
		}

		if( $last_response ){
			$debug_response = new GFEloqua_Debug_Data( 'Last Response', $last_response );
			$this->debugger->add_data( $debug_response );
		}

		if( $errors ){

			$attempts = $this->get_retry_attempts( $entry_id );
			$manual = is_string( $manual ) && $manual === 'manual' ? ' - MANUAL' : '';
			$note = sprintf( __( 'Attempt %s%s' ), $attempts, $manual );

			foreach( $errors as $error ){
				$debug_error = new GFEloqua_Debug_Data( 'Submission Error', $error, $note );
				$this->debugger->add_data( $debug_error );
			}
		}
	}

	function mark_as_sent( $entry_id, $form_id = NULL, $with_errors = false ){
		$success_text = $this->text_for_success;
		if( $with_errors )
			$success_text .= ' (?)';
		gform_update_meta( $entry_id, GFELOQUA_OPT_PREFIX . 'success', $success_text, $form_id );

		$note = $with_errors ? __( 'With errors', 'gfeloqua' ) : '';
		$debug_success = new GFEloqua_Debug_Data( 'Successfully Sent', $success_text, $note );
		$this->debugger->add_data( $debug_success );
	}

	function limit_reached( $entry_id ){
		return gform_get_meta( $entry_id, GFELOQUA_OPT_PREFIX . 'limit_reached' );
	}

	function cease_retries( $entry_id, $form_id = NULL ){
		if( $this->limit_reached( $entry_id ) )
			return;

		gform_update_meta( $entry_id, GFELOQUA_OPT_PREFIX . 'limit_reached', '1', $form_id );

		$debug_limit = new GFEloqua_Debug_Data( 'Automatic retry limit reached', array( 'Entry ID:' => $entry_id, 'Form ID:' => $form_id ) );
		$this->debugger->add_data( $debug_limit );

		$this->retry_limit_notification( $entry_id, $form_id );
	}

	/**
	 * Send an email when Eloqua is disconnected
	 * @return void
	 */
	function disconnect_notification(){
		$enabled = $this->get_plugin_setting( 'enable_disconnect_alert' );
		if( ! $enabled )
			return;

		$recipient = $this->get_plugin_setting( 'disconnect_alert_email' );
		if( ! $recipient )
			return;

		if( ! $this->test_authentication() ){
			// send email
			$subject = __( 'Eloqua Disconnected from Gravity Forms', 'gfeloqua' );
			$headers = array(
				'From: GFEloqua <' . get_bloginfo( 'admin_email' ) . '>',
				'Content-Type: text/html; charset=UTF-8'
			);
			$settings_page_url = $this->get_plugin_settings_url();
			$settings_page_link = '<a href="' . $settings_page_url . '">' . $settings_page_url . '</a>';
			$template = locate_template( array( 'gfeloqua/disconnected-email.php', 'gfeloqua-disconnected-email.php' ) );
			if( ! $template || ! file_exists( $template ) )
				$template = GFELOQUA_PATH . 'views/disconnected-email.php';

			ob_start();
			include( $template );
			$message = ob_get_clean();

			$debug_disconnect = new GFEloqua_Debug_Data( 'Sending disconnect notice email' );
			$debug_disconnect->set_level( 2 );
			$this->debugger->add_data( $debug_disconnect );

			wp_mail( $recipient, $subject, $message, $headers );
		}
	}
	/**
	 * Send an email when retry limit is reached for an entry
	 * @return void
	 */
	function retry_limit_notification( $entry_id, $form_id = NULL ){
		$enabled = $this->get_plugin_setting( 'enable_retry_limit_alert' );
		if( ! $enabled )
			return;

		$recipient = $this->get_plugin_setting( 'retry_limit_alert_email' );
		if( ! $recipient )
			return;

		$entry = GFAPI::get_entry( $entry_id );
		if( ! $entry )
			return;

		// send email
		$subject = __( 'Eloqua Submission Limit Reached', 'gfeloqua' );
		$headers = array(
			'From: GFEloqua <' . get_bloginfo( 'admin_email' ) . '>',
			'Content-Type: text/html; charset=UTF-8'
		);

		$query_string = 'page=gf_entries&view=entry&id=' . absint( $form_id ) . '&lid=' . esc_attr( $entry_id );
		$entry_url = admin_url( 'admin.php?' . $query_string );
		$link_to_entry = '<a href="' . $entry_url . '">' . $entry_url . '</a>';

		$template = locate_template( array( 'gfeloqua/retry-limit.php', 'gfeloqua-retry-limit.php' ) );
		if( ! $template || ! file_exists( $template ) )
			$template = GFELOQUA_PATH . 'views/retry-limit.php';

		ob_start();
		include( $template );
		$message = ob_get_clean();

		$debug_retry = new GFEloqua_Debug_Data( 'Sending retry limit email' );
		$debug_retry->set_level( 2 );
		$this->debugger->add_data( $debug_retry );

		wp_mail( $recipient, $subject, $message, $headers );
	}

	/**
	 * Display an Admin Alert when Eloqua is Disconnected
	 * @return void
	 */
	function disconnect_notice(){
		$enabled = $this->get_plugin_setting( 'enable_disconnect_notice' );
		if( ! $enabled )
			return;

		if( ! $this->test_authentication() ){
			$settings_page_url = $this->get_plugin_settings_url();

			echo '<div class="notice notice-error is-dismissible">
		        <p>' . sprintf( __( 'It seems as though Gravity Forms has lost connection to your Eloqua account. <a href="%s">Click here to re-connect.</a>', 'gfeloqua' ), $settings_page_url ) . '</p>
		    </div>';
		}
	}

	/**
	 * Log GFEloqua Notes in Entry Meta
	 * @param  int $entry_id   Entry ID
	 * @param  int $form_id    GF Form ID
	 * @return void
	 */
	function log_entry_notes( $entry_id, $form_id = NULL ){
		$notes = $this->get_entry_notes( $entry_id );
		if( ! $notes )
			$notes = array();

		$new_notes = $this->debugger->get_data_as_notes();
		$notes = array_merge( $notes, $new_notes );

		gform_update_meta( $entry_id, GFELOQUA_OPT_PREFIX . 'notes', $notes, $form_id );
	}

	/**
	 * Erases all entry notes
	 * @param  int $entry_id   Entry ID
	 * @param  int $form_id    GF Form ID
	 * @return void
	 */
	function clear_entry_notes( $entry_id, $form_id = NULL ){
		gform_update_meta( $entry_id, GFELOQUA_OPT_PREFIX . 'notes', array(), $form_id );
	}

	/**
	 * Grab GFEloqua Notes from Entry Meta
	 * @param  int $entry_id Entry ID
	 * @return array Notes
	 */
	function get_entry_notes( $entry_id ){
		$notes = gform_get_meta( $entry_id, GFELOQUA_OPT_PREFIX . 'notes' );
		if( $notes )
			return $notes;

		return array();
	}

	/**
	 * Display Notes from Submission to Eloqua
	 * @param  object $form  Gravity Object
	 * @param  object $entry Entry Object
	 * @return void
	 */
	function entry_notes( $form, $entry ){
		if( is_admin() ){
			$notes = $this->get_entry_notes( $entry['id'] );

			if( ! $notes )
				return;

			$notes = array_reverse( $notes );
			$success = $this->is_successful_submission( $entry['id'], $notes );
			$retry_attempts = $this->get_retry_attempts( $entry['id'] );
			if( ! $retry_attempts )
				$retry_attempts = 0;
			$retry_limit = $this->get_plugin_setting( 'gfeloqua_retry_limit' );
			if( ! $retry_limit )
				$retry_limit = '&#x221e;';

			// override the notes with the success message.
			if( $success && ! isset( $_GET['gfeloqua-notes'] ) )
				$notes = array( __( 'Data successfully sent to Eloqua!', 'gfeloqua' ) );

			$view = GFELOQUA_PATH . '/views/entry-notes.php';
			$notes_display = GFELOQUA_PATH . '/views/notes-display.php';

			include( $view );
		}
	}

	/**
	 * Determine if the submission is successful
	 * @param  int $entry_id   Entry ID
	 * @param  array   $notes    Notes to check for successful
	 * @return boolean           [description]
	 */
	function is_successful_submission( $entry_id, $notes = array(), $form_id = NULL ){
		$success = gform_get_meta( $entry_id, GFELOQUA_OPT_PREFIX . 'success' );
		$datestamp = current_time( 'mysql' );
		$success_note = __( 'Determined to be successful based on: %s.', 'gfeloqua' );

		if( $success === 1 ){
			$debug_note = sprintf( $success_note, 'marked as success' );
			$debug_success = new GFEloqua_Debug_Data( 'Is Success', $success, $debug_note );
			$debug_success->set_level( 3 );
			$this->debugger->add_data( $debug_success );
			return true;
		}

		if( strpos( strtolower( $success ), 'success' ) !== false ){
			$debug_note = sprintf( $success_note, 'string "success" found in success meta' );
			$debug_success = new GFEloqua_Debug_Data( 'Is Success', $success, $debug_note );
			$debug_success->set_level( 3 );
			$this->debugger->add_data( $debug_success );
			return true;
		}

		if( ! $notes )
			$notes = $this->get_entry_notes( $entry_id );

		if( $success === 0 || strpos( strtolower( $success ), 'failed' ) !== false ){
			$success = false;
		} elseif( count( $notes ) ) { // legacy support
			foreach( $notes as $note ){
				if( is_string( $note ) ) {
					if( strpos( $note, 'Data successfully sent' ) !== false ){
						$success = true;

						$debug_note = sprintf( $success_note, 'string "Data successfully sent" found in notes' );
						$debug_success = new GFEloqua_Debug_Data( 'Is Success', $note, $debug_note );
						$debug_success->set_level( 3 );
						$this->debugger->add_data( $debug_success );

						// mark it as successful to future-proof
						$this->mark_as_sent( $entry_id );
						break;
					} elseif( strpos( $note, 'Unknown error' ) !== false ){
						$success = false;
					}
				}
			}
		}

		return $success;
	}

	/**
	 * Method to retry entry submission (used for AJAX and Cron)
	 * @return json
	 */
	function resubmit_entry( $entry_id = NULL, $form_id = NULL ){
		if( ! $entry_id )
			$entry_id = isset( $_GET['entry_id'] ) && $_GET['entry_id'] ? (int) sanitize_text_field( $_GET['entry_id'] ) : false;

		if( ! $form_id )
			$form_id = isset( $_GET['form_id'] ) && $_GET['form_id'] ? (int) sanitize_text_field( $_GET['form_id'] ) : false;

		if( ! $entry_id || ! $form_id )
			return false;

		// prevent duplicate successful resubmissions
		if( $success = $this->is_successful_submission( $entry_id ) ){
			wp_send_json( array(
				'success' => $success
			) );
			exit();
		}

		// gather the vars we need for resubmission
		$feeds = GFAPI::get_feeds( NULL, $form_id, $this->_slug );

		if( ! $feeds )
			return false;

		$manual = isset( $_GET['entry_id'] ) ? 'manual' : false;

		if( ! $manual ){

			// make sure we haven't reached the retry limit
			$retry_limit = $this->get_plugin_setting( 'gfeloqua_retry_limit' );
			$retry_attempts = $this->get_retry_attempts( $entry_id );

			if( $retry_limit && $retry_attempts >= $retry_limit ){
				$this->cease_retries( $entry_id, $form_id );
				return false;
			}

		}

		$this->update_retry_attempts( $entry_id, $form_id );

		$feed = $feeds[0];
		$entry = GFAPI::get_entry( $entry_id );
		$form = GFAPI::get_form( $form_id );

		// re-attempt to submit the data
		$res = $this->process_feed( $feed, $entry, $form, $manual );

		if( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX )
			return $res;

		// grab a new copy of the notes
		$notes = $this->get_entry_notes( $entry_id );
		$notes = array_reverse( $notes );
		$retries = $this->get_retry_attempts( $entry_id );

		ob_start();
		$notes_display = GFELOQUA_PATH . '/views/notes-display.php';
		include( $notes_display );

		$response = array(
			'notes' => ob_get_clean(),
			'retries' => $retries,
			'success' => $this->is_successful_submission( $entry_id )
		);

		wp_send_json( $response );
		exit();
	}

	function get_retry_attempts( $entry_id ){
		return gform_get_meta( $entry_id, GFELOQUA_OPT_PREFIX . 'retries' );
	}

	function update_retry_attempts( $entry_id, $form_id = NULL ){
		$attempts = $this->get_retry_attempts( $entry_id );
		if( ! $attempts )
			$attempts = 0;
		$attempts++;
		gform_update_meta( $entry_id, GFELOQUA_OPT_PREFIX . 'retries', $attempts, $form_id );
	}

	function add_success_meta( $meta, $form_id ){
		$feeds = GFAPI::get_feeds( NULL, $form_id, $this->_slug );

		if( ! $feeds )
			return $meta;

		$meta[ GFELOQUA_OPT_PREFIX . 'success'] = array(
			'label' => __( 'Sent to Eloqua?', 'gfeloqua' ),
			'is_numeric' => false,
			'update_entry_meta_callback' => 'update_entry_meta',
			'is_default_column' => false
		);

		return $meta;
	}

	function add_extra_cron_schedule( $schedules ){
		if( ! isset( $schedules[ '5min' ] ) ){
			$schedules['5min'] = array(
				'interval' => 5 * 60,
				'display' => __( 'Once every 5 minutes', 'gfeloqua' )
			);
		}
		if( ! isset( $schedules[ '30min' ] ) ){
			$schedules['30min'] = array(
				'interval' => 30 * 60,
				'display' => __( 'Once every 30 minutes', 'gfeloqua' )
			);
		}
		if( ! isset( $schedules[ 'hourly' ] ) ){
			$schedules['hourly'] = array(
				'interval' => HOUR_IN_SECONDS,
				'display' => __( 'Once every hour', 'gfeloqua' )
			);
		}
		if( ! isset( $schedules[ 'daily' ] ) ){
			$schedules['daily'] = array(
				'interval' => DAY_IN_SECONDS,
				'display' => __( 'Once daily', 'gfeloqua' )
			);
		}

		return $schedules;
	}

	/**
	 * Retry failed entry submissions to Eloqua
	 * @return void
	 */
	function retry_failed_submissions(){
		$feeds = GFAPI::get_feeds( NULL, NULL, $this->_slug );

		$forms = array();
		foreach( $feeds as $feed ){
			if( isset( $feed['form_id'] ) && is_array( $feed['form_id'] ) && ! in_array( $feed['form_id'] ) )
				$forms[] = $feed['form_id'];
		}

		$entries = $this->get_failed_entries( $forms );

		foreach( $entries as $entry ){
			$this->resubmit_entry( $entry['id'], $entry['form_id'] );
		}
	}

	/**
	 * Grab failed GF Entries
	 * @param  array  $forms collected forms
	 * @return array        Entries
	 */
	function get_failed_entries( $forms = array() ){

		if ( empty( $forms ) ) {
			return array();
		}
		return GFAPI::get_entries( $forms, array(
			'field_filters' => array(
				'mode' => 'any',
				array( 'key' => GFELOQUA_OPT_PREFIX . 'success', 'value' => 0 ),
				array( 'key' => GFELOQUA_OPT_PREFIX . 'success', 'value' => $this->text_for_failed )
			)
		), null, array( 'page_size' => 50 ) );
	}

	/**
	 * Keeping this in here incase I need to use it in the future.
	 * @return [type] [description]
	 */
	/*function fix_old_entries(){
		$feeds = GFAPI::get_feeds( NULL, NULL, $this->_slug );

		$forms = array();
		foreach( $feeds as $feed ){
			if( ! in_array( $feed['form_id'] ) )
				$forms[] = $feed['form_id'];
		}

		$this->init_entries( $forms );
	}

	function init_entries( $forms, $action = 'fails2success', $offset = 0, $limit = 200 ){
		if( ! $forms )
			return;

		if( $action == 'fails2success' ){
			$init_query = array(
				array( 'key' => GFELOQUA_OPT_PREFIX . 'success', 'value' => $this->text_for_failed )
			);
		} elseif( $action == 'init2failed' ) {
			$init_query = array(
				'mode' => 'any',
				array( 'key' => GFELOQUA_OPT_PREFIX . 'success', 'value' => NULL, 'operator' => 'isnot' ),
				array( 'key' => GFELOQUA_OPT_PREFIX . 'success', 'value' => '', 'operator' => 'isnot' ),
				array( 'key' => GFELOQUA_OPT_PREFIX . 'notes', 'value' => NULL, 'operator' => 'isnot' ),
				array( 'key' => GFELOQUA_OPT_PREFIX . 'notes', 'value' => '', 'operator' => 'isnot' )
			);
		} else {
			return;
		}

		$batch_entries = GFAPI::get_entries( $forms, $init_query, array(), array( 'offset' => $offset, 'page_size' => $limit ) );

		foreach( $batch_entries as $entry ){
			if( ! $this->is_successful_submission( $entry['id'] ) ){
				if( $action == 'fails2success' ){
					gform_update_meta( $entry['id'], GFELOQUA_OPT_PREFIX . 'success', $this->text_for_success, $entry['form_id'] );
				} elseif( $action == 'init2failed' ) {
					gform_update_meta( $entry['id'], GFELOQUA_OPT_PREFIX . 'success', $this->text_for_failed, $entry['form_id'] );
				}
				$this->init_counter++;
			}
		}

		if( count( $batch_entries ) >= $limit )
			$this->init_entries( $forms, $action, $offset + $limit, $limit );

		return $this->init_counter;
	}*/

	function reset_entry( $entry_id = NULL, $form_id = NULL ){
		if( ! $entry_id )
			$entry_id = isset( $_GET['entry_id'] ) && $_GET['entry_id'] ? (int) sanitize_text_field( $_GET['entry_id'] ) : false;

		if( ! $form_id )
			$form_id = isset( $_GET['form_id'] ) && $_GET['form_id'] ? (int) sanitize_text_field( $_GET['form_id'] ) : false;

		if( ! $entry_id || ! $form_id )
			return false;

		gform_update_meta( $entry_id, GFELOQUA_OPT_PREFIX . 'success', $this->text_for_failed, $form_id );

		$response = array(
			'success' => true
		);

		wp_send_json( $response );
		exit();
	}
}
