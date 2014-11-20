<?php

if( class_exists( 'Eloqua_API' ) )
	return;

if( ! class_exists( 'WP_Http' ) )
	include_once( ABSPATH . WPINC . '/class-http.php' );

class Eloqua_API {

	const ELOQUA_IS_REQUIRED = 'IsRequiredCondition';

	var $urls;

	var $connection;
	var $connection_args = array();

	var $auth_string;

	var $basic_auth_url = 'https://login.eloqua.com/id';

	var $client_id = '';

	var $rest_api_version = '2.0';

	function __construct( $auth_string = '', $callback_url = '' ){
		if( $auth_string )
			$this->auth_string = $auth_string;
	}

	function get_auth_url(){
		$auth_url = $this->basic_auth_url;

		return $auth_url;
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

		$this->connection_args = array(
			'headers' => array(
				'Authorization' => 'Basic ' . $this->auth_string
			)
		);

		$this->connection = new WP_Http();
		$response = $this->connection->request( $this->get_auth_url(), $this->connection_args );

		/*if( $response['code'] == '401' ){
			delete_transient( 'gfeloqua_connection' );
		}*/

		$connection = json_decode( $response['body'] );

		if( is_object( $connection ) ){
			return $this->init( $connection );
		}

		// Something went wrong. Probably an error
		echo $connection;

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

		if( count( $data ) ){
			$args['body'] = json_encode( $data );
			$args['headers']['Content-Type'] = 'application/json';
		}

		$response = $this->connection->request( $url, $args );

		if( ! $data = $this->validate_response( $response ) )
			return false;

		return $data;
	}

	public function validate_response( $response ){
		if( ! $response || ! is_array( $response ) )
			return false;

		if( ! isset( $response['response'] ) || ! isset( $response['response']['code'] ) )
			return false;

		if( $response['response']['code'] == '200' && $response['body'] )
			return json_decode( $response['body'] );

		return false;
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

	public function get_forms(){
		$call = 'assets/forms';

		if( $transient = $this->get_transient( $call ) )
			return $transient;

		$forms = $this->_call( $call );

		if( $this->is_valid_data( $forms ) ){
			$this->set_transient( $call, $forms->elements );
			return $forms->elements;
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

	public function submit_form( $form_id, $submission ){
		$response = $this->_call( 'data/form/' . $form_id, $submission, 'POST' );
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
}
