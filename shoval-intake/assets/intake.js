/* Shoval Intake – questionnaire behaviour (port of REPORT_AI/client.js). */
(function () {
	"use strict";

	var cfg = window.ShovalIntake || {};
	var ALLOWED_EXT = ["pdf", "doc", "docx", "jpg", "jpeg", "png"];

	function $(id) {
		return document.getElementById(id);
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			if (key === "text") {
				node.textContent = attrs[key];
			} else {
				node.setAttribute(key, attrs[key]);
			}
		});
		(children || []).forEach(function (child) {
			node.appendChild(child);
		});
		return node;
	}

	function validIsraeliId(id) {
		if (!/^\d{5,9}$/.test(id)) {
			return false;
		}
		id = ("000000000" + id).slice(-9);
		var sum = 0;
		for (var i = 0; i < 9; i++) {
			var n = Number(id[i]) * ((i % 2) + 1);
			sum += n > 9 ? n - 9 : n;
		}
		return sum % 10 === 0;
	}

	document.addEventListener("DOMContentLoaded", function () {
		var form = $("si-intake-form");
		if (!form) {
			return;
		}
		var submitBtn = $("si-submit");
		var statusProcessing = $("si-status-processing");
		var statusSuccess = $("si-status-success");
		var statusError = $("si-status-error");

		var currentMeds = $("si-current-meds");
		var addMedBtn = $("si-add-med");
		var noCurrentMeds = $("si-noCurrentMedications");

		var pastMeds = $("si-past-meds");
		var addPastBtn = $("si-add-past-med");
		var noPastMeds = $("si-noPastMedications");
		var pastWrapper = $("si-past-meds-wrapper");

		/* --- Current medications --- */
		function removeButton(onClick) {
			var btn = el("button", { type: "button", class: "si-btn-remove-row", title: "הסר שורה", "aria-label": "הסר שורה", text: "✕" });
			btn.addEventListener("click", onClick);
			return btn;
		}

		function addCurrentMedRow() {
			var name = el("input", { type: "text", class: "si-med-name-input", placeholder: "שם התרופה", "aria-label": "שם התרופה", maxlength: "200" });
			var dosage = el("input", { type: "text", class: "si-med-dosage-input", placeholder: "מינון (לדוגמה: 10 מ״ג בבוקר)", "aria-label": "מינון", maxlength: "200" });
			var row = el("div", { class: "si-med-row" }, [
				el("div", { class: "si-med-name" }, [name]),
				el("div", { class: "si-med-dosage" }, [dosage])
			]);
			row.appendChild(removeButton(function () {
				if (currentMeds.children.length > 1) {
					row.remove();
				} else {
					name.value = "";
					dosage.value = "";
				}
			}));
			currentMeds.appendChild(row);
		}

		for (var i = 0; i < 5; i++) {
			addCurrentMedRow();
		}
		addMedBtn.addEventListener("click", addCurrentMedRow);

		noCurrentMeds.addEventListener("change", function () {
			var off = noCurrentMeds.checked;
			currentMeds.classList.toggle("si-disabled-area", off);
			addMedBtn.disabled = off;
			currentMeds.querySelectorAll("input").forEach(function (inp) {
				inp.disabled = off;
			});
		});

		/* --- Past medications --- */
		function addPastMedRow() {
			var fields = [
				["si-past-name", "שם התרופה"],
				["si-past-year", "שנה (למשל: 2021)"],
				["si-past-duration", "משך הטיפול"],
				["si-past-admin", "אופן נטילה / סיבה"],
				["si-past-max", "מינון מרבי"]
			];
			var tr = el("tr");
			fields.forEach(function (f) {
				tr.appendChild(el("td", {}, [el("input", { type: "text", class: f[0], placeholder: f[1], "aria-label": f[1], maxlength: "200" })]));
			});
			var td = el("td", { style: "text-align: center;" });
			td.appendChild(removeButton(function () {
				tr.remove();
			}));
			tr.appendChild(td);
			pastMeds.appendChild(tr);
		}

		addPastMedRow();
		addPastMedRow();
		addPastBtn.addEventListener("click", addPastMedRow);

		noPastMeds.addEventListener("change", function () {
			var off = noPastMeds.checked;
			pastWrapper.classList.toggle("si-disabled-area", off);
			pastWrapper.querySelectorAll("input, button").forEach(function (node) {
				node.disabled = off;
			});
		});

		/* --- Status cards --- */
		function show(card) {
			[statusProcessing, statusSuccess, statusError].forEach(function (c) {
				c.hidden = c !== card;
			});
			if (card) {
				card.scrollIntoView({ behavior: "smooth", block: "center" });
			}
		}

		function showError(title, desc) {
			$("si-error-title").textContent = title;
			$("si-error-desc").textContent = desc || "";
			show(statusError);
		}

		/* --- Validation --- */
		function validate() {
			form.querySelectorAll(".si-invalid").forEach(function (node) {
				node.classList.remove("si-invalid");
			});
			var problems = [];
			function fail(id, message) {
				var field = $(id);
				field.classList.add("si-invalid");
				problems.push({ field: field, message: message });
			}

			[
				["si-firstName", "שם פרטי"],
				["si-lastName", "שם משפחה"],
				["si-idNumber", "תעודת זהות"],
				["si-age", "גיל"],
				["si-phone", "טלפון נייד"],
				["si-email", "דואר אלקטרוני"],
				["si-referralReason", "סיבת הפנייה"]
			].forEach(function (f) {
				if (!$(f[0]).value.trim()) {
					fail(f[0], 'נא למלא את השדה "' + f[1] + '".');
				}
			});

			var id = $("si-idNumber").value.replace(/\D/g, "");
			if (id && !validIsraeliId(id)) {
				fail("si-idNumber", "מספר תעודת הזהות אינו תקין. נא לבדוק את הספרות (כולל ספרת הביקורת).");
			}
			var email = $("si-email").value.trim();
			if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) {
				fail("si-email", "כתובת הדואר האלקטרוני אינה תקינה.");
			}
			var phone = $("si-phone").value.replace(/\D/g, "");
			if ($("si-phone").value.trim() && phone.length < 9) {
				fail("si-phone", "מספר הטלפון אינו תקין.");
			}

			var files = $("si-uploadedFiles").files || [];
			var total = 0;
			for (var i = 0; i < files.length; i++) {
				total += files[i].size;
				var ext = (files[i].name.split(".").pop() || "").toLowerCase();
				if (ALLOWED_EXT.indexOf(ext) === -1) {
					fail("si-uploadedFiles", 'הקובץ "' + files[i].name + '" אינו מסוג נתמך. ניתן לצרף PDF, Word או תמונות (JPG / PNG).');
				}
			}
			if (files.length > (cfg.maxFiles || 10)) {
				fail("si-uploadedFiles", "ניתן לצרף עד " + (cfg.maxFiles || 10) + " קבצים.");
			}
			if (total > (cfg.maxTotalBytes || 15728640)) {
				fail("si-uploadedFiles", "גודל הקבצים המצורפים עולה על 15MB. נא לצרף פחות קבצים או קבצים קטנים יותר.");
			}

			if (!$("si-consent").checked) {
				fail("si-consent", "יש לאשר את הסכמתך לשימוש במידע לפני השליחה.");
			}
			return problems;
		}

		form.addEventListener("input", function (e) {
			if (e.target.classList) {
				e.target.classList.remove("si-invalid");
			}
		});

		/* --- Submit --- */
		form.addEventListener("submit", function (e) {
			e.preventDefault();

			var problems = validate();
			if (problems.length) {
				showError("נא להשלים את הפרטים החסרים", problems.map(function (p) { return p.message; }).join(" "));
				problems[0].field.focus({ preventScroll: true });
				return;
			}

			var data = new FormData(form);
			data.set("idNumber", $("si-idNumber").value.replace(/\D/g, ""));

			var meds = [];
			if (!noCurrentMeds.checked) {
				currentMeds.querySelectorAll(".si-med-row").forEach(function (row) {
					var name = row.querySelector(".si-med-name-input").value.trim();
					if (name) {
						meds.push({ name: name, dosage: row.querySelector(".si-med-dosage-input").value.trim() });
					}
				});
			}
			data.set("currentMedications", JSON.stringify(meds));

			var past = [];
			if (!noPastMeds.checked) {
				pastMeds.querySelectorAll("tr").forEach(function (row) {
					var get = function (cls) {
						return row.querySelector("." + cls).value.trim();
					};
					if (get("si-past-name")) {
						past.push({
							medication: get("si-past-name"),
							startYear: get("si-past-year"),
							duration: get("si-past-duration"),
							administration: get("si-past-admin"),
							maxDosage: get("si-past-max")
						});
					}
				});
			}
			data.set("pastMedications", JSON.stringify(past));

			submitBtn.disabled = true;
			show(statusProcessing);

			fetch(cfg.endpoint, { method: "POST", body: data, credentials: "same-origin" })
				.then(function (response) {
					return response.json().catch(function () {
						return {};
					}).then(function (result) {
						return { ok: response.ok, result: result };
					});
				})
				.then(function (r) {
					if (r.ok && r.result && r.result.success) {
						form.hidden = true;
						show(statusSuccess);
						return;
					}
					submitBtn.disabled = false;
					showError("לא ניתן היה לשלוח את השאלון", (r.result && r.result.message) || "אירעה שגיאה בשליחת השאלון. אנא נסה שוב.");
				})
				.catch(function () {
					submitBtn.disabled = false;
					showError("אירעה שגיאה בשליחת השאלון. אנא נסה שוב.", "לא ניתן היה להשלים את התהליך. אנא בדוק את החיבור לרשת ונסה לשלוח שוב.");
				});
		});
	});
})();
