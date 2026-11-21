<?php

return [
    // The "design your own" tee. Colours match the swatches in the React design studio.
    'custom_tee' => [
        'sku' => 'zuno-custom-tee',
        'id' => 'custom',
        'name' => 'Custom tee',
        'price' => 999,
        'colors' => ['White', 'Cream', 'Black', 'Brown', 'Green'],
        'sizes' => ['S', 'M', 'L', 'XL', 'XXL'],
    ],

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
    ],

    // "Continue with Google" (Google Cloud Console > APIs & Services > Credentials > OAuth client ID).
    // Empty here falls back to Configure > Customer > Settings > Social Login in the admin.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],

    // The React shop's address: Google sends shoppers back here after they sign in
    'frontend_url' => rtrim(env('ZUNO_FRONTEND_URL', 'http://localhost:5173'), '/'),

    // Where `zuno:setup` reads the starting product photos from (this repo's seed/ folder, mounted by Docker)
    'images_path' => env('ZUNO_IMAGES_PATH', base_path('../zuno-images')),

    // Stock given to every size and colour when the catalogue is first created
    'initial_stock' => 50,

    // Admin roles that can see every page but change nothing (zuno:setup creates "Viewer")
    'viewer_roles' => ['Viewer'],

    'max_lines' => 10,
    'max_quantity' => 20,
    'max_artwork' => 6,
];
