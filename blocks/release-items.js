(function () {
	window.mdsRmReleaseItems = {
		resolve: function (defaults, attributes, key) {
			if (Array.isArray(attributes.items)) return attributes.items.map((item) => ({ ...item }));
			const patches = attributes.itemOverrides || {};
			let items = defaults.map((item) => ({ ...item, ...(patches[item[key]] || {}) }));
			Object.keys(patches).forEach((id) => {
				if (key === 'name' && !items.some((item) => item[key] === id)) {
					items.push({ [key]: id, ...patches[id] });
				}
			});
			if (Array.isArray(attributes.itemOrder)) {
				const ordered = [];
				attributes.itemOrder.forEach((id) => {
					const index = items.findIndex((item) => item[key] === id);
					if (index !== -1) ordered.push(items.splice(index, 1)[0]);
				});
				items = ordered.concat(items);
			}
			return items;
		},
		update: function (attributes, setAttributes, items, key, index, change) {
			if (Array.isArray(attributes.items)) {
				setAttributes({ items: items.map((item, i) => i === index ? { ...item, ...change } : item) });
				return;
			}
			const id = items[index][key];
			const patches = { ...attributes.itemOverrides };
			patches[id] = { ...patches[id], ...change };
			setAttributes({ itemOverrides: patches });
		},
		move: function (attributes, setAttributes, items, key, index, direction) {
			const target = index + direction;
			if (target < 0 || target >= items.length) return;
			const next = items.slice();
			[next[index], next[target]] = [next[target], next[index]];
			setAttributes(Array.isArray(attributes.items) ? { items: next } : { itemOrder: next.map((item) => item[key]) });
		},
		resetItem: function (attributes, setAttributes, id) {
			const patches = { ...attributes.itemOverrides };
			delete patches[id];
			setAttributes({ itemOverrides: Object.keys(patches).length ? patches : undefined });
		},
		resetProperty: function (attributes, setAttributes, id, key) {
			const patches = { ...attributes.itemOverrides };
			const item = { ...patches[id] };
			delete item[key];
			if (Object.keys(item).length) patches[id] = item;
			else delete patches[id];
			setAttributes({ itemOverrides: Object.keys(patches).length ? patches : undefined });
		},
	};
})();
