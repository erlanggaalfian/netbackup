/**
 * Auto-submit form filter saat mengetik (debounce) atau mengubah select.
 * Form: class="auto-filter-form"
 * Atribut opsional:
 *   data-debounce="700"   — jeda ms sebelum submit (default 400)
 *   data-min-chars="2"    — minimal karakter pencarian sebelum submit (0 = langsung)
 */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form.auto-filter-form').forEach(function (form) {
        var timer = null;
        var delay = parseInt(form.getAttribute('data-debounce') || '400', 10);
        var minChars = parseInt(form.getAttribute('data-min-chars') || '0', 10);

        function resetPage() {
            var pageInput = form.querySelector('input[name="page"]');
            if (pageInput) {
                pageInput.value = '1';
            }
        }

        function submitForm() {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        }

        form.querySelectorAll('input[type="text"], input[type="search"]').forEach(function (input) {
            input.addEventListener('input', function () {
                clearTimeout(timer);
                var val = input.value.trim();

                if (minChars > 0 && val.length > 0 && val.length < minChars) {
                    return;
                }

                resetPage();
                timer = setTimeout(submitForm, delay);
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    clearTimeout(timer);
                    var val = input.value.trim();
                    if (minChars > 0 && val.length > 0 && val.length < minChars) {
                        return;
                    }
                    resetPage();
                    submitForm();
                }
            });
        });

        form.querySelectorAll('input[type="date"]').forEach(function (input) {
            input.addEventListener('change', function () {
                clearTimeout(timer);
                resetPage();
                submitForm();
            });
        });

        form.querySelectorAll('select').forEach(function (select) {
            select.addEventListener('change', function () {
                clearTimeout(timer);
                resetPage();
                submitForm();
            });
        });
    });
});
