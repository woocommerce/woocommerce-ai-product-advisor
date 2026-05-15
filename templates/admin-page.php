<?php
/**
 * Shared admin page template — React mounts into #wcai-pa-root and routes off the URL hash.
 *
 * @package WCAI_PA
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'WooCommerce AI Product Advisor', 'wcai-pa' ); ?></h1>
	<div id="wcai-pa-root"></div>
</div>
