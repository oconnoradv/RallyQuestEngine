/* Local UI enhancements only; WordPress core handles media. */
(function () {
	'use strict';
	const root = document.querySelector('.mrsa');
	if (!root) return;
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
			id: item.value, make: item.dataset.make, model: item.dataset.model, year: item.dataset.year
		}));
		const dependent = row.querySelector('.mrsa-dependent');
		const make = row.querySelector('.mrsa-make');
		const model = row.querySelector('.mrsa-model');
		const year = row.querySelector('.mrsa-year');
		function fill(select, values, label, current) {
			select.replaceChildren(option('', label));
			Array.from(new Set(values)).forEach(value => select.append(option(value, value)));
			select.value = current || '';
		}
		const initial = records.find(item => item.id === catalog.value);
		fill(make, records.map(item => item.make), 'Choose make', initial && initial.make);
		function models(currentModel, currentYear) {
			fill(model, records.filter(item => item.make === make.value).map(item => item.model), 'Choose model', currentModel);
			years(currentYear);
		}
		function years(currentYear) {
			fill(year, records.filter(item => item.make === make.value && item.model === model.value).map(item => item.year), 'Choose year', currentYear);
			sync();
		}
		function sync() {
			const item = records.find(item => item.make === make.value && item.model === model.value && item.year === year.value);
			catalog.value = item ? item.id : '';
		}
		make.addEventListener('change', () => models('', ''));
		model.addEventListener('change', () => years(''));
		year.addEventListener('change', sync);
		models(initial && initial.model, initial && initial.year);
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
