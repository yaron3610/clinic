<?php
/**
 * Stand-alone page for the intake questionnaire (same look as REPORT_AI/index.html).
 * Like the homepage (Elementor Canvas), it has its own header and footer.
 */

defined( 'ABSPATH' ) || exit;

$si_home = home_url( '/' );
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="description" content="שאלון קבלה למטופל חדש במרפאת פרופ' גל שובל - פסיכיאטר מומחה לילדים, נוער ומבוגרים">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'shoval-intake-page' ); ?>>
<?php wp_body_open(); ?>

<header class="si-header">
	<div class="si-header-content">
		<a href="<?php echo esc_url( $si_home ); ?>" class="si-brand">
			<img src="<?php echo esc_url( SHOVAL_INTAKE_URL . 'assets/logo.webp' ); ?>" alt="מרפאת פרופ' גל שובל" width="60" height="48">
			<span class="si-brand-titles">
				<span class="si-brand-name">מרפאת פרופ' גל שובל</span>
				<span class="si-brand-sub">פסיכיאטר מומחה לילדים, נוער ומבוגרים</span>
			</span>
		</a>
		<a href="<?php echo esc_url( $si_home ); ?>" class="si-back-btn">
			<span>חזרה לאתר המרפאה</span>
			<span aria-hidden="true">&larr;</span>
		</a>
	</div>
</header>

<main class="si-container" id="si-main">
	<div class="si-hero">
		<span class="si-badge">שאלון דיגיטלי מאובטח</span>
		<h1>שאלון קבלה למטופל חדש</h1>
		<p>שאלון זה מאפשר ריכוז יסודי ומדויק של המידע לקראת ההערכה הקלינית. הנתונים הנמסרים מוגנים בסודיות רפואית מלאה ומועברים ישירות לצוות הקליני.</p>
	</div>

	<?php
	while ( have_posts() ) {
		the_post();
		the_content();
	}
	?>
</main>

<footer class="si-footer">
	<p>© כל הזכויות שמורות למרפאת פרופ' גל שובל | פסיכיאטר מומחה לילדים, נוער ומבוגרים | סוקולוב 81, רמת השרון</p>
</footer>

<?php wp_footer(); ?>
</body>
</html>
