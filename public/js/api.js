/* ============================================================
   SuperMa POS - API Client
   ============================================================ */

const API = (() => {
    const BASE = '/api';

    function getToken() {
        return sessionStorage.getItem('auth_token');
    }

    function setToken(token) {
        if (token) {
            sessionStorage.setItem('auth_token', token);
        } else {
            sessionStorage.removeItem('auth_token');
        }
    }

    function getCsrfMeta() {
        return {
            'X-Requested-With': 'XMLHttpRequest',
        };
    }

    async function request(endpoint, options = {}) {
        const url = `${BASE}${endpoint}`;
        const config = {
            headers: {
                'Content-Type': 'application/json',
                ...getCsrfMeta(),
                ...options.headers,
            },
            ...options,
        };

        const token = getToken();
        if (token) {
            config.headers['Authorization'] = `Bearer ${token}`;
        }

        if (config.body && typeof config.body === 'object') {
            config.body = JSON.stringify(config.body);
        }

        try {
            const response = await fetch(url, config);
            const data = await response.json();

            if (!response.ok) {
                const error = new Error(data.message || `HTTP ${response.status}`);
                error.status = response.status;
                error.data = data;
                throw error;
            }

            return data;
        } catch (err) {
            if (err.name === 'TypeError' && err.message === 'Failed to fetch') {
                const error = new Error('Network error. Please check your connection.');
                error.status = 0;
                throw error;
            }
            throw err;
        }
    }

    return {
        handleAuthError(err) {
            if (err.status === 401) {
                setToken(null);
                return true;
            }
            return false;
        },

        // Auth
        async login(email, password) {
            const data = await request('/login', {
                method: 'POST',
                body: { email, password },
            });
            if (data.data?.token) {
                setToken(data.data.token);
            }
            return data;
        },

        async logout() {
            try {
                await request('/login', { method: 'DELETE' });
            } finally {
                setToken(null);
            }
        },

        async checkAuth() {
            const token = getToken();
            if (!token) return null;
            try {
                const data = await request('/login', { method: 'GET' });
                return data.data;
            } catch (err) {
                if (err.status === 401) {
                    setToken(null);
                }
                return null;
            }
        },

        // Products
        async getProducts(params = {}) {
            const qs = new URLSearchParams(params).toString();
            return request(`/products${qs ? '?' + qs : ''}`);
        },

        async getProduct(id) {
            return request(`/products?id=${id}`);
        },

        async createProduct(data) {
            return request('/products', { method: 'POST', body: data });
        },

        async updateProduct(id, data) {
            return request(`/products?id=${id}`, { method: 'PUT', body: data });
        },

        async deleteProduct(id) {
            return request(`/products?id=${id}`, { method: 'DELETE' });
        },

        // Categories
        async getCategories(params = {}) {
            const qs = new URLSearchParams(params).toString();
            return request(`/categories${qs ? '?' + qs : ''}`);
        },

        async getCategory(id) {
            return request(`/categories?id=${id}`);
        },

        async createCategory(data) {
            return request('/categories', { method: 'POST', body: data });
        },

        async updateCategory(id, data) {
            return request(`/categories?id=${id}`, { method: 'PUT', body: data });
        },

        async deleteCategory(id) {
            return request(`/categories?id=${id}`, { method: 'DELETE' });
        },

        // Customers
        async getCustomers(params = {}) {
            const qs = new URLSearchParams(params).toString();
            return request(`/customers${qs ? '?' + qs : ''}`);
        },

        async getCustomer(id) {
            return request(`/customers?id=${id}`);
        },

        async createCustomer(data) {
            return request('/customers', { method: 'POST', body: data });
        },

        async updateCustomer(id, data) {
            return request(`/customers?id=${id}`, { method: 'PUT', body: data });
        },

        async deleteCustomer(id) {
            return request(`/customers?id=${id}`, { method: 'DELETE' });
        },

        // Suppliers
        async getSuppliers(params = {}) {
            const qs = new URLSearchParams(params).toString();
            return request(`/suppliers${qs ? '?' + qs : ''}`);
        },

        async getSupplier(id) {
            return request(`/suppliers?id=${id}`);
        },

        async createSupplier(data) {
            return request('/suppliers', { method: 'POST', body: data });
        },

        async updateSupplier(id, data) {
            return request(`/suppliers?id=${id}`, { method: 'PUT', body: data });
        },

        async deleteSupplier(id) {
            return request(`/suppliers?id=${id}`, { method: 'DELETE' });
        },

        // Users
        async getUsers(params = {}) {
            const qs = new URLSearchParams(params).toString();
            return request(`/users${qs ? '?' + qs : ''}`);
        },

        async getUser(id) {
            return request(`/users?id=${id}`);
        },

        async createUser(data) {
            return request('/users', { method: 'POST', body: data });
        },

        async updateUser(id, data) {
            return request(`/users?id=${id}`, { method: 'PUT', body: data });
        },

        async deleteUser(id) {
            return request(`/users?id=${id}`, { method: 'DELETE' });
        },

        // Stores
        async getStores(params = {}) {
            const qs = new URLSearchParams(params).toString();
            return request(`/stores${qs ? '?' + qs : ''}`);
        },

        async getStore(id) {
            return request(`/stores?id=${id}`);
        },

        async createStore(data) {
            return request('/stores', { method: 'POST', body: data });
        },

        async updateStore(id, data) {
            return request(`/stores?id=${id}`, { method: 'PUT', body: data });
        },

        async deleteStore(id) {
            return request(`/stores?id=${id}`, { method: 'DELETE' });
        },

        // Dashboard
        async getDashboard() {
            return request('/dashboard');
        },

        // Sales
        async getSales(params = {}) {
            const qs = new URLSearchParams(params).toString();
            return request(`/sales${qs ? '?' + qs : ''}`);
        },

        async getSale(id) {
            return request(`/sales?id=${id}`);
        },

        async createSale(data) {
            return request('/sales', { method: 'POST', body: data });
        },

        async cancelSale(id) {
            return request(`/sales?id=${id}`, { method: 'DELETE' });
        },

        async downloadSaleDocx(id) {
            const url = `${BASE}/sales?docx=1&id=${id}`;
            const token = getToken();
            const response = await fetch(url, {
                headers: { 'Authorization': `Bearer ${token}` },
            });
            if (!response.ok) {
                const text = await response.text();
                let msg = 'Facture download failed';
                try { const j = JSON.parse(text); msg = j.message || msg; } catch (_) {}
                throw new Error(msg);
            }
            const blob = await response.blob();
            const disposition = response.headers.get('Content-Disposition') || '';
            const match = disposition.match(/filename="?(.+?)"?$/);
            const filename = match ? match[1] : `facture-${id}.docx`;
            const url2 = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url2;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url2);
        },

        // Roles
        async getRoles(params = {}) {
            const qs = new URLSearchParams(params).toString();
            return request(`/roles${qs ? '?' + qs : ''}`);
        },

        // Stock
        async getStock(params = {}) {
            const qs = new URLSearchParams(params).toString();
            return request(`/stock${qs ? '?' + qs : ''}`);
        },

        async adjustStock(data) {
            return request('/stock', { method: 'POST', body: data });
        },

        // Orders
        async getOrders(params = {}) {
            const qs = new URLSearchParams(params).toString();
            return request(`/orders${qs ? '?' + qs : ''}`);
        },

        async getOrder(id) {
            return request(`/orders?id=${id}`);
        },

        async createOrder(data) {
            return request('/orders', { method: 'POST', body: data });
        },

        async updateOrder(id, data) {
            return request(`/orders?id=${id}`, { method: 'PUT', body: data });
        },

        async cancelOrder(id) {
            return request(`/orders?id=${id}`, { method: 'DELETE' });
        },

        async downloadOrderDocx(id) {
            const url = `${BASE}/orders?docx=1&id=${id}`;
            const token = getToken();
            const response = await fetch(url, {
                headers: { 'Authorization': `Bearer ${token}` },
            });
            if (!response.ok) {
                const text = await response.text();
                let msg = 'PDF download failed';
                try { const j = JSON.parse(text); msg = j.message || msg; } catch (_) {}
                throw new Error(msg);
            }
            const blob = await response.blob();
            const disposition = response.headers.get('Content-Disposition') || '';
            const match = disposition.match(/filename="?(.+?)"?$/);
            const filename = match ? match[1] : `order-${id}.pdf`;
            const url2 = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url2;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url2);
        },

        // Stripe
        async getStripeConfig() {
            return request('/stripe');
        },

        async createPaymentIntent(amount, currency = 'mad', description = 'SuperMa POS') {
            return request('/stripe', { method: 'POST', body: { action: 'create_intent', amount, currency, description } });
        },

        async capturePayment(intentId) {
            return request('/stripe', { method: 'POST', body: { action: 'capture', intent_id: intentId } });
        },

        async createMobileLink(amount, description = 'SuperMa POS') {
            return request('/stripe', { method: 'POST', body: { action: 'mobile_link', amount, description } });
        },

        // Ping (mutation signaling)
        async ping() {
            try {
                await fetch(`${BASE}/ping`, {
                    headers: { 'Authorization': `Bearer ${getToken()}` },
                });
            } catch (_) {}
        },
        async getLogs(params = {}) {
            const qs = new URLSearchParams(params).toString();
            return request(`/logs${qs ? '?' + qs : ''}`);
        },

        async getLogChannels() {
            return request('/logs?action=channels');
        },

        async getLogDates(channel = 'app') {
            return request(`/logs?action=dates&channel=${channel}`);
        },

        async logFrontendError(level, message, context = {}) {
            try {
                await request('/logs', { method: 'POST', body: { level, message, context } });
            } catch (_) {}
        },

        // Export
        async exportData(type) {
            const url = `${BASE}/export?type=${type}`;
            const token = getToken();
            const response = await fetch(url, {
                headers: {
                    'Authorization': `Bearer ${token}`,
                },
            });
            if (!response.ok) {
                const text = await response.text();
                let msg = 'Export failed';
                try { const j = JSON.parse(text); msg = j.message || msg; } catch (_) {}
                throw new Error(msg);
            }
            const blob = await response.blob();
            const disposition = response.headers.get('Content-Disposition') || '';
            const match = disposition.match(/filename="?(.+?)"?$/);
            const filename = match ? match[1] : `${type}.xlsx`;
            const url2 = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url2;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url2);
        },
    };
})();
