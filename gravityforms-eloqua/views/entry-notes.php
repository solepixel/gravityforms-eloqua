<style type="text/css">.gfeloqua-entry-notes {margin-bottom:15px;} .entry-notes thead th { font-weight:bold; } .entry-notes tbody td { padding:7px; }</style>

<div class="gfeloqua-entry-notes">
	<table cellspacing="0" class="widefat fixed entry-notes">
		<thead>
			<tr>
				<th><?php _e( 'Eloqua Notes', 'gfeloqua' ); ?>
					<?php if( ! $success ): ?>
						<a href="#gfeloqua-retry" class="button gfeloqua-retry" data-entry-id="<?php echo esc_attr( $entry['id'] ); ?>" data-form-id="<?php echo esc_attr( $form['id'] ); ?>"><?php _e( 'Retry Submission', 'gfeloqua' ); ?></a>
					<?php endif; ?>
				</th>
			</tr>
		</thead>
		<tbody id="gfeloqua-notes">
			<?php include( $notes_display ); ?>
		</tbody>
	</table>
</div>
