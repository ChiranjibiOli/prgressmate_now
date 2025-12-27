// ProgressMate - Main JavaScript File

// Document ready function
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    initTooltips();
    
    // Initialize dropdowns
    initDropdowns();
    
    // Initialize modals
    initModals();
    
    // Initialize form validation
    initFormValidation();
    
    // Initialize notifications
    initNotifications();
    
    // Auto-dismiss alerts
    initAutoDismissAlerts();
    
    // Initialize mobile menu
    initMobileMenu();
    
    // Initialize progress bars
    initProgressBars();
    
    // Initialize charts
    initCharts();
});

// Tooltip initialization
function initTooltips() {
    const tooltips = document.querySelectorAll('[data-toggle="tooltip"]');
    tooltips.forEach(tooltip => {
        tooltip.addEventListener('mouseenter', function() {
            const title = this.getAttribute('title');
            const tooltipEl = document.createElement('div');
            tooltipEl.className = 'custom-tooltip';
            tooltipEl.textContent = title;
            document.body.appendChild(tooltipEl);
            
            const rect = this.getBoundingClientRect();
            tooltipEl.style.position = 'fixed';
            tooltipEl.style.top = (rect.top - tooltipEl.offsetHeight - 10) + 'px';
            tooltipEl.style.left = (rect.left + (rect.width - tooltipEl.offsetWidth) / 2) + 'px';
            
            this.setAttribute('title', '');
        });
        
        tooltip.addEventListener('mouseleave', function() {
            const tooltipEl = document.querySelector('.custom-tooltip');
            if (tooltipEl) {
                tooltipEl.remove();
            }
            this.setAttribute('title', this.getAttribute('data-original-title') || '');
        });
    });
}

// Dropdown initialization
function initDropdowns() {
    const dropdowns = document.querySelectorAll('.dropdown-toggle');
    dropdowns.forEach(dropdown => {
        dropdown.addEventListener('click', function(e) {
            e.preventDefault();
            const menu = this.nextElementSibling;
            menu.classList.toggle('show');
        });
    });
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.matches('.dropdown-toggle')) {
            const dropdowns = document.querySelectorAll('.dropdown-menu');
            dropdowns.forEach(menu => {
                menu.classList.remove('show');
            });
        }
    });
}

// Modal initialization
function initModals() {
    const modalTriggers = document.querySelectorAll('[data-toggle="modal"]');
    const modals = document.querySelectorAll('.modal');
    
    modalTriggers.forEach(trigger => {
        trigger.addEventListener('click', function() {
            const modalId = this.getAttribute('data-target');
            const modal = document.querySelector(modalId);
            if (modal) {
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            }
        });
    });
    
    // Close modal when clicking X or outside
    modals.forEach(modal => {
        const closeBtn = modal.querySelector('.close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            });
        }
        
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        });
    });
}

// Form validation
function initFormValidation() {
    const forms = document.querySelectorAll('.needs-validation');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });
}

// Notifications
function initNotifications() {
    // Request notification permission
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }
    
    // Show notification if permission granted
    window.showNotification = function(title, body, icon = '/assets/img/logo.png') {
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification(title, { body, icon });
        }
    };
}

// Auto-dismiss alerts
function initAutoDismissAlerts() {
    const alerts = document.querySelectorAll('.alert-auto-dismiss');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.remove();
            }, 300);
        }, 5000);
    });
}

// Mobile menu
function initMobileMenu() {
    const menuToggle = document.getElementById('mobileMenuToggle');
    const menu = document.getElementById('mobileMenu');
    const closeBtn = document.getElementById('mobileMenuClose');
    
    if (menuToggle && menu) {
        menuToggle.addEventListener('click', function() {
            menu.classList.add('show');
            document.body.style.overflow = 'hidden';
        });
        
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                menu.classList.remove('show');
                document.body.style.overflow = 'auto';
            });
        }
        
        // Close menu when clicking outside
        menu.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('show');
                document.body.style.overflow = 'auto';
            }
        });
    }
}

// Progress bars
function initProgressBars() {
    const progressBars = document.querySelectorAll('.progress-bar[data-width]');
    progressBars.forEach(bar => {
        const width = bar.getAttribute('data-width');
        bar.style.width = width + '%';
        
        // Animate progress bar
        if (bar.classList.contains('animate')) {
            bar.style.transition = 'width 1s ease-in-out';
        }
    });
}

// Charts initialization
function initCharts() {
    // Simple chart implementation
    // In a real project, you would use a library like Chart.js
    
    const chartContainers = document.querySelectorAll('.progress-chart');
    chartContainers.forEach(container => {
        const data = JSON.parse(container.getAttribute('data-chart') || '{}');
        if (data.type === 'progress') {
            renderProgressChart(container, data);
        } else if (data.type === 'line') {
            renderLineChart(container, data);
        }
    });
}

function renderProgressChart(container, data) {
    const canvas = document.createElement('canvas');
    container.appendChild(canvas);
    
    // Simple progress circle
    const ctx = canvas.getContext('2d');
    const size = Math.min(container.offsetWidth, 200);
    canvas.width = size;
    canvas.height = size;
    
    const center = size / 2;
    const radius = size / 2 - 10;
    const progress = data.value || 0;
    
    // Draw background circle
    ctx.beginPath();
    ctx.arc(center, center, radius, 0, Math.PI * 2);
    ctx.strokeStyle = '#e5e7eb';
    ctx.lineWidth = 10;
    ctx.stroke();
    
    // Draw progress arc
    ctx.beginPath();
    ctx.arc(center, center, radius, -Math.PI / 2, (-Math.PI / 2) + (Math.PI * 2 * progress / 100));
    ctx.strokeStyle = data.color || '#4f46e5';
    ctx.lineWidth = 10;
    ctx.stroke();
    
    // Draw percentage text
    ctx.fillStyle = '#111827';
    ctx.font = 'bold 24px Inter, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(progress + '%', center, center);
}

// Utility functions
window.ProgressMate = {
    // Format date
    formatDate: function(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    },
    
    // Format time
    formatTime: function(dateString) {
        const date = new Date(dateString);
        return date.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit'
        });
    },
    
    // Format datetime
    formatDateTime: function(dateString) {
        return this.formatDate(dateString) + ' ' + this.formatTime(dateString);
    },
    
    // Calculate time ago
    timeAgo: function(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const seconds = Math.floor((now - date) / 1000);
        
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
                return interval + ' ' + unit + (interval > 1 ? 's' : '') + ' ago';
            }
        }
        
        return 'just now';
    },
    
    // Debounce function
    debounce: function(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },
    
    // Throttle function
    throttle: function(func, limit) {
        let inThrottle;
        return function() {
            const args = arguments;
            const context = this;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    },
    
    // Copy to clipboard
    copyToClipboard: function(text) {
        navigator.clipboard.writeText(text).then(() => {
            this.showToast('Copied to clipboard!', 'success');
        }).catch(err => {
            console.error('Failed to copy: ', err);
            this.showToast('Failed to copy to clipboard', 'error');
        });
    },
    
    // Show toast notification
    showToast: function(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        
        const style = document.createElement('style');
        style.textContent = `
            .toast {
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 12px 20px;
                border-radius: 8px;
                color: white;
                z-index: 9999;
                animation: slideInRight 0.3s ease;
            }
            .toast-info { background: #3b82f6; }
            .toast-success { background: #10b981; }
            .toast-error { background: #ef4444; }
            .toast-warning { background: #f59e0b; }
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        `;
        document.head.appendChild(style);
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => {
                toast.remove();
                style.remove();
            }, 300);
        }, 3000);
    },
    
    // Confirm dialog
    confirm: function(message, callback) {
        const modal = document.createElement('div');
        modal.className = 'confirm-modal';
        modal.innerHTML = `
            <div class="confirm-dialog">
                <div class="confirm-body">${message}</div>
                <div class="confirm-actions">
                    <button class="btn btn-outline cancel">Cancel</button>
                    <button class="btn btn-primary confirm">OK</button>
                </div>
            </div>
        `;
        
        const style = document.createElement('style');
        style.textContent = `
            .confirm-modal {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 9999;
            }
            .confirm-dialog {
                background: white;
                padding: 24px;
                border-radius: 12px;
                max-width: 400px;
                width: 90%;
            }
            .confirm-body {
                margin-bottom: 20px;
                color: #374151;
            }
            .confirm-actions {
                display: flex;
                gap: 10px;
                justify-content: flex-end;
            }
        `;
        
        document.head.appendChild(style);
        document.body.appendChild(modal);
        
        modal.querySelector('.cancel').addEventListener('click', () => {
            modal.remove();
            style.remove();
        });
        
        modal.querySelector('.confirm').addEventListener('click', () => {
            callback();
            modal.remove();
            style.remove();
        });
    },
    
    // Loading indicator
    showLoading: function() {
        const loader = document.createElement('div');
        loader.className = 'loading-overlay';
        loader.innerHTML = '<div class="spinner"></div>';
        
        const style = document.createElement('style');
        style.textContent = `
            .loading-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(255,255,255,0.8);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 9999;
            }
            .spinner {
                width: 40px;
                height: 40px;
                border: 4px solid #f3f4f6;
                border-top-color: #4f46e5;
                border-radius: 50%;
                animation: spin 1s linear infinite;
            }
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
        `;
        
        document.head.appendChild(style);
        document.body.appendChild(loader);
        
        return {
            hide: function() {
                loader.remove();
                style.remove();
            }
        };
    }
};

// AJAX helper
window.ajaxRequest = function(url, options = {}) {
    const defaults = {
        method: 'GET',
        data: null,
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
    };
    
    const config = { ...defaults, ...options };
    
    return fetch(url, {
        method: config.method,
        headers: config.headers,
        body: config.data ? JSON.stringify(config.data) : null,
        credentials: config.credentials
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    });
};

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

function init() {
    // Add any additional initialization here
}

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ProgressMate;
}