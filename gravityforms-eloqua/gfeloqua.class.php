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

	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new GFEloqua();
		}

		return self::$_instance;
	}

	public function init(){
		parent::init();

		$this->_maybe_store_settings();
		$this->_maybe_clear_settings();

		$use_basic = get_option( GFELOQUA_OPT_PREFIX . 'auth_basic' );
		$use_oauth = (bool) get_option( GFELOQUA_OPT_PREFIX . 'use_oauth', ! $use_basic );
		$this->eloqua = new Eloqua_API( $this->get_connection_string(), $use_oauth );

		add_action( 'wp_ajax_gfeloqua_clear_transient', array( $this, 'clear_eloqua_transient' ) );

		if( $this->is_detail_page() ){
			wp_enqueue_script( 'gform_conditional_logic' );
			wp_enqueue_script( 'gform_gravityforms' );
			wp_enqueue_script( 'gform_form_admin' );
		}

		add_action( 'admin_init', array( $this, 'insert_version_data' ) );
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

		$body->offerings->{$this->_slug} = $gfeloqua;

		$update_info['body'] = json_encode( $body );

		set_transient( 'gform_update_info', $update_info, DAY_IN_SECONDS );
	}

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

	function enqueue_conditions(){
		return array(
			array( 'query' => 'page=gf_edit_forms&view=settings&subview=' . $this->_slug ),
			array( 'query' => 'page=gf_settings&subview=' . $this->_slug )
		);
	}

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

	public function scripts() {
		$scripts = array(
			array(
				'handle'  => 'select2',
				'src'     => $this->get_base_url() . '/lib/select2/js/select2.min.js',
				'version' => '4.0.0',
				'deps'    => array( 'jquery' ),
				'strings' => array(
					'ajax_url'  => admin_url( 'admin-ajax.php' )
				),
				'enqueue' => $this->enqueue_conditions()
			),
			array(
				'handle'  => 'gfeloqua',
				'src'     => $this->get_base_url() . '/assets/js/gfeloqua.min.js',
				'version' => $this->_version,
				'deps'    => array( 'jquery', 'select2' ),
				'strings' => array(
					'ajax_url'  => admin_url( 'admin-ajax.php' )
				),
				'enqueue' => $this->enqueue_conditions()
			),

		);

		return array_merge( parent::scripts(), $scripts );
	}

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

	public function feed_list_page( $form = NULL ){
		if( ! $this->get_connection_string() ){
			$settings_page = $this->get_plugin_settings_url();
			$view = GFELOQUA_PATH . '/views/needs-setup.php';
			include( $view );
			return;
		}

		parent::feed_list_page( $form );
	}

	public function feed_list_columns() {
		return array(
			'feed_name' => __( 'Feed Name', 'gfeloqua' ),
			'gfeloqua_form' => __( 'Eloqua Form Name', 'gfeloqua' ),
		);
	}

	public function get_column_value_feed_name( $feed ){
		return $feed['meta']['feed_name'];
	}

	public function get_column_value_gfeloqua_form( $feed ){
		$form_name = '';
		$form = $this->eloqua->get_form( $feed['meta']['gfeloqua_form'] );

		if( is_object( $form ) )
			$form_name = $form->name;

		$form_name .= $form_name ? ' (ID: ' . $feed['meta']['gfeloqua_form'] . ')' : 'ID: ' . $feed['meta']['gfeloqua_form'];

		return $form_name;
	}

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

			if( $use_oauth && $refresh_token = get_option( GFELOQUA_OPT_PREFIX . 'oauth_refresh_token' ) ){
				// Try the refresh token

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
						// invalid grant probably.. need to find a place to log errors.
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

						return true;
					}
				}
			}

			return false;
		}

		return true;
	}

	function get_connection_string(){
		$use_basic = get_option( GFELOQUA_OPT_PREFIX . 'auth_basic' );
		$use_oauth = (bool) get_option( GFELOQUA_OPT_PREFIX . 'use_oauth', ! $use_basic );

		$connection_string = $use_oauth ? $this->get_oauth_token() : $this->get_authstring();

		return $connection_string;
	}

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

	public function is_disconnect(){
		$is_disconnect = false;

		if( $this->is_save_postback() ) {
			$posted = $this->get_posted_settings();
			$is_disconnect = isset( $posted['eloqua_disconnect'] ) && $posted['eloqua_disconnect'] == '1';
		}

		return $is_disconnect;
	}

	public function validate_settings( $fields, $settings ){
		if( $this->get_connection_string() && ! $this->test_authentication() )
			return false;

		if( $this->is_switch() || $this->is_disconnect() )
			return true;

		return parent::validate_settings( $fields, $settings );
	}

	public function get_save_error_message( $sections ){
		if( $this->get_connection_string() && ! $this->test_authentication() )
			return __( 'Unable to connect to Eloqua. Invalid authentication credentials.', 'gfeloqua' );

		if( $this->is_switch() )
			return __( 'Switched authentication method.', 'gfeloqua' );

		if( $this->is_disconnect() )
			return __( 'Your connection settings have been removed.', 'gfeloqua' );

		return parent::get_save_error_message( $sections );
	}


	public function get_save_success_message( $sections ){
		if( $this->is_switch() )
			return __( 'Switched authentication method.', 'gfeloqua' );

		if( $this->is_disconnect() )
			return __( 'Your connection settings have been removed.', 'gfeloqua' );

		return parent::get_save_success_message( $sections );
	}

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
					$forms[] = array(
						'label' => $form->name . ' (' . $form->currentStatus . ')',
						'value' => $form->id
					);
				}
			} else {
				$forms[] = array(
					'label' => __( 'No Eloqua Forms were found.', 'gfeloqua' ),
					'value' => ''
				);
			}
		}

		$field['type']    = 'select';
		$field['choices'] = $forms;

		$html = $this->settings_select( $field, false );

		if ( $echo )
			echo $html;

		return $html;
	}

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

	public function clear_eloqua_transient(){
		$transient = isset( $_GET['transient'] ) ? sanitize_text_field( $_GET['transient'] ) : false;
		if( $transient )
			$this->eloqua->clear_transient( $transient );

		wp_send_json( array( 'success' => true ) );
	}

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
		} else {
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
						'label' => __( 'Your Eloqua settings are securely stored. To clear these settings, check this box and click "Update".', 'gfeloqua' )
					)
				)
			);
		}

		return array(
			array(
				'title'  => $title,
				'fields' => $fields
			)
		);
	}

	function get_oauth_token(){
		return get_option( GFELOQUA_OPT_PREFIX . 'oauth_token' );
	}

	function get_authstring(){
		$authstring = get_option( GFELOQUA_OPT_PREFIX . 'authstring' );

		if( ! $authstring )
			$authstring = $this->generate_authstring();

		return $authstring;
	}

	function settings_oauth_link( $field, $echo = true ){
		$field['type'] = 'oauth_link'; //making sure type is set to text
		$attributes    = $this->get_field_attributes( $field );
		$default_value = rgar( $field, 'value' ) ? rgar( $field, 'value' ) : rgar( $field, 'default_value' );
		$value         = $this->get_setting( $field['name'], $default_value );

		$name    = esc_attr( $field['name'] );
		$tooltip = isset( $choice['tooltip'] ) ? gform_tooltip( $choice['tooltip'], rgar( $choice, 'tooltip_class' ), true ) : '';
		$html    = '';

		$oauth_url = $this->eloqua->get_oauth_url( admin_url( '?page=gf_settings&subview=' . $this->_slug ) );

		$html = '<a id="gfeloqua_oauth" data-width="750" data-height="750" class="button" href="' . $oauth_url . '">' . __( 'Authenticate with your Eloqua account', 'gfeloqua' ) . '</a>
			<span id="' . GFELOQUA_OPT_PREFIX . 'oauth_code" style="display:none;">' . __( 'Paste your code here:', 'gfeloqua' ) . ' <input type="text" name="' . $name .'" value="' . $value . '" style="width:375px;" /></span>';

		if ( $echo )
			echo $html;

		return $html;
	}

	function _maybe_clear_settings(){
		if( $this->is_save_postback() ) {

			$posted = $this->get_posted_settings();

			if( isset( $posted['eloqua_disconnect'] ) && $posted['eloqua_disconnect'] == '1' ){

				delete_option( GFELOQUA_OPT_PREFIX . 'oauth_token' );
				delete_option( GFELOQUA_OPT_PREFIX . 'authstring' );

				return true;
			}
		}

		return false;
	}

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

	public function generate_oauth_token(){
		if( $this->is_save_postback() ){
			$param = GFELOQUA_OPT_PREFIX . 'oauth_code';
			$code = isset( $_POST[ $param ] ) ? sanitize_text_field( $_POST[ $param ] ) : '';

			if( $code ){

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
						// invalid grant probably.. need to find a place to log errors.
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

						return $oauth_token;
					}
				}
			}
		}

		return false;
	}

	public function process_feed( $feed, $entry, $form ){
		$form_id = $feed['meta']['gfeloqua_form'];

		$form_submission = new stdClass();

		$form_submission->id = (int) $form_id;
		$form_submission->type = 'FormData';
		$form_submission->submittedAt = (int) current_time( 'timestamp' );
		$form_submission->submittedByContactId = NULL;
		$form_submission->fieldValues = array();

		foreach( $feed['meta'] as $key => $gf_field_id ){

			if( strpos( $key, 'mapped_fields_' ) !== false ){
				if( ! isset( $entry[ $gf_field_id ]) )
					continue;

				$key = str_replace( 'mapped_fields_', '', $key );

				$field_value = new stdClass();
				$field_value->id = (int) $key;
				$field_value->type = 'FieldValue';
				$field_value->value = $entry[ $gf_field_id ];

				$form_submission->fieldValues[] = $field_value;
			}
		}

		$response = $this->eloqua->submit_form( $form_id, $form_submission );
	}
}
