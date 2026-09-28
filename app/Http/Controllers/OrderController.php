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
    // MEMBUAT PESANAN
    // CUSTOMER
    // =========================================================
    public function store(Request $request)
    {
        $validated = $request->validate([
            'restaurant_id' => 'required|exists:restaurants,id',
            'table_id' => 'nullable|exists:tables,id',
            'promo_id' => 'nullable|exists:promos,id',

            'customer_name' => 'nullable|string|max:255',
            'note' => 'nullable|string',

            // LOKASI CUSTOMER
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',

            // ITEMS
            'items' => 'required|array|min:1',
            'items.*.menu_id' => 'required|exists:menus,id',
            'items.*.variant_id' => 'nullable|exists:menu_variants,id',
            'items.*.addon_ids' => 'nullable|array',
            'items.*.addon_ids.*' => 'exists:menu_addons,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.note' => 'nullable|string',
        ]);

        // =========================================================
        // VALIDASI LOKASI CUSTOMER
        // =========================================================

        $restaurant = Restaurant::findOrFail(
            $validated['restaurant_id']
        );

        if (
            $restaurant->latitude === null ||
            $restaurant->longitude === null
        ) {
            abort(
                422,
                'Lokasi restaurant belum diatur'
            );
        }

        $restaurantLatitude = (float) $restaurant->latitude;
        $restaurantLongitude = (float) $restaurant->longitude;

        $customerLatitude = (float) $validated['latitude'];
        $customerLongitude = (float) $validated['longitude'];

        $earthRadius = 6371000;

        $lat1 = deg2rad($restaurantLatitude);
        $lat2 = deg2rad($customerLatitude);

        $deltaLat = deg2rad(
            $customerLatitude - $restaurantLatitude
        );

        $deltaLon = deg2rad(
            $customerLongitude - $restaurantLongitude
        );

        // =========================================================
        // HAVERSINE FORMULA
        // =========================================================

        $a =
            sin($deltaLat / 2) * sin($deltaLat / 2) +
            cos($lat1) *
            cos($lat2) *
            sin($deltaLon / 2) *
            sin($deltaLon / 2);

        $c = 2 * atan2(
            sqrt($a),
            sqrt(1 - $a)
        );

        $distance = $earthRadius * $c;

        // =========================================================
        // CEK RADIUS
        // =========================================================

        if ($distance > $restaurant->location_radius) {
            abort(
                422,
                'Anda berada di luar area restaurant. Silakan berada di dekat restaurant untuk melakukan pemesanan.'
            );
        }

        // =========================================================
        // PROSES ORDER
        // =========================================================

        $order = DB::transaction(function () use ($validated) {

            // =====================================================
            // VALIDASI MEJA
            // =====================================================

            if (!empty($validated['table_id'])) {

                $table = Table::where(
                    'id',
                    $validated['table_id']
                )
                    ->where(
                        'restaurant_id',
                        $validated['restaurant_id']
                    )
                    ->first();

                if (!$table) {
                    abort(
                        422,
                        'Meja tidak sesuai dengan restaurant'
                    );
                }

                if (!$table->is_active) {
                    abort(
                        422,
                        'Meja sedang tidak aktif'
                    );
                }
            }

            $subtotalOrder = 0;
            $itemsData = [];

            // =====================================================
            // PROSES SETIAP ITEM
            // =====================================================

            foreach ($validated['items'] as $item) {

                $menu = Menu::findOrFail(
                    $item['menu_id']
                );

                // -------------------------------------------------
                // MENU HARUS MILIK RESTAURANT YANG SAMA
                // -------------------------------------------------

                if (
                    $menu->restaurant_id !=
                    $validated['restaurant_id']
                ) {
                    abort(
                        422,
                        "Menu {$menu->name} tidak sesuai dengan restaurant"
                    );
                }

                // -------------------------------------------------
                // MENU HARUS TERSEDIA
                // -------------------------------------------------

                if (!$menu->is_available) {
                    abort(
                        422,
                        "Menu {$menu->name} sedang tidak tersedia"
                    );
                }

                // =================================================
                // HARGA DASAR MENU
                // =================================================

                $menuPrice = (float) $menu->price;

                // =================================================
                // VARIANT
                // =================================================

                $variant = null;
                $variantPrice = 0;

                if (!empty($item['variant_id'])) {

                    $variant = MenuVariant::findOrFail(
                        $item['variant_id']
                    );

                    // Variant harus milik menu yang dipilih
                    if ($variant->menu_id != $menu->id) {
                        abort(
                            422,
                            'Variant tidak sesuai dengan menu'
                        );
                    }

                    // Variant harus aktif
                    if (!$variant->is_active) {
                        abort(
                            422,
                            "Variant {$variant->name} sedang tidak tersedia"
                        );
                    }

                    $variantPrice = (float) $variant->price;
                }

                // =================================================
                // ADDON / TOPPING
                // =================================================

                $addons = [];
                $addonTotal = 0;

                if (!empty($item['addon_ids'])) {

                    $addonIds = array_unique(
                        $item['addon_ids']
                    );

                    foreach ($addonIds as $addonId) {

                        $addon = MenuAddon::findOrFail(
                            $addonId
                        );

                        // Addon harus milik menu yang sama
                        if ($addon->menu_id != $menu->id) {
                            abort(
                                422,
                                "Addon {$addon->name} tidak sesuai dengan menu"
                            );
                        }

                        // Addon harus aktif
                        if (!$addon->is_active) {
                            abort(
                                422,
                                "Addon {$addon->name} sedang tidak tersedia"
                            );
                        }

                        $addonPrice = (float) $addon->price;

                        $addonTotal += $addonPrice;

                        $addons[] = [
                            'id' => $addon->id,
                            'name' => $addon->name,
                            'price' => $addonPrice,
                        ];
                    }
                }

                // =================================================
                // HITUNG HARGA ITEM
                // =================================================

                $quantity = (int) $item['quantity'];

                $unitPrice =
                    $menuPrice +
                    $variantPrice +
                    $addonTotal;

                $subtotal = $unitPrice * $quantity;

                $subtotalOrder += $subtotal;

                $itemsData[] = [
                    'menu_id' => $menu->id,
                    'variant_id' => $variant?->id,

                    'addons' => !empty($addons)
                        ? $addons
                        : null,

                    'quantity' => $quantity,
                    'price' => $unitPrice,
                    'subtotal' => $subtotal,

                    'note' => $item['note'] ?? null,
                ];
            }

            // =====================================================
            // PROMO
            // =====================================================

            $promo = null;
            $discount = 0;

            if (!empty($validated['promo_id'])) {

                $promo = Promo::findOrFail(
                    $validated['promo_id']
                );

                // Promo harus milik restaurant yang sama
                if (
                    $promo->restaurant_id !=
                    $validated['restaurant_id']
                ) {
                    abort(
                        422,
                        'Promo tidak sesuai dengan restaurant'
                    );
                }

                // Promo harus aktif
                if (!$promo->is_active) {
                    abort(
                        422,
                        'Promo sedang tidak aktif'
                    );
                }

                // Promo belum mulai
                if (
                    $promo->starts_at &&
                    now()->lt($promo->starts_at)
                ) {
                    abort(
                        422,
                        'Promo belum mulai'
                    );
                }

                // Promo sudah berakhir
                if (
                    $promo->ends_at &&
                    now()->gt($promo->ends_at)
                ) {
                    abort(
                        422,
                        'Promo sudah berakhir'
                    );
                }

                // Minimum pembelian
                if (
                    $subtotalOrder <
                    (float) $promo->min_order
                ) {
                    abort(
                        422,
                        'Minimum pembelian untuk promo ini adalah Rp' .
                        number_format(
                            $promo->min_order,
                            0,
                            ',',
                            '.'
                        )
                    );
                }

                // Hitung diskon
                if ($promo->type === 'percentage') {

                    if ((float) $promo->value > 100) {
                        abort(
                            422,
                            'Persentase diskon tidak boleh lebih dari 100%'
                        );
                    }

                    $discount =
                        $subtotalOrder *
                        (
                            (float) $promo->value / 100
                        );

                } else {

                    $discount =
                        (float) $promo->value;
                }

                // Diskon tidak boleh lebih dari subtotal
                $discount = min(
                    $discount,
                    $subtotalOrder
                );
            }

            // =====================================================
            // TOTAL AKHIR
            // =====================================================

            $total = $subtotalOrder - $discount;

            // =====================================================
            // BUAT ORDER
            // =====================================================

            $order = Order::create([
                'restaurant_id' => $validated['restaurant_id'],

                'table_id' =>
                    $validated['table_id'] ?? null,

                'promo_id' =>
                    $promo?->id,

                'order_code' =>
                    'ORD-' .
                    strtoupper(Str::random(8)),

                'customer_name' =>
                    $validated['customer_name'] ?? null,

                'note' =>
                    $validated['note'] ?? null,

                'total' => $total,

                'discount' => $discount,

                // Kolom lama tetap disimpan
                // agar tidak perlu mengubah database.
                // Customer tidak menggunakan status ini.
                'status' => 'pending',

                // Pembayaran dilakukan di kasir
                'payment_status' => 'unpaid',
            ]);

            // =====================================================
            // SIMPAN ORDER ITEMS
            // =====================================================

            $order->items()->createMany(
                $itemsData
            );

            return $order;
        });

        // =========================================================
        // LOAD RELASI
        // =========================================================

        $order->load([
            'restaurant',
            'table',
            'promo',
            'items.menu',
            'items.variant',
        ]);

        // =========================================================
        // RESPONSE PESANAN BERHASIL
        // =========================================================

        return response()->json([
            'message' =>
                'Pesanan berhasil dibuat. Silakan lanjut melakukan pembayaran di kasir.',

            'data' => $order,

            'payment' => [
                'status' => 'unpaid',
                'message' =>
                    'Silakan lanjut melakukan pembayaran di kasir.',
            ],
        ], 201);
    }


    // =========================================================
    // DETAIL PESANAN
    // CUSTOMER TIDAK PERLU LOGIN
    // AKSES MENGGUNAKAN ORDER CODE
    // =========================================================

    public function show($orderCode)
    {
        $order = Order::with([
            'restaurant',
            'table',
            'promo',
            'items.menu',
            'items.variant',
        ])
            ->where(
                'order_code',
                $orderCode
            )
            ->firstOrFail();

        return response()->json([
            'data' => $order,
        ]);
    }


    // =========================================================
    // SEMUA PESANAN ADMIN
    // =========================================================

    public function index(Request $request)
    {
        $admin = $request->user();

        $orders = Order::with([
            'restaurant',
            'table',
            'promo',
            'items.menu',
            'items.variant',
        ])
            ->where(
                'restaurant_id',
                $admin->restaurant_id
            )
            ->latest()
            ->get();

        return response()->json($orders);
    }


    // =========================================================
// UPDATE STATUS PESANAN
// ADMIN
// =========================================================

public function updateStatus(
    Request $request,
    $id
) {
    $validated = $request->validate([
        'status' =>
            'required|in:pending,confirmed,completed,cancelled',
    ]);

    // =========================================================
    // CARI ORDER SESUAI RESTAURANT ADMIN
    // =========================================================

    $order = Order::where(
        'restaurant_id',
        $request->user()->restaurant_id
    )
        ->findOrFail($id);

    $currentStatus = $order->status;
    $newStatus = $validated['status'];

    // =========================================================
    // STATUS YANG SUDAH FINAL
    // =========================================================

    if (
        in_array(
            $currentStatus,
            ['completed', 'cancelled']
        )
    ) {
        return response()->json([
            'message' =>
                'Status pesanan sudah tidak dapat diubah.',
        ], 422);
    }

    // =========================================================
    // ATURAN PERUBAHAN STATUS
    // =========================================================

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
        !isset($allowedTransitions[$currentStatus]) ||
        !in_array(
            $newStatus,
            $allowedTransitions[$currentStatus]
        )
    ) {
        return response()->json([
            'message' =>
                "Status pesanan tidak dapat diubah dari {$currentStatus} menjadi {$newStatus}.",
        ], 422);
    }

    // =========================================================
    // UPDATE STATUS
    // =========================================================

    $order->update([
        'status' => $newStatus,
    ]);

    // =========================================================
    // LOAD RELASI
    // =========================================================

    $order->load([
        'restaurant',
        'table',
        'promo',
        'items.menu',
        'items.variant',
    ]);

    // =========================================================
    // RESPONSE
    // =========================================================

    return response()->json([
        'message' =>
            'Status pesanan berhasil diperbarui',

        'data' => $order,
    ]);
}


    // =========================================================
    // UPDATE STATUS PEMBAYARAN
    // ADMIN / KASIR
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

        // =========================================================
        // STATUS PEMBAYARAN SAMA
        // =========================================================

        if (
            $order->payment_status ===
            $validated['payment_status']
        ) {
            return response()->json([
                'message' =>
                    'Status pembayaran sudah ' .
                    $validated['payment_status'],

                'data' => $order,
            ]);
        }

        // =========================================================
        // PEMBAYARAN YANG SUDAH LUNAS
        // TIDAK BOLEH DIKEMBALIKAN KE UNPAID
        // =========================================================

        if (
            $order->payment_status === 'paid' &&
            $validated['payment_status'] === 'unpaid'
        ) {
            return response()->json([
                'message' =>
                    'Pembayaran yang sudah lunas tidak dapat dibatalkan.',
            ], 422);
        }

        // =========================================================
        // UPDATE PEMBAYARAN
        // =========================================================

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

            'data' => $order,
        ]);
    }
}