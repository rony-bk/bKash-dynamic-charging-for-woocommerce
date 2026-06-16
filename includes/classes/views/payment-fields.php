<?php
/**
 * Html Payment Fields
 *
 * @category    Page
 * @package     bkash-dynamic-charging
 * @author      bKash Developer <developer@bkash.com>
 * @copyright   Copyright 2023 bKash Limited. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://bkash.com
 */

/**
 * Html Payment Fields
 *
 * @category    Page
 * @package     bkash-for-woocommerce
 * @author      Muhammad Abid Hasan Turzo <turzo.adn@bkash.com>
 * @copyright   Copyright 2026 bKash Limited. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://bkash.com
 */
require __DIR__ . '/helpers.php';

$agreements      = $agreements ?? [];
$isLoggedIn      = get_current_user_id() !== 0;
$isTokenizedOnly = $this->integration_type === "tokenized";
$isTokenizedBoth = $this->integration_type === "tokenized-both";

if ( bkHasTokenizedWordInIntegrationType( $this->integration_type ) ) {
	if ( ! $isLoggedIn && $isTokenizedOnly ) {
		bkShowLoginAction();
	} else {
		bkShowAgreementTable( $agreements, $isLoggedIn, $isTokenizedBoth );
	}
}

if ( ! $isLoggedIn ) {
	echo esc_html("To remember your bKash account number, please login and check remember");
}
?>

<input type="hidden" name="bkash-ajax-nonce" id="bkash-ajax-nonce" value="<?php
echo wp_kses_post( wp_create_nonce( 'bkash-ajax-nonce' ) ); ?>"/>