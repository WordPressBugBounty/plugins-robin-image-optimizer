if (typeof window.tsdk_reposition_notice === 'function') {
	document.addEventListener('DOMContentLoaded', function() {
		setTimeout(function() {
			window.tsdk_reposition_notice();
			const themeisleSale = document.getElementById('tsdk_banner')?.querySelector('.themeisle-sale');
			if (themeisleSale) {
				themeisleSale.style.setProperty('display', 'block','important');
				themeisleSale.style.setProperty('margin-left', '0');
			}
		}, 0);
	});
}