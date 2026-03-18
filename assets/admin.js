document.addEventListener('DOMContentLoaded', function () {
    const testModeCheckbox = document.querySelector('#ba_hbl_test_mode');
    if (!testModeCheckbox) {
        return;
    }

    function toggleCredentials() {
        const rows = document.querySelectorAll('#ba_hbl_mid, #ba_hbl_enckey, #ba_hbl_token, #ba_hbl_signkey, #ba_hbl_decryptkey, #ba_hbl_pacosign, #ba_hbl_pacoenc');
        rows.forEach(function (el) {
            const row = el.closest('tr');
            if (row) {
                row.style.opacity = testModeCheckbox.checked ? '0.4' : '1';
            }
        });
    }

    testModeCheckbox.addEventListener('change', toggleCredentials);
    toggleCredentials();
});
