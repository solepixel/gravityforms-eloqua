<?php foreach( $notes as $note ):
	$output = is_string( $note ) ? $note : print_r( $note, true );
	printf( '<tr><td>%s</td></tr>', $output );
endforeach; ?>
