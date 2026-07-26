<?php
/**
 * Table
 *
 * @category    Page
 * @package     bkash-woocommerce-dynamic-charging
 * @author      bKash Developer <developer@bkash.com>
 * @copyright   Copyright 2023 bKash Limited. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://bkash.com
 */

use bKash\PGW\DC\Admin\AdminUtility;
use bKash\PGW\DC\Sanitizer;

?>
	<div class="wrap abs">
		<style>
			.bkash-admin-table-scroll {
				display: block;
				width: 100%;
				max-width: 100%;
				overflow-x: auto;
				overflow-y: hidden;
				-webkit-overflow-scrolling: touch;
				margin-top: 12px;
			}
			.bkash-admin-table-scroll table {
				display: table;
				min-width: 980px;
				width: auto;
				border-collapse: collapse;
				white-space: nowrap;
			}
		</style>
		<h2>
			<?php
			esc_html_e( $title ?? 'List', 'bkash-woocommerce-dynamic-charging' );
			?>
		</h2>

		<!-- Search Form -->
		<div class="tablenav top">
			<div class="alignleft actions">

				<form action="#" method="GET">
					<?php
					if ( isset( $filters ) && count( $filters ) > 0 ) {
						foreach ( $filters as $key => $filter ) {
							$old_input = Sanitizer::safeGetValue( $key ) ?? '';
							?>
							<label>
								<input type="text" name="<?php esc_attr_e( $key, 'bkash-woocommerce-dynamic-charging' );?>" value="<?php esc_attr_e( $old_input, 'bkash-woocommerce-dynamic-charging' );?>" placeholder="<?php esc_attr_e( $filter, 'bkash-woocommerce-dynamic-charging' ); ?>" autocomplete="off" />
							</label>
							<?php
						}
					}

					$page_name = Sanitizer::safeGetValue( 'page' ) ?? '';
					?>
					<input type="hidden" name="page" value="<?php esc_attr_e( $page_name, 'bkash-woocommerce-dynamic-charging' );?>" />
					<button type="submit">Search</button>
				</form>


			</div>
			<br class="clear">
		</div>

		<!-- Table -->
		<div class="bkash-admin-table-scroll" style="width: 100%; max-width: 100%; overflow-x: auto;">
		<table id="transaction-list-table" class='wp-list-table widefat fixed striped posts' style="min-width: 980px; width: auto; white-space: nowrap;" aria-describedby="
		<?php
		esc_attr_e( $title );
		?>
		">
			<!-- Column Headers -->
			<tr>
				<?php
				if ( isset( $columns ) ) {
					foreach ( array_keys( $columns ) as $table_head ) {
						?>
						<th class='manage-column ss-list-width' scope='col'>
							<?php
							esc_html_e( $table_head );
							?>
						</th>
						<?php
					}

					if ( isset( $actions ) && count( $actions ) > 0 ) {
						?>
						<th class='manage-column ss-list-width' scope='col'>
							Actions
						</th>
						<?php
					}
				}
				?>
			</tr>

			<?php
			if ( isset( $rows ) && count( (array) $rows ) > 0 ) {
				foreach ( $rows as $row ) {
					?>
					<!-- Items -->
					<tr>
						<?php
						foreach ( $columns as $column ) {
							// if want to show multiple value in a single column
							if ( is_array( $column ) ) {
								?>
								<td class='manage-column ss-list-width'>
									<?php
									if ( AdminUtility::ifRefundValueIsPresent( $row, $column ) ) {
										?>
										<span class="bKash-chip">Refunded</span>
										<?php
									}

									foreach ( $column as $item ) {
										if ( ! empty( $row->{$item} ) ) {
											?>
											<p>
												<?php
												$value = AdminUtility::keyToLabel( $item ) . ': ' . $row->{$item};
												esc_html_e( $value, 'bkash-woocommerce-dynamic-charging' );
												?>
											</p>
											<?php
										}
									}
									?>
								</td>
								<?php
							} else { // single value in a column
								?>
								<td class='manage-column ss-list-width'>
									<?php
									if ( str_contains( strtolower( $column ), 'status' ) ) {
										$statusColor = esc_attr( AdminUtility::setStatusColor( $row->{$column} ) );
										?>
										<span class="bKash-chip" style="background:
										<?php
										echo esc_attr( $statusColor )
										?>
										 !important;">
										<?php
											esc_html_e( $row->{$column}, 'bkash-woocommerce-dynamic-charging' );
										?>
											</span>
										<?php
									} else {
										if ( $column === 'user_id' ) {
											$user_id = (int) ( $row->{$column} ?? 0 );
											$value   = '';

											if ( $user_id > 0 ) {
												$user = get_userdata( $user_id );
												if ( $user ) {
													$customer_name = trim( (string) get_user_meta( $user_id, 'billing_first_name', true ) . ' ' . (string) get_user_meta( $user_id, 'billing_last_name', true ) );
													if ( empty( $customer_name ) ) {
														$customer_name = trim( (string) get_user_meta( $user_id, 'shipping_first_name', true ) . ' ' . (string) get_user_meta( $user_id, 'shipping_last_name', true ) );
													}
													if ( empty( $customer_name ) ) {
														$customer_name = trim( (string) get_user_meta( $user_id, 'first_name', true ) . ' ' . (string) get_user_meta( $user_id, 'last_name', true ) );
													}

													if ( ! empty( $customer_name ) ) {
														$value = $customer_name;
													} else {
														$value = $user->display_name ?: $user->user_login ?: $user_id;
													}
												} else {
													$value = $row->{$column};
												}
											} else {
												$value = $row->{$column};
											}

											esc_html_e( $value, 'bkash-woocommerce-dynamic-charging' );
										} else {
											esc_html_e( $row->{$column}, 'bkash-woocommerce-dynamic-charging' );
										}
									}
									?>
								</td>
								<?php
							}
						}
						?>
						<!-- Action Buttons -->
						<?php
						if ( isset( $actions ) && count( $actions ) > 0 ) {
							?>
							<td class='manage-column ss-list-width'>
								<?php
								foreach ( $actions as $action ) {
									$actionUrl = esc_url(
										admin_url(
											'admin.php?page=' . BKASH_DC_ADMIN_PAGE_SLUG . '/'
											. ( $action['page'] ?? '' ) . '&action='
											. ( $action['action'] ?? '' ) . '&id=' . $row->ID
										)
									);
									if ( isset( $action['confirm'] ) && $action['confirm'] ) {
										$clickEvent = 'onclick="return confirm(\'Are you sure to do this?\');"';
									}
									?>
									<a
										<?php
										echo wp_kses_post( $clickEvent ) ?? '';
										?>
										href="<?php echo esc_attr( $actionUrl ); ?>">
										<?php
										esc_html_e( $action['title'] ?? '' )
										?>
									</a>
									<?php
								}
								?>
							</td>
							<?php
						}
						?>
					</tr>
					<?php
				}
			} else {
				echo wp_kses_post( "<tr><td colspan='" . esc_html( count( $columns ) ) . "'>No records found</td></tr>" );
			}
			?>
		</table>
		</div>
	</div>

<?php
if ( isset( $page_links ) && $page_links ) {
	?>
	<div class="tablenav pagination-links" style="width: 99%;">
		<div class="tablenav-pages" style="margin: 1em 0">
		<?php
			echo wp_kses_post( $page_links )
		?>
			</div>
	</div>
	<?php
}
?>
