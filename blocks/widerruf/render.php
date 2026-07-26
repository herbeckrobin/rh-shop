<?php

/**
 * Frontend-Render der Widerrufsseite (§356a). Die Absende-Logik übernimmt widerruf.js.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use RhShop\Withdrawal\WithdrawalView;

$wrapper = get_block_wrapper_attributes(['class' => 'rhshop-widerruf-block']);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WithdrawalView escapt intern.
echo '<div ' . $wrapper . '>' . (new WithdrawalView())->render() . '</div>';
