/* Admin UI and sequential event-gallery uploads; files remain outside the WordPress media library. */
(function () {
	'use strict';
	const root = document.querySelector('.mrsa');
	if (!root) return;
	const imageForm = root.querySelector('#mrsa-import-images form');
	const queue = root.querySelector('.mrsa-image-queue');
	if (imageForm && queue && typeof window.fetch === 'function') {
		const chooser = imageForm.querySelector('[name="bonus_images[]"]');
		const progress = queue.querySelector('.mrsa-queue-progress');
		const review = queue.querySelector('.mrsa-queue-review');
		const log = queue.querySelector('.mrsa-queue-log');
		const stop = queue.querySelector('.mrsa-queue-stop');
		const galleryLink = queue.querySelector('a');
		const galleryUrl = galleryLink.href;
		chooser.multiple = true;
		chooser.setAttribute('webkitdirectory', '');
		let running = false;
		let stopping = false;
		let resolveReview;
		const node = (tag, text, parent) => {
			const element = document.createElement(tag);
			element.textContent = text;
			if (parent) parent.append(element);
			return element;
		};
		const request = async (operation, file, token, unscanned) => {
			// Never reuse the multipart form: it would send every selected image.
			const data = new FormData();
			data.set('action', 'mrsa_image_queue');
			data.set('_wpnonce', imageForm.querySelector('[name="_wpnonce"]').value);
			data.set('event_id', imageForm.querySelector('[name="event_id"]').value);
			data.set('import_step', 'images');
			data.set('queue_operation', operation);
			if (file) data.append('bonus_images[]', file, file.name);
			if (token) data.set('pending_token', token);
			if (operation === 'approve') data.set('scan_approval', '1');
			if (operation === 'bypass' && unscanned) data.set('bypass_confirmation', '1');
			let response;
			try {
				response = await fetch(imageForm.getAttribute('action'), { method: 'POST', credentials: 'same-origin', body: data });
			} catch (error) {
				throw new Error('Connection lost. The last request may have completed. Check the event gallery or saved scan review before retrying.');
			}
			let payload;
			try {
				payload = await response.json();
			} catch (error) {
				throw new Error('No valid upload response was received (HTTP ' + response.status + '). Check server per-file/post limits and your login, then inspect the gallery before retrying.');
			}
			if (!response.ok || !payload.success) {
				throw new Error(payload.data && payload.data.message ? payload.data.message : 'Upload request failed (HTTP ' + response.status + ').');
			}
			return payload.data;
		};
		const decision = reports => new Promise(resolve => {
			review.replaceChildren();
			node('h4', 'Review all images that passed before bulk importing', review);
			node('p', reports.length + ' images passed validation. Their private reviews expire 15 minutes after each scan.', review);
			reports.forEach(item => {
				const section = node('section', '', review);
				node('h5', item.file.name, section);
				item.report.scans.forEach(scan => node('p', scan.file + ': ' + scan.status + ' — ' + scan.detail, section));
				item.report.errors.forEach(error => node('p', error, section));
			});
			const controls = node('form', '', review);
			const unscanned = reports.some(item => item.report.unscanned);
			const label = node('label', '', controls);
			const consent = document.createElement('input');
			consent.type = 'checkbox';
			consent.required = true;
			label.append(consent, document.createTextNode(unscanned ?
				' I accept bulk importing these images without antivirus scanning. Record my consent and remember the unavailable-scanner override for my account.' :
				' I have reviewed the successful scan results and approve bulk importing all listed images.'));
			const approve = node('button', unscanned ? 'Confirm Bypass and Bulk Import Images' : 'Approve and Bulk Import Images', controls);
			approve.type = 'submit';
			approve.className = 'button button-primary';
			controls.addEventListener('submit', event => {
				event.preventDefault();
				if (consent.checked) finish(unscanned ? 'bypass' : 'approve');
			});
			const skip = node('button', 'Cancel Bulk Import and Remove Staged Files', controls);
			skip.type = 'button';
			skip.className = 'button';
			const finish = operation => {
				resolveReview = null;
				controls.querySelectorAll('button, input').forEach(control => { control.disabled = true; });
				resolve(operation);
			};
			skip.addEventListener('click', () => finish('cancel'));
			resolveReview = () => finish('cancel');
			skip.focus();
		});
		const cancelReports = async reports => {
			for (const item of reports) {
				if (!item.report.token) continue;
				try {
					await request('cancel', null, item.report.token, item.report.unscanned);
					item.report.token = '';
				} catch (error) {
					node('li', item.file.name + ': staged-file cleanup could not be confirmed; use the saved review link or wait for expiry.', log);
				}
			}
		};
		stop.addEventListener('click', () => {
			stopping = true;
			progress.textContent = 'Stopping after the current request; earlier imports are retained.';
			if (resolveReview) resolveReview();
		});
		window.addEventListener('beforeunload', event => {
			if (running) { event.preventDefault(); event.returnValue = ''; }
		});
		imageForm.addEventListener('submit', async event => {
			if (event.submitter && event.submitter.value === 'url') {
				chooser.disabled = true;
				setTimeout(() => { if (!running) chooser.disabled = false; }, 0);
				return;
			}
			const files = Array.from(chooser.files);
			if (!files.length) return;
			event.preventDefault();
			if (running) return;
			queue.hidden = false;
			log.replaceChildren();
			review.replaceChildren();
			if (imageForm.querySelector('[name="gallery_url"]').value.trim()) {
				progress.textContent = 'For one-at-a-time local uploads, clear the URL first. Use Load by URL separately.';
				return;
			}
			const partial = imageForm.querySelector('[name="allow_partial_images"]').checked;
			const maxImage = Number(queue.dataset.maxImage);
			if (files.length > Number(queue.dataset.maxCount) || files.reduce((total, file) => total + file.size, 0) > Number(queue.dataset.maxTotal)) {
				progress.textContent = 'Choose no more than 200 images totaling 100 MB per queue.';
				return;
			}
			if (!partial && files.some(file => file.size === 0 || file.size > maxImage)) {
				progress.textContent = 'Each image must be nonempty and no larger than 10 MB. No files were uploaded.';
				return;
			}
			running = true;
			stopping = false;
			stop.disabled = false;
			let imported = 0;
			let failed = false;
			const passed = [];
			const controls = Array.from(root.querySelectorAll('.mrsa-import-header input, .mrsa-import-header button'));
			const originalDisabled = controls.map(control => control.disabled);
			controls.forEach(control => { control.disabled = true; });
			try {
				for (let index = 0; index < files.length && !stopping; index++) {
					const file = files[index];
					if (file.size === 0 || file.size > maxImage) {
						node('li', file.name + ': skipped; image is empty or exceeds 10 MB.', log);
						continue;
					}
					progress.textContent = 'Uploading and scanning ' + (index + 1) + ' of ' + files.length + ': ' + file.name;
					let result = await request('upload', file);
					if (result.state === 'review') {
						// Keep recovery available until the approval response confirms its outcome.
						const recovery = new URL(galleryUrl);
						recovery.searchParams.set('bonus_pending', result.token);
						galleryLink.href = recovery.href + '#mrsa-import-feedback';
						galleryLink.textContent = 'Open saved scan review / check gallery';
						const report = result;
						if (!report.can_approve) {
							report.scans.forEach(scan => node('li', file.name + ': ' + scan.status + ' — ' + scan.detail, log));
							report.errors.forEach(error => node('li', file.name + ': ' + error, log));
							await request('cancel', null, report.token, report.unscanned);
							failed = true;
							if (!partial || report.scanner_error) stopping = true;
						} else {
							passed.push({ file, report });
							node('li', file.name + ': passed scan and is ready for bulk import.', log).className = 'mrsa-queue-success';
						}
						continue;
					}
					if (result.state === 'imported') imported++;
					const entry = node('li', file.name + ': ' + result.message, log);
					if (result.state === 'imported') {
						entry.className = result.message.includes('WARNING:') ? 'mrsa-queue-warning' : 'mrsa-queue-success';
					}
					(result.errors || []).forEach(error => node('li', file.name + ': ' + error, log));
					review.replaceChildren();
					galleryLink.href = galleryUrl;
					galleryLink.textContent = 'Refresh event gallery';
				}
				if (passed.length && (stopping || (failed && !partial))) {
					await cancelReports(passed);
					progress.textContent = 'Queue stopped before authorization. No successfully scanned staged images were imported.';
				} else if (passed.length) {
					progress.textContent = 'Scan complete. Review ' + passed.length + ' successful images and authorize one bulk import.';
					const operation = await decision(passed);
					if (operation === 'cancel') {
						await cancelReports(passed);
						progress.textContent = 'Bulk import cancelled. Staged images were removed.';
					} else {
						progress.textContent = 'Bulk importing ' + passed.length + ' approved images.';
						for (let index = 0; index < passed.length; index++) {
							if (stopping) break;
							const item = passed[index];
							progress.textContent = 'Bulk importing ' + (index + 1) + ' of ' + passed.length + ': ' + item.file.name;
							const result = await request(operation, null, item.report.token, item.report.unscanned);
							if (result.state === 'imported') {
								imported++;
								item.report.token = '';
							}
							const entry = node('li', item.file.name + ': ' + result.message, log);
							entry.className = result.message.includes('WARNING:') ? 'mrsa-queue-warning' : 'mrsa-queue-success';
							(result.errors || []).forEach(error => node('li', item.file.name + ': ' + error, log));
						}
						review.replaceChildren();
						galleryLink.href = galleryUrl;
						galleryLink.textContent = 'Refresh event gallery';
						if (passed.some(item => item.report.token)) {
							await cancelReports(passed);
							progress.textContent = 'Bulk import stopped. ' + imported + ' images imported; remaining staged images were cancelled.';
						} else {
							progress.textContent = 'Bulk import complete. ' + imported + ' images imported. Refresh the gallery to view saved images.';
						}
					}
				} else {
					progress.textContent = (stopping ? 'Queue stopped. ' : 'Queue complete. ') + imported + ' images imported. Refresh the gallery to view saved images.';
				}
			} catch (error) {
				await cancelReports(passed);
				node('p', error.message, review).className = 'notice notice-error inline';
				progress.textContent = 'Queue stopped after an error. ' + imported + ' confirmed image imports; check the gallery before retrying.';
			} finally {
				running = false;
				resolveReview = null;
				stop.disabled = true;
				controls.forEach((control, index) => { control.disabled = originalDisabled[index]; });
			}
		});
	}
	root.querySelectorAll('.mrsa-destructive-action').forEach(section => {
		const form = section.querySelector('form');
		const verification = form.querySelector('.mrsa-delete-verification');
		const dialog = document.createElement('dialog');
		if (typeof dialog.showModal !== 'function') return;
		const input = verification.querySelector('[name="verification_code"]');
		const action = Array.from(form.querySelectorAll('button')).find(button => !verification.contains(button));
		dialog.className = 'mrsa-delete-dialog';
		dialog.setAttribute('aria-labelledby', verification.querySelector('h4').id);
		dialog.append(verification);
		form.append(dialog);
		input.disabled = true;
		verification.querySelectorAll('button').forEach(button => { button.hidden = false; });
		form.addEventListener('submit', event => {
			if (dialog.open) return;
			event.preventDefault();
			const selected = form.querySelector('select');
			verification.querySelector('.mrsa-delete-target').textContent = selected ?
				selected.previousElementSibling.textContent + ': ' + selected.selectedOptions[0].textContent : '';
			input.disabled = false;
			dialog.showModal();
			verification.querySelector('.mrsa-delete-cancel').focus();
		});
		verification.querySelector('.mrsa-delete-cancel').addEventListener('click', () => dialog.close());
		dialog.addEventListener('close', () => {
			input.value = '';
			input.disabled = true;
			action.focus();
		});
	});
	const urlSource = root.querySelector('.mrsa-url-source');
	const urlDialog = document.createElement('dialog');
	if (urlSource && typeof urlDialog.showModal === 'function') {
		// Like Choose Files: one button that prompts; the URL field stays in the form so it submits normally.
		const opener = urlSource.querySelector('.mrsa-url-open');
		const prompt = urlSource.querySelector('.mrsa-url-prompt');
		const input = prompt.querySelector('[name="gallery_url"]');
		urlDialog.className = 'mrsa-url-dialog';
		urlDialog.setAttribute('aria-labelledby', 'mrsa-url-prompt-title');
		prompt.querySelectorAll('[hidden]').forEach(element => { element.hidden = false; });
		prompt.querySelector('.mrsa-url-submit').textContent = 'Load Images';
		urlDialog.append(prompt);
		urlSource.append(urlDialog);
		opener.hidden = false;
		opener.addEventListener('click', () => {
			input.required = true;
			urlDialog.showModal();
			input.focus();
		});
		prompt.querySelector('.mrsa-url-cancel').addEventListener('click', () => urlDialog.close());
		urlDialog.addEventListener('close', () => {
			input.value = '';
			input.required = false;
			opener.focus();
		});
	}
	root.querySelectorAll('.mrsa-url-help').forEach(help => {
		help.addEventListener('mouseenter', () => { help.open = true; });
		help.addEventListener('mouseleave', () => { help.open = false; });
		help.addEventListener('focusin', () => { help.open = true; });
		help.addEventListener('focusout', event => {
			if (!help.contains(event.relatedTarget)) help.open = false;
		});
		help.addEventListener('keydown', event => {
			if (event.key === 'Escape') { help.open = false; event.stopPropagation(); }
		});
	});
	root.querySelectorAll('.mrsa-email-config').forEach(form => {
		const provider = form.querySelector('[name="provider"]');
		const refreshProviderFields = () => {
			form.querySelectorAll('[data-email-provider-types]').forEach(group => {
				const visible = group.dataset.emailProviderTypes.split(/\s+/).includes(provider.value);
				group.hidden = !visible;
				group.querySelectorAll('input, select, textarea, button').forEach(field => {
					field.disabled = !visible;
				});
			});
		};
		provider.addEventListener('change', refreshProviderFields);
		refreshProviderFields();
	});
	const galleryDialog = root.querySelector('.mrsa-gallery-dialog');
	if (galleryDialog && typeof galleryDialog.showModal === 'function') {
		const image = galleryDialog.querySelector('.mrsa-gallery-full-image');
		const title = galleryDialog.querySelector('#mrsa-gallery-title');
		const imageError = galleryDialog.querySelector('.mrsa-gallery-image-error');
		let opener;
		image.addEventListener('error', () => { image.hidden = true; imageError.hidden = false; });
		root.querySelectorAll('.mrsa-bonus-image-link').forEach(link => {
			link.addEventListener('click', event => {
				event.preventDefault();
				opener = link;
				imageError.hidden = true;
				image.hidden = false;
				image.alt = link.querySelector('img').alt;
				title.textContent = image.alt;
				image.src = link.href;
				galleryDialog.showModal();
			});
		});
		galleryDialog.querySelector('.mrsa-gallery-close').addEventListener('click', () => galleryDialog.close());
		galleryDialog.addEventListener('click', event => {
			const bounds = galleryDialog.getBoundingClientRect();
			if (event.target === galleryDialog && (event.clientX < bounds.left || event.clientX > bounds.right ||
				event.clientY < bounds.top || event.clientY > bounds.bottom)) galleryDialog.close();
		});
		galleryDialog.addEventListener('close', () => {
			image.removeAttribute('src');
			if (opener) opener.focus();
		});
	}
	const option = (value, label) => {
		const node = document.createElement('option');
		node.value = String(value);
		node.textContent = String(label);
		return node;
	};
	root.querySelectorAll('[data-confirm]').forEach(button => {
		button.addEventListener('click', event => {
			if (!window.confirm(button.dataset.confirm)) event.preventDefault();
		});
	});
	root.querySelectorAll('.mrsa-user-select').forEach(select => {
		const form = select.closest('form');
		const filter = form.querySelector('.mrsa-user-filter');
		const choices = Array.from(select.options);
		const refresh = () => {
			const selected = select.selectedOptions[0];
			form.querySelectorAll('[data-profile]').forEach(cell => {
				cell.textContent = selected ? (selected.dataset[cell.dataset.profile] || '—') : '—';
			});
		};
		select.addEventListener('change', refresh);
		filter.addEventListener('input', () => {
			const value = filter.value.toLocaleLowerCase();
			choices.forEach(item => {
				item.hidden = Boolean(item.value && item.value !== select.value && !item.textContent.toLocaleLowerCase().includes(value));
			});
		});
		refresh();
	});
	function setupBike(row) {
		const catalog = row.querySelector('.mrsa-catalog');
		const records = Array.from(catalog.options).filter(item => item.value).map(item => ({
			id: item.value, make: item.dataset.make, model: item.dataset.model
		}));
		const dependent = row.querySelector('.mrsa-dependent');
		const make = row.querySelector('.mrsa-make');
		const model = row.querySelector('.mrsa-model');
		function fill(select, values, label, current) {
			select.replaceChildren(option('', label));
			Array.from(new Set(values)).forEach(value => select.append(option(value, value)));
			select.value = current || '';
		}
		const initial = records.find(item => item.id === catalog.value);
		fill(make, records.map(item => item.make), 'Choose make', initial && initial.make);
		function models(currentModel) {
			fill(model, records.filter(item => item.make === make.value).map(item => item.model), 'Choose model', currentModel);
			sync();
		}
		function sync() {
			const selected = records.find(item => item.id === catalog.value);
			if (selected && selected.make === make.value && selected.model === model.value) return;
			const item = records.find(item => item.make === make.value && item.model === model.value);
			catalog.value = item ? item.id : '';
		}
		make.addEventListener('change', () => models(''));
		model.addEventListener('change', sync);
		models(initial && initial.model);
		dependent.hidden = false;
		row.querySelector('.mrsa-catalog-label').hidden = true;
		row.querySelector('.mrsa-remove-bike').addEventListener('click', () => row.remove());
	}
	root.querySelectorAll('.mrsa-bike-row').forEach(setupBike);
	const addBike = root.querySelector('.mrsa-add-bike');
	if (addBike) addBike.addEventListener('click', () => {
		const holder = root.querySelector('.mrsa-bike-rows');
		if (holder.children.length >= 20) {
			window.alert('At most 20 motorcycles are allowed.');
			return;
		}
		const fragment = root.querySelector('#mrsa-bike-template').content.cloneNode(true);
		const row = fragment.querySelector('.mrsa-bike-row');
		setupBike(row);
		holder.append(fragment);
	});
	const selectAllBikes = root.querySelector('.mrsa-select-all-bikes');
	if (selectAllBikes) selectAllBikes.addEventListener('change', () => {
		root.querySelectorAll('[form="mrsa-bulk-delete-bikes"]').forEach(checkbox => {
			checkbox.checked = selectAllBikes.checked;
		});
	});
	root.querySelectorAll('.mrsa-table-region').forEach(region => {
		const table = region.querySelector('table');
		const tbody = table.tBodies[0];
		const rows = Array.from(tbody.rows);
		const tools = region.querySelector('.mrsa-table-tools');
		const search = region.querySelector('.mrsa-search');
		const size = region.querySelector('.mrsa-page-size');
		const paging = region.querySelector('.mrsa-pagination');
		const headers = Array.from(table.tHead.rows[0].cells);
		const filters = [];
		let page = 1;
		let sortIndex = -1;
		let ascending = true;
		function cellText(row, index) {
			return row.cells[index] ? row.cells[index].textContent.trim() : '';
		}
		headers.forEach((header, index) => {
			const label = header.textContent;
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'mrsa-sort';
			button.textContent = label + ' ↕';
			button.setAttribute('aria-label', 'Sort by ' + label);
			header.replaceChildren(button);
			button.addEventListener('click', () => {
				ascending = sortIndex === index ? !ascending : true;
				sortIndex = index;
				headers.forEach(item => item.removeAttribute('aria-sort'));
				header.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
				page = 1;
				render();
			});
			if (header.dataset.filter) {
				const wrapper = document.createElement('label');
				wrapper.append(document.createTextNode(label + ' '));
				const select = document.createElement('select');
				select.append(option('', 'All'));
				Array.from(new Set(rows.map(row => cellText(row, index)))).sort().forEach(value => select.append(option(value, value)));
				wrapper.append(select);
				tools.append(wrapper);
				filters.push({select, index});
				select.addEventListener('change', () => { page = 1; render(); });
			}
		});
		function render() {
			const query = search.value.trim().toLocaleLowerCase();
			let visible = rows.filter(row => row.textContent.toLocaleLowerCase().includes(query) &&
				filters.every(filter => !filter.select.value || cellText(row, filter.index) === filter.select.value));
			if (sortIndex >= 0) visible.sort((a, b) => cellText(a, sortIndex).localeCompare(cellText(b, sortIndex), undefined, {numeric: true, sensitivity: 'base'}) * (ascending ? 1 : -1));
			const perPage = Number(size.value);
			const pages = Math.max(1, Math.ceil(visible.length / perPage));
			page = Math.max(1, Math.min(page, pages));
			rows.forEach(row => { row.hidden = true; });
			visible.forEach((row, index) => {
				tbody.append(row);
				row.hidden = index < (page - 1) * perPage || index >= page * perPage;
			});
			const previous = document.createElement('button');
			previous.type = 'button';
			previous.className = 'button';
			previous.textContent = 'Previous';
			previous.disabled = page === 1;
			previous.addEventListener('click', () => { page -= 1; render(); });
			const next = document.createElement('button');
			next.type = 'button';
			next.className = 'button';
			next.textContent = 'Next';
			next.disabled = page === pages;
			next.addEventListener('click', () => { page += 1; render(); });
			const status = document.createElement('span');
			status.textContent = ' ' + visible.length + ' rows · page ' + page + ' of ' + pages + ' ';
			paging.replaceChildren(previous, status, next);
		}
		search.addEventListener('input', () => { page = 1; render(); });
		size.addEventListener('change', () => { page = 1; render(); });
		render();
	});
	root.querySelectorAll('.mrsa-logo').forEach(holder => {
		const input = holder.querySelector('[name="logo_id"]');
		const preview = holder.querySelector('.mrsa-logo-preview');
		holder.querySelector('.mrsa-clear-media').addEventListener('click', () => {
			input.value = '0';
			preview.replaceChildren();
		});
		holder.querySelector('.mrsa-media').addEventListener('click', () => {
			if (!window.wp || !window.wp.media) {
				window.alert('WordPress media is unavailable. Enter an existing image attachment ID.');
				return;
			}
			const frame = window.wp.media({title: 'Select event logo', library: {type: 'image'}, button: {text: 'Use logo'}, multiple: false});
			frame.on('select', () => {
				const media = frame.state().get('selection').first().toJSON();
				input.value = String(media.id);
				const src = media.sizes && media.sizes.thumbnail ? media.sizes.thumbnail.url : media.url;
				let url;
				try { url = new URL(src, window.location.href); } catch (error) { return; }
				preview.replaceChildren();
				if (url.protocol !== 'https:' && url.protocol !== 'http:') return;
				const image = document.createElement('img');
				image.src = url.href;
				image.alt = media.alt || 'Event logo';
				image.width = 150;
				preview.append(image);
			});
			frame.open();
		});
	});
	if (window.location.search.includes('score_rider=')) {
		const scoring = root.querySelector('#mrsa-scoring');
		if (scoring) scoring.scrollIntoView({block: 'start'});
	}
}());
