<?php

defined( 'ABSPATH' ) || exit;

// Template delle email dei form. Tabelle e stili in linea: sono gli unici che tutti i client di
// posta rispettano. Si usa con Notifications::render().
return static function ( string $heading, string $content, string $lang, array $settings, string $site_name ): void {
	/** @var array{logo: string, accent_color: string, background_color: string, footer: string} $settings */
	$font = 'font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;';
	?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $lang ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $heading ); ?></title>
<style>a { color: <?php echo esc_attr( $settings['accent_color'] ); ?>; }</style>
</head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $settings['background_color'] ); ?>;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:<?php echo esc_attr( $settings['background_color'] ); ?>;">
	<tr>
		<td align="center" style="padding:32px 16px;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-top:4px solid <?php echo esc_attr( $settings['accent_color'] ); ?>;<?php echo esc_attr( $font ); ?>font-size:15px;line-height:1.6;color:#1e1e1e;">
				<tr>
					<td style="padding:32px 32px 0;">
						<?php if ( $settings['logo'] ) : ?>
							<img src="<?php echo esc_url( $settings['logo'] ); ?>" alt="<?php echo esc_attr( $site_name ); ?>" height="48" style="display:block;height:48px;width:auto;border:0;">
						<?php else : ?>
							<strong style="font-size:18px;"><?php echo esc_html( $site_name ); ?></strong>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td style="padding:24px 32px 32px;">
						<h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;"><?php echo esc_html( $heading ); ?></h1>
						<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- testo della notifica filtrato con wp_kses_post(), valori con l'escape (MergeTags::html()). ?>
					</td>
				</tr>
			</table>
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">
				<tr>
					<td style="padding:16px 32px;<?php echo esc_attr( $font ); ?>font-size:12px;line-height:1.5;color:#757575;text-align:center;">
						<?php
						if ( $settings['footer'] ) {
							echo wp_kses_post( nl2br( $settings['footer'] ) );
						} else {
							printf( '<a href="%s">%s</a>', esc_url( home_url( '/' ) ), esc_html( $site_name ) );
						}
						?>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
</body>
</html>
	<?php
};
