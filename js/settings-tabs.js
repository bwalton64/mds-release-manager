jQuery(function ($) {
	var $links = $('#mds-settings-tabs a');
	function activate(hash, updateHistory) {
		var aliases = {
			'#tab-integrations': '#tab-general-settings',
		};
		var target = aliases[hash] && $links.filter(function () { return this.getAttribute('href') === aliases[hash]; }).length
			? aliases[hash] : hash;
		var $link = $links.filter(function () { return $(this).attr('href') === target; });
		if (!$link.length) {
			return;
		}
		$links.removeClass('nav-tab-active').removeAttr('aria-current');
		$link.addClass('nav-tab-active').attr('aria-current', 'page');
		$('.mds-settings-wrap .mds-tab-pane').removeClass('active');
		$(document.getElementById(target.slice(1))).addClass('active');
		if (updateHistory && history.pushState) {
			history.pushState(null, '', target);
		} else if (updateHistory) {
			location.hash = target;
		}
	}
	$links.on('click', function (event) {
		event.preventDefault();
		activate($(this).attr('href'), true);
	});
	$(window).on('popstate hashchange', function () { activate(location.hash, false); });
	activate(location.hash || $links.first().attr('href'), false);
});
