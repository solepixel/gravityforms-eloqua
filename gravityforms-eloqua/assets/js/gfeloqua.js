var gfeloqua_clear_transient;

jQuery(function($){

	gfeloqua_clear_transient = function(){
		// start spinner
		var $spinner = $('<div />').addClass('spinner'),
			$mapped_fields = $('#gaddon-setting-row-mapped_fields td'),
			form_id = $('#gfeloqua_form').val();

		if( ! form_id )
			return false;

		$spinner.show();
		$mapped_fields.html( $spinner );

		$.ajax({
			url: gfeloqua_strings.ajax_url,
			data: {
				action: 'gfeloqua_clear_transient',
				form_id : form_id
			},
			success: function( response ){
				$('#gform-settings').submit();
			}
		});
	}


	$('.gfe-refresh').on('click', function(e){
		e.preventDefault();
		gfeloqua_clear_transient();
		return false;
	});

});
