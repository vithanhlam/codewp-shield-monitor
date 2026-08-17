document.addEventListener('input', function (event) {
	if (!event.target.matches('.shield-monitor-pin input')) {
		return;
	}

	event.target.value = event.target.value.replace(/\D/g, '').slice(0, 1);
	if (event.target.value && event.target.nextElementSibling) {
		event.target.nextElementSibling.focus();
	}
});

document.addEventListener('keydown', function (event) {
	if (!event.target.matches('.shield-monitor-pin input')) {
		return;
	}

	if ('Backspace' === event.key && !event.target.value && event.target.previousElementSibling) {
		event.target.previousElementSibling.focus();
	}
});
