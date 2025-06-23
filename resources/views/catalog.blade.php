<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Каталог товарів</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
</head>
<body class="bg-gray-100">
<div class="container mx-auto px-4 py-8">
    <div class="flex flex-col md:flex-row gap-8">
        <!-- Filters -->
        <div class="w-full md:w-1/4">
            <div class="bg-white p-4 rounded-lg shadow">
                <h2 class="text-lg font-bold mb-4">Фільтри</h2>
                <div id="filters" class="space-y-4">
                    <!-- Filters will be added via JavaScript -->
                </div>
            </div>
        </div>

        <!-- List of products -->
        <div class="w-full md:w-3/4">
            <div class="bg-white p-4 rounded-lg shadow mb-4">
                <!-- Sorting -->
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-bold">Товари</h2>
                    <select id="sort" class="border rounded p-2">
                        <option value="price_asc">Ціна: від нижчої до вищої</option>
                        <option value="price_desc">Ціна: від вищої до нижчої</option>
                    </select>
                </div>

                <!-- Active filters -->
                <div id="active-filters" class="flex flex-wrap gap-2 mb-4"></div>

                <!-- List of products -->
                <div id="products" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4"></div>

                <!-- Pagination -->
                <div id="pagination" class="mt-4 flex justify-center gap-2"></div>
            </div>
        </div>
    </div>
</div>

<script src="/js/catalog.js"></script>
</body>
</html>
