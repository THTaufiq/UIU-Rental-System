/**
 * UIU Student Rental System - Core Vanilla JavaScript (Phase 1)
 */

// --- Demo User Profiles ---
const ROLE_PROFILES = {
  student:  { role: 'student',  name: 'Rakib Hassan', initials: 'RH', email: 'rakib@uiu.ac.bd', dashboardPage: 'pages/student-dashboard.html' },
  landlord: { role: 'landlord', name: 'Kamal Ahmed', initials: 'KA', email: 'kamal@gmail.com',  dashboardPage: 'pages/landlord-dashboard.html' },
  admin:    { role: 'admin',    name: 'Admin User',  initials: 'AD', email: 'admin@uiu.ac.bd',  dashboardPage: 'pages/admin-dashboard.html' },
};

const AVATAR_BG = {
  student:  '#2563EB',
  landlord: '#059669',
  admin:    '#7C3AED',
};

// --- Helper to Compute Initials Dynamically ---
function getInitials(name) {
  if (!name || typeof name !== 'string') return 'U';
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return 'U';
  if (parts.length === 1) {
    return parts[0].substring(0, 2).toUpperCase();
  }
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

// --- Authentication Session Helper ---
function getAuthUser() {
  const stored = localStorage.getItem('uiu_auth_user');
  if (!stored) return null;
  try {
    const user = JSON.parse(stored);
    if (user) {
      const name = user.full_name || user.name || '';
      user.initials = getInitials(name);
    }
    return user;
  } catch (e) {
    return null;
  }
}

function setAuthUser(userObj) {
  if (!userObj) return;

  const stored = localStorage.getItem('uiu_auth_user');
  let existingAuth = null;
  if (stored) {
    try { existingAuth = JSON.parse(stored); } catch (e) {}
  }

  if (typeof userObj === 'string') {
    const role = userObj;
    if (existingAuth && existingAuth.role === role && existingAuth.full_name && existingAuth.full_name !== 'Landlord User' && existingAuth.full_name !== 'User') {
      userObj = { ...existingAuth };
    } else if (ROLE_PROFILES[role]) {
      userObj = { ...ROLE_PROFILES[role] };
    } else {
      userObj = { role: role, name: 'User', email: '' };
    }
  }

  const role = userObj.role || (existingAuth ? existingAuth.role : 'student');
  let name = userObj.full_name || userObj.name;
  if (!name && existingAuth && existingAuth.role === role && existingAuth.full_name && existingAuth.full_name !== 'Landlord User' && existingAuth.full_name !== 'User') {
    name = existingAuth.full_name;
  }
  if (!name && ROLE_PROFILES[role]) {
    name = ROLE_PROFILES[role].name;
  }
  if (!name) name = 'User';

  let email = userObj.email;
  if (email === undefined || email === null) {
    if (existingAuth && existingAuth.role === role && existingAuth.email) {
      email = existingAuth.email;
    } else if (ROLE_PROFILES[role]) {
      email = ROLE_PROFILES[role].email;
    } else {
      email = '';
    }
  }

  const initials = getInitials(name);
  const dashboardPage = userObj.dashboardPage || (existingAuth ? existingAuth.dashboardPage : (ROLE_PROFILES[role] ? ROLE_PROFILES[role].dashboardPage : (role + '-dashboard.html')));

  const authData = {
    user_id: userObj.user_id !== undefined && userObj.user_id !== null ? userObj.user_id : (existingAuth ? existingAuth.user_id : null),
    role: role,
    name: name,
    full_name: name,
    initials: initials,
    email: email,
    avatar_url: userObj.avatar_url || userObj.avatar || (existingAuth ? existingAuth.avatar_url : null),
    dashboardPage: dashboardPage
  };

  localStorage.setItem('uiu_auth_user', JSON.stringify(authData));
  localStorage.setItem('uiu_user_role', role);
  localStorage.setItem('uiu_user_name', name);
}

function logoutUser() {
  localStorage.removeItem('uiu_auth_user');
  localStorage.removeItem('uiu_user_role');
  localStorage.removeItem('uiu_user_name');
  window.location.href = window.location.pathname.includes('/pages/') ? 'login.html' : 'pages/login.html';
}

function getBasePath() {
  return window.location.pathname.includes('/pages/') ? '../' : './';
}

// --- Initialize Page Layout & Components ---
function initApp() {
  initNavbar();
  initSidebar();
  initTabs();
  initModals();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initApp);
} else {
  initApp();
}

// --- Navbar Initialization ---
function initNavbar() {
  const dropBtn = document.getElementById('user-avatar-btn');
  const dropMenu = document.getElementById('user-dropdown-menu');
  const hamburgerBtn = document.getElementById('nav-hamburger-btn');
  const mobileMenu = document.getElementById('nav-mobile-menu');

  if (dropBtn && dropMenu) {
    dropBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isVisible = dropMenu.style.display === 'block';
      dropMenu.style.display = isVisible ? 'none' : 'block';
    });

    document.addEventListener('click', () => {
      dropMenu.style.display = 'none';
    });
  }

  if (hamburgerBtn && mobileMenu) {
    hamburgerBtn.addEventListener('click', () => {
      const isVisible = mobileMenu.style.display === 'block';
      mobileMenu.style.display = isVisible ? 'none' : 'block';
    });
  }

  // Update header based on logged-in user
  updateHeaderUI();
}

function updateHeaderUI() {
  const authUser = getAuthUser();
  const guestActions = document.getElementById('nav-guest-actions');
  const authActions = document.getElementById('nav-auth-actions');
  const avatarBtn = document.getElementById('user-avatar-btn');
  const userInitials = document.getElementById('user-avatar-initials');
  const userNameEl = document.getElementById('user-name-display');
  const userEmailEl = document.getElementById('user-email-display');
  const dashShortcutBtn = document.getElementById('nav-dashboard-shortcut');

  if (authUser) {
    const name = authUser.full_name || authUser.name || '';
    const computedInitials = getInitials(name);
    const email = authUser.email || '';

    if (guestActions) guestActions.style.display = 'none';
    if (authActions) authActions.style.display = 'flex';
    if (userInitials) userInitials.textContent = computedInitials;
    if (avatarBtn) avatarBtn.style.background = AVATAR_BG[authUser.role] || '#2563EB';
    if (userNameEl) userNameEl.textContent = name;
    if (userEmailEl) userEmailEl.textContent = email;
    if (dashShortcutBtn) {
      dashShortcutBtn.textContent = authUser.role.charAt(0).toUpperCase() + authUser.role.slice(1) + ' Dashboard';
      dashShortcutBtn.onclick = () => { window.location.href = getBasePath() + authUser.dashboardPage; };
    }

    // Topbar avatars across student, landlord, admin
    const topbarAvatars = document.querySelectorAll(
      '#user-topbar-avatar, #landlord-topbar-avatar, #admin-topbar-avatar, .topbar-avatar, ' +
      'a[href="profile.html"]:not(.sidebar-link), a[href="landlord-profile.html"]:not(.sidebar-link), a[href="admin-profile.html"]:not(.sidebar-link)'
    );
    topbarAvatars.forEach(el => {
      if (authUser.avatar_url) {
        el.innerHTML = `<img src="${authUser.avatar_url}" alt="Avatar" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">`;
      } else {
        el.textContent = computedInitials;
      }
    });

    // Sidebar avatars across student, landlord, admin
    const sidebarAvatars = document.querySelectorAll('#user-sidebar-avatar, #landlord-sidebar-avatar, #admin-sidebar-avatar');
    sidebarAvatars.forEach(el => {
      if (authUser.avatar_url) {
        el.innerHTML = `<img src="${authUser.avatar_url}" alt="Avatar" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">`;
      } else {
        el.textContent = computedInitials;
      }
    });

    // Sidebar names across student, landlord, admin
    const sidebarNames = document.querySelectorAll('#user-sidebar-name, #landlord-sidebar-name, #admin-sidebar-name');
    sidebarNames.forEach(el => {
      el.textContent = name;
    });

    // Sidebar emails across student, landlord, admin
    const sidebarEmails = document.querySelectorAll('#user-sidebar-email, #landlord-sidebar-email, #admin-sidebar-email');
    sidebarEmails.forEach(el => {
      if (email) el.textContent = email;
    });
  } else {
    if (guestActions) guestActions.style.display = 'flex';
    if (authActions) authActions.style.display = 'none';
  }

  // Automatic backend session verification for landlord portal pages
  if (window.location.pathname.includes('/pages/')) {
    const isLandlordPage = window.location.pathname.includes('landlord-') || window.location.pathname.includes('add-property');
    if (isLandlordPage && !window._fetchingProfile) {
      window._fetchingProfile = true;
      fetch('../../backend/landlord/profile.php')
        .then(res => res.ok ? res.json() : null)
        .then(result => {
          if (result && result.success && result.data && result.data.user) {
            const backendUser = result.data.user;
            const currentAuth = getAuthUser();
            if (!currentAuth || currentAuth.user_id !== backendUser.user_id || currentAuth.full_name !== backendUser.full_name) {
              setAuthUser({
                role: 'landlord',
                full_name: backendUser.full_name,
                email: backendUser.email,
                user_id: backendUser.user_id,
                avatar_url: backendUser.avatar_url
              });
              const freshUser = getAuthUser();
              if (freshUser) {
                const freshName = freshUser.full_name || freshUser.name || '';
                const freshInitials = getInitials(freshName);
                const freshEmail = freshUser.email || '';

                document.querySelectorAll('#landlord-sidebar-name, #user-sidebar-name').forEach(el => el.textContent = freshName);
                document.querySelectorAll('#landlord-sidebar-email, #user-sidebar-email').forEach(el => el.textContent = freshEmail);
                document.querySelectorAll('#landlord-sidebar-avatar, #user-sidebar-avatar, #landlord-topbar-avatar, #user-topbar-avatar, .topbar-avatar').forEach(el => {
                  if (freshUser.avatar_url) {
                    el.innerHTML = `<img src="${freshUser.avatar_url}" alt="Avatar" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">`;
                  } else {
                    el.textContent = freshInitials;
                  }
                });
              }
            }
          }
        })
        .catch(() => {})
        .finally(() => { window._fetchingProfile = false; });
    }
  }
}

// --- Sidebar Initialization ---
function initSidebar() {
  const dashHamburger = document.getElementById('dash-hamburger');
  const sidebar = document.querySelector('.sidebar');

  if (dashHamburger && sidebar) {
    dashHamburger.addEventListener('click', () => {
      sidebar.classList.toggle('mobile-open');
    });
  }
}

// --- Tab Switcher ---
function initTabs() {
  const tabButtons = document.querySelectorAll('[data-tab-target]');
  tabButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const targetId = btn.getAttribute('data-tab-target');
      const container = btn.closest('.tab-wrapper') || document;

      // Deactivate all sibling tabs
      const parentTabList = btn.parentElement;
      if (parentTabList) {
        parentTabList.querySelectorAll('[data-tab-target]').forEach(b => b.classList.remove('active'));
      }
      btn.classList.add('active');

      // Hide all contents and show target
      container.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
        content.style.display = 'none';
      });

      const targetEl = document.getElementById(targetId);
      if (targetEl) {
        targetEl.classList.add('active');
        targetEl.style.display = 'block';
      }
    });
  });
}

// --- Modal Helper Functions ---
function initModals() {
  document.querySelectorAll('[data-modal-open]').forEach(btn => {
    btn.addEventListener('click', () => {
      const targetId = btn.getAttribute('data-modal-open');
      openModal(targetId);
    });
  });

  document.querySelectorAll('[data-modal-close]').forEach(btn => {
    btn.addEventListener('click', () => {
      const modal = btn.closest('.modal-overlay');
      if (modal) closeModal(modal.id);
    });
  });

  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) {
        closeModal(overlay.id);
      }
    });
  });
}

function openModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) {
    modal.classList.add('active');
    modal.style.display = 'flex';
  }
}

function closeModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) {
    modal.classList.remove('active');
    modal.style.display = 'none';
  }
}

// Global Toast helper
function showToast(message, type = 'success') {
  const toast = document.createElement('div');
  toast.className = 'fade-in';
  toast.style.cssText = `
    position: fixed; top: 20px; right: 20px; z-index: 9999;
    background: ${type === 'success' ? '#F0FDF4' : '#FEF2F2'};
    border: 1px solid ${type === 'success' ? '#86EFAC' : '#FECACA'};
    color: ${type === 'success' ? '#166534' : '#991B1B'};
    padding: 12px 20px; border-radius: 10px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
    font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px;
    font-family: 'Poppins', sans-serif; max-width: 380px;
  `;
  toast.innerHTML = `<span style="font-size: 18px;">${type === 'success' ? '✅' : '❌'}</span> ${message}`;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 3000);
}

// Global FAQ Accordion Toggle helper
function toggleFaq(btn) {
  const item = btn.closest('.faq-item') || btn.parentElement;
  const icon = btn.querySelector('.faq-icon');
  const isActive = item.classList.contains('active');

  document.querySelectorAll('.faq-item').forEach(el => {
    el.classList.remove('active');
    const ic = el.querySelector('.faq-icon');
    if (ic) ic.textContent = '+';
  });

  if (!isActive) {
    item.classList.add('active');
    if (icon) icon.textContent = '−';
  }
}

