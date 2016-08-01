<?php

if( ! function_exists( 'ctype_alnum' ) ){
	function ctype_alnum( $text ){
		return ( preg_match('~^[0-9a-z]*$~iu', $text ) > 0 );
	}
}
