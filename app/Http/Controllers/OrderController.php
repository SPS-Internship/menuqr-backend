<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Menu;
use App\Models\Table;
use App\Models\Promo;
use App\Models\MenuVariant;
use App\Models\MenuAddon;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    // =========================================================
    // CUSTOMER - PREVIEW PROMO
    // =========================================================

    public function preview(Request $request)
    {
        $validated = $request->validate([
            'restaurant_id' => 'required|exists:restaurants,id',

            'items' => 'required|array|min:1',

            'items.*.menu_id' =>
                'required|exists:menus,id',

            'items.*.variant_id' =>
                'nullable|exists:menu_variants,id',

            'items.*.addon_ids' =>
                'nullable|array',

            'items.*.addon_ids.*' =>
                'exists:menu_addons,id',

            'items.*.quantity' =>
                'required|integer|min:1',
        ]);

        $restaurant = Restaurant::findOrFail(
            $validated['restaurant_id']
        );

        $subtotal = 0;

        foreach ($validated['items'] as $item) {

            // =================================================
            // MENU
            // =================================================

            $menu = Menu::where('id', $item['menu_id'])
                ->where(
                    'restaurant_id',
                    $validated['restaurant_id']
                )
                ->where('is_available', true)
                ->first();

            if (!$menu) {
                return response()->json([
                    'message' =>
                        'Menu tidak ditemukan atau tidak tersedia untuk restaurant ini.',
                ], 422);
            }

            $unitPrice = (float) $menu->price;

            // =================================================
            // VARIANT
            // =================================================

            if (!empty($item['variant_id'])) {

                $variant = MenuVariant::where(
                    'id',
                    $item['variant_id']
                )
                    ->where('menu_id', $menu->id)
                    ->where('is_active', true)
                    ->first();

                if (!$variant) {
                    return response()->json([
                        'message' =>
                            'Variant tidak valid untuk menu yang dipilih.',
                    ], 422);
                }

                $unitPrice += (float) $variant->price;
            }

            // =================================================
            // ADDONS
            // =================================================

            if (!empty($item['addon_ids'])) {

                $addons = MenuAddon::whereIn(
                    'id',
                    $item['addon_ids']
                )
                    ->where('menu_id', $menu->id)
                    ->where('is_active', true)
                    ->get();

                if (
                    $addons->count() !==
                    count($item['addon_ids'])
                ) {
                    return response()->json([
                        'message' =>
                            'Terdapat addon yang tidak valid untuk menu yang dipilih.',
                    ], 422);
                }

                foreach ($addons as $addon) {
                    $unitPrice += (float) $addon->price;
                }
            }

            // =================================================
            // SUBTOTAL ITEM
            // =================================================

            $subtotal +=
                $unitPrice * (int) $item['quantity'];
        }

        // =====================================================
        // CARI PROMO OTOMATIS
        // =====================================================

        $promo = null;
        $discount = 0;

        $promos = Promo::where(
            'restaurant_id',
            $validated['restaurant_id']
        )
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            })
            ->where('min_order', '<=', $subtotal)
            ->get();

        foreach ($promos as $candidatePromo) {

            $candidateDiscount = 0;

            if ($candidatePromo->type === 'percentage') {

                $percentage =
                    (float) $candidatePromo->value;

                // Maksimal 100%
                $percentage = min(
                    $percentage,
                    100
                );

                $candidateDiscount =
                    $subtotal *
                    ($percentage / 100);

            } elseif ($candidatePromo->type === 'fixed') {

                $candidateDiscount =
                    (float) $candidatePromo->value;
            }

            // Diskon tidak boleh lebih besar
            // dari subtotal
            $candidateDiscount = min(
                $candidateDiscount,
                $subtotal
            );

            // Pilih diskon terbesar
            if ($candidateDiscount > $discount) {

                $promo = $candidatePromo;
                $discount = $candidateDiscount;
            }
        }

        // =====================================================
        // TOTAL
        // =====================================================

        $total = $subtotal - $discount;

        // =====================================================
        // RESPONSE
        // =====================================================

        return response()->json([
            'data' => [
                'subtotal' => $subtotal,

                'promo' => $promo
                    ? [
                        'id' => $promo->id,
                        'name' => $promo->name,
                        'code' => $promo->code ?? null,
                        'type' => $promo->type,
                        'value' => (float) $promo->value,
                    ]
                    : null,

                'discount' => $discount,

                'total' => $total,
            ],
        ]);
    }


    // =========================================================
    // CUSTOMER - CREATE ORDER
    // =========================================================

    public function store(Request $request)
    {
        $validated = $request->validate([
            'restaurant_id' =>
                'required|exists:restaurants,id',

            'table_id' =>
                'nullable|exists:tables,id',

            'promo_id' =>
                'nullable|exists:promos,id',

            'customer_name' =>
                'nullable|string|max:255',

            'note' =>
                'nullable|string',

            'latitude' =>
                'required|numeric|between:-90,90',

            'longitude' =>
                'required|numeric|between:-180,180',

            'items' =>
                'required|array|min:1',

            'items.*.menu_id' =>
                'required|exists:menus,id',

            'items.*.variant_id' =>
                'nullable|exists:menu_variants,id',

            'items.*.addon_ids' =>
                'nullable|array',

            'items.*.addon_ids.*' =>
                'exists:menu_addons,id',

            'items.*.quantity' =>
                'required|integer|min:1',

            'items.*.note' =>
                'nullable|string',
        ]);

        // =====================================================
        // RESTAURANT
        // =====================================================

        $restaurant = Restaurant::findOrFail(
            $validated['restaurant_id']
        );

        // =====================================================
        // VALIDASI LOKASI
        // =====================================================

        if (
            $restaurant->latitude === null ||
            $restaurant->longitude === null
        ) {
            return response()->json([
                'message' =>
                    'Lokasi restaurant belum tersedia.',
            ], 422);
        }

        $earthRadius = 6371000;

        $lat1 = deg2rad(
            (float) $validated['latitude']
        );

        $lat2 = deg2rad(
            (float) $restaurant->latitude
        );

        $deltaLat = deg2rad(
            (float) $restaurant->latitude -
            (float) $validated['latitude']
        );

        $deltaLng = deg2rad(
            (float) $restaurant->longitude -
            (float) $validated['longitude']
        );

        $a =
            sin($deltaLat / 2) *
            sin($deltaLat / 2) +
            cos($lat1) *
            cos($lat2) *
            sin($deltaLng / 2) *
            sin($deltaLng / 2);

        $c = 2 * atan2(
            sqrt($a),
            sqrt(1 - $a)
        );

        $distance = $earthRadius * $c;

        if (
            $restaurant->location_radius !== null &&
            $distance > $restaurant->location_radius
        ) {
            return response()->json([
                'message' =>
                    'Anda berada di luar area restaurant.',
                'distance' => round($distance, 2),
                'allowed_radius' =>
                    $restaurant->location_radius,
            ], 422);
        }

        // =====================================================
        // TRANSACTION
        // =====================================================

        $order = DB::transaction(function () use (
            $validated,
            $restaurant
        ) {

            // =================================================
            // TABLE
            // =================================================

            $table = null;

            if (!empty($validated['table_id'])) {

                $table = Table::where(
                    'id',
                    $validated['table_id']
                )
                    ->where(
                        'restaurant_id',
                        $validated['restaurant_id']
                    )
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (!$table) {
                    abort(
                        422,
                        'Meja tidak ditemukan atau tidak aktif.'
                    );
                }

                $activeOrderExists = Order::where(
                    'table_id',
                    $table->id
                )
                    ->whereIn(
                        'status',
                        [
                            'pending',
                            'confirmed',
                        ]
                    )
                    ->exists();

                if ($activeOrderExists) {
                    abort(
                        409,
                        'Meja sedang digunakan oleh pesanan lain.'
                    );
                }
            }

            // =================================================
            // HITUNG ITEM
            // =================================================

            $subtotalOrder = 0;

            $orderItems = [];

            foreach ($validated['items'] as $item) {

                $menu = Menu::where(
                    'id',
                    $item['menu_id']
                )
                    ->where(
                        'restaurant_id',
                        $validated['restaurant_id']
                    )
                    ->where('is_available', true)
                    ->first();

                if (!$menu) {
                    abort(
                        422,
                        'Menu tidak ditemukan atau tidak tersedia.'
                    );
                }

                $unitPrice = (float) $menu->price;

                $variant = null;

                if (!empty($item['variant_id'])) {

                    $variant = MenuVariant::where(
                        'id',
                        $item['variant_id']
                    )
                        ->where(
                            'menu_id',
                            $menu->id
                        )
                        ->where('is_active', true)
                        ->first();

                    if (!$variant) {
                        abort(
                            422,
                            'Variant tidak valid untuk menu ini.'
                        );
                    }

                    $unitPrice +=
                        (float) $variant->price;
                }

                $addons = collect();

                if (!empty($item['addon_ids'])) {

                    $addons = MenuAddon::whereIn(
                        'id',
                        $item['addon_ids']
                    )
                        ->where(
                            'menu_id',
                            $menu->id
                        )
                        ->where('is_active', true)
                        ->get();

                    if (
                        $addons->count() !==
                        count($item['addon_ids'])
                    ) {
                        abort(
                            422,
                            'Terdapat addon yang tidak valid untuk menu ini.'
                        );
                    }

                    foreach ($addons as $addon) {
                        $unitPrice +=
                            (float) $addon->price;
                    }
                }

                $quantity =
                    (int) $item['quantity'];

                $subtotal =
                    $unitPrice * $quantity;

                $subtotalOrder += $subtotal;

                $orderItems[] = [
                    'menu_id' =>
                        $menu->id,

                    'variant_id' =>
                        $variant?->id,

                    'addons' =>
                        !empty($item['addon_ids'])
                            ? $item['addon_ids']
                            : null,

                    'quantity' =>
                        $quantity,

                    'price' =>
                        $unitPrice,

                    'subtotal' =>
                        $subtotal,

                    'note' =>
                        $item['note'] ?? null,
                ];
            }

            // =================================================
            // AUTO PROMO
            // =================================================

            $promo = null;
            $discount = 0;

            $promos = Promo::where(
                'restaurant_id',
                $validated['restaurant_id']
            )
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('starts_at')
                        ->orWhere(
                            'starts_at',
                            '<=',
                            now()
                        );
                })
                ->where(function ($query) {
                    $query->whereNull('ends_at')
                        ->orWhere(
                            'ends_at',
                            '>=',
                            now()
                        );
                })
                ->where(
                    'min_order',
                    '<=',
                    $subtotalOrder
                )
                ->get();

            foreach ($promos as $candidatePromo) {

                $candidateDiscount = 0;

                if (
                    $candidatePromo->type ===
                    'percentage'
                ) {

                    $percentage =
                        (float) $candidatePromo->value;

                    $percentage = min(
                        $percentage,
                        100
                    );

                    $candidateDiscount =
                        $subtotalOrder *
                        ($percentage / 100);

                } elseif (
                    $candidatePromo->type ===
                    'fixed'
                ) {

                    $candidateDiscount =
                        (float) $candidatePromo->value;
                }

                $candidateDiscount = min(
                    $candidateDiscount,
                    $subtotalOrder
                );

                if (
                    $candidateDiscount >
                    $discount
                ) {
                    $promo =
                        $candidatePromo;

                    $discount =
                        $candidateDiscount;
                }
            }

            $total =
                $subtotalOrder - $discount;

            // =================================================
            // CREATE ORDER
            // =================================================

            $order = Order::create([
                'restaurant_id' =>
                    $validated['restaurant_id'],

                'table_id' =>
                    $validated['table_id'] ?? null,

                'promo_id' =>
                    $promo?->id,

                'order_code' =>
                    'ORD-' .
                    strtoupper(
                        Str::random(8)
                    ),

                'customer_name' =>
                    $validated['customer_name']
                    ?? null,

                'note' =>
                    $validated['note']
                    ?? null,

                'total' =>
                    $total,

                'discount' =>
                    $discount,

                'status' =>
                    'pending',

                'payment_status' =>
                    'unpaid',
            ]);

            // =================================================
            // CREATE ORDER ITEMS
            // =================================================

            foreach ($orderItems as $orderItem) {

                $order->items()->create(
                    $orderItem
                );
            }

            return $order;
        });

        // =====================================================
        // LOAD RELATIONS
        // =====================================================

        $order->load([
            'restaurant',
            'table',
            'promo',
            'items.menu',
            'items.variant',
        ]);

        // =====================================================
        // RESPONSE
        // =====================================================

        return response()->json([
            'message' =>
                'Pesanan berhasil dibuat. Silakan lanjut melakukan pembayaran di kasir.',

            'data' =>
                $order,

            'payment' => [
                'status' =>
                    'unpaid',

                'message' =>
                    'Silakan lanjut melakukan pembayaran di kasir.',
            ],
        ], 201);
    }


    // =========================================================
    // CUSTOMER - SHOW ORDER
    // =========================================================

    public function show($orderCode)
    {
        $order = Order::where(
            'order_code',
            $orderCode
        )
            ->with([
                'restaurant',
                'table',
                'promo',
                'items.menu',
                'items.variant',
            ])
            ->firstOrFail();

        return response()->json([
            'data' => $order,
        ]);
    }


    // =========================================================
    // ADMIN - LIST ORDERS
    // =========================================================

    public function index(Request $request)
    {
        $orders = Order::where(
            'restaurant_id',
            $request->user()->restaurant_id
        )
            ->with([
                'restaurant',
                'table',
                'promo',
                'items.menu',
                'items.variant',
            ])
            ->latest()
            ->get();

        return response()->json([
            'data' => $orders,
        ]);
    }


    // =========================================================
    // ADMIN - UPDATE ORDER STATUS
    // =========================================================

    public function updateStatus(
        Request $request,
        $id
    ) {
        $validated = $request->validate([
            'status' =>
                'required|in:pending,confirmed,completed,cancelled',
        ]);

        $order = Order::where(
            'restaurant_id',
            $request->user()->restaurant_id
        )
            ->findOrFail($id);

        $currentStatus =
            $order->status;

        $newStatus =
            $validated['status'];

        if (
            in_array(
                $currentStatus,
                [
                    'completed',
                    'cancelled',
                ]
            )
        ) {
            return response()->json([
                'message' =>
                    'Status pesanan sudah tidak dapat diubah.',
            ], 422);
        }

        $allowedTransitions = [
            'pending' => [
                'confirmed',
                'cancelled',
            ],

            'confirmed' => [
                'completed',
            ],
        ];

        if (
            !isset(
                $allowedTransitions[
                    $currentStatus
                ]
            ) ||
            !in_array(
                $newStatus,
                $allowedTransitions[
                    $currentStatus
                ]
            )
        ) {
            return response()->json([
                'message' =>
                    "Status pesanan tidak dapat diubah dari {$currentStatus} menjadi {$newStatus}.",
            ], 422);
        }

        $order->update([
            'status' =>
                $newStatus,
        ]);

        $order->load([
            'restaurant',
            'table',
            'promo',
            'items.menu',
            'items.variant',
        ]);

        return response()->json([
            'message' =>
                'Status pesanan berhasil diperbarui',

            'data' =>
                $order,
        ]);
    }


    // =========================================================
    // ADMIN - UPDATE PAYMENT STATUS
    // =========================================================

    public function updatePaymentStatus(
        Request $request,
        $id
    ) {
        $validated = $request->validate([
            'payment_status' =>
                'required|in:unpaid,paid',
        ]);

        $order = Order::where(
            'restaurant_id',
            $request->user()->restaurant_id
        )
            ->findOrFail($id);

        if (
            $order->payment_status === 'paid' &&
            $validated['payment_status'] === 'unpaid'
        ) {
            return response()->json([
                'message' =>
                    'Status pembayaran yang sudah paid tidak dapat dikembalikan menjadi unpaid.',
            ], 422);
        }

        $order->update([
            'payment_status' =>
                $validated['payment_status'],
        ]);

        $order->load([
            'restaurant',
            'table',
            'promo',
            'items.menu',
            'items.variant',
        ]);

        return response()->json([
            'message' =>
                'Status pembayaran berhasil diperbarui',

            'data' =>
                $order,
        ]);
    }
}