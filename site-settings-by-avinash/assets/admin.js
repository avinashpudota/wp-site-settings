(function () {
	var encryptionInputs = document.querySelectorAll('input[name="avinash_site_settings[smtp_encryption]"]');
	var portInput = document.getElementById('avinash-smtp-port');

	if (encryptionInputs.length && portInput) {
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
	}
})();

(function () {
	var list = document.querySelector('[data-avinash-functions-list]');
	var template = document.querySelector('[data-avinash-function-template]');
	var addButton = document.querySelector('[data-avinash-add-function]');

	if (!list || !template || !addButton) {
		return;
	}

	var functionIndex = list.querySelectorAll('[data-avinash-function-item]').length;

	function nextIndex() {
		functionIndex += 1;
		return 'new_' + functionIndex;
	}

	function bindRemove(button) {
		button.addEventListener('click', function () {
			var item = button.closest('[data-avinash-function-item]');

			if (!item) {
				return;
			}

			item.remove();

			if (!list.querySelector('[data-avinash-function-item]')) {
				addFunction();
			}
		});
	}

	function addFunction() {
		var html = template.innerHTML.replace(/__INDEX__/g, nextIndex());
		var wrapper = document.createElement('div');

		wrapper.innerHTML = html.trim();
		list.appendChild(wrapper.firstElementChild);

		var newItem = list.lastElementChild;
		var titleInput = newItem ? newItem.querySelector('input[type="text"]') : null;
		var removeButton = newItem ? newItem.querySelector('[data-avinash-remove-function]') : null;

		if (removeButton) {
			bindRemove(removeButton);
		}

		if (titleInput) {
			titleInput.focus();
		}
	}

	addButton.addEventListener('click', addFunction);

	list.querySelectorAll('[data-avinash-remove-function]').forEach(bindRemove);
})();
