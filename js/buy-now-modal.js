(function () {
	document.addEventListener('click', function (event) {
		var trigger = event.target.closest('[data-mds-buy-now-modal]');
		if (trigger) {
			var dialogId = trigger.getAttribute('data-mds-buy-now-modal');
			var dialog = dialogId ? document.getElementById(dialogId) : null;
			if (dialog && typeof dialog.showModal === 'function') {
				event.preventDefault();
				dialog._mdsTrigger = trigger;
				dialog.showModal();
			}
			return;
		}

		var closeBtn = event.target.closest('.mds-buy-now-dialog__close');
		if (closeBtn) {
			var parentDialog = closeBtn.closest('dialog.mds-buy-now-dialog');
			if (parentDialog && parentDialog.open) {
				parentDialog.close();
			}
			return;
		}

		if (event.target.matches('dialog.mds-buy-now-dialog')) {
			var rect = event.target.getBoundingClientRect();
			var inside = rect.top <= event.clientY && event.clientY <= rect.top + rect.height
				&& rect.left <= event.clientX && event.clientX <= rect.left + rect.width;
			if (!inside && event.target.open) {
				event.target.close();
			}
		}
	});

	document.addEventListener('close', function (event) {
		if (event.target && event.target.matches && event.target.matches('dialog.mds-buy-now-dialog') && event.target._mdsTrigger) {
			event.target._mdsTrigger.focus();
			event.target._mdsTrigger = null;
		}
	}, true);
})();
