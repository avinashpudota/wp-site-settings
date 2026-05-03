(function () {
	var encryptionInputs = document.querySelectorAll('input[name="avinash_site_settings[smtp_encryption]"]');
	var portInput = document.getElementById('avinash-smtp-port');

	if (!encryptionInputs.length || !portInput) {
		return;
	}

	encryptionInputs.forEach(function (input) {
		input.addEventListener('change', function () {
			if (!input.checked) {
				return;
			}

			if (input.value === 'ssl' && (portInput.value === '' || portInput.value === '587')) {
				portInput.value = '465';
			}

			if (input.value === 'tls' && (portInput.value === '' || portInput.value === '465')) {
				portInput.value = '587';
			}
		});
	});
})();
