# E-commerce Product Catalog

Product catalog with filtering on Laravel and JavaScript using Redis for filter caching.

## Tech stack

- PHP 8.2
- Laravel 12.19.3
- Redis
- MySQL
- JavaScript (Vanilla)
- TailwindCSS 4.0.0

## Main functions

- Import products from XML file
- Filter products by various parameters
- Caching filters in Redis
- Pagination of results
- Sorting by price
- Adaptive design

## Installation

1. Clone the repository:

git clone https://github.com/Stilion/test2-uaitlab.git

2. Install PHP dependencies:

composer install


3. Install JavaScript dependencies:

npm install

4. Copy `.env.example` to `.env` and configure the database and Redis connection:

cp .env.example .env

5. Perform migrations:

php artisan migrate

## Import of goods

To import products, use the command:

php artisan products:import price.xml


## Filter structure

Available filters:
- Brand (brend)
- Color (kolir)
- Supplier size (rozmir-postacalnika)

## API Endpoints

- `GET /api/catalog/products` - get list of products
 - Parameters:
  - page: page number
  - limit: number of products per page
  - sort_by: sorting (price_asc, price_desc)
  - filter[]: filters

- `GET /api/catalog/filters` - get available filters
 - Parameters:
  - filter[]: active filters

## Database structure

- `products` - products
- `product_attributes` - product attributes
- `product_images` - product images
- `categories` - categories
- `product_categories` - product-category relationships

## Caching

The project uses Redis to cache filters with the `laravel_database_filter:` prefix.

## Frontend

Frontend is implemented using vanilla JavaScript and TailwindCSS. The main functionality is in `catalog.js`.

## System requirements

- PHP >= 8.2
- MySQL >= 8.0
- Redis >= 6.0
- Node.js >= 16.0




