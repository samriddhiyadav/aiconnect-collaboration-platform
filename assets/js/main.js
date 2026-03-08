/**
 * TeamSphere - Common JavaScript Utilities
 * Contains reusable functions for the entire application
 */

// DOM Ready Handler
document.addEventListener('DOMContentLoaded', function() {
    // Initialize common components
    initTooltips();
    initModals();
    initFormValidations();
    
    // Set up AJAX CSRF token for all requests
    setupCSRFToken();
});

// =============================================
// UI Utilities
// =============================================

/**
 * Initialize Bootstrap tooltips
 */
function initTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

/**
 * Initialize all modals with enhanced functionality
 */
function initModals() {
    // Auto-focus first input in modals when shown
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('shown.bs.modal', function() {
            const input = modal.querySelector('input:not([type="hidden"]), textarea, select');
            if (input) input.focus();
        });
    });
}

/**
 * Show loading spinner
 * @param {HTMLElement} element - Element to show spinner in (defaults to document body)
 */
function showLoading(element = document.body) {
    const spinnerId = 'loading-spinner';
    let spinner = document.getElementById(spinnerId);
    
    if (!spinner) {
        spinner = document.createElement('div');
        spinner.id = spinnerId;
        spinner.className = 'loading-spinner';
        spinner.innerHTML = `
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        `;
        spinner.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        `;
        document.body.appendChild(spinner);
    }
    
    spinner.style.display = 'flex';
}

/**
 * Hide loading spinner
 */
function hideLoading() {
    const spinner = document.getElementById('loading-spinner');
    if (spinner) spinner.style.display = 'none';
}

/**
 * Show toast notification
 * @param {string} message - Message to display
 * @param {string} type - Type of toast (success, error, warning, info)
 * @param {number} duration - Duration in milliseconds (default: 5000)
 */
function showToast(message, type = 'info', duration = 5000) {
    const toastContainer = document.getElementById('toast-container') || createToastContainer();
    const toastId = 'toast-' + Date.now();
    
    const toast = document.createElement('div');
    toast.id = toastId;
    toast.className = `toast show align-items-center text-white bg-${type} border-0`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    `;
    
    toastContainer.appendChild(toast);
    
    // Auto-remove after duration
    setTimeout(() => {
        const bsToast = bootstrap.Toast.getOrCreateInstance(toast);
        bsToast.hide();
        toast.addEventListener('hidden.bs.toast', () => toast.remove());
    }, duration);
}

function createToastContainer() {
    const container = document.createElement('div');
    container.id = 'toast-container';
    container.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 1100;
        min-width: 250px;
    `;
    document.body.appendChild(container);
    return container;
}

// =============================================
// Form Handling Utilities
// =============================================

/**
 * Initialize form validations
 */
function initFormValidations() {
    // Enable Bootstrap validation
    document.querySelectorAll('.needs-validation').forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            
            form.classList.add('was-validated');
        }, false);
    });
    
    // Add password strength indicator
    document.querySelectorAll('[data-password-strength]').forEach(input => {
        input.addEventListener('input', function() {
            checkPasswordStrength(this);
        });
    });
}

/**
 * Check password strength and update indicator
 * @param {HTMLInputElement} input - Password input element
 */
function checkPasswordStrength(input) {
    const password = input.value;
    const strengthIndicator = document.getElementById(input.getAttribute('data-password-strength'));
    
    if (!strengthIndicator || !password) return;
    
    // Calculate strength
    let strength = 0;
    if (password.length >= 8) strength++;
    if (password.match(/[a-z]/)) strength++;
    if (password.match(/[A-Z]/)) strength++;
    if (password.match(/[0-9]/)) strength++;
    if (password.match(/[^a-zA-Z0-9]/)) strength++;
    
    // Update UI
    const strengthText = ['Very Weak', 'Weak', 'Moderate', 'Strong', 'Very Strong'];
    const strengthClass = ['danger', 'warning', 'info', 'success', 'success'];
    
    strengthIndicator.textContent = strengthText[strength - 1] || '';
    strengthIndicator.className = `text-${strengthClass[strength - 1] || 'muted'}`;
}

/**
 * Handle form submission via AJAX
 * @param {HTMLFormElement} form - Form element
 * @param {function} callback - Callback function after successful submission
 */
function submitFormAjax(form, callback) {
    showLoading();
    
    const formData = new FormData(form);
    const action = form.getAttribute('action') || window.location.href;
    const method = form.getAttribute('method') || 'POST';
    
    fetch(action, {
        method: method,
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) throw new Error('Network response was not ok');
        return response.json();
    })
    .then(data => {
        if (data.success) {
            if (data.message) showToast(data.message, 'success');
            if (typeof callback === 'function') callback(data);
        } else {
            showToast(data.message || 'An error occurred', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('An error occurred while processing your request', 'error');
    })
    .finally(() => {
        hideLoading();
    });
}

// =============================================
// API Utilities
// =============================================

/**
 * Setup CSRF token for AJAX requests
 */
function setupCSRFToken() {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (token) {
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': token
            }
        });
    }
}

/**
 * Make an AJAX request
 * @param {string} url - API endpoint
 * @param {string} method - HTTP method (GET, POST, etc.)
 * @param {object} data - Data to send
 * @param {function} callback - Callback function
 */
function makeRequest(url, method = 'GET', data = {}, callback = null) {
    showLoading();
    
    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    };
    
    if (method !== 'GET') {
        options.body = JSON.stringify(data);
    } else if (Object.keys(data).length > 0) {
        url += '?' + new URLSearchParams(data).toString();
    }
    
    fetch(url, options)
    .then(response => {
        if (!response.ok) throw new Error('Network response was not ok');
        return response.json();
    })
    .then(data => {
        if (typeof callback === 'function') callback(data);
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('An error occurred while processing your request', 'error');
    })
    .finally(() => {
        hideLoading();
    });
}

// =============================================
// Data Handling Utilities
// =============================================

/**
 * Format date to readable string
 * @param {string|Date} date - Date to format
 * @param {boolean} withTime - Include time in output
 * @returns {string} Formatted date string
 */
function formatDate(date, withTime = false) {
    if (!date) return '';
    
    const d = new Date(date);
    if (isNaN(d.getTime())) return '';
    
    const options = {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    };
    
    if (withTime) {
        options.hour = '2-digit';
        options.minute = '2-digit';
    }
    
    return d.toLocaleDateString(undefined, options);
}

/**
 * Format time ago (e.g., "2 hours ago")
 * @param {string|Date} date - Date to format
 * @returns {string} Time ago string
 */
function timeAgo(date) {
    if (!date) return '';
    
    const d = new Date(date);
    if (isNaN(d.getTime())) return '';
    
    const seconds = Math.floor((new Date() - d) / 1000);
    const intervals = {
        year: 31536000,
        month: 2592000,
        week: 604800,
        day: 86400,
        hour: 3600,
        minute: 60,
        second: 1
    };
    
    for (const [unit, secondsInUnit] of Object.entries(intervals)) {
        const interval = Math.floor(seconds / secondsInUnit);
        if (interval >= 1) {
            return interval === 1 ? `${interval} ${unit} ago` : `${interval} ${unit}s ago`;
        }
    }
    
    return 'just now';
}

/**
 * Debounce function to limit how often a function can be called
 * @param {function} func - Function to debounce
 * @param {number} wait - Wait time in milliseconds
 * @returns {function} Debounced function
 */
function debounce(func, wait = 300) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

// =============================================
// Theme-Specific Utilities
// =============================================

/**
 * Initialize theme-specific animations
 */
function initThemeAnimations() {
    // Add orbit animation to elements with data-orbit attribute
    document.querySelectorAll('[data-orbit]').forEach(el => {
        const speed = el.dataset.orbitSpeed || '20s';
        const radius = el.dataset.orbitRadius || '100px';
        
        el.style.animation = `orbit ${speed} linear infinite`;
        el.style.setProperty('--orbit-radius', radius);
    });
    
    // Add pulse animation to notification elements
    document.querySelectorAll('.notification-badge').forEach(el => {
        el.style.animation = 'pulse 2s infinite';
    });
}

/**
 * Toggle dark/light theme
 */
function toggleTheme() {
    const html = document.documentElement;
    const currentTheme = html.getAttribute('data-bs-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    
    html.setAttribute('data-bs-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    
    // Dispatch event for other components to react
    document.dispatchEvent(new CustomEvent('themeChange', { detail: newTheme }));
}

// Check for saved theme preference
function loadThemePreference() {
    const savedTheme = localStorage.getItem('theme') || 
                      (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    document.documentElement.setAttribute('data-bs-theme', savedTheme);
}

// Initialize theme on load
loadThemePreference();

// =============================================
// Event Listeners
// =============================================

// Theme toggle button
document.addEventListener('click', function(e) {
    if (e.target.matches('[data-theme-toggle]')) {
        toggleTheme();
    }
});

// Export functions that should be available globally
window.TeamSphere = {
    showLoading,
    hideLoading,
    showToast,
    submitFormAjax,
    makeRequest,
    formatDate,
    timeAgo,
    debounce,
    toggleTheme
};