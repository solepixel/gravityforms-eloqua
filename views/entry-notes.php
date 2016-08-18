<style type="text/css">.gfeloqua-entry-notes {margin-bottom:15px;} .entry-notes thead th { font-weight:bold; } .entry-notes tbody td { padding:7px; }</style>

<div class="gfeloqua-entry-notes">
	<table cellspacing="0" class="widefat fixed entry-notes">
		<thead>
			<tr>
				<th><?php _e( 'Eloqua Notes', 'gfeloqua' ); ?>
					<?php if( ! $success ): ?>
						<span class="gfeloqua-right">
							<strong class="gfeloqua-retries"><?php _e( 'Retries:', 'gfeloqua' ); ?> <span><?php echo $retry_attempts; ?></span>/<?php echo $retry_limit; ?></strong>
							<a href="#gfeloqua-retry" class="button gfeloqua-retry" data-entry-id="<?php echo esc_attr( $entry['id'] ); ?>" data-form-id="<?php echo esc_attr( $form['id'] ); ?>"><?php _e( 'Retry Submission', 'gfeloqua' ); ?></a>
						</span>
					<?php elseif( isset( $_GET['gfeloqua-reset'] ) ): ?>
						<span class="gfeloqua-right">
							<a href="#gfeloqua-false-positive" class="button gfeloqua-false-positive" data-entry-id="<?php echo esc_attr( $entry['id'] ); ?>" data-form-id="<?php echo esc_attr( $form['id'] ); ?>"><?php _e( 'Reset Due to False Positive', 'gfeloqua' ); ?></a>
						</span>
					<?php endif; ?>
				</th>
			</tr>
		</thead>
		<tbody id="gfeloqua-notes">
			<?php include( $notes_display ); ?>
		</tbody>
	</table>
</div>
