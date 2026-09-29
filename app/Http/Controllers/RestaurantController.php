<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use Illuminate\Http\Request;

class RestaurantController extends Controller
{
    // ==========================================
    // PUBLIC
    // ==========================================

    // Menampilkan semua restoran aktif
    public function index()
    {
        $restaurants = Restaurant::where('is_active', true)->get();

        return response()->json($restaurants);
    }


    // Menampilkan data restoran untuk halaman admin login
    public function adminLogin($slug)
    {
        $restaurant = Restaurant::where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (!$restaurant) {
            return response()->json([
                'message' => 'Restoran tidak ditemukan.'
            ], 404);
        }

        return response()->json([
            'message' => 'Data restoran berhasil diambil',
            'data' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'logo' => $restaurant->logo,
            ],
        ]);
    }


    // Menampilkan menu berdasarkan slug restoran
    public function menu($slug)
    {
        $restaurant = Restaurant::where('slug', $slug)
            ->where('is_active', true)
            ->with([
                'categories' => function ($query) {
                    $query->where('is_active', true)
                        ->orderBy('sort_order');
                },

                'menus' => function ($query) {
                    $query->where('is_available', true)
                        ->with([
                            'variants' => function ($query) {
                                $query->where('is_active', true)
                                    ->orderBy('sort_order');
                            },

                            'addons' => function ($query) {
                                $query->where('is_active', true)
                                    ->orderBy('sort_order');
                            },
                        ])
                        ->orderBy('sort_order');
                },
            ])
            ->firstOrFail();

        $restaurant->menus->each(function ($menu) {
            $menu->image_url = $menu->image
                ? asset('storage/' . $menu->image)
                : null;
        });

        return response()->json([
            'restaurant' => $restaurant,
            'categories' => $restaurant->categories,
            'menus' => $restaurant->menus,
        ]);
    }


    // ==========================================
    // ADMIN
    // ==========================================

    // Menambahkan restoran
    public function store(Request $request)
    {
        $admin = $request->user();

        // Admin yang sudah memiliki restoran
        // tidak boleh membuat restoran lain
        if ($admin->restaurant_id) {
            return response()->json([
                'message' => 'Admin sudah memiliki restaurant'
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:restaurants,slug',
            'logo' => 'nullable|string',
            'description' => 'nullable|string',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'whatsapp' => 'required|string|max:20',
            'is_active' => 'boolean',
        ]);

        $restaurant = Restaurant::create($validated);

        // Hubungkan admin dengan restaurant yang baru dibuat
        $admin->update([
            'restaurant_id' => $restaurant->id,
        ]);

        return response()->json([
            'message' => 'Restaurant berhasil ditambahkan',
            'data' => $restaurant
        ], 201);
    }


    // Memperbarui restaurant milik admin
    public function update(Request $request, $id)
    {
        $admin = $request->user();

        $restaurant = Restaurant::where('id', $id)
            ->where('id', $admin->restaurant_id)
            ->first();

        if (!$restaurant) {
            return response()->json([
                'message' => 'Restaurant tidak ditemukan atau bukan milik Anda'
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:restaurants,slug,' . $id,
            'logo' => 'nullable|string',
            'description' => 'nullable|string',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'whatsapp' => 'required|string|max:20',
            'is_active' => 'boolean',
        ]);

        $restaurant->update($validated);

        return response()->json([
            'message' => 'Restaurant berhasil diperbarui',
            'data' => $restaurant
        ]);
    }


    // Menghapus restaurant milik admin
    public function destroy(Request $request, $id)
    {
        $admin = $request->user();

        $restaurant = Restaurant::where('id', $id)
            ->where('id', $admin->restaurant_id)
            ->first();

        if (!$restaurant) {
            return response()->json([
                'message' => 'Restaurant tidak ditemukan atau bukan milik Anda'
            ], 404);
        }

        $restaurant->delete();

        // Putuskan hubungan admin dengan restaurant
        $admin->update([
            'restaurant_id' => null,
        ]);

        return response()->json([
            'message' => 'Restaurant berhasil dihapus'
        ]);
    }
}