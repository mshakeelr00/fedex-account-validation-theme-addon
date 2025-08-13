document.addEventListener('DOMContentLoaded', function () {
    const fedexField = document.querySelector('#fedex_account');
    const feedback = document.createElement('div');
    feedback.id = 'fedex-account-feedback';
    fedexField && fedexField.parentNode.insertBefore(feedback, fedexField.nextSibling);

    function validateFedexAccount(account) {
        feedback.textContent = 'Validating FedEx account...';
        feedback.style.color = '#333';

        fetch(fedexValidation.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                action: 'validate_fedex_account',
                account_number: account
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.valid) {
                feedback.textContent = '✅ FedEx account is valid.';
                feedback.style.color = 'green';
            } else {
                feedback.textContent = '❌ Invalid FedEx account number.';
                feedback.style.color = 'red';
            }
        })
        .catch(err => {
            feedback.textContent = '❌ Error validating FedEx account.';
            feedback.style.color = 'red';
        });
    }

    if (fedexField) {
        fedexField.addEventListener('blur', function () {
            const account = fedexField.value.trim();
            if (account.length >= 7) {
                validateFedexAccount(account);
            }
        });
    }
});
