var gfeloqua_clear_form_transient, gfeloqua_clear_forms_transient;

jQuery(function($){

	gfeloqua_clear_form_transient = function(){
		// start spinner
		var $spinner = $('<div />').addClass('spinner'),
			$mapped_fields = $('#gaddon-setting-row-mapped_fields td'),
			form_id = $('#gfeloqua_form').val();

		if( ! form_id )
			return false;

		$spinner.show();
		$mapped_fields.find( 'table' ).hide();
		$mapped_fields.append( $spinner );

		$.ajax({
			url: gfeloqua_strings.ajax_url,
			data: {
				action: 'gfeloqua_clear_transient',
				transient : 'assets/form/' + form_id
			},
			success: function( response ){
				$('#gform-settings').submit();
			}
		});
	}


	$('a[href$="#gfe-form-fields-refresh"]').on('click', function(e){
		e.preventDefault();
		gfeloqua_clear_form_transient();
		return false;
	});

	gfeloqua_clear_forms_transient = function(){
		// start spinner
		var $spinner = $('<div />').addClass('spinner'),
			$form_list = $('#gaddon-setting-row-gfeloqua_form td');

		$spinner.show();
		$form_list.find( 'select' ).hide();
		$form_list.append( $spinner );

		$.ajax({
			url: gfeloqua_strings.ajax_url,
			data: {
				action: 'gfeloqua_clear_transient',
				transient : 'assets/forms'
			},
			success: function( response ){
				$('#gform-settings').submit();
			}
		});
	}

	$('a[href$="#gfe-forms-refresh"]').on('click', function(e){
		e.preventDefault();
		gfeloqua_clear_forms_transient();
		return false;
	});

});
