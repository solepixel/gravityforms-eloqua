<?php

if( ! function_exists( 'ctype_alnum' ) ){
	function ctype_alnum( $text ){
		return ( preg_match('~^[0-9a-z]*$~iu', $text ) > 0 );
	}
}

if ( ! function_exists( 'update_entry_meta' ) ) {

	/**
	 * Update Entry meta
	 *
	 * Allows a meta to be altered before posting to Eloqua.
	 *
	 * @param  string $key  The key of the metadata.
	 * @param  string $lead The value of the metadata.
	 * @param  object $form The form that the metadata belongs to.
	 * @return string       Returns the value of the entry meta.
	 */
	function update_entry_meta( $key, $lead, $form ) {
	    return '';
	}
}
