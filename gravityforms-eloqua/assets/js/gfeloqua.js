var gfeloqua_clear_form_transient, gfeloqua_clear_forms_transient, gfeloqua_oauth_window;

jQuery(function($){

	gfeloqua_clear_form_transient = function(){
		// start spinner
		var $spinner = $('<div />').addClass('spinner'),
			$mapped_fields = $('#gaddon-setting-row-mapped_fields td'),
			form_id = $('#gfeloqua_form').val();

		if( ! form_id )
			return false;

		$spinner.show().css('visibility','visible');
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

		$spinner.show().css('visibility','visible')
		$form_list.find( 'select,.select2-container' ).hide();
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

	function PopupCenter(url, title, w, h) {
		// Fixes dual-screen position                         Most browsers      Firefox
		var dualScreenLeft = window.screenLeft != undefined ? window.screenLeft : screen.left;
		var dualScreenTop = window.screenTop != undefined ? window.screenTop : screen.top;

		width = window.innerWidth ? window.innerWidth : document.documentElement.clientWidth ? document.documentElement.clientWidth : screen.width;
		height = window.innerHeight ? window.innerHeight : document.documentElement.clientHeight ? document.documentElement.clientHeight : screen.height;

		var left = ((width / 2) - (w / 2)) + dualScreenLeft;
		var top = ((height / 2) - (h / 2)) + dualScreenTop;
		gfeloqua_oauth_window = window.open(url, title, 'scrollbars=yes, chrome=yes, menubar=no, toolbar=no, width=' + w + ', height=' + h + ', top=' + top + ', left=' + left);

		// Puts focus on the newWindow
		if (window.focus) {
			gfeloqua_oauth_window.focus();
		}
		return gfeloqua_oauth_window;
	}

	$('#gfeloqua_oauth').on('click', function(e){
		e.preventDefault();

		var href = $(this).attr('href'),
			width = $(this).data('width') ? $(this).data('width') : 600,
			height = $(this).data('height') ? $(this).data('height') : 600;

		var new_window = PopupCenter( href, 'wclsc_oauth', width, height );

		$(this).hide();
		$('#gfeloqua_oauth_code').show();

		var repeat_checks = function(){
			setTimeout( function(){
				if( new_window.closed ){
					location.reload( true );
				} else {
					repeat_checks();
				}
			}, 500 );
		};

		repeat_checks();

		return false;
	});

	if( $.fn.select2 && $('select#gfeloqua_form').length ){
		$('select#gfeloqua_form').select2({
			minimumResultsForSearch: 10,
			width: '100%'
		}).on('change', function(){
			$(this).parents('form').submit();
		});
	}
});
