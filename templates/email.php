<?php
/**
 * HTML email wrapper. Override by copying to wp-content/uploads/qhta-competitions/templates/email.php.
 *
 * Available: $subject (string), $body_html (escaped HTML), $site_name (string).
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $subject ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;">
	<tr>
		<td align="center" style="padding:24px 12px;">
			<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:4px;overflow:hidden;">
				<tr>
					<td style="background:#1d3461;padding:20px 28px;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:bold;">
						<?php echo esc_html( $site_name ); ?>
					</td>
				</tr>
				<tr>
					<td style="height:4px;line-height:4px;font-size:0;background:#c9a227;background-image:linear-gradient(90deg,#b8860b,#e6c35c,#b8860b);">&nbsp;</td>
				</tr>
				<tr>
					<td style="padding:28px;color:#1f2933;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;">
						<?php echo $body_html; // Escaped in Qhta_Competitions_Mailer::html(). ?>
					</td>
				</tr>
				<tr>
					<td style="padding:16px 28px;border-top:1px solid #e4e7eb;color:#6b7280;font-family:Arial,Helvetica,sans-serif;font-size:12px;">
						Queensland History Teachers' Association · qhta@qhta.com.au
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
</body>
</html>
