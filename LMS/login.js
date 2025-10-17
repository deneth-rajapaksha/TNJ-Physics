// login.js - JWT Authentication with Student Portal
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('loginForm');
  const error = document.getElementById('error');
  const forgot = document.getElementById('forgot');

  // Debug mode - show demo credentials button in development
  const isDev = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
  if (isDev) {
    document.getElementById('demoCreds').classList.remove('hidden');
  }

  forgot.addEventListener('click', () => {
    alert('Forgot password? Contact the admin at: admin@yourdomain.com');
  });

  // Demo credentials for testing
  document.getElementById('demoCreds').addEventListener('click', () => {
    document.getElementById('username').value = 'student1';
    document.getElementById('password').value = 'password123';
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    error.classList.add('hidden');

    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value;

    if (!username || !password) {
      showError('Please fill both fields.');
      return;
    }

    const btn = document.getElementById('loginBtn');
    btn.disabled = true;
    btn.textContent = 'Authenticating...';

    try {
      // WordPress on physics-with-tharuka.local, frontend on localhost:5500
      const wpUrl = 'http://physics-with-tharuka.local';
      
      const resp = await fetch(`${wpUrl}/wp-json/jwt-auth/v1/token`, {
        method: 'POST',
        headers: { 
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ username, password })
      });

      const data = await resp.json();

      if (!resp.ok) {
        const msg = data?.message || 'Login failed. Check your credentials.';
        showError(msg);
        btn.disabled = false;
        btn.textContent = 'LOGIN';
        return;
      }

      // Successful authentication
      if (data.token) {
        // Store authentication data
        localStorage.setItem('tnj_token', data.token);
        localStorage.setItem('tnj_user', data.user_display_name || data.user_nicename || username);
        localStorage.setItem('tnj_user_id', data.user_id || '');
        localStorage.setItem('tnj_user_email', data.user_email || '');
        localStorage.setItem('tnj_login_time', new Date().getTime());

        // Redirect to dashboard in lms folder
        window.location.href = './dashboard.html';
      } else {
        showError('Authentication failed. Please try again.');
        btn.disabled = false;
        btn.textContent = 'LOGIN';
      }
    } catch (err) {
      console.error('Login error:', err);
      showError('Network error. Make sure the WordPress server is running.');
      btn.disabled = false;
      btn.textContent = 'LOGIN';
    }
  });

  function showError(msg) {
    error.textContent = msg;
    error.classList.remove('hidden');
  }
});