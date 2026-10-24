/**
 * effiSimplyScrapperImporter : import des fichiers un par un (une requête
 * AJAX par fichier), barre de progression et compte rendu.
 */
(function ($) {
	'use strict';

	var config = window.effissiAjax || {};
	var strings = config.strings || {};
	var isProcessing = false;
	var stats = null;

	$(function () {
		$('#effissi-import-form').on('submit', handleFormSubmit);
		$('#effissi-files').on('change', updateFileCount);
	});

	/**
	 * Remplace %s et %1$s, %2$s... par les arguments.
	 */
	function format(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var index = 0;

		return String(template || '').replace(/%(?:(\d+)\$)?s/g, function (match, position) {
			var value = position ? args[parseInt(position, 10) - 1] : args[index++];
			return value === undefined ? '' : String(value);
		});
	}

	function updateFileCount() {
		var count = this.files ? this.files.length : 0;
		var $count = $('#effissi-file-count');

		if (!count) {
			$count.remove();
			return;
		}

		if (!$count.length) {
			$count = $('<p id="effissi-file-count" class="description effissi-file-count"></p>');
			$(this).after($count);
		}

		$count.text(count === 1 ? strings.oneFile : format(strings.manyFiles, count));
	}

	function handleFormSubmit(event) {
		event.preventDefault();

		if (isProcessing) {
			return;
		}

		var input = $('#effissi-files')[0];
		var files = input && input.files ? Array.prototype.slice.call(input.files) : [];

		if (!files.length) {
			window.alert(strings.noFile);
			return;
		}

		var options = {
			post_status: $('input[name="post_status"]:checked').val() || 'draft',
			duplicates: $('input[name="duplicates"]:checked').val() || 'skip',
			featured_by_number: $('#effissi-featured-by-number').is(':checked') ? '1' : '0',
			import_images: $('#effissi-import-images').is(':checked') ? '1' : '0'
		};

		stats = { total: files.length, processed: 0, imported: 0, skipped: 0, failed: 0, items: [] };

		startImport();
		processNext(files, options, 0);
	}

	/**
	 * Désactive le formulaire pendant l'import, puis ne réactive que les champs
	 * désactivés ici (l'option images reste grisée sans droit de téléversement).
	 */
	function setFormDisabled(disabled) {
		var $fields = $('#effissi-import-form').find('input, button');

		if (disabled) {
			$fields.filter(':not(:disabled)').attr('data-effissi-locked', '1').prop('disabled', true);
		} else {
			$fields.filter('[data-effissi-locked]').removeAttr('data-effissi-locked').prop('disabled', false);
		}
	}

	function startImport() {
		isProcessing = true;
		setFormDisabled(true);
		$('#effissi-import-btn').text(strings.running);
		$('#effissi-results').prop('hidden', true).empty();
		$('#effissi-progress-container').prop('hidden', false);
		updateProgress(0, strings.starting);
		updateStats();
	}

	function processNext(files, options, index) {
		if (index >= files.length) {
			finishImport();
			return;
		}

		var file = files[index];

		updateProgress((index / stats.total) * 100, format(strings.processing, index + 1, stats.total, file.name));

		var done = function (item) {
			stats.processed++;
			stats[item.state]++;
			stats.items.push(item);
			updateStats();
			processNext(files, options, index + 1);
		};

		var localError = checkFileLocally(file);

		if (localError) {
			done({ state: 'failed', file: file.name, message: localError, warnings: [] });
			return;
		}

		importSingleFile(file, options).then(
			function (response) {
				done(normalizeResponse(file.name, response));
			},
			function (message) {
				done({ state: 'failed', file: file.name, message: message, warnings: [] });
			}
		);
	}

	function checkFileLocally(file) {
		if (!/\.md$/i.test(file.name)) {
			return strings.badExtension;
		}

		if (!file.size) {
			return strings.emptyFile;
		}

		if (config.maxFileSize && file.size > config.maxFileSize) {
			return format(strings.tooBig, config.maxFileSizeLabel || '');
		}

		return '';
	}

	function importSingleFile(file, options) {
		return new Promise(function (resolve, reject) {
			var formData = new FormData();

			formData.append('action', config.action);
			formData.append('nonce', config.nonce);
			formData.append('file', file);

			Object.keys(options).forEach(function (key) {
				formData.append(key, options[key]);
			});

			$.ajax({
				url: config.ajaxUrl,
				type: 'POST',
				data: formData,
				dataType: 'json',
				processData: false,
				contentType: false,
				// Plus long quand les images sont téléchargées.
				timeout: options.import_images === '1' ? 300000 : 60000
			}).done(function (response) {
				resolve(response);
			}).fail(function (xhr, status, error) {
				// wp_send_json_error() avec un code HTTP (403...) arrive ici avec un JSON exploitable.
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					reject(xhr.responseJSON.data.message);
				} else if (status === 'timeout') {
					reject(strings.timeout);
				} else if (xhr.status === 403 || xhr.responseText === '-1' || xhr.responseText === '0') {
					reject(strings.forbidden);
				} else if (status === 'parsererror') {
					reject(strings.unexpected);
				} else {
					reject(strings.network + (error ? ' : ' + error : ''));
				}
			});
		});
	}

	function normalizeResponse(fileName, response) {
		if (!response || typeof response !== 'object') {
			return { state: 'failed', file: fileName, message: strings.unexpected, warnings: [] };
		}

		var data = response.data || {};

		if (!response.success) {
			return { state: 'failed', file: fileName, message: data.message || strings.unknown, warnings: [] };
		}

		return {
			state: data.status === 'skipped' ? 'skipped' : 'imported',
			file: fileName,
			message: data.message || '',
			editUrl: typeof data.edit_url === 'string' ? data.edit_url : '',
			warnings: Array.isArray(data.warnings) ? data.warnings : []
		};
	}

	function updateProgress(percentage, text) {
		var rounded = Math.max(0, Math.min(100, Math.round(percentage)));

		$('#effissi-progress-fill').css('width', rounded + '%');
		$('#effissi-progress-text').text(text);
	}

	function updateStats() {
		$('#effissi-progress-stats').text(
			format(strings.stats, stats.processed, stats.total, stats.imported, stats.skipped, stats.failed)
		);
	}

	function finishImport() {
		isProcessing = false;
		updateProgress(100, strings.completed);
		displayResults();
		resetForm();
	}

	function displayResults() {
		var resultClass = 'success';
		var title = strings.titleOk;

		if (stats.failed > 0 && stats.imported === 0) {
			resultClass = 'error';
			title = strings.titleError;
		} else if (stats.failed > 0 || stats.skipped > 0) {
			resultClass = 'partial';
			title = strings.titlePartial;
		}

		var $results = $('#effissi-results');
		var $list = $('<ul class="effissi-result-list"></ul>');
		var labels = { imported: strings.imported, skipped: strings.skipped, failed: strings.failed };

		stats.items.forEach(function (item) {
			var $item = $('<li></li>').addClass('effissi-result-' + item.state);

			$('<strong></strong>').text(labels[item.state] + ' · ' + item.file).appendTo($item);

			if (item.message) {
				$item.append(document.createTextNode(' : ' + item.message));
			}

			if (item.editUrl) {
				$item.append(document.createTextNode(' '));
				$('<a></a>').attr('href', item.editUrl).text(strings.edit).appendTo($item);
			}

			if (item.warnings.length) {
				var $warnings = $('<ul class="effissi-warnings"></ul>');

				item.warnings.forEach(function (warning) {
					$('<li></li>').text(String(warning)).appendTo($warnings);
				});

				$item.append($warnings);
			}

			$list.append($item);
		});

		$results
			.removeClass('success error partial')
			.addClass(resultClass)
			.empty()
			.append($('<h3></h3>').text(title))
			.append($('<p></p>').text(format(strings.stats, stats.processed, stats.total, stats.imported, stats.skipped, stats.failed)))
			.append($list)
			.prop('hidden', false);
	}

	function resetForm() {
		setFormDisabled(false);
		$('#effissi-import-btn').text(strings.submit);
		$('#effissi-files').val('');
		$('#effissi-file-count').remove();

		window.setTimeout(function () {
			$('#effissi-progress-container').prop('hidden', true);
		}, 2000);
	}
})(jQuery);
