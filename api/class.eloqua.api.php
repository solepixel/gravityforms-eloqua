<?php

if( class_exists( 'Eloqua_API' ) )
	return;

if( ! class_exists( 'WP_Http' ) )
	include_once( ABSPATH . WPINC . '/class-http.php' );

class Eloqua_API {

	const ELOQUA_IS_REQUIRED = 'IsRequiredCondition';

	public $urls;

	public $connection;
	public $connection_args = array();

	public $authstring;
	public $use_oauth = false;

	public $basic_auth_url = 'https://login.eloqua.com/id';
	public $rest_api_version = '2.0';

	public $_oauth_authorize_url = 'https://login.eloqua.com/auth/oauth2/authorize';
	public $_oauth_token_url = 'https://login.eloqua.com/auth/oauth2/token';

	public $_oauth_client_id = '11c8590a-f513-496a-aa9c-4a224dd92861';
	public $_oauth_client_secret = '15325mypy7U2JFaTg35mF8ekItAyOdiOwfsZBx2dbHEECNecqSy9KK5ammgNlEhMwhhEav1te0hP8hdmQ1KaZjY1z9yQLlaGkQgP';
	public $_oauth_redirect_uri = 'https://api.briandichiara.com/gravityformseloqua/';
	public $_oauth_scope = 'full';

	public $errors = array();

	function __construct( $authstring = '', $use_oauth = false ){
		if( $authstring )
			$this->authstring = $authstring;

		$this->use_oauth = $use_oauth;
	}

	public function get_errors(){
		return $this->errors;
	}

	function get_oauth_url( $source = false ){
		$return_url = admin_url( 'admin.php?page=gf_settings&subview=gravityformseloqua' );
		$return_url = str_replace( array( 'http://', 'https://' ), '', $return_url );

		$url = $this->_oauth_authorize_url .
			'?response_type=code&client_id=' . $this->_oauth_client_id .
			'&scope=' . urlencode( $this->_oauth_scope ) .
			'&redirect_uri=' . urlencode( $this->_oauth_redirect_uri ) .
			'&state=' . $return_url;

		if( $source )
			$url .= '&source=' . urlencode( $source );

		return $url;
	}

	function get_auth_url(){
		return $this->basic_auth_url;
	}

	function init( $connection = false ){
		if( ! $this->connection )
			return false;

		if( ! $connection )
			$connection = get_transient( 'gfeloqua_connection' );

		if( ! $connection  )
			return false;

		if( ! isset( $connection->urls ) )
			return false;

		$this->_setup_urls( $connection->urls );

		if( ! get_transient( 'gfeloqua_connection' ) )
			set_transient( 'gfeloqua_connection', $connection, MINUTE_IN_SECONDS * 60 );

		return true;
	}

	public function connect(){
		if( $this->init() )
			return true;

		$type = $this->use_oauth ? 'Bearer' : 'Basic';

		$this->connection_args = array(
			'headers' => array(
				'Authorization' => $type . ' ' . $this->authstring
			)
		);

		$this->connection = new WP_Http();
		$response = $this->connection->request( $this->get_auth_url(), $this->connection_args );

		if( is_wp_error( $response ) ){
			$this->errors[] = 'WP_Http Error: ' . $response->get_error_message();
			return false;
		}

		$connection = json_decode( $response['body'] );

		if( is_object( $connection ) )
			return $this->init( $connection );

		// Looks like the credentials are bad.
		if( is_string( $connection ) && strpos( strtolower( $connection ), 'not authenticated' ) !== false )
			return false;

		// Something went wrong. Probably an error
		#echo $connection;

		return false;

	}

	private function _setup_urls( $urls ){
		$rest_urls = array();
		foreach( $urls->apis->rest as $key => $rest_url ){
			$rest_urls[ $key ] = str_replace( '{version}', $this->rest_api_version, $rest_url );
		}

		$this->urls = $rest_urls;
	}


	public function _call( $endpoint, $data = array(), $method = 'GET' ){
		if( ! $this->connect() )
			return false;

		$url = $this->urls['standard'] . trim( $endpoint, '/' );
		$args = $this->connection_args;
		$args['method'] = $method;

		if( count( $data ) ){
			$args['body'] = json_encode( $data );
			$args['headers']['Content-Type'] = 'application/json';
		}

		$response = $this->connection->request( $url, $args );

		if( ! $data = $this->validate_response( $response ) ){
			if( is_string( $response ) ){
				$this->errors[] = $response;
			} else {
				$this->errors[] = 'Response Validation Failed.';
			}

			return false;
		}

		return $data;
	}

	public function validate_response( $response ){
		$return = false;

		if( $response && is_array( $response ) ){

			if( isset( $response['response'] ) && isset( $response['response']['code'] ) ){

				// 201 = "Created"
				if( in_array( $response['response']['code'], array( '200', '201', '202' ) ) && $response['body'] ){
					$return = json_decode( $response['body'] );
				}

			}

		}

		return apply_filters( 'gfeloqua_validate_response', $return, $response );
	}

	public function is_valid_data( $data ){
		if( ! is_object( $data ) || ! isset( $data->elements ) )
			return false;

		return true;
	}

	private function get_transient( $transient ){
		return get_transient( 'gfeloqua/' . $transient );
	}

	private function set_transient( $transient, $value, $expiration = NULL ){
		if( $expiration === NULL )
			$expiration = DAY_IN_SECONDS * 15;

		set_transient( 'gfeloqua/' . $transient, $value, $expiration );
	}

	public function clear_transient( $transient ){
		delete_transient( 'gfeloqua/' . $transient );
	}

	public function get_forms( $page = NULL, $count = 1000 ){
		$call = 'assets/forms';

		if( $transient = $this->get_transient( $call ) )
			return $transient;

		$actual_call = $call;

		if( $count || $page ){
			$qs = '';
			if( $count )
				$qs .= 'count=' . $count;
			if( $page )
				$qs .= $qs ? '&page=' . $page : 'page=' . $page;
			$actual_call .= '?' . $qs;
		}

		$forms = $this->_call( $actual_call );

		if( $this->is_valid_data( $forms ) ){
			$all_forms = $forms->elements;

			if( count( $all_forms ) >= $count ){
				$page = $page ? $page + 1 : 2;
				$the_rest = $this->get_forms( $page, $count );
				if( is_array( $the_rest ) ){
					$all_forms = array_merge( $all_forms, $the_rest );
				}
			}

			usort( $all_forms, array( $this, 'compare_by_folder' ) );

			$this->set_transient( $call, $all_forms );

			return $all_forms;
		}

		return array();
	}

	public function get_form( $form_id ){
		$call = 'assets/form/' . $form_id;

		if( $transient = $this->get_transient( $call ) )
			return $transient;

		$form = $this->_call( $call );

		if( $form ){
			$this->set_transient( $call, $form );
			return $form;
		}
	}

	public function get_form_fields( $form_id ){
		$form = $this->get_form( $form_id );

		if( $this->is_valid_data( $form ) ){
			return $form->elements;
		}

		return array();
	}

	public function get_form_folder_name( $folder_id ){
		$call = 'assets/folder/' . $folder_id;

		if( $transient = $this->get_transient( $call ) )
			return $transient;

		$folder = $this->_call( $call );

		if( $folder ){
			$this->set_transient( $call, $folder );
			return $folder;
		}
	}

	public function submit_form( $form_id, $submission ){
		$response = $this->_call( 'data/form/' . $form_id, $submission, 'POST' );

		// on Success, Eloqua returns the submission data
		if( $response )
			return true;
		else
			return false;
	}

	public function create_contact( $contact ){
		$response = $this->_call( 'data/contact', $contact, 'POST' );
		if( $response )
			return true;
		else
			return false;
	}

	public function is_field_required( $field ){
		$validations = $field->validations;

		if( is_array( $validations ) && count( $validations ) ){
			foreach( $validations as $validation ){
				if( $validation->condition->type == self::ELOQUA_IS_REQUIRED ){
					if( $validation->isEnabled == 'true' ){
						return true;
					}
				}
			}
		}

		return false;
	}

	public function compare_by_folder( $a, $b ){
		$folderA = isset( $a->folderId ) && $a->folderId ? $a->folderId : '';
		$folderB = isset( $b->folderId ) && $b->folderId ? $b->folderId : '';
		return strcmp( $folderA, $folderB );
	}
}
