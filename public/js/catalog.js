class CatalogApp {
    constructor() {
        this.currentPage = 1;
        this.limit = 9;
        this.activeFilters = {};
        this.totalPages = 1;
        this.filtersData = []; // Saving filter data

        // Initializing event handlers
        this.initEventListeners();

        // Loading initial data
        this.loadFilters();
        this.loadProducts();
    }

    initEventListeners() {
        // Sorting handler
        document.getElementById('sort').addEventListener('change', (e) => {
            this.currentPage = 1;
            this.loadProducts();
        });
    }

    async loadFilters() {
        try {
            const response = await axios.get('/api/catalog/filters', {
                params: { filter: this.activeFilters }
            });
            this.filtersData = response.data; // Saving filters data
            this.renderFilters(response.data);
            this.renderActiveFilters();
        } catch (error) {
            console.error('Error loading filters:', error);
        }
    }

    async loadProducts() {
        try {
            const params = {
                page: this.currentPage,
                limit: this.limit,
                sort_by: document.getElementById('sort').value,
                filter: this.activeFilters
            };

            const response = await axios.get('/api/catalog/products', { params });
            this.renderProducts(response.data.data);
            this.renderPagination(response.data.meta);
        } catch (error) {
            console.error('Error loading products:', error);
        }
    }

    renderFilters(filters) {
        const filtersContainer = document.getElementById('filters');
        filtersContainer.innerHTML = '';

        filters.forEach(filter => {
            const filterGroup = document.createElement('div');
            filterGroup.className = 'border-b pb-4';

            // Filter group header
            filterGroup.innerHTML = `
                <h3 class="font-semibold mb-2">${filter.name}</h3>
                <div class="h-[50vh] overflow-y-auto scrollbar-thin scrollbar-thumb-gray-400 scrollbar-track-gray-100">
                    <div class="space-y-2">
                        ${filter.values.map(value => `
                            <div class="filter-item cursor-pointer hover:bg-gray-50 p-2 rounded flex items-center justify-between ${
                    this.isFilterActive(filter.slug, value.value) ? 'bg-blue-50' : ''
                }" data-filter="${filter.slug}" data-value="${value.value}">
                                <span>${value.display_value || value.value}</span>
                                <span class="text-gray-500 text-sm">(${value.count})</span>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;

            // Adding handlers for filter elements
            filterGroup.querySelectorAll('.filter-item').forEach(item => {
                item.addEventListener('click', () => {
                    const filterName = item.dataset.filter;
                    const filterValue = item.dataset.value;
                    this.toggleFilter(filterName, filterValue);
                });
            });

            filtersContainer.appendChild(filterGroup);
        });
    }

    renderActiveFilters() {
        const container = document.getElementById('active-filters');
        container.innerHTML = '';

        Object.entries(this.activeFilters).forEach(([filterName, values]) => {
            values.forEach(value => {
                const filterGroup = this.filtersData.find(f => f.slug === filterName);
                const filterLabel = filterGroup ? filterGroup.name : filterName;
                const filterValue = filterGroup ?
                    (filterGroup.values.find(v => v.value === value)?.display_value || value) :
                    value;

                const tag = document.createElement('div');
                tag.className = 'bg-blue-100 text-blue-800 px-3 py-1 rounded-full flex items-center gap-2';
                tag.innerHTML = `
                    <span>${filterLabel}: ${filterValue}</span>
                    <button class="text-blue-600 hover:text-blue-800" data-filter="${filterName}" data-value="${value}">
                        ×
                    </button>
                `;

                tag.querySelector('button').addEventListener('click', () => {
                    this.toggleFilter(filterName, value);
                });

                container.appendChild(tag);
            });
        });
    }

    renderProducts(products) {
        const productsContainer = document.getElementById('products');
        if (products.length === 0) {
            productsContainer.innerHTML = `
                <div class="col-span-full text-center py-8 text-gray-500">
                    Товари не знайдені
                </div>
            `;
            return;
        }

        productsContainer.innerHTML = products.map(product => `
            <div class="border rounded-lg p-4 hover:shadow-lg transition-shadow">
                ${product.images && product.images[0] ? `
                    <img src="${product.images[0].image_url}"
                         alt="${product.name}"
                         class="w-full h-48 object-cover mb-4 rounded">
                ` : ''}
                <h3 class="font-semibold mb-2">${product.name}</h3>
                <p class="text-lg font-bold text-blue-600">${product.price} ${product.currency_id}</p>
                <p class="text-sm text-gray-600 mb-2">${product.vendor}</p>
                <p class="text-sm text-gray-500">${product.description?.substring(0, 100) || ''}...</p>
            </div>
        `).join('');
    }

    renderPagination(meta) {
        const paginationContainer = document.getElementById('pagination');
        this.totalPages = meta.last_page;

        let pages = [];

        // Adding a "Previous" button
        if (meta.current_page > 1) {
            pages.push(`
                <button class="px-3 py-1 border rounded hover:bg-gray-100"
                        onclick="catalogApp.goToPage(${meta.current_page - 1})">
                    ←
                </button>
            `);
        }

        // Logic for displaying page numbers
        let startPage = Math.max(1, meta.current_page - 2);
        let endPage = Math.min(meta.last_page, meta.current_page + 2);

        if (startPage > 1) {
            pages.push(`<span class="px-3 py-1">...</span>`);
        }

        for (let i = startPage; i <= endPage; i++) {
            pages.push(`
                <button class="px-3 py-1 border rounded ${i === meta.current_page ? 'bg-blue-500 text-white' : 'hover:bg-gray-100'}"
                        onclick="catalogApp.goToPage(${i})">
                    ${i}
                </button>
            `);
        }

        if (endPage < meta.last_page) {
            pages.push(`<span class="px-3 py-1">...</span>`);
        }

        // Adding a "Next" button
        if (meta.current_page < meta.last_page) {
            pages.push(`
                <button class="px-3 py-1 border rounded hover:bg-gray-100"
                        onclick="catalogApp.goToPage(${meta.current_page + 1})">
                    →
                </button>
            `);
        }

        paginationContainer.innerHTML = pages.join('');
    }

    toggleFilter(filterName, filterValue) {
        if (!this.activeFilters[filterName]) {
            this.activeFilters[filterName] = [];
        }

        const index = this.activeFilters[filterName].indexOf(filterValue);
        if (index === -1) {
            this.activeFilters[filterName].push(filterValue);
        } else {
            this.activeFilters[filterName].splice(index, 1);
            if (this.activeFilters[filterName].length === 0) {
                delete this.activeFilters[filterName];
            }
        }

        this.currentPage = 1;
        this.loadFilters();
        this.loadProducts();
    }

    isFilterActive(filterName, filterValue) {
        return this.activeFilters[filterName]?.includes(filterValue) || false;
    }

    goToPage(page) {
        this.currentPage = page;
        this.loadProducts();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

// Initializing the Application
const catalogApp = new CatalogApp();
