/* ============================================================
   SuperMa POS - Main Application
   ============================================================ */

const App = (() => {
    let state = {
        user: null,
        roles: [],
        permissions: [],
        currentView: 'dashboard',
        loading: false,
        sidebarOpen: false,
        pageTitle: 'Tableau de bord',
        currentPage: {},
        sse: null,
    };

    const $ = (sel, ctx = document) => ctx.querySelector(sel);
    const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

    const NAV_ITEMS = [
        { id: 'dashboard',  label: 'Tableau de bord', icon: '\u2302' },
        { id: 'products',   label: 'Produits',       icon: '\uD83D\uDCE6' },
        { id: 'categories', label: 'Catégories',     icon: '\uD83D\uDCC1' },
        { id: 'customers',  label: 'Clients',        icon: '\uD83D\uDC65', roles: ['manager', 'cashier'] },
        { id: 'suppliers',  label: 'Fournisseurs',   icon: '\uD83D\uDE9A', roles: ['manager', 'stock_keeper'] },
        { id: 'stock',      label: 'Stock',          icon: '\uD83D\uDCCA', roles: ['manager', 'stock_keeper'] },
        { id: 'orders',     label: 'Commandes',      icon: '\uD83D\uDCE6', roles: ['manager', 'stock_keeper'] },
        { id: 'sales',      label: 'Ventes',         icon: '\uD83D\uDCCB', roles: ['manager', 'cashier'] },
        { id: 'users',      label: 'Utilisateurs',   icon: '\uD83D\uDC64', adminOnly: true },
        { id: 'stores',     label: 'Magasins',       icon: '\uD83C\uDFEA', adminOnly: true },
        { id: 'logs',       label: 'Journaux',       icon: '\uD83D\uDCDD', adminOnly: true },
    ];

    function hasPermission(code) {
        if (!code) return true;
        return state.permissions.includes(code);
    }

    function isAdmin() {
        return state.roles.includes('admin');
    }

    function canView(item) {
        if (item.adminOnly) return isAdmin();
        if (item.roles) return isAdmin() || item.roles.some(r => state.roles.includes(r));
        return true;
    }

    function getDefaultPage() {
        if (state.roles.includes('cashier')) return 'sales';
        if (state.roles.includes('stock_keeper')) return 'stock';
        return 'dashboard';
    }

    function showToast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            toast.style.transition = 'all 300ms ease';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    function createModal({ title, content, size = '', footer = '' } = {}) {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal ${size}">
                <div class="modal-header">
                    <h2>${title}</h2>
                    <button class="modal-close" data-close-modal>&times;</button>
                </div>
                <div class="modal-body">${content}</div>
                ${footer ? `<div class="modal-footer">${footer}</div>` : ''}
            </div>
        `;

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) overlay.remove();
        });
        overlay.querySelector('[data-close-modal]').addEventListener('click', () => overlay.remove());

        document.body.appendChild(overlay);
        return overlay;
    }

    // --- Views ---

    function renderLogin() {
        const app = document.getElementById('app');
        app.innerHTML = `
            <div class="auth-container">
                <div class="auth-card">
                    <h1>\uD83C\uDFEA SuperMa</h1>
                    <p class="subtitle">Système de Point de Vente</p>
                    <div class="auth-error" id="auth-error"></div>
                    <form id="login-form">
                        <div class="form-group">
                            <label class="form-label" for="email">Email</label>
                            <input type="email" id="email" class="form-control"
                                   placeholder="admin@superma.ma" required autocomplete="email">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="password">Mot de passe</label>
                            <div style="position:relative">
                                <input type="password" id="password" class="form-control"
                                       placeholder="Entrez votre mot de passe" required autocomplete="current-password"
                                       style="padding-right:40px">
                                <button type="button" id="toggle-password" tabindex="-1"
                                        style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:4px;line-height:1;color:#888;font-size:18px"
                                        aria-label="Afficher le mot de passe">\uD83D\uDC41</button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg" style="width:100%;margin-top:8px;">
                            Connexion
                        </button>
                    </form>
                </div>
            </div>
        `;

        document.getElementById('login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            const errorEl = document.getElementById('auth-error');
            const submitBtn = e.target.querySelector('button[type="submit"]');

            errorEl.classList.remove('show');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Connexion en cours...';

            try {
                await API.login(email, password);
                await initApp();
            } catch (err) {
                errorEl.textContent = err.message || 'Login failed';
                errorEl.classList.add('show');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Connexion';
            }
        });

        document.getElementById('toggle-password')?.addEventListener('click', function () {
            const pw = document.getElementById('password');
            const isPassword = pw.type === 'password';
            pw.type = isPassword ? 'text' : 'password';
            this.textContent = isPassword ? '\uD83D\uDC40' : '\uD83D\uDC41';
            this.setAttribute('aria-label', isPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
        });
    }

    async function initApp() {
        const auth = await API.checkAuth();
        if (!auth) {
            renderLogin();
            return;
        }

        state.user = auth.user;
        state.roles = auth.roles;
        state.permissions = auth.permissions;

        renderLayout();
        navigate(getDefaultPage());

        document.getElementById('page-actions')?.addEventListener('click', e => {
            const btn = e.target.closest('.btn-export');
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Export en cours...';
                API.exportData(btn.dataset.exportType)
                    .catch(err => showToast(err.message, 'error'))
                    .finally(() => {
                        btn.disabled = false;
                        btn.textContent = 'Exporter';
                    });
            }
        });

        // Put cursor at end when focusing any search input
        document.getElementById('page-body')?.addEventListener('focus', e => {
            if (e.target.matches('input[type="text"][id^="search-"]')) {
                const len = e.target.value.length;
                e.target.setSelectionRange(len, len);
            }
        }, true);

        // Convert search input to lowercase on input
        document.getElementById('page-body')?.addEventListener('input', e => {
            if (e.target.matches('input[type="text"][id^="search-"]') && e.target.value !== e.target.value.toLowerCase()) {
                e.target.value = e.target.value.toLowerCase();
            }
        }, true);

        // Frontend error capture
        window.addEventListener('error', e => {
            API.logFrontendError('ERROR', e.message || 'Unknown error', {
                url: e.filename, line: e.lineno, col: e.colno, stack: e.error?.stack
            });
        });
        window.addEventListener('unhandledrejection', e => {
            API.logFrontendError('WARNING', e.reason?.message || 'Unhandled rejection', {
                stack: e.reason?.stack
            });
        });
        console.error = (function(orig) {
            return function(...args) {
                API.logFrontendError('ERROR', args.map(a => typeof a === 'string' ? a : JSON.stringify(a)).join(' '));
                return orig.apply(console, args);
            };
        })(console.error);
    }

    function renderLayout() {
        const app = document.getElementById('app');
        const navItems = NAV_ITEMS.filter(canView).map(item => `
            <button class="nav-item" data-nav="${item.id}">
                <span class="nav-icon">${item.icon}</span>
                ${item.label}
            </button>
        `).join('');

        const initials = (state.user.first_name?.[0] || '') + (state.user.last_name?.[0] || '');
        const roleName = state.roles[0] || 'user';

        app.innerHTML = `
            <div class="app-layout">
                <div class="sidebar-overlay" id="sidebar-overlay"></div>
                <aside class="sidebar" id="sidebar">
                    <div class="sidebar-brand">
                        <h2>\uD83C\uDFEA SuperMa</h2>
                        <span>Point de Vente</span>
                    </div>
                    <nav class="sidebar-nav">${navItems}</nav>
                    <div class="sidebar-footer">
                        <div class="sidebar-user">
                            <div class="avatar">${initials}</div>
                            <div class="user-info">
                                <div class="user-name">${state.user.first_name} ${state.user.last_name}</div>
                                <div class="user-role">${roleName}</div>
                            </div>
                        </div>
                        <button class="btn-logout" id="btn-logout">Déconnexion</button>
                    </div>
                </aside>
                <main class="main-content">
                    <header class="page-header">
                        <div style="display:flex;align-items:center;gap:12px;">
                            <button class="sidebar-toggle" id="sidebar-toggle">\u2630</button>
                            <h1 id="page-title">Dashboard</h1>
                        </div>
                        <div class="page-header-actions" id="page-actions"></div>
                    </header>
                    <div class="page-body" id="page-body"></div>
                </main>
                <div id="toast-container" class="toast-container"></div>
            </div>
        `;

        document.getElementById('sidebar-toggle').addEventListener('click', toggleSidebar);
        document.getElementById('sidebar-overlay').addEventListener('click', toggleSidebar);
        document.getElementById('btn-logout').addEventListener('click', handleLogout);

        $$('.nav-item').forEach(el => {
            el.addEventListener('click', () => {
                navigate(el.dataset.nav);
                if (window.innerWidth <= 768) toggleSidebar();
            });
        });

        // Sidebar keyboard navigation (up/down arrows)
        const sidebar = document.getElementById('sidebar');
        if (sidebar) {
            sidebar.addEventListener('keydown', e => {
                const navItems = Array.from(sidebar.querySelectorAll('.nav-item'));
                const currentIndex = navItems.indexOf(document.activeElement);
                if (currentIndex === -1) return;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    const next = (currentIndex + 1) % navItems.length;
                    navItems[next].focus();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    const prev = (currentIndex - 1 + navItems.length) % navItems.length;
                    navItems[prev].focus();
                } else if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    navItems[currentIndex].click();
                }
            });
        }
    }

    function toggleSidebar() {
        state.sidebarOpen = !state.sidebarOpen;
        document.getElementById('sidebar').classList.toggle('open', state.sidebarOpen);
        document.getElementById('sidebar-overlay').classList.toggle('open', state.sidebarOpen);
    }

    function setPageTitle(title) {
        state.pageTitle = title;
        const el = document.getElementById('page-title');
        if (el) el.textContent = title;
        document.title = `${title} - SuperMa POS`;
    }

    function setPageActions(html) {
        const el = document.getElementById('page-actions');
        if (el) el.innerHTML = html;
    }

    function showLoading(show = true) {
        state.loading = show;
        const body = document.getElementById('page-body');
        if (!body) return;
        if (show) {
            body.innerHTML = `
                <div style="display:flex;justify-content:center;padding:80px 0;">
                    <div class="spinner-ring"></div>
                </div>
            `;
        }
    }

    function showError(message = 'Échec du chargement des données') {
        const body = document.getElementById('page-body');
        if (!body) return;
        body.innerHTML = `
            <div style="text-align:center;padding:80px 20px;">
                <div style="font-size:3rem;margin-bottom:12px;opacity:0.5;">&#x26A0;</div>
                <h3 style="margin-bottom:8px;">Une erreur est survenue</h3>
                <p style="color:var(--text-secondary);margin-bottom:16px;">${message}</p>
                <button class="btn btn-primary" id="btn-retry">Réessayer</button>
            </div>
        `;
        document.getElementById('btn-retry')?.addEventListener('click', () => navigate(state.currentView));
    }

    function connectSSE() {
        if (state.sse) {
            state.sse.close();
            state.sse = null;
        }
        const token = sessionStorage.getItem('auth_token');
        if (!token) return;
        const url = `/api/sse?token=${encodeURIComponent(token)}`;
        state.sse = new EventSource(url);
        state.sse.addEventListener('refresh', () => {
            if (state.currentView === 'dashboard') {
                renderDashboard(false);
            }
        });
        state.sse.addEventListener('connected', () => {});
        state.sse.onerror = () => {
            state.sse?.close();
            state.sse = null;
            setTimeout(connectSSE, 3000);
        };
    }

    function markMutated() {
        API.ping();
    }

    function navigate(view) {
        const item = NAV_ITEMS.find(i => i.id === view);
        if (item && !canView(item)) view = getDefaultPage();
        state.currentView = view;
        $$('.nav-item').forEach(el => el.classList.toggle('active', el.dataset.nav === view));
        renderView(view);
        if (view === 'dashboard' && !state.sse) {
            connectSSE();
        }
    }

    function renderView(view) {
        switch (view) {
            case 'dashboard': renderDashboard(); break;
            case 'products': renderProducts(); break;
            case 'categories': renderCategories(); break;
            case 'customers': renderCustomers(); break;
            case 'suppliers': renderSuppliers(); break;
            case 'stock': renderStock(); break;
            case 'orders': renderOrders(); break;
            case 'sales': renderSales(); break;
            case 'users': renderUsers(); break;
            case 'stores': renderStores(); break;
            case 'logs': renderLogs(); break;
            default: renderDashboard();
        }
    }

    // --- Dashboard ---
    async function renderDashboard(showLoader = true) {
        setPageTitle('Tableau de bord');
        setPageActions('');
        if (showLoader) showLoading(true);

        if (!isAdmin()) {
            const body = document.getElementById('page-body');
            body.innerHTML = `
                <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:80px 20px;text-align:center;">
                    <div style="font-size:5rem;margin-bottom:16px;line-height:1;">🏪</div>
                    <h1 style="font-size:2rem;margin-bottom:4px;">SuperMa</h1>
                    <p style="color:var(--text-secondary);font-size:1.1rem;">${state.user.first_name} ${state.user.last_name}</p>
                </div>
            `;
            return;
        }

        try {
            const res = await API.getDashboard();
            const d = res.data;
            const body = document.getElementById('page-body');

            body.innerHTML = `
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon blue">\uD83D\uDCB0</div>
                        <div class="stat-info">
                            <div class="stat-label">Revenu du jour</div>
                            <div class="stat-value">${Number(d.today.revenue).toFixed(2)} <small style="font-size:0.7rem;color:var(--text-secondary)">MAD</small></div>
                            <div class="stat-change up">${d.today.transactions} transactions</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green">\uD83D\uDCC8</div>
                        <div class="stat-info">
                            <div class="stat-label">Revenu mensuel</div>
                            <div class="stat-value">${Number(d.month.revenue).toFixed(2)} <small style="font-size:0.7rem;color:var(--text-secondary)">MAD</small></div>
                            <div class="stat-change up">${d.month.transactions} transactions</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple">\uD83D\uDCE6</div>
                        <div class="stat-info">
                            <div class="stat-label">Produits</div>
                            <div class="stat-value">${d.inventory.total_products}</div>
                            <div class="stat-change ${d.inventory.active_alerts > 0 ? 'down' : 'up'}">
                                ${d.inventory.low_stock} en alerte, ${d.inventory.out_of_stock} en rupture
                            </div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon yellow">\uD83D\uDCB5</div>
                        <div class="stat-info">
                            <div class="stat-label">Valeur du stock</div>
                            <div class="stat-value">${Number(d.inventory.stock_value).toFixed(2)} <small style="font-size:0.7rem;color:var(--text-secondary)">MAD</small></div>
                            <div class="stat-change">${d.customers.total} clients</div>
                        </div>
                    </div>
                </div>

                <div class="grid-2" style="margin-bottom:24px;">
                    <div class="card">
                        <div class="card-header"><h3>Revenu (14 jours)</h3></div>
                        <div class="card-body">
                            <div class="chart-container" id="revenue-chart">
                                <div style="display:flex;align-items:end;gap:4px;height:160px;padding-top:20px;">
                                    ${d.revenue_by_day.map(r => {
                                        const maxRev = Math.max(...d.revenue_by_day.map(x => parseFloat(x.revenue)), 1);
                                        const h = (r.revenue / maxRev) * 140;
                                        const day = r.day.split('-').slice(1).join('/');
                                        return `<div style="flex:1;display:flex;flex-direction:column;align-items:center;">
                                            <div style="width:100%;background:var(--primary);border-radius:4px 4px 0 0;height:${Math.max(h, 4)}px;min-height:4px;transition:height 300ms;" title="${r.revenue} MAD"></div>
                                            <span style="font-size:0.65rem;color:var(--text-secondary);margin-top:4px;">${day}</span>
                                        </div>`;
                                    }).join('')}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header"><h3>Meilleurs produits</h3></div>
                        <div class="card-body" style="padding:0;">
                            ${d.top_products.length ? `
                            <table>
                                <thead>
                                    <tr>
                                        <th>Produit</th>
                                        <th style="text-align:right">Qté</th>
                                        <th style="text-align:right">Revenu</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${d.top_products.slice(0, 6).map(p => `
                                        <tr>
                                            <td>${p.name}</td>
                                            <td style="text-align:right">${Number(p.total_qty).toFixed(0)}</td>
                                            <td style="text-align:right">${Number(p.total_revenue).toFixed(2)}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>` : `<div class="empty-state"><p>Aucune donnée de vente</p></div>`}
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3>Ventes récentes</h3></div>
                    <div class="card-body" style="padding:0;">
                        ${d.recent_sales.length ? `
                        <table class="table-responsive-cards">
                            <thead>
                                <tr>
                                    <th>N°</th>
                                    <th>Caissier</th>
                                    <th>Client</th>
                                    <th>Montant</th>
                                    <th>Heure</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${d.recent_sales.map(s => `
                                    <tr>
                                        <td data-label="N°">${s.sale_number}</td>
                                        <td data-label="Caissier">${s.cashier || '-'}</td>
                                        <td data-label="Client">${s.customer || 'Libre'}</td>
                                        <td data-label="Montant">${Number(s.total_amount).toFixed(2)}</td>
                                        <td data-label="Heure">${new Date(s.sale_date).toLocaleString()}</td>
                                        <td data-label="Statut"><span class="badge badge-${s.status === 'completed' ? 'success' : 'danger'}">${s.status === 'completed' ? 'Terminée' : s.status === 'cancelled' ? 'Annulée' : s.status}</span></td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>` : `<div class="empty-state"><p>Aucune vente</p></div>`}
                    </div>
                </div>
            `;
        } catch (err) {
            if (API.handleAuthError(err)) { renderLogin(); return; }
            showError(err.message || 'Échec du chargement du tableau de bord');
        }
    }

    // --- Products ---
    async function renderProducts(params = {}) {
        setPageTitle('Produits');
        setPageActions(`<button class="btn btn-primary" id="btn-add-product">+ Nouveau produit</button>${isAdmin() ? ' <button class="btn btn-secondary btn-export" data-export-type="products">Exporter</button>' : ''}`);
        showLoading(true);

        try {
            const res = await API.getProducts({ ...params, per_page: 20 });
            const { products, pagination } = res.data;
            const body = document.getElementById('page-body');

            body.innerHTML = `
                <div class="toolbar">
                    <div class="search-box">
                        <input type="text" autofocus id="search-products" placeholder="Rechercher des produits..." value="${params.search || ''}">
                    </div>
                    <select class="form-control" id="filter-category" style="width:auto;min-width:150px;">
                        <option value="">Toutes les catégories</option>
                    </select>
                </div>
                <div class="table-container">
                    <table class="table-responsive-cards">
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Code-barres</th>
                                <th>Catégorie</th>
                                <th>Prix</th>
                                <th>Stock</th>
                                <th>Statut</th>
                                <th style="text-align:right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="products-table-body">
                            ${products.map(p => `
                                <tr>
                                    <td data-label="Nom"><strong>${p.name}</strong></td>
                                    <td data-label="Code-barres">${p.barcode || '-'}</td>
                                    <td data-label="Catégorie">${p.category_name || '-'}</td>
                                    <td data-label="Prix">${Number(p.selling_price).toFixed(2)}</td>
                                    <td data-label="Stock" class="${p.stock_quantity <= 0 ? 'stock-out' : p.stock_quantity <= p.stock_min ? 'stock-low' : 'stock-ok'}">
                                        ${Number(p.stock_quantity).toFixed(p.unit === 'piece' ? 0 : 2)} ${p.unit === 'piece' ? 'pièce' : p.unit || 'pc'}
                                    </td>
                                    <td data-label="Statut"><span class="badge badge-${p.is_active ? 'success' : 'gray'}">${p.is_active ? 'Actif' : 'Inactif'}</span></td>
                                    <td data-label="Actions" style="text-align:right">
                                        <button class="btn btn-sm btn-secondary" data-view-product="${p.id}">Voir</button>
                                        <button class="btn btn-sm btn-secondary" data-edit-product="${p.id}">Modifier</button>
                                        <button class="btn btn-sm btn-danger" data-delete-product="${p.id}">Supprimer</button>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                    ${products.length === 0 ? '<div class="empty-state"><div class="empty-icon">\uD83D\uDCE6</div><h3>Aucun produit trouvé</h3><p>Ajoutez votre premier produit pour commencer.</p><button class="btn btn-primary" id="btn-add-product-empty">+ Ajouter un produit</button></div>' : ''}
                </div>
                ${pagination ? `
                <div class="pagination">
                    <button ${pagination.page <= 1 ? 'disabled' : ''} data-page="${pagination.page - 1}">Précédent</button>
                    <span class="page-info">Page ${pagination.page} sur ${pagination.total_pages}</span>
                    <button ${pagination.page >= pagination.total_pages ? 'disabled' : ''} data-page="${pagination.page + 1}">Suivant</button>
                </div>` : ''}
            `;

            // Load categories for filter
            try {
                const catRes = await API.getCategories();
                const catSelect = document.getElementById('filter-category');
                catRes.data.categories.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.id;
                    opt.textContent = c.name;
                    catSelect.appendChild(opt);
                });
            } catch (_) {}

            // Events
            document.getElementById('search-products')?.addEventListener('input', function() {
                const q = this.value.toLowerCase();
                document.querySelectorAll('#products-table-body tr').forEach(row => {
                    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
                });
            });

            document.getElementById('filter-category')?.addEventListener('change', e => {
                renderProducts({ ...params, category_id: e.target.value, page: 1 });
            });

            document.querySelectorAll('[data-view-product]').forEach(el => {
                el.addEventListener('click', () => viewProduct(el.dataset.viewProduct));
            });

            document.querySelectorAll('[data-edit-product]').forEach(el => {
                el.addEventListener('click', () => editProduct(el.dataset.editProduct));
            });

            document.querySelectorAll('.pagination button').forEach(el => {
                el.addEventListener('click', () => {
                    if (!el.disabled) renderProducts({ ...params, page: el.dataset.page });
                });
            });

            document.getElementById('btn-add-product')?.addEventListener('click', () => editProduct());
            document.getElementById('btn-add-product-empty')?.addEventListener('click', () => editProduct());

            document.querySelectorAll('[data-delete-product]').forEach(el => {
                el.addEventListener('click', () => deleteProduct(el.dataset.deleteProduct));
            });

        } catch (err) {
            if (API.handleAuthError(err)) { renderLogin(); return; }
            showError(err.message || 'Échec du chargement des produits');
        }
    }

    async function deleteProduct(id) {
        if (!confirm('Supprimer ce produit ? Il sera désactivé.')) return;
        try {
            await API.deleteProduct(id);
            showToast('Produit désactivé', 'success');
            renderProducts();
        } catch (err) {
            showToast(err.message || 'Échec de la suppression du produit', 'error');
        }
    }

    async function viewProduct(id) {
        try {
            const res = await API.getProduct(id);
            const p = res.data;
            const modal = createModal({
                title: p.name,
                size: 'modal-lg',
                content: `
                    <div class="grid-2">
                        <div>
                            <div class="detail-row"><span class="detail-label">Code-barres</span><span class="detail-value">${p.barcode || '-'}</span></div>
                            <div class="detail-row"><span class="detail-label">SKU</span><span class="detail-value">${p.sku || '-'}</span></div>
                            <div class="detail-row"><span class="detail-label">Catégorie</span><span class="detail-value">${p.category_name || '-'}</span></div>
                            <div class="detail-row"><span class="detail-label">Fournisseur</span><span class="detail-value">${p.supplier_name || '-'}</span></div>
                            <div class="detail-row"><span class="detail-label">Unité</span><span class="detail-value">${p.unit || 'pièce'}</span></div>
                        </div>
                        <div>
                            <div class="detail-row"><span class="detail-label">Prix de vente</span><span class="detail-value"><strong>${Number(p.selling_price).toFixed(2)} MAD</strong></span></div>
                            <div class="detail-row"><span class="detail-label">Prix d'achat</span><span class="detail-value">${Number(p.purchase_price).toFixed(2)} MAD</span></div>
                            <div class="detail-row"><span class="detail-label">TVA</span><span class="detail-value">${p.tax_rate}%</span></div>
                            <div class="detail-row"><span class="detail-label">Stock</span><span class="detail-value ${p.stock_quantity <= 0 ? 'stock-out' : ''}">${Number(p.stock_quantity).toFixed(2)}</span></div>
                            <div class="detail-row"><span class="detail-label">Statut</span><span class="detail-value"><span class="badge badge-${p.is_active ? 'success' : 'gray'}">${p.is_active ? 'Actif' : 'Inactif'}</span></span></div>
                        </div>
                    </div>
                    ${p.description ? `<div class="detail-row" style="margin-top:12px;"><span class="detail-label">Description</span><span class="detail-value">${p.description}</span></div>` : ''}
                `,
            });
        } catch (err) {
            showToast(err.message || 'Échec du chargement du produit', 'error');
        }
    }

    async function editProduct(id = null) {
        const isEdit = !!id;
        let product = { name: '', barcode: '', sku: '', selling_price: '', purchase_price: '0', tax_rate: '20', stock_quantity: '0', stock_min: '5', unit: 'piece', description: '', category_id: '', supplier_id: '', is_service: '0', allow_negative_stock: '0' };

        if (isEdit) {
            try {
                const res = await API.getProduct(id);
                product = res.data;
            } catch (err) {
                showToast(err.message || 'Échec du chargement du produit', 'error');
                return;
            }
        }

        let catOptions = '<option value="">Aucune catégorie</option>';
        let supOptions = '<option value="">Aucun fournisseur</option>';

        try {
            const [catRes, supRes] = await Promise.all([API.getCategories(), API.getSuppliers({ per_page: 100 })]);
            catOptions += (catRes.data.categories || []).map(c =>
                `<option value="${c.id}" ${product.category_id === c.id ? 'selected' : ''}>${c.name}</option>`
            ).join('');
            supOptions += (supRes.data.suppliers || []).map(s =>
                `<option value="${s.id}" ${product.supplier_id === s.id ? 'selected' : ''}>${s.company_name}</option>`
            ).join('');
        } catch (_) {}

        const modal = createModal({
            title: isEdit ? 'Modifier le produit' : 'Nouveau produit',
            size: 'modal-lg',
            content: `
                <form id="product-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Nom *</label>
                            <input type="text" class="form-control" name="name" value="${product.name}" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Code-barres</label>
                            <input type="text" class="form-control" name="barcode" value="${product.barcode || ''}">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Catégorie</label>
                            <select class="form-control" name="category_id">${catOptions}</select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Fournisseur</label>
                            <select class="form-control" name="supplier_id">${supOptions}</select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Unité</label>
                            <select class="form-control" name="unit">
                                <option value="piece" ${product.unit === 'piece' ? 'selected' : ''}>Pièce</option>
                                <option value="kg" ${product.unit === 'kg' ? 'selected' : ''}>Kilogramme</option>
                                <option value="litre" ${product.unit === 'litre' ? 'selected' : ''}>Litre</option>
                                <option value="box" ${product.unit === 'box' ? 'selected' : ''}>Boîte</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">SKU</label>
                            <input type="text" class="form-control" name="sku" value="${product.sku || ''}">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Prix de vente *</label>
                            <input type="number" step="0.01" class="form-control" name="selling_price" value="${product.selling_price}" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Prix d'achat</label>
                            <input type="number" step="0.01" class="form-control" name="purchase_price" value="${product.purchase_price}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">TVA (%)</label>
                            <input type="number" step="0.01" class="form-control" name="tax_rate" value="${product.tax_rate}">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Quantité en stock</label>
                            <input type="number" step="0.001" class="form-control" name="stock_quantity" value="${product.stock_quantity}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Stock minimum</label>
                            <input type="number" step="0.001" class="form-control" name="stock_min" value="${product.stock_min}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Stock maximum</label>
                            <input type="number" step="0.001" class="form-control" name="stock_max" value="${product.stock_max || ''}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description">${product.description || ''}</textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-check">
                            <input type="checkbox" id="is_service" name="is_service" value="1" ${product.is_service ? 'checked' : ''}>
                            <label for="is_service">Service (pas de suivi de stock)</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" id="allow_negative_stock" name="allow_negative_stock" value="1" ${product.allow_negative_stock ? 'checked' : ''}>
                            <label for="allow_negative_stock">Autoriser le stock négatif</label>
                        </div>
                    </div>
                </form>
            `,
            footer: `
                <button class="btn btn-secondary" data-close-modal>Annuler</button>
                <button class="btn btn-primary" id="btn-save-product">${isEdit ? 'Mettre à jour' : 'Créer'}</button>
            `,
        });

        document.getElementById('btn-save-product').addEventListener('click', async () => {
            const form = document.getElementById('product-form');
            const data = Object.fromEntries(new FormData(form));
            data.is_service = data.is_service === '1';
            data.allow_negative_stock = data.allow_negative_stock === '1';
            data.stock_quantity = parseFloat(data.stock_quantity) || 0;
            data.stock_min = parseFloat(data.stock_min) || 5;
            data.selling_price = parseFloat(data.selling_price) || 0;
            data.purchase_price = parseFloat(data.purchase_price) || 0;
            data.tax_rate = parseFloat(data.tax_rate) || 20;
            if (!data.stock_max) data.stock_max = null;
            else data.stock_max = parseFloat(data.stock_max);

            try {
                if (isEdit) {
                    await API.updateProduct(id, data);
                    showToast('Produit mis à jour', 'success');
                } else {
                    await API.createProduct(data);
                    showToast('Produit créé', 'success');
                }
                modal.remove();
                markMutated();
                renderProducts();
            } catch (err) {
                showToast(err.message || 'Échec de l\'enregistrement du produit', 'error');
            }
        });
    }

    // --- Catégories ---
    async function renderCategories() {
        setPageTitle('Catégories');
        setPageActions(`<button class="btn btn-primary" id="btn-add-category">+ Nouvelle catégorie</button>${isAdmin() ? ' <button class="btn btn-secondary btn-export" data-export-type="categories">Exporter</button>' : ''}`);
        showLoading(true);

        try {
            const res = await API.getCategories();
            const body = document.getElementById('page-body');

            body.innerHTML = `
                <div class="toolbar">
                    <div class="search-box">
                        <input type="text" autofocus id="search-categories" placeholder="Rechercher des catégories...">
                    </div>
                </div>
                <div class="grid-2" style="grid-template-columns: 1fr;">
                    <div class="table-container">
                        <table class="table-responsive-cards">
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Parent</th>
                                    <th>Produits</th>
                                    <th>Ordre</th>
                                    <th style="text-align:right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="categories-table-body">
                                ${(res.data.categories || []).map(c => `
                                    <tr>
                                        <td><strong>${c.name}</strong> ${c.color ? `<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:${c.color};vertical-align:middle;"></span>` : ''}</td>
                                        <td>${c.parent_name || '-'}</td>
                                        <td>${c.product_count || 0}</td>
                                        <td>${c.sort_order || 0}</td>
                                        <td style="text-align:right">
                                            <button class="btn btn-sm btn-secondary" data-edit-category="${c.id}">Modifier</button>
                                            <button class="btn btn-sm btn-danger" data-delete-category="${c.id}">Supprimer</button>
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                        ${(!res.data.categories || res.data.categories.length === 0) ? '<div class="empty-state"><h3>Aucune catégorie</h3><p>Créez votre première catégorie.</p></div>' : ''}
                    </div>
                </div>
            `;

            document.getElementById('btn-add-category')?.addEventListener('click', () => editCategory());

            document.getElementById('search-categories')?.addEventListener('input', function() {
                const q = this.value.toLowerCase();
                document.querySelectorAll('#categories-table-body tr').forEach(row => {
                    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
                });
            });

            document.querySelectorAll('[data-edit-category]').forEach(el => {
                el.addEventListener('click', () => editCategory(el.dataset.editCategory));
            });

            document.querySelectorAll('[data-delete-category]').forEach(el => {
                el.addEventListener('click', () => deleteCategory(el.dataset.deleteCategory));
            });

        } catch (err) {
            if (API.handleAuthError(err)) { renderLogin(); return; }
            showError(err.message || 'Échec du chargement des catégories');
        }
    }

    async function editCategory(id = null) {
        const isEdit = !!id;
        let cat = { name: '', description: '', color: '', sort_order: '0', parent_id: '' };

        if (isEdit) {
            try {
                const res = await API.getCategory(id);
                cat = res.data;
            } catch (err) {
                showToast(err.message || 'Échec du chargement de la catégorie', 'error');
            }
        }

        const modal = createModal({
            title: isEdit ? 'Modifier la catégorie' : 'Nouvelle catégorie',
            content: `
                <form id="category-form">
                    <div class="form-group">
                        <label class="form-label">Nom *</label>
                        <input type="text" class="form-control" name="name" value="${cat.name || ''}" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Couleur</label>
                            <input type="color" class="form-control" name="color" value="${cat.color || '#6366f1'}" style="height:40px;padding:4px;">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Ordre d'affichage</label>
                            <input type="number" class="form-control" name="sort_order" value="${cat.sort_order || 0}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description">${cat.description || ''}</textarea>
                    </div>
                </form>
            `,
            footer: `
                <button class="btn btn-secondary" data-close-modal>Annuler</button>
                <button class="btn btn-primary" id="btn-save-category">${isEdit ? 'Mettre à jour' : 'Créer'}</button>
            `,
        });

        document.getElementById('btn-save-category').addEventListener('click', async () => {
            const form = document.getElementById('category-form');
            const data = Object.fromEntries(new FormData(form));
            data.sort_order = parseInt(data.sort_order) || 0;

            try {
                if (isEdit) {
                    await API.updateCategory(id, data);
                    showToast('Catégorie mise à jour', 'success');
                } else {
                    await API.createCategory(data);
                    showToast('Catégorie créée', 'success');
                }
                modal.remove();
                renderCategories();
            } catch (err) {
                showToast(err.message || 'Échec de l\'enregistrement de la catégorie', 'error');
            }
        });
    }

    async function deleteCategory(id) {
        if (!confirm('Supprimer cette catégorie ?')) return;
        try {
            await API.deleteCategory(id);
            showToast('Catégorie supprimée', 'success');
            renderCategories();
        } catch (err) {
            showToast(err.message || 'Échec de la suppression de la catégorie', 'error');
        }
    }

    // --- Customers ---
    async function renderCustomers(params = {}) {
        setPageTitle('Clients');
        setPageActions(`<button class="btn btn-primary" id="btn-add-customer">+ Nouveau client</button>${isAdmin() ? ' <button class="btn btn-secondary btn-export" data-export-type="customers">Exporter</button>' : ''}`);
        showLoading(true);

        try {
            const res = await API.getCustomers({ ...params, per_page: 20 });
            const { customers, pagination } = res.data;
            const body = document.getElementById('page-body');

            body.innerHTML = `
                <div class="toolbar">
                    <div class="search-box">
                        <input type="text" autofocus id="search-customers" placeholder="Rechercher des clients..." value="${params.search || ''}">
                    </div>
                </div>
                <div class="table-container">
                    <table class="table-responsive-cards">
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Email</th>
                                <th>Téléphone</th>
                                <th>Ville</th>
                                <th>Points</th>
                                <th>Dépenses</th>
                                <th style="text-align:right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="customers-table-body">
                            ${customers.map(c => `
                                <tr>
                                    <td data-label="Nom"><strong>${c.first_name} ${c.last_name}</strong></td>
                                    <td data-label="Email">${c.email || '-'}</td>
                                    <td data-label="Téléphone">${c.phone || '-'}</td>
                                    <td data-label="Ville">${c.city || '-'}</td>
                                    <td data-label="Points">${c.loyalty_points || 0}</td>
                                    <td data-label="Dépenses">${Number(c.total_spent || 0).toFixed(2)}</td>
                                    <td data-label="Actions" style="text-align:right">
                                        <button class="btn btn-sm btn-secondary" data-view-customer="${c.id}">Voir</button>
                                        <button class="btn btn-sm btn-secondary" data-edit-customer="${c.id}">Modifier</button>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                    ${customers.length === 0 ? '<div class="empty-state"><h3>Aucun client trouvé</h3></div>' : ''}
                </div>
                ${pagination ? `
                <div class="pagination">
                    <button ${pagination.page <= 1 ? 'disabled' : ''} data-page="${pagination.page - 1}">Précédent</button>
                    <span class="page-info">Page ${pagination.page} sur ${pagination.total_pages}</span>
                    <button ${pagination.page >= pagination.total_pages ? 'disabled' : ''} data-page="${pagination.page + 1}">Suivant</button>
                </div>` : ''}
            `;

            document.getElementById('search-customers')?.addEventListener('input', function() {
                const q = this.value.toLowerCase();
                document.querySelectorAll('#customers-table-body tr').forEach(row => {
                    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
                });
            });

            document.getElementById('btn-add-customer')?.addEventListener('click', () => editCustomer());

            document.querySelectorAll('[data-view-customer]').forEach(el => {
                el.addEventListener('click', () => viewCustomer(el.dataset.viewCustomer));
            });

            document.querySelectorAll('[data-edit-customer]').forEach(el => {
                el.addEventListener('click', () => editCustomer(el.dataset.editCustomer));
            });

            document.querySelectorAll('.pagination button').forEach(el => {
                el.addEventListener('click', () => {
                    if (!el.disabled) renderCustomers({ ...params, page: el.dataset.page });
                });
            });

        } catch (err) {
            if (API.handleAuthError(err)) { renderLogin(); return; }
            showError(err.message || 'Échec du chargement des clients');
        }
    }

    async function viewCustomer(id) {
        try {
            const res = await API.getCustomer(id);
            const c = res.data;
            createModal({
                title: `${c.first_name} ${c.last_name}`,
                size: 'modal-lg',
                content: `
                    <div class="grid-2">
                        <div>
                            <div class="detail-row"><span class="detail-label">Email</span><span class="detail-value">${c.email || '-'}</span></div>
                            <div class="detail-row"><span class="detail-label">Téléphone</span><span class="detail-value">${c.phone || '-'}</span></div>
                            <div class="detail-row"><span class="detail-label">Ville</span><span class="detail-value">${c.city || '-'}</span></div>
                        </div>
                        <div>
                            <div class="detail-row"><span class="detail-label">Points de fidélité</span><span class="detail-value"><strong>${c.loyalty_points || 0}</strong></span></div>
                            <div class="detail-row"><span class="detail-label">Total dépensé</span><span class="detail-value"><strong>${Number(c.total_spent || 0).toFixed(2)} MAD</strong></span></div>
                            <div class="detail-row"><span class="detail-label">Créé le</span><span class="detail-value">${new Date(c.created_at).toLocaleDateString()}</span></div>
                        </div>
                    </div>
                    ${c.notes ? `<div class="detail-row"><span class="detail-label">Notes</span><span class="detail-value">${c.notes}</span></div>` : ''}
                    ${(c.recent_sales || []).length ? `
                        <h4 style="margin-top:16px;margin-bottom:8px;">Ventes récentes</h4>
                        <table>
                            <thead><tr><th>N°</th><th>Montant</th><th>Date</th><th>Statut</th></tr></thead>
                            <tbody>${c.recent_sales.map(s => `
                                <tr><td>${s.sale_number}</td><td>${Number(s.total_amount).toFixed(2)}</td><td>${new Date(s.sale_date).toLocaleDateString()}</td><td><span class="badge badge-success">${s.status}</span></td></tr>
                            `).join('')}</tbody>
                        </table>` : ''}
                `,
            });
        } catch (err) {
            showToast(err.message || 'Échec du chargement du client', 'error');
        }
    }

    async function editCustomer(id = null) {
        const isEdit = !!id;
        let c = { first_name: '', last_name: '', email: '', phone: '', address: '', city: '', notes: '' };
        if (isEdit) {
            try {
                const res = await API.getCustomer(id);
                c = res.data;
            } catch (_) {}
        }

        const modal = createModal({
            title: isEdit ? 'Modifier le client' : 'Nouveau client',
            content: `
                <form id="customer-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Prénom *</label>
                            <input type="text" class="form-control" name="first_name" value="${c.first_name || ''}" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Nom *</label>
                            <input type="text" class="form-control" name="last_name" value="${c.last_name || ''}" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="${c.email || ''}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Téléphone</label>
                            <input type="text" class="form-control" name="phone" value="${c.phone || ''}">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Ville</label>
                            <input type="text" class="form-control" name="city" value="${c.city || ''}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Adresse</label>
                        <textarea class="form-control" name="address">${c.address || ''}</textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes">${c.notes || ''}</textarea>
                    </div>
                </form>
            `,
            footer: `
                <button class="btn btn-secondary" data-close-modal>Annuler</button>
                <button class="btn btn-primary" id="btn-save-customer">${isEdit ? 'Mettre à jour' : 'Créer'}</button>
            `,
        });

        document.getElementById('btn-save-customer').addEventListener('click', async () => {
            const form = document.getElementById('customer-form');
            const data = Object.fromEntries(new FormData(form));
            try {
                if (isEdit) {
                    await API.updateCustomer(id, data);
                    showToast('Client mis à jour', 'success');
                } else {
                    await API.createCustomer(data);
                    showToast('Client créé', 'success');
                }
                modal.remove();
                renderCustomers();
            } catch (err) {
                showToast(err.message || 'Échec de l\'enregistrement du client', 'error');
            }
        });
    }

    // --- Suppliers ---
    async function renderSuppliers(params = {}) {
        setPageTitle('Fournisseurs');
        setPageActions(`<button class="btn btn-primary" id="btn-add-supplier">+ Nouveau fournisseur</button>${isAdmin() ? ' <button class="btn btn-secondary btn-export" data-export-type="suppliers">Exporter</button>' : ''}`);
        showLoading(true);

        try {
            const res = await API.getSuppliers({ ...params, per_page: 20 });
            const { suppliers, pagination } = res.data;
            const body = document.getElementById('page-body');

            body.innerHTML = `
                <div class="toolbar">
                    <div class="search-box">
                        <input type="text" autofocus id="search-suppliers" placeholder="Rechercher des fournisseurs..." value="${params.search || ''}">
                    </div>
                </div>
                <div class="table-container">
                    <table class="table-responsive-cards">
                        <thead>
                            <tr>
                                <th>Société</th>
                                <th>Contact</th>
                                <th>Email</th>
                                <th>Téléphone</th>
                                <th>Ville</th>
                                <th style="text-align:right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="suppliers-table-body">
                            ${suppliers.map(s => `
                                <tr>
                                    <td data-label="Société"><strong>${s.company_name}</strong></td>
                                    <td data-label="Contact">${s.contact_name || '-'}</td>
                                    <td data-label="Email">${s.email || '-'}</td>
                                    <td data-label="Téléphone">${s.phone || '-'}</td>
                                    <td data-label="Ville">${s.city || '-'}</td>
                                    <td data-label="Actions" style="text-align:right">
                                        <button class="btn btn-sm btn-secondary" data-edit-supplier="${s.id}">Modifier</button>
                                        <button class="btn btn-sm btn-danger" data-delete-supplier="${s.id}">Supprimer</button>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                    ${suppliers.length === 0 ? '<div class="empty-state"><h3>Aucun fournisseur</h3></div>' : ''}
                </div>
                ${pagination ? `
                <div class="pagination">
                    <button ${pagination.page <= 1 ? 'disabled' : ''} data-page="${pagination.page - 1}">Précédent</button>
                    <span class="page-info">Page ${pagination.page} sur ${pagination.total_pages}</span>
                    <button ${pagination.page >= pagination.total_pages ? 'disabled' : ''} data-page="${pagination.page + 1}">Suivant</button>
                </div>` : ''}
            `;

            document.getElementById('search-suppliers')?.addEventListener('input', function() {
                const q = this.value.toLowerCase();
                document.querySelectorAll('#suppliers-table-body tr').forEach(row => {
                    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
                });
            });

            document.getElementById('btn-add-supplier')?.addEventListener('click', () => editSupplier());

            document.querySelectorAll('[data-edit-supplier]').forEach(el => {
                el.addEventListener('click', () => editSupplier(el.dataset.editSupplier));
            });

            document.querySelectorAll('[data-delete-supplier]').forEach(el => {
                el.addEventListener('click', () => deleteSupplier(el.dataset.deleteSupplier));
            });

            document.querySelectorAll('.pagination button').forEach(el => {
                el.addEventListener('click', () => { if (!el.disabled) renderSuppliers({ ...params, page: el.dataset.page }); });
            });

        } catch (err) {
            if (API.handleAuthError(err)) { renderLogin(); return; }
            showError(err.message || 'Échec du chargement des fournisseurs');
        }
    }

    async function editSupplier(id = null) {
        const isEdit = !!id;
        let s = { company_name: '', contact_name: '', email: '', phone: '', address: '', city: '', country: 'MA', tax_id: '', payment_terms: '', notes: '' };
        if (isEdit) {
            try {
                const res = await API.getSupplier(id);
                s = res.data;
            } catch (err) {
                showToast(err.message || 'Échec du chargement du fournisseur', 'error');
            }
        }

        const modal = createModal({
            title: isEdit ? 'Modifier le fournisseur' : 'Nouveau fournisseur',
            size: 'modal-lg',
            content: `
                <form id="supplier-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Société *</label>
                            <input type="text" class="form-control" name="company_name" value="${s.company_name || ''}" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Nom du contact</label>
                            <input type="text" class="form-control" name="contact_name" value="${s.contact_name || ''}">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="${s.email || ''}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Téléphone</label>
                            <input type="text" class="form-control" name="phone" value="${s.phone || ''}">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Ville</label>
                            <input type="text" class="form-control" name="city" value="${s.city || ''}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Pays</label>
                            <input type="text" class="form-control" name="country" value="${s.country || 'MA'}">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">N° de TVA</label>
                            <input type="text" class="form-control" name="tax_id" value="${s.tax_id || ''}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Conditions de paiement</label>
                            <input type="text" class="form-control" name="payment_terms" value="${s.payment_terms || ''}" placeholder="ex. 30 jours net">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Adresse</label>
                        <textarea class="form-control" name="address">${s.address || ''}</textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes">${s.notes || ''}</textarea>
                    </div>
                </form>
            `,
            footer: `
                <button class="btn btn-secondary" data-close-modal>Annuler</button>
                <button class="btn btn-primary" id="btn-save-supplier">${isEdit ? 'Mettre à jour' : 'Créer'}</button>
            `,
        });

        document.getElementById('btn-save-supplier').addEventListener('click', async () => {
            const form = document.getElementById('supplier-form');
            const data = Object.fromEntries(new FormData(form));
            try {
                if (isEdit) {
                    await API.updateSupplier(id, data);
                    showToast('Fournisseur mis à jour', 'success');
                } else {
                    await API.createSupplier(data);
                    showToast('Fournisseur créé', 'success');
                }
                modal.remove();
                renderSuppliers();
            } catch (err) {
                showToast(err.message || 'Échec de l\'enregistrement du fournisseur', 'error');
            }
        });
    }

    async function deleteSupplier(id) {
        if (!confirm('Supprimer ce fournisseur ?')) return;
        try {
            await API.deleteSupplier(id);
            showToast('Fournisseur supprimé', 'success');
            renderSuppliers();
        } catch (err) {
            showToast(err.message || 'Échec de la suppression du fournisseur', 'error');
        }
    }

    // --- Stock ---
    async function renderStock(params = {}) {
        setPageTitle('Stock');
        const canAdjust = hasPermission('stock.adjust');
        setPageActions('');

        if (canAdjust) {
            setPageActions(`<button class="btn btn-secondary" id="btn-adjust-stock">+ Ajuster le stock</button>`);
        }

        showLoading(true);

        try {
            const res = await API.getStock({ ...params, view: 'status' });
            const products = res.data.products || [];
            const body = document.getElementById('page-body');

            const lowCount = products.filter(p => p.stock_status === 'low_stock').length;
            const outCount = products.filter(p => p.stock_status === 'out_of_stock').length;

            body.innerHTML = `
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon green">\u2705</div>
                        <div class="stat-info">
                            <div class="stat-label">En stock</div>
                            <div class="stat-value">${products.filter(p => p.stock_status === 'ok').length}</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon yellow">\u26A0\uFE0F</div>
                        <div class="stat-info">
                            <div class="stat-label">Stock faible</div>
                            <div class="stat-value">${lowCount}</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red">\u274C</div>
                        <div class="stat-info">
                            <div class="stat-label">Rupture de stock</div>
                            <div class="stat-value">${outCount}</div>
                        </div>
                    </div>
                </div>

                <div class="toolbar">
                    <div class="search-box">
                        <input type="text" autofocus id="search-stock" placeholder="Rechercher des produits..." value="${params.search || ''}">
                    </div>
                    <select class="form-control" id="filter-stock" style="width:auto;min-width:130px;">
                        <option value="all">Tous</option>
                        <option value="low_stock" ${params.filter === 'low_stock' ? 'selected' : ''}>Stock faible</option>
                        <option value="out_of_stock" ${params.filter === 'out_of_stock' ? 'selected' : ''}>Rupture de stock</option>
                        <option value="overstock" ${params.filter === 'overstock' ? 'selected' : ''}>Surstock</option>
                    </select>
                </div>

                <div class="table-container">
                    <table class="table-responsive-cards">
                        <thead>
                            <tr>
                                <th>Produit</th>
                                <th>Code-barres</th>
                                <th>Catégorie</th>
                                <th>Stock</th>
                                <th>Min</th>
                                <th>Statut</th>
                                <th>Valeur</th>
                                ${canAdjust ? '<th style="text-align:right">Actions</th>' : ''}
                            </tr>
                        </thead>
                        <tbody id="stock-table-body">
                            ${products.map(p => {
                                const statusClass = { 'ok': 'stock-ok', 'low_stock': 'stock-low', 'out_of_stock': 'stock-out', 'overstock': 'stock-over' }[p.stock_status] || '';
                                const badgeClass = { 'ok': 'badge-success', 'low_stock': 'badge-warning', 'out_of_stock': 'badge-danger', 'overstock': 'badge-info' }[p.stock_status] || 'badge-gray';
                                const statusLabel = { 'ok': 'OK', 'low_stock': 'Faible', 'out_of_stock': 'Rupture', 'overstock': 'Surstock' }[p.stock_status] || p.stock_status;
                                return `
                                    <tr>
                                        <td data-label="Produit"><strong>${p.name}</strong></td>
                                        <td data-label="Code-barres">${p.barcode || '-'}</td>
                                        <td data-label="Catégorie">${p.category_name || '-'}</td>
                                        <td data-label="Stock" class="${statusClass}"><strong>${Number(p.stock_quantity).toFixed(2)}</strong></td>
                                        <td data-label="Min">${Number(p.stock_min).toFixed(2)}</td>
                                        <td data-label="Statut"><span class="badge ${badgeClass}">${statusLabel}</span></td>
                                        <td data-label="Valeur">${Number(p.stock_value || 0).toFixed(2)}</td>
                                        ${canAdjust ? `<td data-label="Actions" style="text-align:right">
                                            <button class="btn btn-sm btn-secondary" data-adjust-stock="${p.id}" data-name="${p.name}">Ajuster</button>
                                        </td>` : ''}
                                    </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                    ${products.length === 0 ? '<div class="empty-state"><h3>Aucun produit</h3></div>' : ''}
                </div>
            `;

            document.getElementById('search-stock')?.addEventListener('input', function() {
                const q = this.value.toLowerCase();
                document.querySelectorAll('#stock-table-body tr').forEach(row => {
                    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
                });
            });

            document.getElementById('filter-stock')?.addEventListener('change', e => {
                renderStock({ ...params, filter: e.target.value });
            });

            document.getElementById('btn-adjust-stock')?.addEventListener('click', () => adjustStock());

            document.querySelectorAll('[data-adjust-stock]').forEach(el => {
                el.addEventListener('click', () => adjustStock(el.dataset.adjustStock, el.dataset.name));
            });

        } catch (err) {
            if (API.handleAuthError(err)) { renderLogin(); return; }
            showError(err.message || 'Échec du chargement du stock');
        }
    }

    async function adjustStock(productId = null, productName = '') {
        let productOptions = '';

        if (!productId) {
            try {
                const res = await API.getProducts({ per_page: 200 });
                productOptions = (res.data.products || []).map(p =>
                    `<option value="${p.id}">${p.name} (${p.barcode || 'sans code-barres'})</option>`
                ).join('');
            } catch (err) {
                console.error('Stock adjust products load failed', err);
                showToast(err.message || 'Échec du chargement des produits', 'error');
            }
        }

        const modal = createModal({
            title: productId ? `Ajuster le stock - ${productName}` : 'Ajuster le stock',
            content: `
                <form id="stock-adjust-form">
                    ${!productId ? `
                    <div class="form-group">
                        <label class="form-label">Produit</label>
                        <select class="form-control" name="product_id" required>
                            <option value="">Sélectionnez un produit...</option>
                            ${productOptions}
                        </select>
                    </div>` : `<input type="hidden" name="product_id" value="${productId}">`}
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Type de mouvement</label>
                            <select class="form-control" name="movement_type" required>
                                <option value="adjustment">Ajustement</option>
                                <option value="purchase">Achat (entrée)</option>
                                <option value="loss">Perte (sortie)</option>
                                <option value="return">Retour</option>
                                <option value="transfer_in">Transfert entrant</option>
                                <option value="transfer_out">Transfert sortant</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Quantité *</label>
                            <input type="number" step="0.001" class="form-control" name="quantity" required placeholder="Utilisez positif pour l'entrée, négatif pour la sortie">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Coût unitaire</label>
                        <input type="number" step="0.01" class="form-control" name="unit_cost" placeholder="Optionnel">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" placeholder="Motif de l'ajustement..."></textarea>
                    </div>
                </form>
            `,
            footer: `
                <button class="btn btn-secondary" data-close-modal>Annuler</button>
                <button class="btn btn-primary" id="btn-save-adjustment">Enregistrer l'ajustement</button>
            `,
        });

        document.getElementById('btn-save-adjustment').addEventListener('click', async () => {
            const form = document.getElementById('stock-adjust-form');
            const data = Object.fromEntries(new FormData(form));
            data.quantity = parseFloat(data.quantity);
            data.unit_cost = data.unit_cost ? parseFloat(data.unit_cost) : null;

            if (!data.product_id) { showToast('Sélectionnez un produit', 'error'); return; }
            if (!data.quantity) { showToast('Entrez une quantité', 'error'); return; }

            try {
                await API.adjustStock(data);
                showToast('Stock ajusté', 'success');
                modal.remove();
                markMutated();
                renderStock();
            } catch (err) {
                showToast(err.message || 'Échec de l\'ajustement du stock', 'error');
            }
        });
    }

    // --- Purchase Orders ---
    const ORDER_STATUSES = {
        draft: { label: 'Brouillon', badge: 'badge-gray' },
        sent: { label: 'Envoyée', badge: 'badge-info' },
        partial: { label: 'Partielle', badge: 'badge-warning' },
        received: { label: 'Reçue', badge: 'badge-success' },
        cancelled: { label: 'Annulée', badge: 'badge-danger' },
    };

    async function renderOrders(params = {}) {
        setPageTitle('Commandes');
        setPageActions(`<button class="btn btn-primary" id="btn-new-order">+ Nouvelle commande</button>`);
        showLoading(true);

        try {
            const res = await API.getOrders({ ...params, per_page: 20 });
            const { orders, pagination } = res.data;
            const body = document.getElementById('page-body');

            body.innerHTML = `
                <div class="toolbar">
                    <div class="search-box">
                        <input type="text" autofocus id="search-orders" placeholder="Rechercher par N° ou fournisseur..." value="${params.search || ''}">
                    </div>
                    <select class="form-control" id="filter-order-status" style="width:auto;min-width:130px;">
                        <option value="">Tous les statuts</option>
                        ${Object.entries(ORDER_STATUSES).map(([k, v]) =>
                            `<option value="${k}" ${params.status === k ? 'selected' : ''}>${v.label}</option>`
                        ).join('')}
                    </select>
                </div>
                <div class="table-container">
                    <table class="table-responsive-cards">
                        <thead>
                            <tr>
                                <th>N°</th>
                                <th>Fournisseur</th>
                                <th>Articles</th>
                                <th>Total</th>
                                <th>Date</th>
                                <th>Statut</th>
                                <th style="text-align:right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="orders-table-body">
                            ${orders.map(o => {
                                const st = ORDER_STATUSES[o.status] || { label: o.status, badge: 'badge-gray' };
                                return `
                                <tr>
                                    <td data-label="N°"><strong>${o.order_number}</strong></td>
                                    <td data-label="Fournisseur">${o.company_name || '-'}</td>
                                    <td data-label="Articles">${o.items_count || 0} (${Number(o.total_qty || 0).toFixed(2)})</td>
                                    <td data-label="Total"><strong>${Number(o.total_amount).toFixed(2)}</strong></td>
                                    <td data-label="Date">${new Date(o.order_date).toLocaleDateString()}</td>
                                    <td data-label="Statut"><span class="badge ${st.badge}">${st.label}</span></td>
                                    <td data-label="Actions" style="text-align:right">
                                        <button class="btn btn-sm btn-secondary" data-view-order="${o.id}">Voir</button>
                                        <button class="btn btn-sm btn-secondary" data-docx-order="${o.id}">Word</button>
                                        ${(o.status === 'draft' || o.status === 'sent') ? `<button class="btn btn-sm btn-danger" data-cancel-order="${o.id}">Annuler</button>` : ''}
                                    </td>
                                </tr>`;
                            }).join('')}
                        </tbody>
                    </table>
                    ${orders.length === 0 ? '<div class="empty-state"><h3>Aucune commande</h3></div>' : ''}
                </div>
                ${pagination ? `
                <div class="pagination">
                    <button ${pagination.page <= 1 ? 'disabled' : ''} data-page="${pagination.page - 1}">Précédent</button>
                    <span class="page-info">Page ${pagination.page} sur ${pagination.total_pages}</span>
                    <button ${pagination.page >= pagination.total_pages ? 'disabled' : ''} data-page="${pagination.page + 1}">Suivant</button>
                </div>` : ''}
            `;

            document.getElementById('search-orders')?.addEventListener('input', function() {
                const q = this.value.toLowerCase();
                document.querySelectorAll('#orders-table-body tr').forEach(row => {
                    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
                });
            });

            document.getElementById('filter-order-status')?.addEventListener('change', e => {
                renderOrders({ ...params, status: e.target.value, page: 1 });
            });

            document.getElementById('btn-new-order')?.addEventListener('click', () => editOrder());

            document.querySelectorAll('[data-view-order]').forEach(el => {
                el.addEventListener('click', () => viewOrder(el.dataset.viewOrder));
            });

            document.querySelectorAll('[data-docx-order]').forEach(el => {
                el.addEventListener('click', () => downloadOrderDocx(el.dataset.docxOrder));
            });

            document.querySelectorAll('[data-cancel-order]').forEach(el => {
                el.addEventListener('click', () => cancelOrder(el.dataset.cancelOrder));
            });

            document.querySelectorAll('.pagination button').forEach(el => {
                el.addEventListener('click', () => { if (!el.disabled) renderOrders({ ...params, page: el.dataset.page }); });
            });

        } catch (err) {
            if (API.handleAuthError(err)) { renderLogin(); return; }
            showError(err.message || 'Échec du chargement des commandes');
        }
    }

    async function viewOrder(id) {
        try {
            const res = await API.getOrder(id);
            const o = res.data;
            const st = ORDER_STATUSES[o.status] || { label: o.status, badge: 'badge-gray' };

            createModal({
                title: `Commande ${o.order_number}`,
                size: 'modal-lg',
                content: `
                    <div class="grid-2">
                        <div>
                            <div class="detail-row"><span class="detail-label">N°</span><span class="detail-value"><strong>${o.order_number}</strong></span></div>
                            <div class="detail-row"><span class="detail-label">Fournisseur</span><span class="detail-value">${o.company_name || '-'}</span></div>
                            <div class="detail-row"><span class="detail-label">Contact</span><span class="detail-value">${o.contact_name || '-'}</span></div>
                            <div class="detail-row"><span class="detail-label">Date</span><span class="detail-value">${new Date(o.order_date).toLocaleDateString()}</span></div>
                        </div>
                        <div>
                            <div class="detail-row"><span class="detail-label">Statut</span><span class="detail-value"><span class="badge ${st.badge}">${st.label}</span></span></div>
                            <div class="detail-row"><span class="detail-label">Total</span><span class="detail-value"><strong>${Number(o.total_amount).toFixed(2)} MAD</strong></span></div>
                            <div class="detail-row"><span class="detail-label">TVA</span><span class="detail-value">${Number(o.tax_amount).toFixed(2)} MAD</span></div>
                            ${o.expected_date ? `<div class="detail-row"><span class="detail-label">Livraison prévue</span><span class="detail-value">${new Date(o.expected_date).toLocaleDateString()}</span></div>` : ''}
                        </div>
                    </div>
                    ${(o.items || []).length ? `
                        <h4 style="margin-top:16px;margin-bottom:8px;">Articles</h4>
                        <table>
                            <thead><tr><th>Produit</th><th>Qté</th><th>P.U.</th><th>Rem%</th><th>Sous-total</th></tr></thead>
                            <tbody>${o.items.map(i => `
                                <tr><td>${i.product_name}</td><td>${Number(i.ordered_qty).toFixed(2)}</td><td>${Number(i.unit_price).toFixed(2)}</td><td>${i.discount_pct || 0}%</td><td>${Number(i.ordered_qty * i.unit_price * (1 - (i.discount_pct || 0) / 100)).toFixed(2)}</td></tr>
                            `).join('')}</tbody>
                        </table>` : '<p>Aucun article</p>'}
                    ${o.notes ? `<div class="detail-row" style="margin-top:12px;"><span class="detail-label">Notes</span><span class="detail-value">${o.notes}</span></div>` : ''}
                    <div style="margin-top:16px;display:flex;gap:8px;">
                        <button class="btn btn-secondary" id="btn-docx-from-view">Télécharger Word</button>
                        ${(o.status === 'sent') ? `<button class="btn btn-success" id="btn-receive-order">Marquer reçue</button>` : ''}
                    </div>
                `,
            });

            document.getElementById('btn-docx-from-view')?.addEventListener('click', () => {
                downloadOrderDocx(id);
            });
            document.getElementById('btn-receive-order')?.addEventListener('click', async () => {
                try {
                    await API.updateOrder(id, { status: 'received' });
                    showToast('Commande reçue', 'success');
                    document.querySelector('.modal-overlay')?.remove();
                    markMutated();
                    renderOrders();
                } catch (err) {
                    showToast(err.message || 'Échec de la réception', 'error');
                }
            });
        } catch (err) {
            showToast(err.message || 'Échec du chargement de la commande', 'error');
        }
    }

    async function editOrder(id = null) {
        const isEdit = !!id;
        let orderData = { supplier_id: '', notes: '', expected_date: '', items: [] };
        let supplierOptions = '<option value="">Sélectionnez un fournisseur...</option>';
        let productOptions = '<option value="">Sélectionnez un produit...</option>';
        let itemRowsHtml = '';
        let allProducts = [];

        try {
            const suppRes = await API.getSuppliers({ per_page: 200 });
            const prodRes = await API.getProducts({ per_page: 500 });

            supplierOptions += (suppRes.data.suppliers || []).map(s =>
                `<option value="${s.id}">${s.company_name}${s.contact_name ? ` (${s.contact_name})` : ''}</option>`
            ).join('');

            allProducts = prodRes.data.products || [];
            productOptions += allProducts.map(p =>
                `<option value="${p.id}" data-price="${p.purchase_price || p.selling_price}" data-name="${p.name}">${p.name} (${p.barcode || 'sans code'})</option>`
            ).join('');

            if (isEdit) {
                const res = await API.getOrder(id);
                orderData = res.data;
                itemRowsHtml = (orderData.items || []).map(i => `
                    <tr>
                        <td><strong>${i.product_name}</strong></td>
                        <td><input type="number" class="form-control item-qty" value="${Number(i.ordered_qty).toFixed(2)}" step="0.001" style="width:70px;"></td>
                        <td><input type="number" class="form-control item-price" value="${Number(i.unit_price).toFixed(2)}" step="0.01" style="width:90px;"></td>
                        <td><input type="number" class="form-control item-discount" value="${i.discount_pct || 0}" min="0" max="100" step="0.1" style="width:60px;"></td>
                        <td class="item-subtotal">${Number(i.ordered_qty * i.unit_price * (1 - (i.discount_pct || 0) / 100)).toFixed(2)}</td>
                        <td><button type="button" class="btn btn-sm btn-danger remove-item" data-pid="${i.product_id}">&times;</button></td>
                    </tr>
                `).join('');
            }
        } catch (err) {
            console.error('Order form data load failed', err);
            showToast(err.message || 'Échec du chargement des données', 'error');
        }

        const modal = createModal({
            title: isEdit ? 'Modifier la commande' : 'Nouvelle commande',
            size: 'modal-lg',
            content: `
                <style>
                    .order-layout { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
                    .order-items-table { max-height:300px; overflow-y:auto; }
                    @media (max-width:768px) { .order-layout { grid-template-columns:1fr; } }
                </style>
                <div class="order-layout">
                    <div class="form-group">
                        <label class="form-label">Fournisseur *</label>
                        <select class="form-control" id="order-supplier" ${isEdit ? 'disabled' : ''}>${supplierOptions}</select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date livraison prévue</label>
                        <input type="date" class="form-control" id="order-expected" value="${orderData.expected_date || ''}">
                    </div>
                </div>
                <div style="display:flex;gap:8px;margin-bottom:8px;">
                    <select class="form-control" id="order-product-select" style="flex:1;">${productOptions}</select>
                    <input type="number" class="form-control" id="order-item-qty" placeholder="Qté" value="1" step="0.001" style="width:80px;">
                    <input type="number" class="form-control" id="order-item-price" placeholder="P.U." step="0.01" style="width:100px;">
                    <button class="btn btn-primary" id="btn-add-order-item">+</button>
                </div>
                <div class="order-items-table">
                    <table>
                        <thead><tr><th>Produit</th><th>Qté</th><th>P.U.</th><th>Rem%</th><th>Total</th><th></th></tr></thead>
                        <tbody id="order-items-body">
                            ${itemRowsHtml || '<tr><td colspan="6" style="text-align:center;padding:16px;color:var(--text-secondary);">Ajoutez des produits à la commande</td></tr>'}
                        </tbody>
                    </table>
                </div>
                <div class="form-group" style="margin-top:8px;">
                    <label class="form-label">Notes</label>
                    <textarea class="form-control" id="order-notes" rows="2">${orderData.notes || ''}</textarea>
                </div>
            `,
            footer: `
                <button class="btn btn-secondary" data-close-modal>Annuler</button>
                <button class="btn btn-primary" id="btn-save-order">${isEdit ? 'Mettre à jour' : 'Créer'}</button>
            `,
        });

        // Order items logic
        let orderItems = isEdit ? (orderData.items || []).map(i => ({
            product_id: i.product_id,
            product_name: i.product_name,
            ordered_qty: Number(i.ordered_qty),
            unit_price: Number(i.unit_price),
            discount_pct: Number(i.discount_pct || 0),
        })) : [];

        function renderOrderItems() {
            const tbody = document.getElementById('order-items-body');
            if (!tbody) return;
            if (orderItems.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:16px;color:var(--text-secondary);">Ajoutez des produits à la commande</td></tr>';
                return;
            }
            tbody.innerHTML = orderItems.map((item, idx) => {
                const sub = item.ordered_qty * item.unit_price * (1 - (item.discount_pct || 0) / 100);
                return `
                    <tr>
                        <td><strong>${item.product_name}</strong></td>
                        <td><input type="number" class="form-control item-qty" value="${Number(item.ordered_qty).toFixed(2)}" step="0.001" style="width:70px;" data-idx="${idx}"></td>
                        <td><input type="number" class="form-control item-price" value="${Number(item.unit_price).toFixed(2)}" step="0.01" style="width:90px;" data-idx="${idx}"></td>
                        <td><input type="number" class="form-control item-discount" value="${item.discount_pct || 0}" min="0" max="100" step="0.1" style="width:60px;" data-idx="${idx}"></td>
                        <td class="item-subtotal">${Number(sub).toFixed(2)}</td>
                        <td><button type="button" class="btn btn-sm btn-danger remove-item" data-idx="${idx}">&times;</button></td>
                    </tr>
                `;
            }).join('');

            tbody.querySelectorAll('.item-qty').forEach(inp => {
                inp.addEventListener('input', function() {
                    const idx = parseInt(this.dataset.idx);
                    if (orderItems[idx]) {
                        orderItems[idx].ordered_qty = parseFloat(this.value) || 0;
                        renderOrderItems();
                    }
                });
            });
            tbody.querySelectorAll('.item-price').forEach(inp => {
                inp.addEventListener('input', function() {
                    const idx = parseInt(this.dataset.idx);
                    if (orderItems[idx]) {
                        orderItems[idx].unit_price = parseFloat(this.value) || 0;
                        renderOrderItems();
                    }
                });
            });
            tbody.querySelectorAll('.item-discount').forEach(inp => {
                inp.addEventListener('input', function() {
                    const idx = parseInt(this.dataset.idx);
                    if (orderItems[idx]) {
                        orderItems[idx].discount_pct = parseFloat(this.value) || 0;
                        renderOrderItems();
                    }
                });
            });
            tbody.querySelectorAll('.remove-item').forEach(btn => {
                btn.addEventListener('click', function() {
                    const idx = parseInt(this.dataset.idx);
                    orderItems.splice(idx, 1);
                    renderOrderItems();
                });
            });
        }

        // Add item button
        document.getElementById('btn-add-order-item')?.addEventListener('click', () => {
            const sel = document.getElementById('order-product-select');
            const qtyInp = document.getElementById('order-item-qty');
            const priceInp = document.getElementById('order-item-price');
            const pid = sel.value;
            if (!pid) { showToast('Sélectionnez un produit', 'warning'); return; }
            const opt = sel.selectedOptions[0];
            const name = opt?.dataset?.name || 'Produit';
            const defaultPrice = parseFloat(opt?.dataset?.price || '0');
            const qty = parseFloat(qtyInp.value) || 1;
            const price = parseFloat(priceInp.value) || defaultPrice;

            const existing = orderItems.findIndex(i => i.product_id === pid);
            if (existing >= 0) {
                orderItems[existing].ordered_qty += qty;
                orderItems[existing].unit_price = price;
            } else {
                orderItems.push({ product_id: pid, product_name: name, ordered_qty: qty, unit_price: price, discount_pct: 0 });
            }
            renderOrderItems();
            qtyInp.value = '1';
            priceInp.value = '';
            sel.value = '';
        });

        // Re-render if editing
        if (isEdit) renderOrderItems();

        document.getElementById('btn-save-order')?.addEventListener('click', async () => {
            if (orderItems.length === 0) {
                showToast('Ajoutez au moins un article', 'warning');
                return;
            }

            const supplierId = document.getElementById('order-supplier')?.value;
            if (!supplierId) {
                showToast('Sélectionnez un fournisseur', 'warning');
                return;
            }

            const data = {
                supplier_id: supplierId,
                expected_date: document.getElementById('order-expected')?.value || null,
                notes: document.getElementById('order-notes')?.value || '',
                items: orderItems.map(i => ({
                    product_id: i.product_id,
                    ordered_qty: i.ordered_qty,
                    unit_price: i.unit_price,
                    discount_pct: i.discount_pct || 0,
                })),
            };

            try {
                if (isEdit) {
                    await API.updateOrder(id, data);
                    showToast('Commande mise à jour', 'success');
                } else {
                    await API.createOrder(data);
                    showToast('Commande créée', 'success');
                }
                modal.remove();
                markMutated();
                renderOrders();
            } catch (err) {
                showToast(err.message || 'Échec de l\'enregistrement de la commande', 'error');
            }
        });
    }

    async function cancelOrder(id) {
        if (!confirm('Annuler cette commande ?')) return;
        try {
            await API.cancelOrder(id);
            showToast('Commande annulée', 'success');
            markMutated();
            renderOrders();
        } catch (err) {
            showToast(err.message || 'Échec de l\'annulation', 'error');
        }
    }

    async function downloadOrderDocx(id) {
        try {
            await API.downloadOrderDocx(id);
        } catch (err) {
            showToast(err.message || 'Échec du téléchargement Word', 'error');
        }
    }

    // --- Sales ---
    async function renderSales(params = {}) {
        setPageTitle('Ventes');
        setPageActions(`<button class="btn btn-primary" id="btn-new-sale">+ Nouvelle vente</button>${isAdmin() ? ' <button class="btn btn-secondary btn-export" data-export-type="sales">Exporter les ventes par jour</button>' : ''}`);
        showLoading(true);

        try {
            const res = await API.getSales({ ...params, per_page: 20 });
            const { sales, pagination } = res.data;
            const body = document.getElementById('page-body');

            body.innerHTML = `
                <div class="toolbar">
                    <div class="search-box">
                        <input type="text" autofocus id="search-sales" placeholder="Rechercher par N° de vente..." value="${params.search || ''}">
                    </div>
                    <select class="form-control" id="filter-sale-status" style="width:auto;min-width:130px;">
                        <option value="">Tous les statuts</option>
                        <option value="completed" ${params.status === 'completed' ? 'selected' : ''}>Terminée</option>
                        <option value="cancelled" ${params.status === 'cancelled' ? 'selected' : ''}>Annulée</option>
                    </select>
                </div>
                <div class="table-container">
                    <table class="table-responsive-cards">
                        <thead>
                            <tr>
                                <th>N°</th>
                                <th>Caissier</th>
                                <th>Client</th>
                                <th>Montant</th>
                                <th>Paiement</th>
                                <th>Date</th>
                                <th>Statut</th>
                                <th style="text-align:right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="sales-table-body">
                            ${sales.map(s => `
                                <tr>
                                    <td data-label="N°"><strong>${s.sale_number}</strong></td>
                                    <td data-label="Caissier">${s.cashier || '-'}</td>
                                    <td data-label="Client">${s.customer_name || 'Libre'}</td>
                                    <td data-label="Montant"><strong>${Number(s.total_amount).toFixed(2)}</strong></td>
                                    <td data-label="Paiement">${s.payment_method_name || '-'}</td>
                                    <td data-label="Date">${new Date(s.sale_date).toLocaleString()}</td>
                                    <td data-label="Statut"><span class="badge badge-${s.status === 'completed' ? 'success' : 'danger'}">${s.status}</span></td>
                                    <td data-label="Actions" style="text-align:right">
                                        <button class="btn btn-sm btn-secondary" data-view-sale="${s.id}">Voir</button>
                                        ${s.status === 'completed' ? `<button class="btn btn-sm btn-danger" data-cancel-sale="${s.id}">Annuler</button>` : ''}
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                    ${sales.length === 0 ? '<div class="empty-state"><h3>Aucune vente trouvée</h3></div>' : ''}
                </div>
                ${pagination ? `
                <div class="pagination">
                    <button ${pagination.page <= 1 ? 'disabled' : ''} data-page="${pagination.page - 1}">Précédent</button>
                    <span class="page-info">Page ${pagination.page} sur ${pagination.total_pages}</span>
                    <button ${pagination.page >= pagination.total_pages ? 'disabled' : ''} data-page="${pagination.page + 1}">Suivant</button>
                </div>` : ''}
            `;

            document.getElementById('search-sales')?.addEventListener('input', function() {
                const q = this.value.toLowerCase();
                document.querySelectorAll('#sales-table-body tr').forEach(row => {
                    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
                });
            });

            document.getElementById('filter-sale-status')?.addEventListener('change', e => {
                renderSales({ ...params, status: e.target.value, page: 1 });
            });

            document.getElementById('btn-new-sale')?.addEventListener('click', createSale);

            document.querySelectorAll('[data-view-sale]').forEach(el => {
                el.addEventListener('click', () => viewSale(el.dataset.viewSale));
            });

            document.querySelectorAll('[data-cancel-sale]').forEach(el => {
                el.addEventListener('click', () => cancelSale(el.dataset.cancelSale));
            });

            document.querySelectorAll('.pagination button').forEach(el => {
                el.addEventListener('click', () => { if (!el.disabled) renderSales({ ...params, page: el.dataset.page }); });
            });

        } catch (err) {
            if (API.handleAuthError(err)) { renderLogin(); return; }
            showError(err.message || 'Échec du chargement des ventes');
        }
    }

    async function viewSale(id) {
        try {
            const res = await API.getSale(id);
            const s = res.data;
            createModal({
                title: `Vente ${s.sale_number}`,
                size: 'modal-lg',
                content: `
                    <div class="grid-2">
                        <div>
                            <div class="detail-row"><span class="detail-label">N°</span><span class="detail-value"><strong>${s.sale_number}</strong></span></div>
                            <div class="detail-row"><span class="detail-label">Caissier</span><span class="detail-value">${s.cashier || '-'}</span></div>
                            <div class="detail-row"><span class="detail-label">Client</span><span class="detail-value">${s.customer_name || 'Libre'}</span></div>
                            <div class="detail-row"><span class="detail-label">Paiement</span><span class="detail-value">${s.payment_method_name || '-'}</span></div>
                        </div>
                        <div>
                            <div class="detail-row"><span class="detail-label">Sous-total</span><span class="detail-value">${Number(s.subtotal).toFixed(2)} MAD</span></div>
                            <div class="detail-row"><span class="detail-label">Avance</span><span class="detail-value">-${Number(s.discount_amount).toFixed(2)} MAD</span></div>
                            <div class="detail-row"><span class="detail-label">Total</span><span class="detail-value"><strong>${Number(s.total_amount).toFixed(2)} MAD</strong></span></div>
                            <div class="detail-row"><span class="detail-label">Payé</span><span class="detail-value">${Number(s.paid_amount).toFixed(2)} MAD</span></div>
                            <div class="detail-row"><span class="detail-label">Monnaie</span><span class="detail-value">${Number(s.change_amount).toFixed(2)} MAD</span></div>
                            <div class="detail-row"><span class="detail-label">Statut</span><span class="detail-value"><span class="badge badge-${s.status === 'completed' ? 'success' : 'danger'}">${s.status}</span></span></div>
                        </div>
                    </div>
                    ${(s.items || []).length ? `
                        <h4 style="margin-top:16px;margin-bottom:8px;">Articles</h4>
                        <table>
                            <thead><tr><th>Produit</th><th>Qté</th><th>Prix</th><th>Rem%</th><th>Sous-total</th></tr></thead>
                            <tbody>${s.items.map(i => `
                                <tr><td>${i.product_name}</td><td>${Number(i.quantity).toFixed(2)}</td><td>${Number(i.unit_price).toFixed(2)}</td><td>${i.discount_pct || 0}%</td><td>${Number(i.subtotal).toFixed(2)}</td></tr>
                            `).join('')}</tbody>
                        </table>` : ''}
                    ${s.notes ? `<div class="detail-row" style="margin-top:12px;"><span class="detail-label">Notes</span><span class="detail-value">${s.notes}</span></div>` : ''}
                    <div style="margin-top:16px;display:flex;gap:8px;">
                        <button class="btn btn-secondary" id="btn-download-invoice">&#128196; Télécharger la facture (Word)</button>
                    </div>
                `,
            });

            document.getElementById('btn-download-invoice')?.addEventListener('click', () => {
                API.downloadSaleDocx(id).catch(err => {
                    showToast(err.message || 'Échec du téléchargement', 'error');
                });
            });
        } catch (err) {
            showToast(err.message || 'Échec du chargement de la vente', 'error');
        }
    }

    async function cancelSale(id) {
        if (!confirm('Annuler cette vente ? Cette action est irréversible.')) return;
        try {
            await API.cancelSale(id);
            showToast('Vente annulée', 'success');
            markMutated();
            renderSales();
        } catch (err) {
            showToast(err.message || 'Échec de l\'annulation de la vente', 'error');
        }
    }

    async function createSale() {
        let customerOptions = '<option value="">Client libre</option>';
        let productRowsHtml = '';
        const barcodeMap = {};
        let productMap = {};

        try {
            const [custRes, prodRes] = await Promise.all([
                API.getCustomers({ per_page: 200 }),
                API.getProducts({ per_page: 500 }),
            ]);

            customerOptions += (custRes.data.customers || []).map(c =>
                `<option value="${c.id}">${c.first_name} ${c.last_name}</option>`
            ).join('');

            const products = (prodRes.data.products || []).filter(p => p.is_active);
            productMap = {};
            products.forEach(p => {
                productMap[p.id] = p;
                if (p.barcode) barcodeMap[p.barcode] = p;
                const name = p.name.replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                const meta = (p.barcode ? p.barcode : '') + (p.barcode && p.category_name ? ' &middot; ' : '') + (p.category_name ? p.category_name.replace(/"/g, '&quot;') : '');
                const stockQty = Number(p.stock_quantity);
                const stockMin = Number(p.stock_min);
                let stockBadge = '';
                if (p.is_service) {
                    stockBadge = `<span class="stock-badge stock-service">Service</span>`;
                } else if (stockQty <= 0) {
                    stockBadge = `<span class="stock-badge stock-out">${stockQty.toFixed(0)}</span>`;
                } else if (stockQty <= stockMin) {
                    stockBadge = `<span class="stock-badge stock-low">${stockQty.toFixed(0)}</span>`;
                } else {
                    stockBadge = `<span class="stock-badge stock-ok">${stockQty.toFixed(0)}</span>`;
                }
                productRowsHtml += `
                <div class="product-card" data-pid="${p.id}" data-name="${name}" data-price="${p.selling_price}" data-tax="${p.tax_rate}" data-barcode="${p.barcode || ''}">
                    <div class="product-card-info">
                        <div class="product-card-name">${name}</div>
                        <div class="product-card-meta">${meta}</div>
                    </div>
                    <div class="product-card-footer">
                        <div class="product-card-price">${Number(p.selling_price).toFixed(2)}</div>
                        <div class="product-card-actions">
                            <button type="button" class="btn btn-sm btn-success add-to-cart" title="Ajouter au panier">+</button>
                        </div>
                    </div>
                    ${stockBadge}
                </div>`;
            });
        } catch (err) {
            showToast(err.message || 'Échec du chargement des produits', 'error');
            return;
        }

        const modal = createModal({
            title: 'Nouvelle vente',
            size: 'modal-lg',
            content: `
                <style>
                    .sale-layout { display:grid; grid-template-columns:1fr 380px; gap:16px; }
                    .cart-items { max-height:300px; overflow-y:auto; }
                    .cart-item { display:flex; align-items:center; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border); font-size:0.85rem; }
                    .cart-item-qty { width:60px; text-align:center; }
                    .cart-total { font-size:1.25rem; font-weight:700; text-align:right; padding-top:12px; border-top:2px solid var(--gray-200); }
                    .barcode-scanner { display:flex; gap:8px; margin-bottom:8px; }
                    .barcode-scanner input { flex:1; font-size:1.1rem; letter-spacing:1px; }
                    .barcode-scanner .scanner-icon { font-size:1.3rem; line-height:38px; }
                    .product-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:6px; max-height:380px; overflow-y:auto; padding:4px; }
                    .product-card { display:flex; flex-direction:column; padding:8px; border:1px solid var(--border); border-radius:var(--radius); background:var(--card-bg); cursor:pointer; transition:box-shadow .15s; position:relative; }
                    .product-card:hover { box-shadow:0 1px 6px rgba(0,0,0,0.1); border-color:var(--primary); }
                    .product-card-info { flex:1; min-width:0; }
                    .product-card-name { font-weight:600; font-size:0.8rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
                    .product-card-meta { font-size:0.65rem; color:var(--text-secondary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
                    .product-card-footer { display:flex; align-items:center; justify-content:space-between; margin-top:4px; }
                    .product-card-price { font-size:0.9rem; font-weight:700; color:var(--primary); }
                    .product-card-actions { }
                    .product-card-actions .btn { padding:2px 8px; font-size:0.75rem; }
                    .product-card.hidden { display:none; }
                    .stock-badge { position:absolute; top:4px; right:4px; font-size:0.6rem; font-weight:700; padding:1px 5px; border-radius:8px; color:#fff; line-height:1.4; }
                    .stock-badge.stock-ok { background:#16a34a; }
                    .stock-badge.stock-low { background:#dc2626; }
                    .stock-badge.stock-out { background:#dc2626; }
                    .stock-badge.stock-service { background:#6b7280; }
                    @media (max-width:768px) { .sale-layout { grid-template-columns:1fr; } .product-grid { grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); } }
                </style>
                <div class="sale-layout">
                    <div>
                        <div class="barcode-scanner">
                            <span class="scanner-icon">\uD83D\uDCF7</span>
                            <input type="text" id="sale-barcode" class="form-control" placeholder="Scanner un code-barres..." autofocus>
                        </div>
                        <div class="toolbar" style="margin-bottom:8px;">
                            <input type="text" id="sale-search-product" class="form-control" placeholder="Rechercher des produits..." style="flex:1;">
                        </div>
                        <div class="product-grid" id="product-grid">${productRowsHtml || '<div style="text-align:center;padding:20px;color:var(--text-secondary);font-size:0.85rem;">Aucun produit disponible</div>'}</div>
                    </div>
                    <div>
                        <div class="form-group">
                            <label class="form-label">Client</label>
                            <select class="form-control" id="sale-customer">${customerOptions}</select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Moyen de paiement</label>
                            <select class="form-control" id="sale-payment">
                                <option value="1">Espèces</option>
                                <option value="2">Carte</option>
                                <option value="6">Paiement mobile</option>
                            </select>
                        </div>
                        <div id="stripe-card-section" style="display:none;margin-bottom:8px;padding:8px;border:1px dashed var(--gray-300);border-radius:var(--radius);">
                            <div id="stripe-card-form">
                                <div style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:4px;">Paiement par carte sécurisé</div>
                                <div id="card-element" style="padding:8px;border:1px solid var(--border);border-radius:4px;background:#fff;">
                                    <div style="display:flex;gap:8px;align-items:center;">
                                        <span style="font-size:1.2rem;">&#128179;</span>
                                        <span style="color:var(--text-secondary);font-size:0.85rem;" id="stripe-card-placeholder">Prêt à traiter le paiement par carte...</span>
                                    </div>
                                </div>
                                <div id="stripe-card-error" style="color:#dc2626;font-size:0.8rem;margin-top:4px;display:none;"></div>
                            </div>
                            <div id="stripe-card-success" style="display:none;">
                                <div style="color:#16a34a;font-size:0.9rem;">&#10003; Paiement par carte réussi</div>
                            </div>
                        </div>
                        <div id="mobile-payment-section" style="display:none;margin-bottom:8px;padding:8px;border:1px dashed var(--gray-300);border-radius:var(--radius);">
                            <div style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:4px;">Paiement mobile</div>
                            <div id="mobile-payment-info" style="padding:8px;background:var(--gray-50);border-radius:4px;">
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <span style="font-size:1.2rem;">&#128241;</span>
                                    <span id="mobile-payment-text" style="color:var(--text-secondary);font-size:0.85rem;">Génération du lien de paiement mobile...</span>
                                </div>
                                <div id="mobile-payment-link" style="display:none;margin-top:4px;">
                                    <a href="#" id="mobile-payment-url" target="_blank" style="font-size:0.85rem;">Ouvrir le lien de paiement</a>
                                    <span style="font-size:0.8rem;color:var(--text-secondary);margin-left:8px;">Réf: <span id="mobile-payment-ref"></span></span>
                                </div>
                            </div>
                        </div>
                        <h4 style="margin-bottom:8px;">Panier</h4>
                        <div class="cart-items" id="cart-items">
                            <div style="text-align:center;padding:20px;color:var(--text-secondary);font-size:0.85rem;">Ajoutez des produits depuis la liste</div>
                        </div>
                        <div style="margin-top:8px;">
                            <div class="detail-row"><span class="detail-label">Sous-total</span><span class="detail-value" id="cart-subtotal">0.00</span></div>
                            <div class="detail-row"><span class="detail-label">Avance</span><span class="detail-value">
                                <input type="number" id="cart-discount" value="0" step="0.01" class="form-control" style="width:100px;text-align:right;display:inline;">
                            </span></div>
                            <div class="cart-total" style="margin-top:8px;">
                                Total: <span id="cart-total">0.00</span> MAD
                            </div>
                        </div>
                        <div class="form-group" style="margin-top:8px;">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" id="sale-notes" rows="2"></textarea>
                        </div>
                        <button class="btn btn-success btn-lg" id="btn-complete-sale" style="width:100%;">Finaliser la vente</button>
                    </div>
                </div>
            `,
        });

        // Cart logic
        const cart = {};
        let searchTimeout;

        function updateCart() {
            const container = document.getElementById('cart-items');
            const items = Object.values(cart);
            let subtotal = 0;

            if (items.length === 0) {
                container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-secondary);font-size:0.85rem;">Ajoutez des produits depuis la liste</div>';
                document.getElementById('cart-subtotal').textContent = '0.00';
                document.getElementById('cart-total').textContent = '0.00';
                return;
            }

            container.innerHTML = items.map((item, idx) => {
                const lineTotal = item.qty * item.price * (1 - item.discount / 100);
                subtotal += lineTotal;
                return `
                    <div class="cart-item">
                        <div style="flex:1;min-width:0;">
                            <div style="font-weight:600;font-size:0.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${item.name}</div>
                            <div style="font-size:0.75rem;color:var(--text-secondary);">${item.qty} x ${Number(item.price).toFixed(2)}</div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-weight:600;">${Number(lineTotal).toFixed(2)}</div>
                            <button class="btn btn-sm btn-danger" style="padding:2px 6px;font-size:0.7rem;" data-remove-cart="${item.pid}">Retirer</button>
                        </div>
                    </div>
                `;
            }).join('');

            const discount = parseFloat(document.getElementById('cart-discount')?.value || '0');
            const total = subtotal - discount;

            document.getElementById('cart-subtotal').textContent = subtotal.toFixed(2);
            document.getElementById('cart-total').textContent = total.toFixed(2);

            document.querySelectorAll('[data-remove-cart]').forEach(el => {
                el.addEventListener('click', () => {
                    delete cart[el.dataset.removeCart];
                    updateCart();
                });
            });
        }

        document.getElementById('sale-search-product')?.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const q = this.value.toLowerCase();
                document.querySelectorAll('.product-card').forEach(card => {
                    const name = card.dataset.name?.toLowerCase() || '';
                    const barcode = card.dataset.barcode?.toLowerCase() || '';
                    card.classList.toggle('hidden', !(name.includes(q) || barcode.includes(q)));
                });
            }, 300);
        });

        const barcodeInput = document.getElementById('sale-barcode');
        if (barcodeInput) {
            barcodeInput.focus();
            barcodeInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === 'Tab' || e.keyCode === 13 || e.keyCode === 9) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                if (e.key !== 'Enter' && e.keyCode !== 13) return;
                const code = this.value.trim();
                if (!code) return;

                const p = barcodeMap[code];
                if (!p) {
                    showToast('Code-barres introuvable', 'error');
                    this.value = '';
                    this.focus();
                    return;
                }

                const pid = p.id;
                const qty = 1;
                const price = Number(p.selling_price);
                const discount = 0;
                const pname = p.name || 'Produit';

                if (cart[pid]) {
                    cart[pid].qty += qty;
                } else {
                    cart[pid] = {
                        pid,
                        name: pname,
                        qty,
                        price,
                        discount,
                        tax: Number(p.tax_rate || 20),
                    };
                }
                updateCart();
                showToast(`${pname} ajouté au panier`, 'success');
                this.value = '';
                this.focus();
            });
        }

        document.querySelectorAll('.product-card').forEach(el => {
            el.addEventListener('click', function(e) {
                if (e.target.closest('.product-card-actions')) return;
                const btn = this.querySelector('.add-to-cart');
                if (btn) btn.click();
            });
        });

        document.querySelectorAll('.add-to-cart').forEach(el => {
            el.addEventListener('click', function(e) {
                e.stopPropagation();
                const card = this.closest('.product-card');
                if (!card) return;
                const pid = card.dataset.pid;
                const p = productMap[pid] || {};
                const pname = p.name || card.dataset.name || 'Produit';
                const price = parseFloat(p.selling_price || card.dataset.price);
                const tax = parseFloat(p.tax_rate || card.dataset.tax || '20');

                if (cart[pid]) {
                    cart[pid].qty += 1;
                } else {
                    cart[pid] = { pid, name: pname, qty: 1, price, discount: 0, tax };
                }
                updateCart();
                showToast(`${pname} ajouté au panier`, 'success');
            });
        });

        document.getElementById('cart-discount')?.addEventListener('input', updateCart);

        // Payment method toggle
        const stripeSection = document.getElementById('stripe-card-section');
        const mobileSection = document.getElementById('mobile-payment-section');
        document.getElementById('sale-payment')?.addEventListener('change', function() {
            const val = this.value;
            if (stripeSection) stripeSection.style.display = val === '2' ? '' : 'none';
            if (mobileSection) mobileSection.style.display = val === '6' ? '' : 'none';
        });

        document.getElementById('btn-complete-sale')?.addEventListener('click', async () => {
            const items = Object.values(cart);
            if (items.length === 0) {
                showToast('Le panier est vide', 'warning');
                return;
            }

            const subtotal = items.reduce((sum, i) => sum + i.qty * i.price * (1 - i.discount / 100), 0);
            const discount = parseFloat(document.getElementById('cart-discount')?.value || '0');

            if (discount > subtotal) {
                showToast('L\'avance ne peut pas dépasser le sous-total', 'warning');
                return;
            }

            const paymentMethodId = parseInt(document.getElementById('sale-payment')?.value || '1');
            const btn = document.getElementById('btn-complete-sale');
            btn.disabled = true;
            btn.textContent = 'Traitement...';

            try {
                // Stripe card payment: create + capture payment intent
                if (paymentMethodId === 2) {
                    const cardForm = document.getElementById('stripe-card-form');
                    const cardSuccess = document.getElementById('stripe-card-success');
                    const cardError = document.getElementById('stripe-card-error');
                    if (cardForm) cardForm.style.display = 'none';
                    if (cardError) cardError.style.display = 'none';

                    const intentRes = await API.createPaymentIntent(subtotal - discount, 'mad', 'SuperMa POS - Vente');
                    const intent = intentRes.data;

                    if (intent.simulated) {
                        // Simulation mode — automatically "confirm"
                        const captureRes = await API.capturePayment(intent.id);
                        if (cardSuccess) { cardSuccess.style.display = ''; }
                    } else {
                        // Live mode — would use Stripe.js to confirm card payment
                        showToast('Mode simulation: paiement traité', 'info');
                        const captureRes = await API.capturePayment(intent.id);
                        if (cardSuccess) { cardSuccess.style.display = ''; }
                    }
                }

                // Mobile payment: generate link
                if (paymentMethodId === 6) {
                    const mobileLinkRes = await API.createMobileLink(subtotal - discount, 'SuperMa POS - Vente');
                    const linkData = mobileLinkRes.data;
                    const linkContainer = document.getElementById('mobile-payment-link');
                    const linkEl = document.getElementById('mobile-payment-url');
                    const refEl = document.getElementById('mobile-payment-ref');
                    const textEl = document.getElementById('mobile-payment-text');
                    if (textEl) textEl.textContent = 'Lien de paiement généré';
                    if (linkEl) linkEl.href = linkData.url;
                    if (refEl) refEl.textContent = linkData.reference;
                    if (linkContainer) linkContainer.style.display = '';
                }

                const saleData = {
                    customer_id: document.getElementById('sale-customer')?.value || null,
                    payment_method_id: paymentMethodId,
                    discount_amount: discount,
                    paid_amount: subtotal - discount,
                    notes: document.getElementById('sale-notes')?.value || '',
                    items: items.map(i => ({
                        product_id: i.pid,
                        quantity: i.qty,
                        unit_price: i.price,
                        discount_pct: i.discount,
                        tax_rate: i.tax,
                    })),
                };

                const res = await API.createSale(saleData);
                showToast(`Vente ${res.data.sale_number} terminée !`, 'success');
                modal.remove();
                markMutated();
                renderSales();
            } catch (err) {
                showToast(err.message || 'Échec de la création de la vente', 'error');
                const cardError = document.getElementById('stripe-card-error');
                if (cardError) { cardError.textContent = err.message || 'Erreur de paiement'; cardError.style.display = ''; }
                const cardForm = document.getElementById('stripe-card-form');
                if (cardForm) cardForm.style.display = '';
            } finally {
                btn.disabled = false;
                btn.textContent = 'Finaliser la vente';
            }
        });
    }

    // --- Users (Admin only) ---
    async function renderUsers(params = {}) {
        setPageTitle('Utilisateurs');
        setPageActions(`<button class="btn btn-primary" id="btn-add-user">+ Nouvel utilisateur</button>${isAdmin() ? ' <button class="btn btn-secondary btn-export" data-export-type="users">Exporter</button>' : ''}`);
        showLoading(true);

        try {
            const res = await API.getUsers({ ...params, per_page: 20 });
            const { users, pagination } = res.data;
            const body = document.getElementById('page-body');

            body.innerHTML = `
                <div class="toolbar">
                    <div class="search-box">
                        <input type="text" autofocus id="search-users" placeholder="Rechercher des utilisateurs..." value="${params.search || ''}">
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Email</th>
                                <th>Magasin</th>
                                <th>Rôles</th>
                                <th>Dernière connexion</th>
                                <th>Statut</th>
                                <th style="text-align:right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="users-table-body">
                            ${users.map(u => `
                                <tr>
                                    <td><strong>${u.first_name} ${u.last_name}</strong></td>
                                    <td>${u.email}</td>
                                    <td>${u.store_name || '-'}</td>
                                    <td>${(u.roles || []).map(r => `<span class="badge badge-info" style="margin-right:4px;">${r}</span>`).join('') || '-'}</td>
                                    <td>${u.last_login_at ? new Date(u.last_login_at).toLocaleString() : 'Jamais'}</td>
                                    <td><span class="badge badge-${u.is_active ? 'success' : 'danger'}">${u.is_active ? 'Actif' : 'Inactif'}</span></td>
                                    <td style="text-align:right">
                                        <button class="btn btn-sm btn-secondary" data-edit-user="${u.id}">Modifier</button>
                                        <button class="btn btn-sm btn-secondary" data-roles-user="${u.id}" data-name="${u.first_name} ${u.last_name}">Rôles</button>
                                        ${isAdmin() && u.id !== state.user.id ? `<button class="btn btn-sm btn-danger" data-delete-user="${u.id}">Désactiver</button>` : ''}
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                    ${users.length === 0 ? '<div class="empty-state"><h3>Aucun utilisateur</h3></div>' : ''}
                </div>
                ${pagination ? `
                <div class="pagination">
                    <button ${pagination.page <= 1 ? 'disabled' : ''} data-page="${pagination.page - 1}">Précédent</button>
                    <span class="page-info">Page ${pagination.page} sur ${pagination.total_pages}</span>
                    <button ${pagination.page >= pagination.total_pages ? 'disabled' : ''} data-page="${pagination.page + 1}">Suivant</button>
                </div>` : ''}
            `;

            document.getElementById('search-users')?.addEventListener('input', function() {
                const q = this.value.toLowerCase();
                document.querySelectorAll('#users-table-body tr').forEach(row => {
                    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
                });
            });

            document.getElementById('btn-add-user')?.addEventListener('click', () => editUser());

            document.querySelectorAll('[data-edit-user]').forEach(el => {
                el.addEventListener('click', () => editUser(el.dataset.editUser));
            });

            document.querySelectorAll('[data-delete-user]').forEach(el => {
                el.addEventListener('click', () => deleteUser(el.dataset.deleteUser));
            });

            document.querySelectorAll('[data-roles-user]').forEach(el => {
                el.addEventListener('click', () => manageRoles(el.dataset.rolesUser, el.dataset.name));
            });

            document.querySelectorAll('.pagination button').forEach(el => {
                el.addEventListener('click', () => { if (!el.disabled) renderUsers({ ...params, page: el.dataset.page }); });
            });

        } catch (err) {
            if (API.handleAuthError(err)) { renderLogin(); return; }
            showError(err.message || 'Échec du chargement des utilisateurs');
        }
    }

    async function editUser(id = null) {
        const isEdit = !!id;
        let userData = { first_name: '', last_name: '', email: '', phone: '', password: '', roles: [] };
        let roleOptions = '';
        let userRoleIds = [];

        try {
            const rolesRes = await API.getRoles();
            const allRoles = rolesRes.data.roles || [];

            if (isEdit) {
                try {
                    const res = await API.getUser(id);
                    userData = { ...res.data, password: '' };
                    userRoleIds = (userData.roles || []).map(r => r.id);
                } catch (err) {
                    showToast(err.message || 'Échec du chargement de l\'utilisateur', 'error');
                    return;
                }
            }

            roleOptions = allRoles.map(r => {
                const selected = isEdit && userRoleIds.includes(r.id) ? 'selected' : '';
                return `<option value="${r.id}" ${selected}>${r.name}</option>`;
            }).join('');
        } catch (err) {
            showToast(err.message || 'Échec du chargement des rôles', 'error');
            return;
        }

        const modal = createModal({
            title: isEdit ? 'Modifier l\'utilisateur' : 'Nouvel utilisateur',
            content: `
                <form id="user-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Prénom *</label>
                            <input type="text" class="form-control" name="first_name" value="${userData.first_name || ''}" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Nom *</label>
                            <input type="text" class="form-control" name="last_name" value="${userData.last_name || ''}" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" value="${userData.email || ''}" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Téléphone</label>
                            <input type="text" class="form-control" name="phone" value="${userData.phone || ''}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mot de passe ${isEdit ? '(laisser vide pour conserver l\'actuel)' : '*'}</label>
                        <input type="password" class="form-control" name="password" ${!isEdit ? 'required' : ''}>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Rôles</label>
                        <select class="form-control" name="roles" multiple style="min-height:100px;">
                            ${roleOptions}
                        </select>
                        <small style="color:var(--text-secondary);">Maintenez Ctrl/Cmd pour une sélection multiple</small>
                    </div>
                </form>
            `,
            footer: `
                <button class="btn btn-secondary" data-close-modal>Annuler</button>
                <button class="btn btn-primary" id="btn-save-user">${isEdit ? 'Mettre à jour' : 'Créer'}</button>
            `,
        });

        document.getElementById('btn-save-user').addEventListener('click', async () => {
            const form = document.getElementById('user-form');
            const formData = new FormData(form);
            const data = Object.fromEntries(formData);
            data.roles = formData.getAll('roles').map(Number);

            if (!data.password) delete data.password;

            try {
                if (isEdit) {
                    await API.updateUser(id, data);
                    showToast('Utilisateur mis à jour', 'success');
                } else {
                    await API.createUser(data);
                    showToast('Utilisateur créé', 'success');
                }
                modal.remove();
                renderUsers();
            } catch (err) {
                showToast(err.message || 'Échec de l\'enregistrement de l\'utilisateur', 'error');
            }
        });
    }

    async function manageRoles(userId, userName) {
        try {
            const [rolesRes, userRes] = await Promise.all([
                API.getRoles({ with_permissions: 1 }),
                API.getUser(userId)
            ]);
            const allRoles = rolesRes.data.roles || [];
            const userRoleIds = (userRes.data.roles || []).map(r => r.id);

            const modal = createModal({
                title: `Rôles — ${userName}`,
                content: `
                    <form id="roles-form">
                        <p style="color:var(--text-secondary);margin-bottom:16px;">
                            Sélectionnez les rôles pour <strong>${userName}</strong>
                        </p>
                        <div style="display:flex;flex-direction:column;gap:8px;">
                            ${allRoles.map(r => `
                                <label class="checkbox-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:8px;border:1px solid var(--border);border-radius:6px;">
                                    <input type="checkbox" name="roles" value="${r.id}" ${userRoleIds.includes(r.id) ? 'checked' : ''}>
                                    <strong>${r.name}</strong>
                                    <span style="color:var(--text-secondary);font-size:0.9em;">${r.description || ''}</span>
                                </label>
                            `).join('')}
                        </div>
                    </form>
                `,
                footer: `
                    <button class="btn btn-secondary" data-close-modal>Annuler</button>
                    <button class="btn btn-primary" id="btn-save-roles">Enregistrer les rôles</button>
                `,
            });

            document.getElementById('btn-save-roles').addEventListener('click', async () => {
                const form = document.getElementById('roles-form');
                const fd = new FormData(form);
                const roleIds = fd.getAll('roles').map(Number);
                try {
                    await API.updateUser(userId, { roles: roleIds });
                    showToast('Rôles mis à jour', 'success');
                    modal.remove();
                    renderUsers();
                } catch (err) {
                    showToast(err.message || 'Échec de la mise à jour des rôles', 'error');
                }
            });
        } catch (err) {
            showToast(err.message || 'Échec du chargement des rôles', 'error');
        }
    }

    async function deleteUser(id) {
        if (!confirm('Désactiver cet utilisateur ?')) return;
        try {
            await API.deleteUser(id);
            showToast('Utilisateur désactivé', 'success');
            renderUsers();
        } catch (err) {
            showToast(err.message || 'Échec de la désactivation de l\'utilisateur', 'error');
        }
    }

    // --- Stores (Admin only) ---
    async function renderStores() {
        setPageTitle('Magasins');
        setPageActions(`<button class="btn btn-primary" id="btn-add-store">+ Nouveau magasin</button>${isAdmin() ? ' <button class="btn btn-secondary btn-export" data-export-type="stores">Exporter</button>' : ''}`);
        showLoading(true);

        try {
            const res = await API.getStores();
            const stores = res.data.stores || [];
            const body = document.getElementById('page-body');

            body.innerHTML = `
                <div class="grid-2" style="grid-template-columns: 1fr;">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Ville</th>
                                    <th>Téléphone</th>
                                    <th>Email</th>
                                    <th>Devise</th>
                                    <th>Statut</th>
                                    <th style="text-align:right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${stores.map(s => `
                                    <tr>
                                        <td><strong>${s.name}</strong></td>
                                        <td>${s.city || '-'}</td>
                                        <td>${s.phone || '-'}</td>
                                        <td>${s.email || '-'}</td>
                                        <td>${s.currency || 'MAD'}</td>
                                        <td><span class="badge badge-${s.is_active ? 'success' : 'gray'}">${s.is_active ? 'Actif' : 'Inactif'}</span></td>
                                        <td style="text-align:right">
                                            <button class="btn btn-sm btn-secondary" data-edit-store="${s.id}">Modifier</button>
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                        ${stores.length === 0 ? '<div class="empty-state"><h3>Aucun magasin</h3></div>' : ''}
                    </div>
                </div>
            `;

            document.getElementById('btn-add-store')?.addEventListener('click', () => editStore());
            document.querySelectorAll('[data-edit-store]').forEach(el => {
                el.addEventListener('click', () => editStore(el.dataset.editStore));
            });

        } catch (err) {
            if (API.handleAuthError(err)) { renderLogin(); return; }
            showError(err.message || 'Échec du chargement des magasins');
        }
    }

    async function editStore(id = null) {
        const isEdit = !!id;
        let store = { name: '', address: '', city: '', country: 'MA', phone: '', email: '', tax_id: '', currency: 'MAD', timezone: 'Africa/Casablanca' };
        if (isEdit) {
            try {
                const res = await API.getStore(id);
                store = res.data;
            } catch (err) {
                showToast(err.message || 'Échec du chargement du magasin', 'error');
            }
        }

        const modal = createModal({
            title: isEdit ? 'Modifier le magasin' : 'Nouveau magasin',
            size: 'modal-lg',
            content: `
                <form id="store-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Nom *</label>
                            <input type="text" class="form-control" name="name" value="${store.name || ''}" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">N° de TVA</label>
                            <input type="text" class="form-control" name="tax_id" value="${store.tax_id || ''}">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="${store.email || ''}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Téléphone</label>
                            <input type="text" class="form-control" name="phone" value="${store.phone || ''}">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Adresse</label>
                            <input type="text" class="form-control" name="address" value="${store.address || ''}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Ville</label>
                            <input type="text" class="form-control" name="city" value="${store.city || ''}">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Pays</label>
                            <input type="text" class="form-control" name="country" value="${store.country || 'MA'}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Devise</label>
                            <select class="form-control" name="currency">
                                <option value="MAD" ${store.currency === 'MAD' ? 'selected' : ''}>MAD</option>
                                <option value="EUR" ${store.currency === 'EUR' ? 'selected' : ''}>EUR</option>
                                <option value="USD" ${store.currency === 'USD' ? 'selected' : ''}>USD</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fuseau horaire</label>
                        <input type="text" class="form-control" name="timezone" value="${store.timezone || 'Africa/Casablanca'}">
                    </div>
                </form>
            `,
            footer: `
                <button class="btn btn-secondary" data-close-modal>Annuler</button>
                <button class="btn btn-primary" id="btn-save-store">${isEdit ? 'Mettre à jour' : 'Créer'}</button>
            `,
        });

        document.getElementById('btn-save-store').addEventListener('click', async () => {
            const form = document.getElementById('store-form');
            const data = Object.fromEntries(new FormData(form));
            try {
                if (isEdit) {
                    await API.updateStore(id, data);
                    showToast('Magasin mis à jour', 'success');
                } else {
                    await API.createStore(data);
                    showToast('Magasin créé', 'success');
                }
                modal.remove();
                renderStores();
            } catch (err) {
                showToast(err.message || 'Échec de l\'enregistrement du magasin', 'error');
            }
        });
    }

    // --- Log viewer ---
    async function renderLogs() {
        setPageTitle('Journaux d\'activité');
        setPageActions('');
        showLoading(true);

        const body = document.getElementById('page-body');
        const params = new URLSearchParams(window.location.search);
        let currentChannel = params.get('channel') || 'app';
        let currentDate = params.get('date') || new Date().toISOString().slice(0, 10);
        let currentLevel = params.get('level') || '';
        let currentSearch = params.get('search') || '';

        try {
            const [channelsRes, datesRes, logsRes] = await Promise.all([
                API.getLogChannels(),
                API.getLogDates(currentChannel),
                API.getLogs({ channel: currentChannel, date: currentDate, level: currentLevel, search: currentSearch, per_page: 200 }),
            ]);

            const channels = channelsRes.data.channels || [];
            const dates = datesRes.data.dates || [];
            const { logs, total } = logsRes.data || { logs: [], total: 0 };

            body.innerHTML = `
                <div class="toolbar" style="flex-wrap:wrap;gap:8px;">
                    <select id="log-channel" class="form-control" style="width:auto;min-width:120px;">
                        ${channels.map(c => `<option value="${c}" ${c === currentChannel ? 'selected' : ''}>${c}</option>`).join('')}
                    </select>
                    <select id="log-date" class="form-control" style="width:auto;min-width:140px;">
                        ${dates.map(d => `<option value="${d}" ${d === currentDate ? 'selected' : ''}>${d}</option>`).join('')}
                        <option value="${currentDate}" ${!dates.includes(currentDate) ? 'selected' : ''}>${currentDate}</option>
                    </select>
                    <select id="log-level" class="form-control" style="width:auto;min-width:100px;">
                        <option value="">Tous niveaux</option>
                        <option value="ERROR" ${currentLevel === 'ERROR' ? 'selected' : ''}>ERROR</option>
                        <option value="WARNING" ${currentLevel === 'WARNING' ? 'selected' : ''}>WARNING</option>
                        <option value="INFO" ${currentLevel === 'INFO' ? 'selected' : ''}>INFO</option>
                        <option value="DEBUG" ${currentLevel === 'DEBUG' ? 'selected' : ''}>DEBUG</option>
                        <option value="CRITICAL" ${currentLevel === 'CRITICAL' ? 'selected' : ''}>CRITICAL</option>
                    </select>
                    <input type="text" id="log-search" class="form-control" placeholder="Rechercher..." value="${currentSearch}" style="flex:1;min-width:150px;">
                    <button class="btn btn-secondary" id="btn-refresh-logs">Actualiser</button>
                </div>
                <div class="table-container" style="margin-top:8px;">
                    <div style="max-height:600px;overflow-y:auto;font-family:monospace;font-size:0.8rem;line-height:1.5;">
                        ${logs.length === 0 ? '<div class="empty-state"><h3>Aucune entrée</h3></div>' : logs.map(l => {
                            const levelClass = l.level === 'ERROR' || l.level === 'CRITICAL' ? 'color:#dc2626;' :
                                l.level === 'WARNING' ? 'color:#d97706;' :
                                l.level === 'DEBUG' ? 'color:#6b7280;' : 'color:#16a34a;';
                            return `<div style="padding:4px 8px;border-bottom:1px solid var(--border);display:flex;gap:8px;">
                                <span style="color:var(--text-secondary);white-space:nowrap;">${l.timestamp}</span>
                                <span style="${levelClass}font-weight:600;white-space:nowrap;">[${l.level}]</span>
                                <span style="color:var(--text-secondary);white-space:nowrap;">${l.file}:${l.line}</span>
                                <span style="flex:1;word-break:break-all;">${l.message}</span>
                            </div>`;
                        }).join('')}
                    </div>
                </div>
                <div style="margin-top:8px;color:var(--text-secondary);font-size:0.85rem;">
                    ${total} entrées
                    <button class="btn btn-sm btn-secondary" id="btn-pg-log-config" style="margin-left:12px;">Configurer logs PostgreSQL</button>
                </div>
            `;

            document.getElementById('log-channel')?.addEventListener('change', function() {
                currentChannel = this.value;
                renderLogsWithParams();
            });
            document.getElementById('log-date')?.addEventListener('change', function() {
                currentDate = this.value;
                renderLogsWithParams();
            });
            document.getElementById('log-level')?.addEventListener('change', function() {
                currentLevel = this.value;
                renderLogsWithParams();
            });
            document.getElementById('log-search')?.addEventListener('input', debounce(function() {
                currentSearch = this.value;
                renderLogsWithParams();
            }, 400));
            document.getElementById('btn-refresh-logs')?.addEventListener('click', () => renderLogsWithParams());
            document.getElementById('btn-pg-log-config')?.addEventListener('click', () => {
                showToast('Fichier de configuration généré dans logs/postgresql/', 'info');
            });
        } catch (err) {
            if (API.handleAuthError(err)) { renderLogin(); return; }
            showError(err.message || 'Échec du chargement des journaux');
        }

        function renderLogsWithParams() {
            navigate(`logs?channel=${currentChannel}&date=${currentDate}&level=${currentLevel}&search=${currentSearch}`);
        }
    }

    // --- Logout ---
    async function handleLogout() {
        try {
            await API.logout();
        } catch (_) {}
        state = { ...state, user: null, roles: [], permissions: [] };
        renderLogin();
    }

    // --- Utilities ---
    function debounce(fn, ms = 300) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), ms);
        };
    }

    // --- Initialization ---
    document.addEventListener('DOMContentLoaded', async () => {
        try {
            const auth = await API.checkAuth();
            if (auth) {
                state.user = auth.user;
                state.roles = auth.roles;
                state.permissions = auth.permissions;
                renderLayout();
                navigate(getDefaultPage());
            } else {
                renderLogin();
            }
        } catch (_) {
            renderLogin();
        }
    });

    return { render: (view) => { if (view === 'login') renderLogin(); } };
})();
