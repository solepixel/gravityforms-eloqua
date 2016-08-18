<?php

if( class_exists( 'GFEloqua_Debugger' ) )
	return;

class GFEloqua_Debugger {

	public $data = array();

	public $html_wrapper;

	function __construct( $data_object = NULL ){
		if( $data_object )
			$this->add_data( $data_object );

		$this->html_wrapper = '<a href="#gfeloqua-debug-detail" class="toggle-debug-detail">' . __( 'See Debug Info', 'gfeloqua' ) . '</a><div class="gfeloqua-debug-detail" style="display:none;">%s</div>';
	}

	function add_data( $data_object ){
		if( ! is_a( $data_object, 'GFEloqua_Debug_Data' ) )
			return false;

		$this->data[] = $data_object;
	}

	public function get_data_as_notes( $level = 1 ){
		$notes = array();

		foreach( $this->data as $data ){
			if( $level < $data->get_level() )
				continue;

			if( ! $data->get_key() && ! $data->get_value() )
				continue;

			$note = '[' . $data->get_datestamp() . '] <strong>' . $data->get_key() . '</strong>';

			if( $extra_notes = $data->get_notes_html() )
				$note .= $extra_notes;

			if( is_string( $data->get_value() ) ){
				$dump = $data->get_value();
			} else {
				ob_start();
				var_dump( $data->get_value() );
				$dump = ob_get_clean();
			}

			if( $dump )
				$note .= ' ' . sprintf( $this->html_wrapper, '<pre>' . $dump . '</pre>' );

			$notes[] = $note;
		}

		return $notes;
	}

	public function get_last_message_html(){
		$data = end( $this->data );
		reset( $this->data );

		$html = '<strong>' . $data->get_key() . '</strong>';

		if( $extra_notes = $data->get_notes_html() )
			$html .= $extra_notes;

		if( is_string( $data->get_value() ) ){
			$html .= $data->get_value();
		} else {
			ob_start();
			var_dump( $data->get_value() );
			$html .= ob_get_clean();
		}

		return $html;
	}
}

class GFEloqua_Debug_Data {

	public $level = 1;
	public $key ;
	public $value;
	public $data = array();
	public $notes = array();
	public $datestamp;

	function __construct( $key, $value = '', $note = '', $level = false ){
		if( $level )
			$this->set_level( $level );

		if( $note )
			$this->add_note( $note );

		$this->key = $key;
		if( $value )
			$this->value = $value;

		$this->datestamp = current_time( 'mysql' );
	}

	public function set_level( $level ){
		if( is_int( $level ) && $level > 1 )
			$this->level = $level;
	}

	public function add_notes( $note ){
		$this->add_note( $note );
	}

	public function add_note( $note ){
		$this->notes[] = $note;
	}

	public function get_level(){
		return $this->level;
	}
	public function get_key(){
		return $this->key;
	}
	public function get_value(){
		return $this->value;
	}
	public function get_notes(){
		return $this->notes;
	}
	public function get_datestamp(){
		return $this->datestamp;
	}

	public function get_notes_html(){
		$html = '';
		if( $this->notes ){
			foreach( $this->notes as $note ){
				$html .= '<div class="gfeloqua-note">' . $note . '</div>';
			}
		}
		return $html;
	}
}
