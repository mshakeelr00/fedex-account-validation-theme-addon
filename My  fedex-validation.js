(function () {
  const bound = new WeakSet();

  function initFedExValidation(input) {
    if (!input || bound.has(input)) return;
    bound.add(input);

    const feedback = document.createElement('div');
    feedback.className = 'fedex-account-feedback';
    feedback.style.marginTop = '5px';
    input.parentNode.insertBefore(feedback, input.nextSibling);


    async function validate() {
      const account = input.value.trim();
      const placeOrderBtn = document.querySelector('#place_order');

      if (account.length < 6) { 
        feedback.textContent = ''; 
        placeOrderBtn?.removeAttribute('disabled'); // reset
        return; 
      }

      feedback.textContent = 'Validating FedEx account...';
      feedback.style.color = '#333';
      placeOrderBtn?.setAttribute('disabled', 'disabled');

      try {
        // Collect WooCommerce checkout fields
        const customer_name  = document.querySelector('#billing_first_name')?.value + ' ' + document.querySelector('#billing_last_name')?.value;
        const customer_email = document.querySelector('#billing_email')?.value;
        const customer_phone = document.querySelector('#billing_phone')?.value;
        const customer_address = document.querySelector('#billing_address_1')?.value;
        const customer_city  = document.querySelector('#billing_city')?.value;
        const customer_zip   = document.querySelector('#billing_postcode')?.value;
        const customer_state = document.querySelector('#billing_state')?.value;

        const params = new URLSearchParams({
          action: 'validate_fedex_account',
          account_number: account,
          customer_name,
          customer_email,
          customer_phone,
          customer_city,
          customer_zip,
          customer_state,
          nonce: fedexValidation.nonce
        });

        const res = await fetch(fedexValidation.ajax_url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: params.toString(),
          credentials: 'same-origin'
        });

        const text = await res.text();
        let json; try { json = JSON.parse(text); } catch { json = { raw: text }; }
        console.log('[FedEx Validate] status:', res.status, 'payload:', json);

        const payload = json?.data ?? json;
        const valid = !!(json?.success && payload?.valid);

        if (valid) {
          feedback.textContent = '✅ FedEx account is valid.';
          feedback.style.color = 'green';
          placeOrderBtn?.removeAttribute('disabled'); 
        } else {
          const msg = payload?.error || payload?.message || 'Invalid FedEx account.';
          feedback.textContent = `❌ ${msg}`;
          feedback.style.color = 'red';
          placeOrderBtn?.setAttribute('disabled', 'disabled');
        }
      } catch (err) {
        console.error('[FedEx Validate] network/JS error:', err);
        feedback.textContent = '❌ Error validating account.';
        feedback.style.color = 'red';
        placeOrderBtn?.setAttribute('disabled', 'disabled');
      }
    }


    input.addEventListener('blur', validate);
  }

  function scan() {
    document.querySelectorAll('input.shipper_number').forEach(initFedExValidation);
  }

  document.addEventListener('DOMContentLoaded', scan);
  new MutationObserver(scan).observe(document.body, { childList: true, subtree: true });
})();
