<?php
/**
 * The questionnaire form ([shoval_intake_form]). Fields match REPORT_AI/index.html.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="si-app" dir="rtl">

	<div id="si-status-processing" class="si-status si-status-processing" role="status" aria-live="polite" hidden>
		<div class="si-spinner"></div>
		<div class="si-status-title">מעבד את השאלון...</div>
		<div class="si-status-desc">המערכת מעבדת את המידע ומעבירה אותו באופן מאובטח. אנא המתן מספר שניות.</div>
	</div>

	<div id="si-status-success" class="si-status si-status-success" role="alert" hidden>
		<div class="si-status-icon">✓</div>
		<div class="si-status-title">השאלון נשלח ונקלט בהצלחה!</div>
		<div class="si-status-desc">כל הפרטים והמסמכים נקלטו במערכת המרפאה ומועברים ישירות לצוות הקליני לתיעוד והערכה.</div>
		<div class="si-status-actions">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="si-back-btn">חזרה לדף הבית</a>
		</div>
	</div>

	<div id="si-status-error" class="si-status si-status-error" role="alert" hidden>
		<div class="si-status-icon">⚠️</div>
		<div class="si-status-title" id="si-error-title">אירעה שגיאה בשליחת השאלון. אנא נסה שוב.</div>
		<div class="si-status-desc" id="si-error-desc">לא ניתן היה להשלים את התהליך. אנא בדוק את החיבור לרשת ונסה לשלוח שוב.</div>
	</div>

	<form id="si-intake-form" novalidate enctype="multipart/form-data">
		<input type="hidden" name="_si_token" value="<?php echo esc_attr( Shoval_Intake_Frontend::form_token() ); ?>">
		<div class="si-hp" aria-hidden="true">
			<label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
		</div>

		<!-- א' - פרטים אישיים -->
		<section class="si-card">
			<div class="si-card-header">
				<div class="si-card-icon">א</div>
				<h2>פרטים אישיים</h2>
			</div>
			<div class="si-grid-3">
				<div class="si-group">
					<label for="si-firstName"><span class="si-req">*</span> שם פרטי</label>
					<input type="text" id="si-firstName" name="firstName" required autocomplete="given-name" maxlength="60">
				</div>
				<div class="si-group">
					<label for="si-lastName"><span class="si-req">*</span> שם משפחה</label>
					<input type="text" id="si-lastName" name="lastName" required autocomplete="family-name" maxlength="60">
				</div>
				<div class="si-group">
					<label for="si-gender">מין</label>
					<select id="si-gender" name="gender">
						<option value="">נא לבחור...</option>
						<option value="זכר">זכר</option>
						<option value="נקבה">נקבה</option>
						<option value="אחר">אחר</option>
					</select>
				</div>
				<div class="si-group">
					<label for="si-idNumber"><span class="si-req">*</span> תעודת זהות</label>
					<input type="text" id="si-idNumber" name="idNumber" required placeholder="9 ספרות" inputmode="numeric" maxlength="12" dir="ltr">
				</div>
				<div class="si-group">
					<label for="si-age"><span class="si-req">*</span> גיל</label>
					<input type="text" id="si-age" name="age" required placeholder="למשל: 14 או 32" maxlength="20">
				</div>
				<div class="si-group">
					<label for="si-city">יישוב מגורים</label>
					<input type="text" id="si-city" name="city" placeholder="למשל: רמת השרון" maxlength="80">
				</div>
				<div class="si-group">
					<label for="si-parentsNames">שמות ההורים (עבור ילדים/נוער)</label>
					<input type="text" id="si-parentsNames" name="parentsNames" placeholder="שמות פרטיים של ההורים" maxlength="120">
				</div>
				<div class="si-group">
					<label for="si-childrenCount">מספר הילדים המשותפים של ההורים</label>
					<input type="number" id="si-childrenCount" name="childrenCount" min="0" max="20" placeholder="0">
				</div>
				<div class="si-group">
					<label for="si-phone"><span class="si-req">*</span> טלפון נייד</label>
					<input type="tel" id="si-phone" name="phone" required placeholder="05X-XXXXXXX" autocomplete="tel" maxlength="30" dir="ltr">
				</div>
				<div class="si-group si-full">
					<label for="si-email"><span class="si-req">*</span> דואר אלקטרוני</label>
					<input type="email" id="si-email" name="email" required placeholder="your@email.com" autocomplete="email" maxlength="120" dir="ltr">
				</div>
			</div>
		</section>

		<!-- ב' - סיבת הפנייה -->
		<section class="si-card">
			<div class="si-card-header">
				<div class="si-card-icon">ב</div>
				<h2>סיבת הפנייה</h2>
			</div>
			<div class="si-group">
				<label for="si-referralReason"><span class="si-req">*</span> מהי סיבת הביקור הנוכחי או התלונה העיקרית?</label>
				<textarea id="si-referralReason" name="referralReason" rows="5" required maxlength="5000" placeholder="נא לפרט בהרחבה את סיבת הפנייה, הקשיים העיקריים, מתי החלו, כיצד הם משפיעים על התפקוד היומיומי ומהן הציפיות מההערכה..."></textarea>
			</div>
		</section>

		<!-- ג' - תרופות ומינונים -->
		<section class="si-card">
			<div class="si-card-header">
				<div class="si-card-icon">ג</div>
				<h2>תרופות ומינונים</h2>
			</div>
			<p class="si-card-desc">נא לרשום את כל התרופות שהמטופל נוטל כעת באופן קבוע.</p>

			<label class="si-checkbox" for="si-noCurrentMedications">
				<input type="checkbox" id="si-noCurrentMedications" name="noCurrentMedications" value="1">
				אין טיפול תרופתי קבוע
			</label>

			<div id="si-current-meds"></div>

			<button type="button" id="si-add-med" class="si-btn-add"><span>+ הוסף תרופה</span></button>
		</section>

		<!-- ד' - טיפולים תרופתיים בעבר -->
		<section class="si-card">
			<div class="si-card-header">
				<div class="si-card-icon">ד</div>
				<h2>טיפולים תרופתיים בעבר</h2>
			</div>
			<p class="si-card-desc">נא לפרט טיפולים תרופתיים שנוסו בעבר (פסיכיאטריים או רפואיים רלוונטיים).</p>

			<label class="si-checkbox" for="si-noPastMedications">
				<input type="checkbox" id="si-noPastMedications" name="noPastMedications" value="1">
				אין טיפולים תרופתיים קודמים
			</label>

			<div id="si-past-meds-wrapper">
				<div class="si-table-container">
					<table class="si-past-meds">
						<thead>
							<tr>
								<th style="width: 28%;">תרופה</th>
								<th style="width: 16%;">שנת התחלה</th>
								<th style="width: 16%;">משך</th>
								<th style="width: 20%;">אופן נטילה</th>
								<th style="width: 16%;">מינון מרבי</th>
								<th style="width: 4%;"><span class="screen-reader-text">הסרה</span></th>
							</tr>
						</thead>
						<tbody id="si-past-meds"></tbody>
					</table>
				</div>
				<button type="button" id="si-add-past-med" class="si-btn-add"><span>+ הוסף טיפול קודם</span></button>
			</div>
		</section>

		<!-- ה' - מסמכים ואבחונים קודמים -->
		<section class="si-card">
			<div class="si-card-header">
				<div class="si-card-icon">ה</div>
				<h2>מסמכים ואבחונים קודמים</h2>
			</div>
			<p class="si-card-desc">נא לציין או לפרט מסמכים רלוונטיים קיימים (כגון: סיכומי טיפול, אבחונים קודמים, הערכות פסיכולוגיות, הערכות חינוכיות, מסמכים רפואיים וכו').</p>

			<div class="si-group">
				<label for="si-previousDocuments">פירוט מסמכים, אבחונים וחוות דעת קודמות:</label>
				<textarea id="si-previousDocuments" name="previousDocuments" rows="4" maxlength="5000" placeholder="למשל: אבחון דידקטי משנת 2023, סיכום ביקור אצל נוירולוג ילדים, הערכה פסיכולוגית קודמת..."></textarea>
			</div>

			<div class="si-group">
				<label for="si-uploadedFiles">צירוף קבצים או מסמכים (אופציונלי):</label>
				<input type="file" id="si-uploadedFiles" name="uploadedFiles[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
				<small class="si-hint">ניתן לצרף קובצי PDF, תמונות או מסמכים (עד 15MB סה"כ).</small>
			</div>
		</section>

		<!-- הסכמה -->
		<section class="si-card si-consent-card">
			<label class="si-consent" for="si-consent">
				<input type="checkbox" id="si-consent" name="consent" value="1" required>
				<span><span class="si-req">*</span> אני מאשר/ת שהמידע שמסרתי בשאלון ישמש את מרפאת פרופ' גל שובל לצורך ההערכה והטיפול, יישמר באופן מאובטח, ויעובד בין היתר באמצעות שירותי מחשוב חיצוניים (לרבות שירות בינה מלאכותית, ללא פרטים מזהים) לצורך הכנת טיוטת סיכום לרופא.</span>
			</label>
		</section>

		<div class="si-submit-area">
			<button type="submit" id="si-submit" class="si-btn-submit">
				<span>שליחת שאלון</span>
				<span aria-hidden="true">&larr;</span>
			</button>
		</div>
	</form>
</div>
