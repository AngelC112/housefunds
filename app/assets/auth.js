document.addEventListener('DOMContentLoaded', async () => {
  const csrf = await csrfFresco();
  const raw = sessionStorage.getItem('csrf_token');
  if (raw && raw !== csrf.token) {
    sessionStorage.setItem('csrf_token', csrf.token);
  }

  // Tab切换
  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.tab-panel').forEach(p => p.classList.add('hidden'));
      const target = btn.dataset.target;
      btn.classList.add('active');
      document.getElementById(target).classList.remove('hidden');
    });
  });

  // Login
  document.getElementById('loginForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const email = document.getElementById('loginEmail').value;
    const pass  = document.getElementById('loginPass').value;
    try {
      const res = await api('/api/auth.php?azione=login', {
        method: 'POST',
        body: JSON.stringify({ email, password: pass, csrf_token: await csrfFresco() })
      });
      sessionStorage.setItem('utente', JSON.stringify(res.utente));
      sessionStorage.setItem('casa_attiva', JSON.stringify(res.casa_attiva));
      window.location.href = 'app.html';
    } catch (err) {
      document.getElementById('loginErrore').textContent = err.message;
    }
  });

  // Registrazione
  document.getElementById('registerForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const nome = document.getElementById('regNome').value;
    const email = document.getElementById('regEmail').value;
    const pass = document.getElementById('regPass').value;
    const pass2 = document.getElementById('regPass2').value;

    if (pass !== pass2) {
      document.getElementById('regErrore').textContent = 'Le password non corrispondono.';
      return;
    }

    try { 
      const res = await api('/api/auth.php?azione=registrazione', {
        method: 'POST',
        body: JSON.stringify({ nome, email, password: pass, csrf_token: await csrfFresco() })
      });
      //sessionStorage.setItem('utente', JSON.stringify(res.utente));
      // sessionStorage.setItem('casa_default', JSON.stringify(res.casa_default));
      //window.location.href = 'app.html';
      document.getElementById('regSuccesso').textContent = res?.messaggio;
      window.location.href = res?.redirect;
	  
    } catch (err) {
      document.getElementById('regErrore').textContent = err.message;
    }
  });
});