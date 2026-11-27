<?php

return [
    'navigation' => [
        [
            'group' => 'Overview',
            'items' => [
                ['label' => 'Dashboard', 'route' => 'vendor.dashboard', 'icon' => 'home', 'active' => 'dashboard'],
            ],
        ],
        [
            'group' => 'Location',
            'items' => [
                ['label' => 'Countries', 'route' => 'vendor.countries.index', 'icon' => 'world', 'active' => 'countries'],
                ['label' => 'Cities', 'route' => 'vendor.cities.index', 'icon' => 'map-pin', 'active' => 'cities'],
                ['label' => 'Region / Zone', 'route' => 'vendor.zones.index', 'icon' => 'map-2', 'active' => 'zones'],
            ],
        ],
        [
            'group' => 'Master Data',
            'items' => [
                ['label' => 'Categories', 'route' => 'vendor.categories.index', 'icon' => 'category', 'active' => 'categories'],
                // Hidden for now: subcategories and taxes are not used yet.
                // ['label' => 'Subcategories', 'route' => 'vendor.subcategories.index', 'icon' => 'category-2', 'active' => 'subcategories'],
                ['label' => 'Customers', 'route' => 'vendor.customers.index', 'icon' => 'users', 'active' => 'customers'],
                // ['label' => 'Taxes', 'route' => 'vendor.taxes.index', 'icon' => 'receipt-2', 'active' => 'taxes'],
            ],
        ],
        [
            'group' => 'Commerce',
            'items' => [
                ['label' => 'Products', 'route' => 'vendor.products.index', 'icon' => 'shopping-bag', 'active' => 'products'],
                ['label' => 'Inventory', 'route' => 'vendor.inventory.index', 'icon' => 'box-seam', 'active' => 'inventory'],
                ['label' => 'Orders', 'route' => 'vendor.orders.index', 'icon' => 'clipboard-list', 'active' => 'orders'],
                ['label' => 'Coupons', 'route' => 'vendor.coupons.index', 'icon' => 'discount-2', 'active' => 'coupons'],
                ['label' => 'Delivery Charge', 'route' => 'vendor.delivery.edit', 'icon' => 'truck-delivery', 'active' => 'delivery'],
                ['label' => 'Payments', 'route' => 'vendor.payments.index', 'icon' => 'credit-card', 'active' => 'payments'],
            ],
        ],
        [
            'group' => 'System',
            'items' => [
                ['label' => 'Home Banner', 'route' => 'vendor.banner.edit', 'icon' => 'photo', 'active' => 'banner'],
                ['label' => 'Payouts', 'route' => 'vendor.stripe.index', 'icon' => 'building-bank', 'active' => 'stripe'],
                ['label' => 'Zoho POS', 'route' => 'vendor.zoho-pos.index', 'icon' => 'building-store', 'active' => 'zoho-pos'],
                ['label' => 'Notifications', 'route' => 'vendor.notifications.index', 'icon' => 'bell', 'active' => 'notifications'],
                ['label' => 'Reports', 'route' => 'vendor.reports.index', 'icon' => 'chart-bar', 'active' => 'reports'],
            ],
        ],
    ],
];
