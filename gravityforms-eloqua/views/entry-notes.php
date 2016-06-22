<style type="text/css">.gfeloqua-entry-notes {margin-bottom:15px;} .entry-notes thead th { font-weight:bold; } .entry-notes tbody td { padding:7px; }</style>

<div class="gfeloqua-entry-notes">
	<table cellspacing="0" class="widefat fixed entry-notes">
		<thead>
			<tr>
				<th><?php _e( 'Eloqua Notes', 'gfeloqua' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach( $notes as $note ):
				$output = is_string( $note ) ? $note : print_r( $note, true );
				printf( '<tr><td>%s</td></tr>', $output );
			endforeach; ?>
		</tbody>
	</table>
</div>
