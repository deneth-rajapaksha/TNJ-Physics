// auth-utils.js - Authentication utilities for protected pages
class AuthManager {
  constructor() {
    this.tokenKey = 'tnj_token';
    this.userKey = 'tnj_user';
    this.baseUrl = window.location.origin;
  }

  // Check if user is logged in
  isAuthenticated() {
    return !!this.getToken();
  }

  // Get stored token
  getToken() {
    return localStorage.getItem(this.tokenKey);
  }

  // Get user info
  getUserInfo() {
    return {
      displayName: localStorage.getItem(this.userKey),
      userId: localStorage.getItem('tnj_user_id'),
      email: localStorage.getItem('tnj_user_email'),
      loginTime: localStorage.getItem('tnj_login_time')
    };
  }

  // Make authenticated API request
  async apiRequest(endpoint, options = {}) {
    const token = this.getToken();
    
    if (!token) {
      throw new Error('Not authenticated');
    }

    const headers = {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`,
      ...options.headers
    };

    const resp = await fetch(`${this.baseUrl}/wp-json${endpoint}`, {
      ...options,
      headers,
      credentials: 'include'
    });

    // Token might be expired
    if (resp.status === 401 || resp.status === 403) {
      this.logout();
      throw new Error('Session expired. Please login again.');
    }

    return resp.json();
  }

  // Validate token with server
  async validateToken() {
    try {
      const result = await this.apiRequest('/jwt-auth/v1/token/validate', {
        method: 'POST'
      });
      return result.code === 'jwt_auth_valid_token';
    } catch {
      return false;
    }
  }

  // Logout
  logout() {
    localStorage.removeItem(this.tokenKey);
    localStorage.removeItem(this.userKey);
    localStorage.removeItem('tnj_user_id');
    localStorage.removeItem('tnj_user_email');
    localStorage.removeItem('tnj_login_time');
    window.location.href = '/login.html';
  }

  // Protect route - redirect to login if not authenticated
  requireAuth() {
    if (!this.isAuthenticated()) {
      window.location.href = '/login.html';
    }
  }
}

// Create global auth manager
const auth = new AuthManager();